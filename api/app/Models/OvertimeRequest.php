<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hora extra informada (pedido ao aprovador, antes ou depois de fazer) ou
 * hora extra a confirmar (o trecho fora do expediente de quem tem perfil
 * restrito: não é lançamento até o aprovador confirmar).
 */
class OvertimeRequest extends Model
{
    public const KIND_REQUEST = 'request';

    public const KIND_CONFIRMATION = 'confirmation';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const DESTINATIONS = [AdditionalHourReview::OVERTIME, AdditionalHourReview::BANK];

    protected $fillable = [
        'tenant_id',
        'member_id',
        'kind',
        'date_from',
        'date_to',
        'seconds_per_day',
        'start_time',
        'end_time',
        'reason',
        'suggested_destination',
        'after_the_fact',
        'status',
        'approved_seconds_per_day',
        'decided_by',
        'decided_at',
        'decision_note',
        'project_id',
        'devops_work_item_id',
        'activity_type_id',
        'note',
        'billable',
        'timezone',
        'source',
        'time_entry_id',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'after_the_fact' => 'boolean',
            'billable' => 'boolean',
            'decided_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'seconds_per_day' => 'integer',
            'approved_seconds_per_day' => 'integer',
        ];
    }

    public function dateFrom(): string
    {
        return substr((string) $this->date_from, 0, 10);
    }

    public function dateTo(): string
    {
        return substr((string) $this->date_to, 0, 10);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'decided_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function activityType(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class);
    }
}
