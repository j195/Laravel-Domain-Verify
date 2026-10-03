<?php

namespace App\Console\Commands;

use App\Models\CheckBatch;
use App\Services\BulkCheckService;
use Illuminate\Console\Command;

class HandoffStaleBatchesCommand extends Command
{
    protected $signature = 'mailin:handoff-stale';

    protected $description = 'Hand unfinished checks to the queue when the browser stopped ticking.';

    public function handle(BulkCheckService $bulk): int
    {
        $stale = CheckBatch::query()
            ->where('status', 'running')
            ->whereNull('queue_handoff_at')
            ->whereNotNull('last_tick_at')
            ->where('last_tick_at', '<', now()->subSeconds(45))
            ->get();

        foreach ($stale as $batch) {
            $bulk->handoffToQueue($batch);
        }

        return self::SUCCESS;
    }
}
