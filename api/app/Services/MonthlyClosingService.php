<?php

namespace App\Services;

use App\Models\AdditionalHourReview;
use App\Models\HourBankMovement;
use App\Models\Member;
use App\Models\OvertimeRequest;
use App\Models\OvertimeRule;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\WeeklySubmission;
use App\Support\AdditionalHoursResult;
use Carbon\CarbonImmutable;

/**
 * Fechamento do mês para o DP: por pessoa, as horas adicionais pelo dia
 * trabalhado (hora extra e a pagar por fator; banco creditado), o que ainda
 * falta decidir (semana sem aprovação, hora não classificada), o movimento
 * do banco de horas e os avisos de limite de jornada.
 *
 * A ferramenta entrega HORAS; o cálculo em reais é do DP.
 */
class MonthlyClosingService
{
    public const CATEGORIES = [
        AdditionalHourReview::OVERTIME,
        AdditionalHourReview::PAYABLE,
        AdditionalHourReview::BANK,
        'pending',
        'unapproved',
    ];

    public function __construct(
        private readonly AdditionalHoursService $additional,
        private readonly HourBankService $bank,
        private readonly WorkLimitService $limits,
        private readonly OvertimeCoverageService $coverage,
    ) {}

    /**
     * @param  string  $month  YYYY-MM
     * @return array{month: string, from: string, to: string, members: list<array<string, mixed>>}
     */
    public function month(Tenant $tenant, string $month): array
    {
        $from = CarbonImmutable::parse("{$month}-01");
        $start = $from->toDateString();
        $end = $from->endOfMonth()->toDateString();

        $entries = TimeEntry::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('local_date', [$start, $end])
            ->orderBy('local_date')
            ->orderBy('id')
            ->get();

        $evaluation = $this->additional->evaluate($tenant, $entries);
        $coverage = $this->coverage->coverage($tenant, $entries, $evaluation);

        // Hora extra a confirmar (perfil restrito) recusada no mês: informativo.
        $refused = OvertimeRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', OvertimeRequest::KIND_CONFIRMATION)
            ->where('status', OvertimeRequest::STATUS_REJECTED)
            ->whereBetween('date_from', [$start, $end])
            ->get(['member_id', 'seconds_per_day'])
            ->groupBy('member_id')
            ->map(fn ($rows) => (int) $rows->sum('seconds_per_day'));
        $withoutRequest = [];

        $approved = WeeklySubmission::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', WeeklySubmission::STATUS_APPROVED)
            ->whereIn('member_id', $entries->pluck('member_id')->unique()->values()->all())
            ->get()
            ->mapWithKeys(fn (WeeklySubmission $submission) => [$submission->member_id.':'.substr((string) $submission->week_start_date, 0, 10) => true]);

        $rules = OvertimeRule::query()->where('tenant_id', $tenant->id)->get()->keyBy('id');

        /** @var array<int, array<string, array<string, mixed>>> $lines */
        $lines = [];
        $denied = [];

        foreach ($entries as $entry) {
            $item = $evaluation[$entry->id];
            $view = $this->additional->present($item);
            if ($view === null) {
                continue;
            }

            $review = $item['review'];
            $week = $entry->member_id.':'.substr((string) $entry->week_start_date, 0, 10);
            $category = match (true) {
                ! $approved->has($week) => 'unapproved',
                $review?->classification !== null => $review->classification,
                default => 'pending',
            };

            $rule = $review?->overtime_rule_id !== null ? $rules->get($review->overtime_rule_id) : $item['rule'];
            $factor = in_array($category, [AdditionalHourReview::OVERTIME, AdditionalHourReview::PAYABLE, AdditionalHourReview::BANK], true)
                ? $this->factor($rule, $item['result']->dayType)
                : null;

            $key = $category.':'.($factor ?? '-');
            $line = $lines[$entry->member_id][$key] ?? ['category' => $category, 'factor' => $factor, 'seconds' => 0, 'nightSeconds' => 0, 'weightedSeconds' => 0];
            $line['seconds'] += $view['seconds'];
            $line['nightSeconds'] += $view['nightSeconds'];
            $line['weightedSeconds'] += $view['weightedSeconds'];
            $lines[$entry->member_id][$key] = $line;

            if ($view['denied'] !== null) {
                $denied[$entry->member_id] = ($denied[$entry->member_id] ?? 0) + 1;
            }

            $withoutRequest[$entry->member_id] = ($withoutRequest[$entry->member_id] ?? 0) + ($coverage[$entry->id]['uncoveredSeconds'] ?? 0);
        }

        // Quem tem banco de horas aparece mesmo sem hora adicional no mês (folga, vencimento).
        $bankMembers = AdditionalHourReview::query()
            ->where('tenant_id', $tenant->id)
            ->where('classification', AdditionalHourReview::BANK)
            ->pluck('member_id')
            ->merge(HourBankMovement::query()->where('tenant_id', $tenant->id)->pluck('member_id'))
            ->unique();

        $members = Member::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $entries->pluck('member_id')->merge($bankMembers)->merge($refused->keys())->unique()->values()->all())
            ->orderBy('display_name')
            ->get();

        $rows = [];
        foreach ($members as $member) {
            $memberLines = array_values($lines[$member->id] ?? []);
            usort($memberLines, fn (array $a, array $b) => [array_search($a['category'], self::CATEGORIES, true), $a['factor'] ?? 0]
                <=> [array_search($b['category'], self::CATEGORIES, true), $b['factor'] ?? 0]);

            $bank = $bankMembers->contains($member->id) ? $this->bank->month($tenant, $member->id, $start, $end) : null;
            if ($bank !== null && $this->isEmptyBank($bank)) {
                $bank = null;
            }

            $alerts = ['daily_extra' => 0, 'weekly_hours' => 0, 'rest' => 0];
            foreach ($this->limits->alerts($tenant, $member, $start, $end) as $alert) {
                $alerts[$alert['type']]++;
            }

            $overtime = [
                'withoutRequestSeconds' => $withoutRequest[$member->id] ?? 0,
                'refusedSeconds' => $refused->get($member->id, 0),
            ];

            if ($memberLines === [] && $bank === null && array_sum($alerts) === 0 && $overtime['refusedSeconds'] === 0) {
                continue;
            }

            $rows[] = [
                'memberId' => (string) $member->id,
                'memberName' => $member->display_name,
                'regime' => $member->hours_regime ?? Member::REGIME_CLT,
                'totals' => $this->totals($memberLines),
                'lines' => $memberLines,
                'bank' => $bank,
                'alerts' => $alerts,
                'deniedCount' => $denied[$member->id] ?? 0,
                // Fase 4: hora adicional sem pedido aprovado e hora a confirmar recusada.
                'overtime' => $overtime,
            ];
        }

        return ['month' => $month, 'from' => $start, 'to' => $end, 'members' => $rows];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, array{seconds: int, nightSeconds: int, weightedSeconds: int}>
     */
    private function totals(array $lines): array
    {
        $totals = [];
        foreach (self::CATEGORIES as $category) {
            $totals[$category] = ['seconds' => 0, 'nightSeconds' => 0, 'weightedSeconds' => 0];
        }

        foreach ($lines as $line) {
            foreach (['seconds', 'nightSeconds', 'weightedSeconds'] as $field) {
                $totals[$line['category']][$field] += $line[$field];
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, int>  $bank
     */
    private function isEmptyBank(array $bank): bool
    {
        return count(array_filter($bank, fn (int $value) => $value !== 0)) === 0;
    }

    private function factor(?OvertimeRule $rule, string $dayType): ?float
    {
        if ($rule === null) {
            return null;
        }

        return (float) match ($dayType) {
            AdditionalHoursResult::SATURDAY => $rule->factor_saturday,
            AdditionalHoursResult::SUNDAY => $rule->factor_sunday,
            AdditionalHoursResult::HOLIDAY => $rule->factor_holiday,
            default => $rule->factor_weekday,
        };
    }
}
