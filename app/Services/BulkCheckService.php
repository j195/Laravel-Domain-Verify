<?php

namespace App\Services;

use App\Jobs\ProcessCheckBatchRemainderJob;
use App\Jobs\ProcessCheckItemJob;
use App\Models\CheckBatch;
use App\Models\CheckItem;
use App\Models\User;
use App\Support\DomainNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates check batches and advances bulk jobs one domain at a time so the UI can show live progress.
 */
class BulkCheckService
{
    public function __construct(private DomainCheckService $checks) {}

    /**
     * @param  array<int, string>  $inputs
     */
    public function createFromInputs(
        User $user,
        string $type,
        array $inputs,
        string $source = 'single',
        ?string $filename = null,
        ?string $dkimSelector = null,
        bool $includeAll = false,
        bool $dispatchJobs = true,
    ): CheckBatch {
        $normalized = [];

        foreach ($inputs as $input) {
            $input = trim((string) $input);
            if ($input === '' || str_starts_with($input, '#')) {
                continue;
            }

            try {
                $domain = DomainNormalizer::fromInput($input);
            } catch (InvalidArgumentException) {
                $domain = null;
            }

            $key = strtolower($input);
            // Last occurrence of a duplicate line wins; we only check each unique input once.
            $normalized[$key] = [
                'input' => $input,
                'domain' => $domain,
            ];
        }

        $rows = array_values($normalized);
        $max = (int) config('mailin.bulk_max_items', 5000);

        if ($rows === []) {
            throw new InvalidArgumentException('The file contains no domains or email addresses.');
        }

        if (count($rows) > $max) {
            throw new InvalidArgumentException("Bulk uploads are limited to {$max} domains.");
        }

        return DB::transaction(function () use ($user, $type, $rows, $source, $filename, $dkimSelector, $includeAll, $dispatchJobs) {
            $batch = CheckBatch::query()->create([
                'user_id' => $user->id,
                'type' => $type,
                'source' => $source,
                'total' => count($rows),
                'completed' => 0,
                'failed' => 0,
                'status' => 'queued',
                'original_filename' => $filename,
                'dkim_selector' => $dkimSelector,
                'include_all_records' => $includeAll,
            ]);

            foreach (array_chunk($rows, 250) as $chunk) {
                $insert = [];
                foreach ($chunk as $row) {
                    $insert[] = [
                        'check_batch_id' => $batch->id,
                        'input' => $row['input'],
                        'domain' => $row['domain'],
                        'status' => $row['domain'] ? 'queued' : 'failed',
                        'error' => $row['domain'] ? null : 'Invalid domain or email.',
                        'finished_at' => $row['domain'] ? null : now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                CheckItem::query()->insert($insert);
            }

            $batch->refreshCounters();

            // Single checks run inline. Bulk uploads skip this so the browser can drive one-domain-at-a-time ticks.
            if ($dispatchJobs) {
                CheckItem::query()
                    ->where('check_batch_id', $batch->id)
                    ->where('status', 'queued')
                    ->pluck('id')
                    ->each(fn ($id) => ProcessCheckItemJob::dispatch($id));
            }

            return $batch->fresh('items');
        });
    }

    public function createFromUpload(
        User $user,
        string $type,
        UploadedFile $file,
        ?string $dkimSelector = null,
        bool $includeAll = false,
    ): CheckBatch {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'txt'], true)) {
            throw new InvalidArgumentException('Upload a CSV or TXT file.');
        }

        $contents = $file->get();
        $lines = preg_split('/\r\n|\r|\n/', (string) $contents) ?: [];
        $inputs = [];

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $cells = str_getcsv($line);
            $candidate = trim((string) ($cells[0] ?? ''));

            // Skip a first-row header such as domain,email
            if ($index === 0 && preg_match('/^(domain|email|input|host)$/i', $candidate)) {
                continue;
            }

            if ($candidate !== '') {
                $inputs[] = $candidate;
            }
        }

        return $this->createFromInputs(
            user: $user,
            type: $type,
            inputs: $inputs,
            source: 'bulk',
            filename: $file->getClientOriginalName(),
            dkimSelector: $dkimSelector,
            includeAll: $includeAll,
            dispatchJobs: false,
        );
    }

    public function processItem(CheckItem $item): CheckItem
    {
        $lock = Cache::lock('mailin-item-'.$item->id, 120);

        if (! $lock->get()) {
            return $item->refresh();
        }

        try {
            $fresh = $item->fresh();
            if (! $fresh || ! in_array($fresh->status, ['queued', 'checking'], true)) {
                return $fresh ?: $item;
            }

            $fresh->forceFill([
                'status' => 'checking',
                'started_at' => $fresh->started_at ?: now(),
            ])->save();

            $batch = $fresh->batch;

            try {
                $payload = $this->checks->run(
                    $batch->type,
                    $fresh->input,
                    $batch->dkim_selector,
                    (bool) $batch->include_all_records,
                );

                $fresh->forceFill([
                    'domain' => $payload['domain'] ?? $fresh->domain,
                    'status' => 'completed',
                    'result_label' => $this->labelFromPayload($batch->type, $payload),
                    'payload' => $payload,
                    'error' => null,
                    'finished_at' => now(),
                ])->save();
            } catch (\Throwable $e) {
                $fresh->forceFill([
                    'status' => 'failed',
                    'result_label' => 'Failed',
                    'error' => $e->getMessage(),
                    'finished_at' => now(),
                ])->save();
            }

            $batch->refreshCounters();

            return $fresh->refresh();
        } finally {
            $lock->release();
        }
    }

    /**
     * Two-step advance so the table can paint "Checking" before DNS starts:
     * 1) queued → checking (fast)
     * 2) next tick runs DNS and sets completed/failed
     */
    public function advanceOne(CheckBatch $batch): CheckBatch
    {
        $inFlight = CheckItem::query()
            ->where('check_batch_id', $batch->id)
            ->where('status', 'checking')
            ->orderBy('id')
            ->first();

        if ($inFlight) {
            $this->processItem($inFlight);

            return $batch->fresh('items') ?? $batch;
        }

        $next = CheckItem::query()
            ->where('check_batch_id', $batch->id)
            ->where('status', 'queued')
            ->orderBy('id')
            ->first();

        if ($next) {
            $claimed = CheckItem::query()
                ->whereKey($next->id)
                ->where('status', 'queued')
                ->update([
                    'status' => 'checking',
                    'started_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($claimed === 1) {
                $batch->refreshCounters();
            }
        }

        return $batch->fresh('items') ?? $batch;
    }

    public function touchLastTick(CheckBatch $batch): void
    {
        $batch->forceFill(['last_tick_at' => now()])->save();
    }

    /**
     * Browser left the page: keep live ticks off and finish leftover rows on the queue.
     */
    public function handoffToQueue(CheckBatch $batch): CheckBatch
    {
        $batch = $batch->fresh() ?? $batch;

        if ($batch->queue_handoff_at || $batch->status === 'completed') {
            return $batch;
        }

        $open = $batch->items()
            ->whereIn('status', ['queued', 'checking'])
            ->exists();

        if (! $open) {
            $batch->refreshCounters();

            return $batch;
        }

        $batch->forceFill(['queue_handoff_at' => now()])->save();
        ProcessCheckBatchRemainderJob::dispatch($batch->id);
        app(QueueWorkerLauncher::class)->ensureRunning();

        return $batch->fresh('items') ?? $batch;
    }

    /**
     * @return array<int, CheckItem>
     */
    public function processNext(CheckBatch $batch, ?int $limit = null): array
    {
        $limit = 1;

        $ids = CheckItem::query()
            ->where('check_batch_id', $batch->id)
            ->where('status', 'queued')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $processed = [];

        foreach ($ids as $id) {
            $item = CheckItem::query()->find($id);
            if ($item) {
                $processed[] = $this->processItem($item);
            }
        }

        return $processed;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function labelFromPayload(string $type, array $payload): string
    {
        if ($type === 'blacklist') {
            $status = strtolower((string) ($payload['blacklist']['status'] ?? ''));

            return $status === 'listed' ? 'Blacklisted' : 'Clean';
        }

        return (string) ($payload['provider'] ?? 'Other');
    }
}
