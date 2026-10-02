<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Decisões humanas sobre as horas adicionais de um lançamento: "não autorizada"
 * (aprovador) e o destino das horas (administrador).
 */
class AdditionalHourReview extends Model
{
    public const OVERTIME = 'overtime';

    public const BANK = 'bank';

    public const PAYABLE = 'payable';

    public const CLASSIFICATIONS = [self::OVERTIME, self::BANK, self::PAYABLE];

    protected $fillable = [
        'tenant_id',
        'member_id',
        'time_entry_id',
        'denial_reason',
        'denied_by',
        'denied_at',
        'classification',
        'additional_seconds',
        'weighted_seconds',
        'overtime_rule_id',
        'classified_by',
        'classified_at',
        'classification_note',
        'bank_seconds',
        'bank_expires_on',
    ];

    protected function casts(): array
    {
        return [
            'denied_at' => 'datetime',
            'classified_at' => 'datetime',
            'additional_seconds' => 'integer',
            'weighted_seconds' => 'integer',
            'bank_seconds' => 'integer',
            'bank_expires_on' => 'date',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class, 'time_entry_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
