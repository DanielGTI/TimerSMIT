<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Models\AdditionalHourReview;
use App\Models\HourBankMovement;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\WorkItemSnapshot;
use App\Support\HourBankLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Banco de horas por pessoa. Créditos: horas adicionais classificadas como
 * banco (valores congelados na classificação). Débitos e ajustes: movimentos
 * lançados pelo administrador. Saldo, consumo e vencimento são calculados a
 * cada leitura (HourBankLedger).
 *
 * Regra que nenhuma operação pode quebrar: o saldo nunca fica sem cobertura —
 * uma folga não pode usar horas que não existem (ou que já venceram), e um
 * crédito já usado não pode sumir (reclassificação, reabertura da semana).
 */
class HourBankService
{
    /** Folga agendada pode ser lançada até este número de dias à frente. */
    public const MAX_DAYS_AHEAD = 90;

    public function __construct(
        private readonly OvertimeRuleService $rules,
        private readonly AuditService $audit,
    ) {}

    /**
     * Extrato completo de uma pessoa.
     *
     * @return array<string, mixed>
     */
    public function statement(Tenant $tenant, Member $member): array
    {
        $ledger = $this->ledger($tenant, $member->id);

        return [
            'member' => [
                'id' => (string) $member->id,
                'name' => $member->display_name,
                'regime' => $member->hours_regime ?? Member::REGIME_CLT,
            ],
            'validityMonths' => $this->validityMonths($tenant),
            'summary' => $this->summary($ledger),
            'events' => array_map(fn (array $event) => $this->presentEvent($event), $ledger['events']),
        ];
    }

    /**
     * Saldo de cada pessoa CLT e de quem já tem movimento no banco.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(Tenant $tenant): array
    {
        $withBank = AdditionalHourReview::query()
            ->where('tenant_id', $tenant->id)
            ->where('classification', AdditionalHourReview::BANK)
            ->pluck('member_id')
            ->merge(HourBankMovement::query()->where('tenant_id', $tenant->id)->pluck('member_id'))
            ->unique()
            ->all();

        $members = Member::query()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($query) => $query
                ->where(fn ($clt) => $clt->where('hours_regime', Member::REGIME_CLT)->where('directory_active', true))
                ->orWhereIn('id', $withBank))
            ->orderBy('display_name')
            ->get();

        return $members->map(fn (Member $member) => [
            'memberId' => (string) $member->id,
            'memberName' => $member->display_name,
            'regime' => $member->hours_regime ?? Member::REGIME_CLT,
            'summary' => $this->summary($this->ledger($tenant, $member->id)),
        ])->values()->all();
    }

    /**
     * Folga, pagamento ou ajuste. `seconds` é sempre positivo; `direction`
     * só vale para ajuste (para mais ou para menos).
     *
     * @return array<string, mixed> extrato atualizado
     */
    public function addMovement(
        Tenant $tenant,
        Member $actor,
        int $memberId,
        string $kind,
        string $direction,
        int $seconds,
        string $localDate,
        string $note,
        string $idempotencyKey,
    ): array {
        $note = trim($note);

        return DB::transaction(function () use ($tenant, $actor, $memberId, $kind, $direction, $seconds, $localDate, $note, $idempotencyKey) {
            $member = $this->lockMember($tenant, $memberId);

            $previous = HourBankMovement::query()
                ->where('tenant_id', $tenant->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($previous !== null) {
                return $this->statement($tenant, $member);
            }

            if ($note === '') {
                throw ValidationException::withMessages(['note' => 'Informe o motivo.']);
            }

            $credit = $kind === HourBankMovement::KIND_ADJUSTMENT && $direction === 'credit';
            $today = $this->today($tenant);

            if ($credit && ($member->hours_regime ?? Member::REGIME_CLT) !== Member::REGIME_CLT) {
                throw ValidationException::withMessages(['kind' => 'Banco de horas só vale para quem é CLT.']);
            }

            $latest = $kind === HourBankMovement::KIND_TIME_OFF
                ? CarbonImmutable::parse($today)->addDays(self::MAX_DAYS_AHEAD)->toDateString()
                : $today;
            if ($localDate > $latest) {
                throw ValidationException::withMessages(['localDate' => $kind === HourBankMovement::KIND_TIME_OFF
                    ? 'A folga pode ser agendada até '.self::MAX_DAYS_AHEAD.' dias à frente.'
                    : 'Use a data de hoje ou uma data passada.']);
            }

            $this->guard($tenant, [$member->id], function () use ($tenant, $actor, $member, $kind, $credit, $seconds, $localDate, $note, $idempotencyKey) {
                $movement = HourBankMovement::query()->create([
                    'tenant_id' => $tenant->id,
                    'member_id' => $member->id,
                    'kind' => $kind,
                    'seconds' => $credit ? $seconds : -$seconds,
                    'local_date' => $localDate,
                    'expires_on' => $credit
                        ? CarbonImmutable::parse($localDate)->addMonthsNoOverflow($this->validityMonths($tenant))->toDateString()
                        : null,
                    'note' => $note,
                    'created_by' => $actor->id,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $this->audit->record($tenant, 'hour_bank.movement_created', HourBankMovement::class, $movement->id, $actor, null, [
                    'memberId' => $member->id,
                    'kind' => $kind,
                    'seconds' => $movement->seconds,
                    'localDate' => $localDate,
                    'note' => $note,
                ]);
            }, 'Saldo insuficiente');

            return $this->statement($tenant, $member);
        });
    }

    /**
     * Desfaz um movimento (ex.: folga lançada por engano).
     *
     * @return array<string, mixed> extrato atualizado
     */
    public function removeMovement(Tenant $tenant, Member $actor, int $movementId): array
    {
        return DB::transaction(function () use ($tenant, $actor, $movementId) {
            $movement = HourBankMovement::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($movementId)
                ->firstOrFail();

            $member = $this->lockMember($tenant, $movement->member_id);

            $this->guard($tenant, [$member->id], function () use ($tenant, $actor, $movement) {
                $this->audit->record($tenant, 'hour_bank.movement_removed', HourBankMovement::class, $movement->id, $actor, null, [
                    'memberId' => $movement->member_id,
                    'kind' => $movement->kind,
                    'seconds' => $movement->seconds,
                    'localDate' => $movement->local_date->toDateString(),
                    'note' => $movement->note,
                ]);

                $movement->delete();
            }, 'Não dá para desfazer este ajuste: as horas dele já foram usadas');

            return $this->statement($tenant, $member);
        });
    }

    /**
     * Executa a mutação e confere que nenhuma pessoa ficou com horas usadas sem
     * cobertura além do que já havia. Se ficou, desfaz tudo (exceção dentro da
     * transação de quem chamou).
     *
     * @param  list<int>  $memberIds
     */
    public function guard(Tenant $tenant, array $memberIds, callable $mutation, string $message): mixed
    {
        $memberIds = array_values(array_unique($memberIds));
        sort($memberIds);

        // Serializa com outros lançamentos no banco das mesmas pessoas.
        Member::query()->where('tenant_id', $tenant->id)->whereIn('id', $memberIds)->orderBy('id')->lockForUpdate()->get();

        $before = [];
        foreach ($memberIds as $memberId) {
            $before[$memberId] = $this->ledger($tenant, $memberId)['shortfallSeconds'];
        }

        $result = $mutation();

        foreach ($memberIds as $memberId) {
            $after = $this->ledger($tenant, $memberId);
            if ($after['shortfallSeconds'] > $before[$memberId]) {
                $first = $after['shortfalls'][0];
                throw new ConflictException(sprintf(
                    '%s: em %s faltariam %s no banco de horas%s.',
                    $message,
                    CarbonImmutable::parse($first['date'])->format('d/m/Y'),
                    self::hours($after['shortfallSeconds'] - $before[$memberId]),
                    count($memberIds) > 1 ? ' de '.Member::query()->whereKey($memberId)->value('display_name') : '',
                ));
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function ledger(Tenant $tenant, int $memberId): array
    {
        $reviews = AdditionalHourReview::query()
            ->where('additional_hour_reviews.tenant_id', $tenant->id)
            ->where('additional_hour_reviews.member_id', $memberId)
            ->where('classification', AdditionalHourReview::BANK)
            ->whereNotNull('bank_seconds')
            ->with('entry.project')
            ->get();

        $titles = $this->titles($tenant, $reviews);

        $credits = [];
        foreach ($reviews as $review) {
            $entry = $review->entry;
            $date = substr((string) $entry->local_date, 0, 10);

            $credits[] = [
                'key' => 'entry:'.$entry->id,
                'date' => $date,
                'seconds' => (int) $review->bank_seconds,
                'expiresOn' => $review->bank_expires_on?->toDateString()
                    ?? CarbonImmutable::parse($date)->addMonthsNoOverflow(6)->toDateString(),
                'meta' => [
                    'source' => 'entry',
                    'entryId' => (string) $entry->id,
                    'workItemId' => $entry->devops_work_item_id,
                    'workItemTitle' => $titles[$entry->project_id.':'.$entry->devops_work_item_id] ?? null,
                    'projectName' => $entry->project?->devops_project_name,
                    'additionalSeconds' => (int) $review->additional_seconds,
                    'note' => $review->classification_note,
                ],
            ];
        }

        $movements = HourBankMovement::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $memberId)
            ->with('author')
            ->get();

        $debits = [];
        foreach ($movements as $movement) {
            $item = [
                'key' => 'movement:'.str_pad((string) $movement->id, 10, '0', STR_PAD_LEFT),
                'date' => $movement->local_date->toDateString(),
                'seconds' => abs($movement->seconds),
                'meta' => [
                    'source' => 'movement',
                    'movementId' => (string) $movement->id,
                    'kind' => $movement->kind,
                    'note' => $movement->note,
                    'createdBy' => $movement->author?->display_name,
                ],
            ];

            if ($movement->seconds > 0) {
                $credits[] = $item + ['expiresOn' => $movement->expires_on?->toDateString() ?? $item['date']];
            } else {
                $debits[] = $item;
            }
        }

        return HourBankLedger::compute($credits, $debits, $this->today($tenant));
    }

    /**
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function summary(array $ledger): array
    {
        return [
            'balanceSeconds' => $ledger['balanceSeconds'],
            'expiredSeconds' => $ledger['expiredSeconds'],
            'expiringSoonSeconds' => $ledger['expiringSoon']['seconds'],
            'nextExpiry' => $ledger['expiringSoon']['date'],
            'uncoveredSeconds' => $ledger['shortfallSeconds'],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function presentEvent(array $event): array
    {
        $meta = $event['meta'];

        $view = [
            'id' => $event['key'],
            'type' => match (true) {
                $event['type'] === 'expiry' => 'expiry',
                $meta['source'] === 'entry' => 'credit',
                default => $meta['kind'],
            },
            'date' => $event['date'],
            'seconds' => $event['seconds'],
            'balanceSeconds' => $event['balanceSeconds'],
            'note' => $meta['note'] ?? null,
            'movementId' => $meta['movementId'] ?? null,
            'createdBy' => $meta['createdBy'] ?? null,
            'entryId' => $meta['entryId'] ?? null,
            'workItemId' => $meta['workItemId'] ?? null,
            'workItemTitle' => $meta['workItemTitle'] ?? null,
            'projectName' => $meta['projectName'] ?? null,
            'additionalSeconds' => $meta['additionalSeconds'] ?? null,
            'expiresOn' => $event['expiresOn'] ?? null,
            'remainingSeconds' => $event['remainingSeconds'] ?? null,
            'creditDate' => $event['creditDate'] ?? null,
            'uncoveredSeconds' => $event['uncoveredSeconds'] ?? null,
        ];

        // Na linha de vencimento, a origem é o crédito que venceu.
        if ($event['type'] === 'expiry') {
            $view['note'] = null;
            $view['movementId'] = null;
        }

        return $view;
    }

    /**
     * @param  iterable<AdditionalHourReview>  $reviews
     * @return array<string, string>
     */
    private function titles(Tenant $tenant, iterable $reviews): array
    {
        $entries = collect($reviews)->map(fn (AdditionalHourReview $review) => $review->entry);
        if ($entries->isEmpty()) {
            return [];
        }

        return WorkItemSnapshot::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('project_id', $entries->pluck('project_id')->unique())
            ->whereIn('devops_work_item_id', $entries->pluck('devops_work_item_id')->unique())
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (WorkItemSnapshot $snapshot) => [$snapshot->project_id.':'.$snapshot->devops_work_item_id => $snapshot->title])
            ->all();
    }

    private function lockMember(Tenant $tenant, int $memberId): Member
    {
        return Member::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($memberId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function validityMonths(Tenant $tenant): int
    {
        return $this->rules->current($tenant)?->bank_validity_months ?? 6;
    }

    private function today(Tenant $tenant): string
    {
        return CarbonImmutable::instance(Date::now())->setTimezone($tenant->default_timezone ?: 'UTC')->toDateString();
    }

    public static function hours(int $seconds): string
    {
        $minutes = (int) round($seconds / 60);

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
