<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Support\AdditionalHoursResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Avisos de limite de jornada (nunca bloqueiam o lançamento; servem para o
 * aprovador e o administrador agirem):
 *
 * - `daily_extra`: horas adicionais num dia útil acima do limite (CLT art. 59: 2h).
 * - `weekly_hours`: total da semana acima do limite (CF art. 7º, XIII: 44h).
 * - `rest`: descanso entre duas jornadas abaixo do mínimo (CLT art. 66: 11h).
 *   Jornada = tudo o que começou no mesmo dia; lançamentos emendados (ex.: o
 *   timer que atravessa a meia-noite vira dois) contam como um bloco só.
 *   Só entram lançamentos com horário.
 *
 * Só para CLT com o controle de horas adicionais ligado; usa a regra em vigor.
 */
class WorkLimitService
{
    /** Lançamentos com até este intervalo entre si são o mesmo bloco de trabalho. */
    private const CONTIGUOUS_SECONDS = 60;

    public function __construct(
        private readonly AdditionalHoursService $additional,
        private readonly OvertimeRuleService $rules,
    ) {}

    /**
     * Avisos com data entre `from` e `to`. O semanal vale para as semanas que
     * começam (segunda-feira) nesse intervalo.
     *
     * @return list<array<string, mixed>>
     */
    public function alerts(Tenant $tenant, Member $member, string $from, string $to): array
    {
        $rule = $this->rules->current($tenant);

        if ($rule === null || ! $rule->enabled || ($member->hours_regime ?? Member::REGIME_CLT) !== Member::REGIME_CLT) {
            return [];
        }

        $daily = (int) ($rule->alert_daily_extra_minutes ?? 120) * 60;
        $weekly = (int) ($rule->alert_weekly_minutes ?? 2640) * 60;
        $rest = (int) ($rule->alert_rest_minutes ?? 660) * 60;

        $weekStarts = $this->mondays($from, $to);
        $loadTo = $weekStarts === [] ? $to : max($to, CarbonImmutable::parse(end($weekStarts))->addDays(6)->toDateString());

        $entries = TimeEntry::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $member->id)
            // O dia anterior entra para medir o descanso até a primeira jornada do período.
            ->whereBetween('local_date', [CarbonImmutable::parse($from)->subDay()->toDateString(), $loadTo])
            ->orderBy('started_at_utc')
            ->orderBy('id')
            ->get();

        $alerts = [];

        if ($daily > 0) {
            $alerts = [...$alerts, ...$this->dailyExtra($tenant, $entries, $from, $to, $daily)];
        }

        if ($weekly > 0) {
            foreach ($weekStarts as $weekStart) {
                $weekEnd = CarbonImmutable::parse($weekStart)->addDays(6)->toDateString();
                $total = (int) $entries->filter(fn (TimeEntry $entry) => $this->dateOf($entry) >= $weekStart && $this->dateOf($entry) <= $weekEnd)
                    ->sum('duration_seconds');

                if ($total > $weekly) {
                    $alerts[] = ['type' => 'weekly_hours', 'date' => $weekStart, 'seconds' => $total, 'limitSeconds' => $weekly];
                }
            }
        }

        if ($rest > 0) {
            $alerts = [...$alerts, ...$this->shortRests($entries, $from, $to, $rest)];
        }

        usort($alerts, fn (array $a, array $b) => [$a['date'], $a['type']] <=> [$b['date'], $b['type']]);

        return $alerts;
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function dailyExtra(Tenant $tenant, Collection $entries, string $from, string $to, int $limit): array
    {
        $inRange = $entries->filter(fn (TimeEntry $entry) => $this->dateOf($entry) >= $from && $this->dateOf($entry) <= $to)->values();
        $evaluation = $this->additional->evaluate($tenant, $inRange);

        $perDay = [];
        foreach ($inRange as $entry) {
            $result = $evaluation[$entry->id]['result'];
            if ($result->dayType === AdditionalHoursResult::WEEKDAY && $result->additionalSeconds > 0) {
                $perDay[$this->dateOf($entry)] = ($perDay[$this->dateOf($entry)] ?? 0) + $result->additionalSeconds;
            }
        }

        $alerts = [];
        foreach ($perDay as $date => $seconds) {
            if ($seconds > $limit) {
                $alerts[] = ['type' => 'daily_extra', 'date' => $date, 'seconds' => $seconds, 'limitSeconds' => $limit];
            }
        }

        return $alerts;
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries  ordenados pelo início
     * @return list<array<string, mixed>>
     */
    private function shortRests(Collection $entries, string $from, string $to, int $limit): array
    {
        // Blocos contínuos de trabalho; cada um pertence ao dia em que começou.
        $blocks = [];
        foreach ($entries as $entry) {
            if ($entry->started_at_utc === null || $entry->ended_at_utc === null) {
                continue;
            }

            $start = $entry->started_at_utc->getTimestamp();
            $end = $entry->ended_at_utc->getTimestamp();
            $last = array_key_last($blocks);

            if ($last !== null && $start - $blocks[$last]['end'] <= self::CONTIGUOUS_SECONDS) {
                $blocks[$last]['end'] = max($blocks[$last]['end'], $end);

                continue;
            }

            $blocks[] = ['date' => $this->dateOf($entry), 'start' => $start, 'end' => $end, 'timezone' => $entry->timezone ?: 'UTC'];
        }

        // Jornada do dia: do primeiro início ao último fim dos blocos que começaram nele.
        $days = [];
        foreach ($blocks as $block) {
            $day = $days[$block['date']] ?? ['start' => $block['start'], 'end' => $block['end'], 'timezone' => $block['timezone']];
            $day['start'] = min($day['start'], $block['start']);
            $day['end'] = max($day['end'], $block['end']);
            $days[$block['date']] = $day;
        }
        ksort($days);

        $alerts = [];
        $previous = null;
        foreach ($days as $date => $day) {
            if ($previous !== null && $date >= $from && $date <= $to) {
                $gap = $day['start'] - $previous['end'];
                if ($gap < $limit) {
                    $alerts[] = [
                        'type' => 'rest',
                        'date' => $date,
                        'seconds' => max(0, $gap),
                        'limitSeconds' => $limit,
                        'previousEnd' => CarbonImmutable::createFromTimestamp($previous['end'], $previous['timezone'])->format('Y-m-d H:i'),
                        'nextStart' => CarbonImmutable::createFromTimestamp($day['start'], $day['timezone'])->format('Y-m-d H:i'),
                    ];
                }
            }
            $previous = $day;
        }

        return $alerts;
    }

    /**
     * @return list<string>
     */
    private function mondays(string $from, string $to): array
    {
        $mondays = [];
        $day = CarbonImmutable::parse($from);
        if (! $day->isMonday()) {
            $day = $day->next(CarbonImmutable::MONDAY);
        }

        for (; $day->toDateString() <= $to; $day = $day->addWeek()) {
            $mondays[] = $day->toDateString();
        }

        return $mondays;
    }

    private function dateOf(TimeEntry $entry): string
    {
        return substr((string) $entry->local_date, 0, 10);
    }
}
