<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Models\ApproverAssignment;
use App\Models\Member;
use App\Models\OvertimeRequest;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\WeeklySubmission;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Informar hora extra" (a pessoa avisa, antes ou depois, e o aprovador
 * decide) e a decisão sobre a "hora extra a confirmar" do perfil restrito.
 *
 * Quem decide: os aprovadores designados para a pessoa e os administradores
 * (os mesmos da aprovação da semana). Ninguém decide o próprio pedido, a não
 * ser administrador.
 */
class OvertimeRequestService
{
    /** Um pedido cobre no máximo 31 dias. */
    public const MAX_DAYS = 31;

    public function __construct(
        private readonly AuditService $audit,
        private readonly ApproverResolver $approvers,
        private readonly OvertimeCoverageService $coverage,
    ) {}

    /**
     * @param  array{dateFrom: string, dateTo?: ?string, secondsPerDay: int, startTime?: ?string, endTime?: ?string, reason: string, suggestedDestination?: ?string}  $data
     */
    public function inform(Tenant $tenant, Member $member, array $data): OvertimeRequest
    {
        if (! $this->coverage->applies($tenant, $member)) {
            throw ValidationException::withMessages(['dateFrom' => 'Hora extra informada só vale para CLT, com o controle de horas adicionais ligado.']);
        }

        $from = $data['dateFrom'];
        $to = $data['dateTo'] ?? $from;

        if ($to < $from) {
            throw ValidationException::withMessages(['dateTo' => 'A data final não pode ser antes da inicial.']);
        }

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['dateTo' => 'Um pedido cobre no máximo '.self::MAX_DAYS.' dias. Para mais, faça outro pedido.']);
        }

        $start = $data['startTime'] ?? null;
        $end = $data['endTime'] ?? null;
        if (($start === null) !== ($end === null) || ($start !== null && $end <= $start)) {
            throw ValidationException::withMessages(['startTime' => 'Informe o horário previsto completo (De e Até), com o fim depois do início, ou deixe os dois em branco.']);
        }

        $now = CarbonImmutable::now($tenant->default_timezone ?: 'UTC');
        $afterTheFact = $from < $now->toDateString() || ($from === $now->toDateString() && $start !== null && $start < $now->format('H:i'));

        $request = OvertimeRequest::query()->create([
            'tenant_id' => $tenant->id,
            'member_id' => $member->id,
            'kind' => OvertimeRequest::KIND_REQUEST,
            'date_from' => $from,
            'date_to' => $to,
            'seconds_per_day' => $data['secondsPerDay'],
            'start_time' => $start,
            'end_time' => $end,
            'reason' => trim($data['reason']),
            'suggested_destination' => $data['suggestedDestination'] ?? null,
            'after_the_fact' => $afterTheFact,
            'status' => OvertimeRequest::STATUS_PENDING,
        ]);

        $this->audit->record($tenant, 'overtime.informed', OvertimeRequest::class, $request->id, $member, null, [
            'dateFrom' => $from,
            'dateTo' => $to,
            'secondsPerDay' => $request->seconds_per_day,
            'afterTheFact' => $afterTheFact,
        ]);

        return $request;
    }

    public function cancel(Tenant $tenant, Member $member, int $requestId): OvertimeRequest
    {
        return DB::transaction(function () use ($tenant, $member, $requestId) {
            $request = OvertimeRequest::query()
                ->where('tenant_id', $tenant->id)
                ->where('member_id', $member->id)
                ->where('kind', OvertimeRequest::KIND_REQUEST)
                ->lockForUpdate()
                ->find($requestId);

            if (! $request) {
                abort(404);
            }

            if ($request->status !== OvertimeRequest::STATUS_PENDING) {
                throw new ConflictException('Este pedido já foi decidido e não pode mais ser cancelado.');
            }

            $request->update(['status' => OvertimeRequest::STATUS_CANCELLED]);
            $this->audit->record($tenant, 'overtime.cancelled', OvertimeRequest::class, $request->id, $member);

            return $request;
        });
    }

    /**
     * Os pedidos e as horas a confirmar da própria pessoa: tudo o que está
     * pendente e o que foi decidido nos últimos 90 dias.
     *
     * @return list<array<string, mixed>>
     */
    public function mine(Tenant $tenant, Member $member): array
    {
        $since = CarbonImmutable::now($tenant->default_timezone ?: 'UTC')->subDays(90)->toDateString();

        return OvertimeRequest::query()
            ->with(['decider', 'project'])
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $member->id)
            ->where(fn ($query) => $query->where('status', OvertimeRequest::STATUS_PENDING)->orWhere('date_to', '>=', $since))
            ->orderByDesc('date_from')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (OvertimeRequest $request) => $this->present($request))
            ->all();
    }

    /**
     * Pendentes que este membro pode decidir, mais antigos primeiro.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingFor(Tenant $tenant, Member $approver): array
    {
        $query = OvertimeRequest::query()
            ->with(['member', 'project'])
            ->where('tenant_id', $tenant->id)
            ->where('status', OvertimeRequest::STATUS_PENDING);

        if (! $this->approvers->isAdmin($approver)) {
            $designated = ApproverAssignment::query()
                ->where('tenant_id', $tenant->id)
                ->where('approver_id', $approver->id)
                ->pluck('member_id')
                ->all();

            $query->whereIn('member_id', $designated ?: [0])->where('member_id', '!=', $approver->id);
        }

        $requests = $query->orderBy('created_at')->orderBy('id')->get();
        $weeks = $this->weekStatuses($tenant, $requests);

        return $requests
            ->map(fn (OvertimeRequest $request) => $this->present($request) + [
                'person' => ['id' => (string) $request->member_id, 'displayName' => $request->member->display_name],
                'weekStatus' => $request->kind === OvertimeRequest::KIND_CONFIRMATION
                    ? ($weeks[$request->member_id.':'.WeekCalendar::startOf($request->dateFrom())] ?? WeeklySubmission::STATUS_OPEN)
                    : null,
            ])
            ->all();
    }

    /**
     * Aprovar ou recusar. Pedido: pode aprovar menos horas por dia do que o
     * pedido. Hora a confirmar: confirmada vira lançamento (com a regra de
     * quando foi registrada); recusada fica só como registro.
     */
    public function decide(Tenant $tenant, Member $approver, int $requestId, bool $approve, ?int $approvedSecondsPerDay, ?string $note): OvertimeRequest
    {
        $note = $note === null ? null : trim($note);

        return DB::transaction(function () use ($tenant, $approver, $requestId, $approve, $approvedSecondsPerDay, $note) {
            $request = OvertimeRequest::query()->where('tenant_id', $tenant->id)->lockForUpdate()->find($requestId);

            if (! $request) {
                abort(404);
            }

            if (! $this->canDecide($tenant, $approver, $request)) {
                throw new AuthorizationException('Você não decide os pedidos desta pessoa.');
            }

            if ($request->status !== OvertimeRequest::STATUS_PENDING) {
                throw new ConflictException('Este pedido já foi decidido ou cancelado.');
            }

            if (! $approve && ($note === null || $note === '')) {
                throw ValidationException::withMessages(['note' => 'Informe o motivo da recusa.']);
            }

            $changes = [
                'status' => $approve ? OvertimeRequest::STATUS_APPROVED : OvertimeRequest::STATUS_REJECTED,
                'decided_by' => $approver->id,
                'decided_at' => Date::now(),
                'decision_note' => $note === '' ? null : $note,
            ];

            if ($request->kind === OvertimeRequest::KIND_REQUEST && $approve) {
                $changes['approved_seconds_per_day'] = min($request->seconds_per_day, $approvedSecondsPerDay ?? $request->seconds_per_day);
            }

            if ($request->kind === OvertimeRequest::KIND_CONFIRMATION && $approve) {
                $changes['time_entry_id'] = $this->toEntry($tenant, $request)->id;
            }

            $request->update($changes);

            $this->audit->record(
                $tenant,
                $request->kind === OvertimeRequest::KIND_REQUEST ? 'overtime.request_decided' : 'overtime.confirmation_decided',
                OvertimeRequest::class,
                $request->id,
                $approver,
                null,
                [
                    'memberId' => $request->member_id,
                    'approved' => $approve,
                    'approvedSecondsPerDay' => $changes['approved_seconds_per_day'] ?? null,
                    'note' => $changes['decision_note'],
                ],
            );

            return $request;
        });
    }

    /**
     * Hora extra a confirmar do perfil restrito (lançamento manual ou timer).
     *
     * @param  array{localDate: string, startTime: ?string, endTime: ?string, seconds: int, projectId: int, workItemId: int, activityTypeId: ?int, note: ?string, billable: bool, timezone: string, source: string, reason: string}  $data
     */
    public function createConfirmation(Tenant $tenant, Member $member, array $data): OvertimeRequest
    {
        $now = CarbonImmutable::now($tenant->default_timezone ?: 'UTC');

        $request = OvertimeRequest::query()->create([
            'tenant_id' => $tenant->id,
            'member_id' => $member->id,
            'kind' => OvertimeRequest::KIND_CONFIRMATION,
            'date_from' => $data['localDate'],
            'date_to' => $data['localDate'],
            'seconds_per_day' => $data['seconds'],
            'start_time' => $data['startTime'],
            'end_time' => $data['endTime'],
            'reason' => trim($data['reason']),
            'after_the_fact' => $data['localDate'] < $now->toDateString() || $data['startTime'] === null || $data['startTime'] <= $now->format('H:i'),
            'status' => OvertimeRequest::STATUS_PENDING,
            'project_id' => $data['projectId'],
            'devops_work_item_id' => $data['workItemId'],
            'activity_type_id' => $data['activityTypeId'],
            'note' => $data['note'],
            'billable' => $data['billable'],
            'timezone' => $data['timezone'],
            'source' => $data['source'],
            'acknowledged_at' => Date::now(),
        ]);

        $this->audit->record($tenant, 'overtime.confirmation_created', OvertimeRequest::class, $request->id, $member, null, [
            'localDate' => $data['localDate'],
            'startTime' => $data['startTime'],
            'endTime' => $data['endTime'],
            'seconds' => $data['seconds'],
            'source' => $data['source'],
        ]);

        return $request;
    }

    /**
     * Horas a confirmar de uma semana (folha da pessoa e aprovação).
     *
     * @return list<array<string, mixed>>
     */
    public function weekConfirmations(Tenant $tenant, Member $member, string $weekStart): array
    {
        return OvertimeRequest::query()
            ->with(['decider', 'project'])
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $member->id)
            ->where('kind', OvertimeRequest::KIND_CONFIRMATION)
            ->whereBetween('date_from', [$weekStart, WeekCalendar::endOf($weekStart)])
            ->orderBy('date_from')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get()
            ->map(fn (OvertimeRequest $request) => $this->present($request))
            ->all();
    }

    public function pendingConfirmations(Tenant $tenant, int $memberId, string $weekStart): int
    {
        return OvertimeRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $memberId)
            ->where('kind', OvertimeRequest::KIND_CONFIRMATION)
            ->where('status', OvertimeRequest::STATUS_PENDING)
            ->whereBetween('date_from', [$weekStart, WeekCalendar::endOf($weekStart)])
            ->count();
    }

    public function canDecide(Tenant $tenant, Member $approver, OvertimeRequest $request): bool
    {
        if ($this->approvers->isAdmin($approver)) {
            return true;
        }

        if ($request->member_id === $approver->id) {
            return false;
        }

        return ApproverAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('approver_id', $approver->id)
            ->where('member_id', $request->member_id)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(OvertimeRequest $request): array
    {
        return [
            'id' => (string) $request->id,
            'kind' => $request->kind,
            'dateFrom' => $request->dateFrom(),
            'dateTo' => $request->dateTo(),
            'secondsPerDay' => $request->seconds_per_day,
            'startTime' => $request->start_time,
            'endTime' => $request->end_time,
            'reason' => $request->reason,
            'suggestedDestination' => $request->suggested_destination,
            'afterTheFact' => $request->after_the_fact,
            'status' => $request->status,
            'approvedSecondsPerDay' => $request->approved_seconds_per_day,
            'decidedBy' => $request->decider?->display_name,
            'decidedAt' => $request->decided_at?->toIso8601String(),
            'decisionNote' => $request->decision_note,
            'projectName' => $request->project?->devops_project_name,
            'workItemId' => $request->devops_work_item_id,
            'note' => $request->note,
            'createdAt' => $request->created_at?->toIso8601String(),
        ];
    }

    /** Hora confirmada vira lançamento, com a data de registro original (vale a regra daquele momento). */
    private function toEntry(Tenant $tenant, OvertimeRequest $request): TimeEntry
    {
        $week = WeeklySubmission::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $request->member_id)
            ->where('week_start_date', WeekCalendar::startOf($request->dateFrom()))
            ->value('status');

        if ($week === WeeklySubmission::STATUS_APPROVED) {
            throw new ConflictException('A semana desta hora já foi aprovada. Reabra a semana para confirmar.');
        }

        $timezone = $request->timezone ?: ($tenant->default_timezone ?: 'UTC');
        $start = $request->start_time === null ? null : CarbonImmutable::parse($request->dateFrom().' '.$request->start_time, $timezone);

        $entry = TimeEntry::query()->create([
            'tenant_id' => $tenant->id,
            'project_id' => $request->project_id,
            'member_id' => $request->member_id,
            'devops_work_item_id' => $request->devops_work_item_id,
            'activity_type_id' => $request->activity_type_id,
            'local_date' => $request->dateFrom(),
            'timezone' => $timezone,
            'duration_seconds' => $request->seconds_per_day,
            'started_at_utc' => $start?->utc(),
            'ended_at_utc' => $start?->addSeconds($request->seconds_per_day)->utc(),
            'source' => $request->source ?: TimeEntry::SOURCE_MANUAL,
            'billable' => $request->billable,
            'note' => $request->note,
            'revision' => 1,
        ]);

        // A regra de hora adicional é a de quando a hora foi registrada, não a de agora.
        $entry->forceFill(['created_at' => $request->created_at])->save();

        return $entry;
    }

    /**
     * @param  iterable<OvertimeRequest>  $requests
     * @return array<string, string>
     */
    private function weekStatuses(Tenant $tenant, iterable $requests): array
    {
        $keys = collect($requests)
            ->where('kind', OvertimeRequest::KIND_CONFIRMATION)
            ->map(fn (OvertimeRequest $request) => [$request->member_id, WeekCalendar::startOf($request->dateFrom())]);

        if ($keys->isEmpty()) {
            return [];
        }

        return WeeklySubmission::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('member_id', $keys->pluck(0)->unique()->values()->all())
            ->whereIn('week_start_date', $keys->pluck(1)->unique()->values()->all())
            ->get()
            ->mapWithKeys(fn (WeeklySubmission $submission) => [$submission->member_id.':'.substr((string) $submission->week_start_date, 0, 10) => $submission->status])
            ->all();
    }
}
