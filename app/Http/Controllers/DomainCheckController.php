<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunBulkCheckRequest;
use App\Http\Requests\RunSingleCheckRequest;
use App\Models\CheckBatch;
use App\Models\CheckItem;
use App\Services\BulkCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DomainCheckController extends Controller
{
    public function blacklist(Request $request): Response
    {
        return Inertia::render('Tools/BlacklistDns', [
            'batch' => $this->selectedBatch($request, 'blacklist'),
            'jobs' => $this->jobHistory($request, 'blacklist'),
        ]);
    }

    public function provider(Request $request): Response
    {
        return Inertia::render('Tools/ProviderDetect', [
            'batch' => $this->selectedBatch($request, 'provider'),
            'jobs' => $this->jobHistory($request, 'provider'),
        ]);
    }

    public function single(RunSingleCheckRequest $request, BulkCheckService $bulk): JsonResponse
    {
        $data = $request->validated();

        $this->hitRateLimit($request);

        $batch = $bulk->createFromInputs(
            user: $request->user(),
            type: $data['type'],
            inputs: [$data['input']],
            source: 'single',
            dkimSelector: $data['dkim_selector'] ?? null,
            includeAll: (bool) ($data['include_all_records'] ?? false),
            dispatchJobs: false,
        );

        $item = $batch->items()->first();
        if ($item && $item->status === 'queued') {
            $bulk->processItem($item);
        }

        $presented = $this->presentBatch($batch->id);
        $fresh = collect($presented['items'])->first();

        return response()->json([
            'ok' => true,
            'result' => $fresh['payload'] ?? null,
            'item' => $fresh,
            'batch' => $presented,
        ]);
    }

    public function bulk(RunBulkCheckRequest $request, BulkCheckService $bulk): JsonResponse
    {
        $data = $request->validated();

        $this->hitRateLimit($request, 10);

        try {
            $batch = $bulk->createFromUpload(
                $request->user(),
                $data['type'],
                $request->file('file'),
                $data['dkim_selector'] ?? null,
                (bool) ($data['include_all_records'] ?? false),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'batch' => $this->presentBatch($batch->id),
        ]);
    }

    public function show(Request $request, CheckBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        return response()->json([
            'ok' => true,
            'batch' => $this->presentBatch($batch->id),
        ]);
    }

    /**
     * Browser poll: finish or start exactly one domain, then return so the row can update.
     * After a tab close, the batch is handed to the queue and this only returns progress.
     */
    public function tick(Request $request, CheckBatch $batch, BulkCheckService $bulk): JsonResponse
    {
        $this->authorizeBatch($request, $batch);
        $batch->refresh();
        $bulk->touchLastTick($batch);

        if (! $batch->queue_handoff_at) {
            $bulk->advanceOne($batch);
        }

        return response()->json([
            'ok' => true,
            'batch' => $this->presentBatch($batch->id),
        ]);
    }

    /**
     * Tab/window closed: stop relying on live ticks and finish remaining domains on the queue.
     */
    public function handoff(Request $request, CheckBatch $batch, BulkCheckService $bulk): JsonResponse
    {
        $this->authorizeBatch($request, $batch);
        $bulk->handoffToQueue($batch);

        return response()->json([
            'ok' => true,
            'batch' => $this->presentBatch($batch->id),
        ]);
    }

    public function export(Request $request, CheckBatch $batch): StreamedResponse
    {
        $this->authorizeBatch($request, $batch);

        $filename = 'mailin-'.$batch->type.'-'.$batch->id.'.csv';

        return response()->streamDownload(function () use ($batch) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            if ($batch->type === 'blacklist') {
                fputcsv($handle, ['Domain', 'Input', 'Status', 'Blacklist', 'Listed On', 'MX', 'SPF', 'DMARC', 'Nameservers', 'Errors']);
            } else {
                fputcsv($handle, ['Domain', 'Input', 'Provider', 'MX records found', 'Detection evidence/reason', 'Status', 'Errors']);
            }

            CheckItem::query()
                ->where('check_batch_id', $batch->id)
                ->whereIn('status', ['completed', 'failed'])
                ->orderBy('id')
                ->chunk(200, function ($chunk) use ($handle, $batch) {
                    foreach ($chunk as $item) {
                        $payload = $item->payload ?? [];
                        if ($batch->type === 'blacklist') {
                            $lists = collect($payload['blacklist']['lists'] ?? [])->pluck('name')->implode('; ');
                            fputcsv($handle, [
                                $item->domain,
                                $item->input,
                                $item->status,
                                $payload['blacklist']['status'] ?? $item->result_label,
                                $lists,
                                collect($payload['mx']['records'] ?? [])->pluck('host')->implode('; '),
                                $payload['spf']['value'] ?? '',
                                $payload['dmarc']['value'] ?? '',
                                implode('; ', $payload['nameservers'] ?? []),
                                $item->error ?: implode('; ', $payload['errors'] ?? []),
                            ]);
                        } else {
                            $mx = collect($payload['mx'] ?? [])
                                ->map(function ($record) {
                                    $host = $record['host'] ?? '';
                                    $priority = $record['priority'] ?? null;

                                    return $priority === null || $priority === '' ? $host : "{$host} (priority {$priority})";
                                })
                                ->filter()
                                ->implode('; ');

                            fputcsv($handle, [
                                $payload['domain'] ?? $item->domain,
                                $item->input,
                                $payload['provider'] ?? $item->result_label,
                                $mx !== '' ? $mx : 'None found',
                                implode('; ', $payload['evidence'] ?? []),
                                $payload['status'] ?? '',
                                $item->error ?: implode('; ', $payload['errors'] ?? []),
                            ]);
                        }
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function authorizeBatch(Request $request, CheckBatch $batch): void
    {
        abort_unless($batch->user_id === $request->user()->id, 403);
    }

    private function latestBatch(Request $request, string $type): ?array
    {
        $batch = CheckBatch::query()
            ->where('user_id', $request->user()->id)
            ->where('type', $type)
            ->latest()
            ->first();

        return $batch ? $this->presentBatch($batch->id) : null;
    }

    /**
     * Open a specific past job via ?batch=id, otherwise the newest run for this tool.
     */
    private function selectedBatch(Request $request, string $type): ?array
    {
        if ($request->filled('batch')) {
            $batch = CheckBatch::query()
                ->where('user_id', $request->user()->id)
                ->where('type', $type)
                ->whereKey($request->integer('batch'))
                ->first();

            if ($batch) {
                return $this->presentBatch($batch->id);
            }
        }

        return $this->latestBatch($request, $type);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function jobHistory(Request $request, string $type): array
    {
        return CheckBatch::query()
            ->where('user_id', $request->user()->id)
            ->where('type', $type)
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (CheckBatch $batch) => $this->summarizeJob($batch))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeJob(CheckBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'source' => $batch->source,
            'status' => $batch->status,
            'total' => $batch->total,
            'completed' => $batch->completed + $batch->failed,
            'filename' => $batch->original_filename,
            'created_at' => optional($batch->created_at)?->toIso8601String(),
        ];
    }

    private function presentBatch(int $id): array
    {
        $batch = CheckBatch::query()->with(['items' => fn ($q) => $q->orderBy('id')])->findOrFail($id);

        return [
            'id' => $batch->id,
            'type' => $batch->type,
            'source' => $batch->source,
            'status' => $batch->status,
            'total' => $batch->total,
            // "checked" in the UI includes both successful lookups and invalid/failed rows.
            'completed' => $batch->completed + $batch->failed,
            'finished' => $batch->completed,
            'failed' => $batch->failed,
            'filename' => $batch->original_filename,
            'created_at' => optional($batch->created_at)?->toIso8601String(),
            'background' => $batch->queue_handoff_at !== null,
            'items' => $batch->items->map(fn (CheckItem $item) => [
                'id' => $item->id,
                'input' => $item->input,
                'domain' => $item->domain,
                'status' => $item->status,
                'label' => $item->result_label,
                'error' => $item->error,
                'payload' => $item->payload,
            ]),
        ];
    }

    private function hitRateLimit(Request $request, int $max = 0): void
    {
        $max = $max ?: (int) config('mailin.rate_limit_per_minute', 60);
        $key = 'mailin-check:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, $max)) {
            abort(429, 'Too many checks. Please wait a moment and try again.');
        }

        RateLimiter::hit($key, 60);
    }
}
