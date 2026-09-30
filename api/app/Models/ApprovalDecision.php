<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalDecision extends Model
{
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const REOPENED = 'reopened';

    protected $fillable = [
        'tenant_id',
        'weekly_submission_id',
        'revision',
        'approver_id',
        'decision',
        'reason',
        'self_decision',
        'idempotency_key',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'self_decision' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(WeeklySubmission::class, 'weekly_submission_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'approver_id');
    }
}
