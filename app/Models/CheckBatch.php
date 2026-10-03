<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single or bulk run. type is blacklist|provider; source is single|bulk.
 */
#[Fillable([
    'user_id',
    'type',
    'source',
    'total',
    'completed',
    'failed',
    'status',
    'original_filename',
    'dkim_selector',
    'include_all_records',
    'last_tick_at',
    'queue_handoff_at',
])]
class CheckBatch extends Model
{
    protected function casts(): array
    {
        return [
            'include_all_records' => 'boolean',
            'last_tick_at' => 'datetime',
            'queue_handoff_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CheckItem::class);
    }

    /**
     * Recalculate completed/failed counts. Status is completed only when nothing remains queued or checking.
     */
    public function refreshCounters(): void
    {
        $completed = $this->items()->where('status', 'completed')->count();
        $failed = $this->items()->where('status', 'failed')->count();
        $checking = $this->items()->whereIn('status', ['queued', 'checking'])->count();

        $this->forceFill([
            'completed' => $completed,
            'failed' => $failed,
            'status' => $checking === 0 ? 'completed' : 'running',
        ])->save();
    }
}
