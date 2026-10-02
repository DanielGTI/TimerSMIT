<?php

namespace App\Services;

use App\Models\AdditionalHourReview;
use App\Models\Holiday;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Support\AdditionalHoursResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Horas adicionais dos lançamentos: aplica a regra que valia quando o
 * lançamento foi feito, o regime da pessoa e o calendário de feriados, e
 * junta as decisões humanas (aprovador e administrador).
 *
 * Nada aqui é gravado: a hora adicional é sempre derivada do lançamento. Só
 * a classificação do administrador congela os valores (ver ClassificationService).
 */
class AdditionalHoursService
{
    public function __construct(
        private readonly AdditionalHoursCalculator $calculator,
        private readonly OvertimeRuleService $rules,
    ) {}

    /**
     * @param  Collection<int, TimeEntry>  $entries  da mesma organização
     * @return array<int, array{entry: TimeEntry, result: AdditionalHoursResult, regime: string, review: ?AdditionalHourReview, ruleId: ?int}> indexado pelo id do lançamento
     */
    public function evaluate(Tenant $tenant, Collection $entries): array
    {
        if ($entries->isEmpty()) {
            return [];
        }

        $versions = $this->rules->versions($tenant);

        $holidays = Holiday::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('date', $entries->map(fn (TimeEntry $entry) => substr((string) $entry->local_date, 0, 10))->unique()->values()->all())
            ->pluck('date')
            ->flip();

        $regimes = Member::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $entries->pluck('member_id')->unique()->values()->all())
            ->pluck('hours_regime', 'id');

        $reviews = AdditionalHourReview::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('time_entry_id', $entries->pluck('id')->all())
            ->get()
            ->keyBy('time_entry_id');

        $evaluation = [];
        foreach ($entries as $entry) {
            $rule = $versions->isEmpty() ? null : $this->rules->ruleAt($versions, $entry->created_at ?? Date::now());
            $regime = $regimes->get($entry->member_id) ?? Member::REGIME_CLT;

            $evaluation[$entry->id] = [
                'entry' => $entry,
                'result' => $this->calculator->calculate(
                    $entry,
                    $rule,
                    $holidays->has(substr((string) $entry->local_date, 0, 10)),
                    $regime,
                ),
                'regime' => $regime,
                'review' => $reviews->get($entry->id),
                'ruleId' => $rule?->id,
            ];
        }

        return $evaluation;
    }

    /**
     * Visão de um lançamento para a API; nulo se não há hora adicional.
     * Classificado: valem os números congelados na classificação.
     *
     * @param  array{result: AdditionalHoursResult, review: ?AdditionalHourReview}  $item
     * @return array<string, mixed>|null
     */
    public function present(array $item): ?array
    {
        $review = $item['review'];
        $result = $item['result'];
        $classified = $review?->classification !== null;

        $seconds = $classified ? (int) $review->additional_seconds : $result->additionalSeconds;
        if ($seconds <= 0) {
            return null;
        }

        return [
            'seconds' => $seconds,
            'weightedSeconds' => $classified ? (int) $review->weighted_seconds : $result->weightedSeconds,
            'nightSeconds' => $result->nightSeconds,
            'dayType' => $result->dayType,
            // Sem classificação do administrador, para quem lançou é sempre "a validar".
            'status' => $review?->classification ?? 'pending',
            'denied' => $review?->denial_reason !== null ? ['reason' => $review->denial_reason] : null,
        ];
    }
}
