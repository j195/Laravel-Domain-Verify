<?php

namespace App\Jobs;

use App\Models\CheckBatch;
use App\Models\CheckItem;
use App\Services\BulkCheckService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Finishes leftover domains after the browser tab closes. One domain per job so DNS timeouts stay isolated.
 */
class ProcessCheckBatchRemainderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public int $batchId) {}

    public function handle(BulkCheckService $bulk): void
    {
        $batch = CheckBatch::query()->find($this->batchId);

        if (! $batch || $batch->status === 'completed') {
            return;
        }

        $item = CheckItem::query()
            ->where('check_batch_id', $batch->id)
            ->where('status', 'checking')
            ->orderBy('id')
            ->first()
            ?? CheckItem::query()
                ->where('check_batch_id', $batch->id)
                ->where('status', 'queued')
                ->orderBy('id')
                ->first();

        if (! $item) {
            $batch->refreshCounters();

            return;
        }

        $bulk->processItem($item);

        $stillOpen = CheckItem::query()
            ->where('check_batch_id', $batch->id)
            ->whereIn('status', ['queued', 'checking'])
            ->exists();

        if ($stillOpen) {
            self::dispatch($this->batchId);
        }
    }
}
