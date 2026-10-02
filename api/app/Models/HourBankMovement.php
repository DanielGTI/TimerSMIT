<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimento avulso do banco de horas, lançado pelo administrador: folga
 * (compensação), pagamento em folha ou ajuste. Os créditos normais vêm das
 * horas adicionais classificadas como banco (AdditionalHourReview).
 */
class HourBankMovement extends Model
{
    /** Folga, falta compensada ou emenda de feriado: tira do saldo. */
    public const KIND_TIME_OFF = 'time_off';

    /** Saldo pago como hora extra (ex.: rescisão): tira do saldo. */
    public const KIND_PAYOUT = 'payout';

    /** Correção manual, para mais ou para menos. */
    public const KIND_ADJUSTMENT = 'adjustment';

    public const KINDS = [self::KIND_TIME_OFF, self::KIND_PAYOUT, self::KIND_ADJUSTMENT];

    protected $fillable = [
        'tenant_id',
        'member_id',
        'kind',
        'seconds',
        'local_date',
        'expires_on',
        'note',
        'created_by',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'seconds' => 'integer',
            'local_date' => 'date:Y-m-d',
            'expires_on' => 'date:Y-m-d',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'created_by');
    }
}
