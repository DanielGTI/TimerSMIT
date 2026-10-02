<?php

namespace App\Services;

use App\Models\Member;
use App\Models\OvertimeRule;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Regras de hora adicional (expediente, fatores, adicional noturno), sempre
 * versionadas: uma alteração vale para os lançamentos feitos daqui para a
 * frente e nunca reclassifica o que já existe.
 *
 * Sem nenhuma versão cadastrada o controle fica desligado (nada vira hora
 * adicional e o De/Até não é exigido).
 */
class OvertimeRuleService
{
    /** Valores usados na primeira configuração (expediente 09–18, fatores 1,5 / 1,5 / 2 / 2). */
    public const DEFAULTS = [
        'enabled' => false,
        'workdayStart' => '09:00',
        'workdayEnd' => '18:00',
        'factorWeekday' => 1.5,
        'factorSaturday' => 1.5,
        'factorSunday' => 2.0,
        'factorHoliday' => 2.0,
        'nightStart' => '22:00',
        'nightEnd' => '05:00',
        'nightPercent' => 20,
        'nightReducedHour' => true,
        'requireTimeOfDay' => true,
        'bankValidityMonths' => 6,
        'bankWeighted' => true,
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Todas as versões da organização, da mais antiga para a mais nova.
     *
     * @return Collection<int, OvertimeRule>
     */
    public function versions(Tenant $tenant): Collection
    {
        return OvertimeRule::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('effective_from')
            ->orderBy('version')
            ->get();
    }

    /**
     * Versão em vigor num instante: a mais nova que já valia.
     *
     * @param  Collection<int, OvertimeRule>  $versions
     */
    public function ruleAt(Collection $versions, CarbonInterface $at): ?OvertimeRule
    {
        return $versions
            ->filter(fn (OvertimeRule $rule) => $rule->effective_from->lessThanOrEqualTo($at))
            ->sortByDesc('version')
            ->first();
    }

    public function current(Tenant $tenant): ?OvertimeRule
    {
        return $this->ruleAt($this->versions($tenant), Date::now());
    }

    /** Controle ligado e De/Até exigido para esta pessoa (quem não controla jornada nunca precisa). */
    public function requiresTimeOfDay(Tenant $tenant, Member $member): bool
    {
        $rule = $this->current($tenant);

        return $rule !== null
            && $rule->enabled
            && $rule->require_time_of_day
            && ($member->hours_regime ?? Member::REGIME_CLT) !== Member::REGIME_NONE;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(?OvertimeRule $rule): array
    {
        if ($rule === null) {
            return self::DEFAULTS + ['version' => 0, 'effectiveFrom' => null];
        }

        return [
            'enabled' => $rule->enabled,
            'workdayStart' => $rule->workday_start,
            'workdayEnd' => $rule->workday_end,
            'factorWeekday' => $rule->factor_weekday,
            'factorSaturday' => $rule->factor_saturday,
            'factorSunday' => $rule->factor_sunday,
            'factorHoliday' => $rule->factor_holiday,
            'nightStart' => $rule->night_start,
            'nightEnd' => $rule->night_end,
            'nightPercent' => $rule->night_percent,
            'nightReducedHour' => $rule->night_reduced_hour,
            'requireTimeOfDay' => $rule->require_time_of_day,
            'bankValidityMonths' => $rule->bank_validity_months ?? 6,
            'bankWeighted' => $rule->bank_weighted ?? true,
            'version' => $rule->version,
            'effectiveFrom' => $rule->effective_from->toIso8601String(),
        ];
    }

    /**
     * Nova versão, em vigor a partir de agora.
     *
     * @param  array<string, mixed>  $values  mesmas chaves de {@see present()}
     */
    public function update(Tenant $tenant, Member $actor, array $values): OvertimeRule
    {
        return DB::transaction(function () use ($tenant, $actor, $values) {
            // Serializa edições simultâneas para a numeração de versões não colidir.
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();

            $before = $this->present($this->current($tenant));
            $version = (int) OvertimeRule::query()->where('tenant_id', $tenant->id)->max('version') + 1;

            $rule = OvertimeRule::query()->create([
                'tenant_id' => $tenant->id,
                'version' => $version,
                'enabled' => $values['enabled'],
                'workday_start' => $values['workdayStart'],
                'workday_end' => $values['workdayEnd'],
                'factor_weekday' => $values['factorWeekday'],
                'factor_saturday' => $values['factorSaturday'],
                'factor_sunday' => $values['factorSunday'],
                'factor_holiday' => $values['factorHoliday'],
                'night_start' => $values['nightStart'],
                'night_end' => $values['nightEnd'],
                'night_percent' => $values['nightPercent'],
                'night_reduced_hour' => $values['nightReducedHour'],
                'require_time_of_day' => $values['requireTimeOfDay'],
                // Campos do banco de horas: quem não os envia mantém os da versão atual.
                'bank_validity_months' => $values['bankValidityMonths'] ?? $before['bankValidityMonths'],
                'bank_weighted' => $values['bankWeighted'] ?? $before['bankWeighted'],
                'effective_from' => Date::now(),
            ]);

            $this->audit->record($tenant, 'settings.overtime_rules_updated', OvertimeRule::class, $rule->id, $actor, null, [
                'version' => $version,
                'before' => $before,
                'after' => $this->present($rule),
            ]);

            return $rule;
        });
    }
}
