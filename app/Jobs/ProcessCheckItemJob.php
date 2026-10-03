<?php

namespace App\Jobs;

use App\Models\CheckItem;
use App\Services\BulkCheckService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Optional queue worker path. Bulk UI processing uses HTTP ticks instead so WAMP does not need Supervisor.
 */
class ProcessCheckItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public int $checkItemId) {}

    public function handle(BulkCheckService $bulk): void
    {
        $item = CheckItem::query()->find($this->checkItemId);

        if (! $item || ! in_array($item->status, ['queued', 'checking'], true)) {
            return;
        }

        $bulk->processItem($item);
    }
}
