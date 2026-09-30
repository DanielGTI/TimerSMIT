<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * week_start_date fica como texto 'Y-m-d' de propósito (sem cast `date`): o
 * cast grava datetime completo e quebra comparações exatas no SQLite.
 */
class WeeklySubmission extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPROVED = 'approved';

    /** Estados em que lançamentos da semana não podem ser criados nem alterados. */
    public const LOCKED_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_APPROVED];

    protected $fillable = [
        'tenant_id',
        'member_id',
        'week_start_date',
        'status',
        'revision',
        'submitted_at',
        'approved_at',
        'approver_id',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'approver_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(WeeklySubmissionRevision::class);
    }
}
