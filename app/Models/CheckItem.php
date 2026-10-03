<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One domain/email inside a batch. payload holds the DNS/blacklist/provider result JSON.
 * status: queued → checking → completed | failed
 */
#[Fillable([
    'check_batch_id',
    'input',
    'domain',
    'status',
    'result_label',
    'payload',
    'error',
    'started_at',
    'finished_at',
])]
class CheckItem extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CheckBatch::class, 'check_batch_id');
    }
}
