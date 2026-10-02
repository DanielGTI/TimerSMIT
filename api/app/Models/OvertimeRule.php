<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regras de hora adicional da organização, versionadas: cada alteração cria
 * uma nova versão que vale para os lançamentos feitos daqui para a frente.
 */
class OvertimeRule extends Model
{
    protected $fillable = [
        'tenant_id',
        'version',
        'enabled',
        'workday_start',
        'workday_end',
        'factor_weekday',
        'factor_saturday',
        'factor_sunday',
        'factor_holiday',
        'night_start',
        'night_end',
        'night_percent',
        'night_reduced_hour',
        'require_time_of_day',
        'bank_validity_months',
        'bank_weighted',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'factor_weekday' => 'float',
            'factor_saturday' => 'float',
            'factor_sunday' => 'float',
            'factor_holiday' => 'float',
            'night_percent' => 'integer',
            'night_reduced_hour' => 'boolean',
            'require_time_of_day' => 'boolean',
            'bank_validity_months' => 'integer',
            'bank_weighted' => 'boolean',
            'effective_from' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
