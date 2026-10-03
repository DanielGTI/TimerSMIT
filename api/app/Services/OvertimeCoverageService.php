<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\Member;
use App\Models\OvertimeRequest;
use App\Models\Tenant;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Hora extra informada x hora extra lançada (Fase 4).
 *
 *  - `plan`: para um lançamento novo (ou editado), quanto é hora adicional,
 *    quanto o pedido aprovado do dia ainda cobre e, no perfil restrito, o que
 *    entra como lançamento e o que vira "hora extra a confirmar".
 *  - `coverage`: para lançamentos já gravados, se a hora adicional estava
 *    coberta (pedido aprovado, perfil pré-aprovado, hora confirmada) ou não.
 *
 * O pedido aprovado vale por dia (até X horas por dia no período) e é gasto
 * na ordem dos lançamentos do dia. O horário previsto no pedido é só
 * informativo. Só vale para CLT com o controle de horas adicionais ligado.
 */
class OvertimeCoverageService
{
    public function __construct(
        private readonly AdditionalHoursCalculator $calculator,
        private readonly AdditionalHoursService $additional,
        private readonly OvertimeRuleService $rules,
    ) {}

    public function applies(Tenant $tenant, Member $member): bool
    {
        $rule = $this->rules->current($tenant);

        return $rule !== null && $rule->enabled && ($member->hours_regime ?? Member::REGIME_CLT) === Member::REGIME_CLT;
    }

    /**
     * @return array{applies: bool, profile: string, additionalSeconds: int, coveredSeconds: int, uncoveredSeconds: int, normal: list<array{start: ?int, end: ?int, seconds: int}>, pending: list<array{start: ?int, end: ?int, seconds: int}>}
     */
    public function plan(Tenant $tenant, Member $member, string $localDate, ?string $startTime, int $durationSeconds, string $timezone, ?int $excludeEntryId = null): array
    {
        $start = $startTime === null ? null : $this->secondsOf($startTime);
        $whole = ['start' => $start, 'end' => $start === null ? null : $start + $durationSeconds, 'seconds' => $durationSeconds];
        $profile = $member->overtimeProfile();

        $plan = [
            'applies' => false,
            'profile' => $profile,
            'additionalSeconds' => 0,
            'coveredSeconds' => 0,
            'uncoveredSeconds' => 0,
            'normal' => [$whole],
            'pending' => [],
        ];

        if ($durationSeconds <= 0 || ! $this->applies($tenant, $member)) {
            return $plan;
        }

        $plan['applies'] = true;
        $parts = $this->calculator->additionalParts(
            $this->draft($member, $localDate, $startTime, $durationSeconds, $timezone),
            $this->rules->current($tenant),
            $this->isHoliday($tenant, $localDate),
            Member::REGIME_CLT,
        );

        if ($parts['seconds'] <= 0) {
            return $plan;
        }

        // Pré-aprovada: tudo coberto. Os demais gastam o que sobrou do pedido aprovado do dia.
        $remaining = $profile === Member::PROFILE_PREAPPROVED
            ? $parts['seconds']
            : max(0, $this->budget($tenant, $member->id, $localDate) - $this->used($tenant, $member, $localDate, $excludeEntryId));

        $uncovered = [];
        $covered = 0;
        foreach ($parts['parts'] ?? [[null, null]] as [$from, $to]) {
            $length = $from === null ? $parts['seconds'] : $to - $from;
            $take = min($remaining, $length);
            $remaining -= $take;
            $covered += $take;

            if ($take < $length) {
                $uncovered[] = $from === null
                    ? ['start' => null, 'end' => null, 'seconds' => $length - $take]
                    : ['start' => $from + $take, 'end' => $to, 'seconds' => $length - $take];
            }
        }

        $plan['additionalSeconds'] = $parts['seconds'];
        $plan['coveredSeconds'] = $covered;
        $plan['uncoveredSeconds'] = $parts['seconds'] - $covered;

        if ($profile === Member::PROFILE_RESTRICTED && $uncovered !== []) {
            $plan['pending'] = $uncovered;
            $plan['normal'] = $this->subtract($whole, $uncovered);
        }

        return $plan;
    }

    /**
     * Situação da hora adicional de cada lançamento (nulo: sem hora adicional,
     * ou regime que não usa pedido).
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @param  array<int, array<string, mixed>>  $evaluation  de AdditionalHoursService::evaluate
     * @return array<int, array{kind: string, coveredSeconds: int, uncoveredSeconds: int, requestId: ?string, afterTheFact: ?bool, suggestedDestination: ?string, requestPending: bool}|null>
     */
    public function coverage(Tenant $tenant, Collection $entries, array $evaluation): array
    {
        $seconds = [];
        foreach ($entries as $entry) {
            $view = isset($evaluation[$entry->id]) ? $this->additional->present($evaluation[$entry->id]) : null;
            if ($view !== null && ($evaluation[$entry->id]['regime'] ?? null) === Member::REGIME_CLT) {
                $seconds[$entry->id] = (int) $view['seconds'];
            }
        }

        if ($seconds === []) {
            return [];
        }

        $relevant = $entries->filter(fn (TimeEntry $entry) => isset($seconds[$entry->id]));
        $members = Member::query()->where('tenant_id', $tenant->id)->whereIn('id', $relevant->pluck('member_id')->unique()->values()->all())->get()->keyBy('id');
        $dates = $relevant->map(fn (TimeEntry $entry) => substr((string) $entry->local_date, 0, 10));

        $confirmed = OvertimeRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', OvertimeRequest::KIND_CONFIRMATION)
            ->whereIn('time_entry_id', $relevant->pluck('id')->all())
            ->pluck('time_entry_id')
            ->flip();

        $requests = OvertimeRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', OvertimeRequest::KIND_REQUEST)
            ->whereIn('status', [OvertimeRequest::STATUS_APPROVED, OvertimeRequest::STATUS_PENDING])
            ->whereIn('member_id', $members->keys()->all())
            ->where('date_from', '<=', $dates->max())
            ->where('date_to', '>=', $dates->min())
            ->orderBy('id')
            ->get();

        $budgets = [];
        $result = [];
        $ordered = $relevant->sortBy(fn (TimeEntry $entry) => sprintf('%s|%s|%020d', substr((string) $entry->local_date, 0, 10), $entry->started_at_utc?->getTimestamp() ?? 0, $entry->id));

        foreach ($ordered as $entry) {
            $member = $members->get($entry->member_id);
            $date = substr((string) $entry->local_date, 0, 10);
            $total = $seconds[$entry->id];
            $dayRequests = $requests->filter(fn (OvertimeRequest $request) => $request->member_id === $entry->member_id
                && $request->dateFrom() <= $date && $request->dateTo() >= $date);
            $approved = $dayRequests->where('status', OvertimeRequest::STATUS_APPROVED);
            $reference = $approved->first();

            $view = [
                'kind' => 'none',
                'coveredSeconds' => 0,
                'uncoveredSeconds' => $total,
                'requestId' => $reference ? (string) $reference->id : null,
                'afterTheFact' => $reference?->after_the_fact,
                'suggestedDestination' => $reference?->suggested_destination,
                'requestPending' => $dayRequests->contains('status', OvertimeRequest::STATUS_PENDING),
            ];

            if ($member?->overtimeProfile() === Member::PROFILE_PREAPPROVED || $confirmed->has($entry->id)) {
                $view['kind'] = $confirmed->has($entry->id) ? 'confirmed' : 'preapproved';
                $view['coveredSeconds'] = $total;
                $view['uncoveredSeconds'] = 0;
            } else {
                $key = $entry->member_id.':'.$date;
                $budgets[$key] ??= (int) $approved->sum('approved_seconds_per_day');
                $take = min($budgets[$key], $total);
                $budgets[$key] -= $take;
                $view['coveredSeconds'] = $take;
                $view['uncoveredSeconds'] = $total - $take;
                $view['kind'] = match (true) {
                    $take === $total => 'request',
                    $take > 0 => 'partial',
                    default => 'none',
                };
            }

            $result[$entry->id] = $view;
        }

        return $result;
    }

    /** Soma, por dia, do que os pedidos aprovados liberam. */
    private function budget(Tenant $tenant, int $memberId, string $date): int
    {
        return (int) OvertimeRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $memberId)
            ->where('kind', OvertimeRequest::KIND_REQUEST)
            ->where('status', OvertimeRequest::STATUS_APPROVED)
            ->where('date_from', '<=', $date)
            ->where('date_to', '>=', $date)
            ->sum('approved_seconds_per_day');
    }

    /** Hora adicional que os lançamentos do dia já gastaram do pedido (a hora confirmada não gasta). */
    private function used(Tenant $tenant, Member $member, string $date, ?int $excludeEntryId): int
    {
        $confirmed = OvertimeRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $member->id)
            ->where('kind', OvertimeRequest::KIND_CONFIRMATION)
            ->whereNotNull('time_entry_id')
            ->pluck('time_entry_id')
            ->all();

        $entries = TimeEntry::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $member->id)
            ->where('local_date', $date)
            ->when($excludeEntryId !== null, fn ($query) => $query->where('id', '!=', $excludeEntryId))
            ->whereNotIn('id', $confirmed ?: [0])
            ->get();

        return (int) collect($this->additional->evaluate($tenant, $entries))
            ->sum(fn (array $item) => $item['result']->additionalSeconds);
    }

    /**
     * Lançamento menos os trechos a confirmar: o que entra como lançamento.
     *
     * @param  array{start: ?int, end: ?int, seconds: int}  $whole
     * @param  list<array{start: ?int, end: ?int, seconds: int}>  $pending
     * @return list<array{start: ?int, end: ?int, seconds: int}>
     */
    private function subtract(array $whole, array $pending): array
    {
        if ($whole['start'] === null) {
            $left = $whole['seconds'] - array_sum(array_column($pending, 'seconds'));

            return $left > 0 ? [['start' => null, 'end' => null, 'seconds' => $left]] : [];
        }

        $pieces = [];
        $cursor = $whole['start'];
        foreach ($pending as $part) {
            if ($part['start'] > $cursor) {
                $pieces[] = ['start' => $cursor, 'end' => $part['start'], 'seconds' => $part['start'] - $cursor];
            }
            $cursor = max($cursor, $part['end']);
        }
        if ($whole['end'] > $cursor) {
            $pieces[] = ['start' => $cursor, 'end' => $whole['end'], 'seconds' => $whole['end'] - $cursor];
        }

        return $pieces;
    }

    private function draft(Member $member, string $localDate, ?string $startTime, int $durationSeconds, string $timezone): TimeEntry
    {
        $entry = new TimeEntry([
            'member_id' => $member->id,
            'local_date' => $localDate,
            'timezone' => $timezone,
            'duration_seconds' => $durationSeconds,
        ]);

        if ($startTime !== null) {
            $start = CarbonImmutable::parse($localDate.' '.$startTime, $timezone);
            $entry->started_at_utc = $start->utc();
            $entry->ended_at_utc = $start->addSeconds($durationSeconds)->utc();
        }

        return $entry;
    }

    private function isHoliday(Tenant $tenant, string $date): bool
    {
        return Holiday::query()->where('tenant_id', $tenant->id)->where('date', $date)->exists();
    }

    /** "HH:MM" ou "HH:MM:SS" em segundos desde 00:00. */
    private function secondsOf(string $time): int
    {
        $parts = array_map('intval', explode(':', $time));

        return $parts[0] * 3600 + ($parts[1] ?? 0) * 60 + ($parts[2] ?? 0);
    }
}
