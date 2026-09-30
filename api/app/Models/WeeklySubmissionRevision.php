<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklySubmissionRevision extends Model
{
    protected $fillable = [
        'weekly_submission_id',
        'revision',
        'idempotency_key',
        'submitted_at',
        'total_seconds',
        'entry_count',
        'entries_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'entries_snapshot' => 'array',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(WeeklySubmission::class, 'weekly_submission_id');
    }
}
