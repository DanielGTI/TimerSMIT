<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Models\ApprovalDecision;
use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use App\Models\WeeklySubmission;
use App\Models\WorkItemSnapshot;
use App\Support\TimeEntryPresenter;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Folha semanal (US2): consulta da semana/mês, envio e cancelamento do envio. Somas são sempre em
 * segundos inteiros, feitas aqui no servidor a partir das mesmas linhas que
 * a tela mostra (FR-005) — a tela nunca recalcula totais por conta própria.
 */
class TimesheetService
{
    public function __construct(
        private readonly WeekLockGuard $weeks,
        private readonly AuditService $audit,
        private readonly ApproverResolver $approvers,
        private readonly AdditionalHoursService $additional,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function week(Tenant $tenant, Member $member, string $weekStart): array
    {
        $this->assertWeekStart($weekStart);

        $entries = TimeEntry::query()
            ->with(['project', 'activityType'])
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $member->id)
            ->whereBetween('local_date', [$weekStart, WeekCalendar::endOf($weekStart)])
            ->orderBy('local_date')
            ->orderBy('id')
            ->get();

        $submission = $this->submissionFor($tenant, $member, $weekStart);
        $snapshots = $this->latestSnapshots($tenant, $entries);
        $evaluation = $this->additional->evaluate($tenant, $entries);
        $additional = $entries->mapWithKeys(fn (TimeEntry $entry) => [$entry->id => $this->additional->present($evaluation[$entry->id])]);
        $totals = $entries->groupBy('local_date')->map(fn (Collection $day) => (int) $day->sum('duration_seconds'));

        return [
            'weekStartDate' => $weekStart,
            'weekEndDate' => WeekCalendar::endOf($weekStart),
            'status' => $submission?->status ?? WeeklySubmission::STATUS_OPEN,
            'revision' => $submission?->revision ?? 0,
            'submittedAt' => $submission?->submitted_at?->toIso8601String(),
            // Para reabrir a própria semana aprovada sem passar pela tela de Aprovações
            // (só administradores; o servidor confere de novo em ApprovalService::reopen).
            'submissionId' => $submission ? (string) $submission->id : null,
            'canReopen' => $submission?->status === WeeklySubmission::STATUS_APPROVED && $this->approvers->isAdmin($member),
            'totalSeconds' => (int) $entries->sum('duration_seconds'),
            'decisions' => $submission
                ? $submission->decisions()->with('approver')->orderBy('id')->get()->map(fn (ApprovalDecision $decision) => [
                    'revision' => $decision->revision,
                    'decision' => $decision->decision,
                    'reason' => $decision->reason,
                    'approverName' => $decision->approver?->display_name,
                    'selfDecision' => $decision->self_decision,
                    'decidedAt' => $decision->decided_at->toIso8601String(),
                ])->all()
                : [],
            'days' => array_map(
                fn (string $date) => ['date' => $date, 'totalSeconds' => $totals->get($date, 0)],
                WeekCalendar::days($weekStart),
            ),
            // Horas fora do expediente (ou em fim de semana/feriado) desta semana, já com os fatores.
            'additionalTotals' => [
                'seconds' => (int) $additional->filter()->sum('seconds'),
                'weightedSeconds' => (int) $additional->filter()->sum('weightedSeconds'),
                'pendingSeconds' => (int) $additional->filter(fn ($view) => $view !== null && $view['status'] === 'pending')->sum('seconds'),
            ],
            'entries' => $entries->map(function (TimeEntry $entry) use ($snapshots, $additional) {
                $snapshot = $snapshots->get($entry->project_id.':'.$entry->devops_work_item_id);

                return TimeEntryPresenter::present($entry) + [
                    'projectId' => $entry->project->devops_project_id,
                    'projectName' => $entry->project->devops_project_name,
                    'workItemTitle' => $snapshot?->title,
                    'workItemType' => $snapshot?->work_item_type,
                    'activityTypeName' => $entry->activityType?->name,
                    'activityTypeColor' => $entry->activityType?->color,
                    'additional' => $additional->get($entry->id),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Visão mensal: horas por data local. Uma semana que atravessa o mês
     * aparece nos dois, cada dia no seu mês — a unidade de aprovação
     * continua sendo a semana.
     *
     * @return array<string, mixed>
     */
    public function month(Tenant $tenant, Member $member, string $month): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01');
        $last = $first->endOfMonth();

        $totals = TimeEntry::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $member->id)
            ->whereBetween('local_date', [$first->toDateString(), $last->toDateString()])
            ->orderBy('local_date')
            ->get(['local_date', 'duration_seconds'])
            ->groupBy('local_date')
            ->map(fn (Collection $day) => (int) $day->sum('duration_seconds'));

        $weekStarts = [];
        for ($cursor = CarbonImmutable::parse(WeekCalendar::startOf($first->toDateString())); $cursor->lessThanOrEqualTo($last); $cursor = $cursor->addDays(7)) {
            $weekStarts[] = $cursor->toDateString();
        }

        $statuses = WeeklySubmission::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $member->id)
            ->whereIn('week_start_date', $weekStarts)
            ->pluck('status', 'week_start_date');

        return [
            'month' => $month,
            'totalSeconds' => (int) $totals->sum(),
            'days' => $totals->map(fn (int $seconds, string $date) => ['date' => $date, 'totalSeconds' => $seconds])->values()->all(),
            'weeks' => array_map(
                fn (string $start) => ['weekStartDate' => $start, 'status' => $statuses->get($start, WeeklySubmission::STATUS_OPEN)],
                $weekStarts,
            ),
        ];
    }

    /**
     * open|rejected → submitted, registrando a versão dos lançamentos.
     * Repetir o envio com a mesma Idempotency-Key devolve o mesmo resultado.
     */
    public function submit(Tenant $tenant, Member $member, string $weekStart, string $idempotencyKey): WeeklySubmission
    {
        $this->assertWeekStart($weekStart);

        return DB::transaction(function () use ($tenant, $member, $weekStart, $idempotencyKey) {
            $this->weeks->lockMember($member);

            $submission = $this->submissionFor($tenant, $member, $weekStart);

            if ($submission?->revisions()->where('idempotency_key', $idempotencyKey)->exists()) {
                return $submission;
            }

            if ($submission && in_array($submission->status, WeeklySubmission::LOCKED_STATUSES, true)) {
                throw new ConflictException('Esta semana já foi enviada ou aprovada.');
            }

            $weekEnd = WeekCalendar::endOf($weekStart);

            // Timer aberto ainda vai gerar lançamentos; se a semana já
            // estivesse enviada, ele nunca conseguiria ser parado (409).
            $hasOpenTimer = TimerSession::query()
                ->where('tenant_id', $tenant->id)
                ->where('member_id', $member->id)
                ->where('status', TimerSession::STATUS_ACTIVE)
                ->exists();

            if ($hasOpenTimer) {
                throw new ConflictException('Pare o timer ativo antes de enviar a semana.');
            }

            $entries = TimeEntry::query()
                ->where('tenant_id', $tenant->id)
                ->where('member_id', $member->id)
                ->whereBetween('local_date', [$weekStart, $weekEnd])
                ->orderBy('local_date')
                ->orderBy('id')
                ->get();

            if ($entries->isEmpty()) {
                throw ValidationException::withMessages(['week' => 'Não há lançamentos nesta semana para enviar.']);
            }

            // Quem vai poder decidir é resolvido agora (CL-002). Sem ninguém —
            // nem designado, nem administrador — o envio não faz sentido.
            $designated = $this->approvers->designatedFor(
                $tenant,
                $member,
                $entries->pluck('project_id')->unique()->values()->all(),
            );

            if ($designated === [] && ! $this->approvers->tenantHasAdmin($tenant)) {
                throw new ConflictException('Nenhum aprovador configurado para esta semana. Peça a um administrador para designar um aprovador.');
            }

            $submission ??= WeeklySubmission::query()->create([
                'tenant_id' => $tenant->id,
                'member_id' => $member->id,
                'week_start_date' => $weekStart,
                'status' => WeeklySubmission::STATUS_OPEN,
                'revision' => 0,
            ]);

            $now = Date::now();
            $revision = $submission->revision + 1;
            $totalSeconds = (int) $entries->sum('duration_seconds');

            $submission->update([
                'status' => WeeklySubmission::STATUS_SUBMITTED,
                'revision' => $revision,
                'submitted_at' => $now,
                'approved_at' => null,
                'approver_id' => null,
            ]);

            foreach ($designated as $approverId) {
                $submission->approverRows()->create(['revision' => $revision, 'approver_id' => $approverId]);
            }

            $submission->revisions()->create([
                'revision' => $revision,
                'idempotency_key' => $idempotencyKey,
                'submitted_at' => $now,
                'total_seconds' => $totalSeconds,
                'entry_count' => $entries->count(),
                'entries_snapshot' => $entries->map(fn (TimeEntry $entry) => [
                    'id' => $entry->id,
                    'revision' => $entry->revision,
                    'localDate' => $entry->local_date,
                    'durationSeconds' => $entry->duration_seconds,
                    'workItemId' => $entry->devops_work_item_id,
                ])->all(),
            ]);

            $this->audit->record(
                tenant: $tenant,
                action: 'week.submitted',
                subjectType: WeeklySubmission::class,
                subjectId: $submission->id,
                actor: $member,
                context: [
                    'weekStartDate' => $weekStart,
                    'revision' => $revision,
                    'totalSeconds' => $totalSeconds,
                    'entryCount' => $entries->count(),
                    'designatedApproverIds' => $designated,
                ],
            );

            return $submission;
        });
    }

    /**
     * submitted → open, pela própria pessoa, enquanto ninguém decidiu: ela
     * recolhe o envio para lançar ou corrigir e depois envia de novo (nova
     * revisão). Aprovada continua bloqueada — só um administrador reabre.
     *
     * Trava a linha da semana como a decisão do aprovador: ou a decisão vem
     * antes (e o cancelamento recebe 409), ou o cancelamento vem antes e a
     * decisão encontra a semana aberta (409 do lado do aprovador).
     */
    public function recall(Tenant $tenant, Member $member, string $weekStart, string $idempotencyKey): WeeklySubmission
    {
        $this->assertWeekStart($weekStart);

        return DB::transaction(function () use ($tenant, $member, $weekStart, $idempotencyKey) {
            $this->weeks->lockMember($member);

            $submission = WeeklySubmission::query()
                ->where('tenant_id', $tenant->id)
                ->where('member_id', $member->id)
                ->where('week_start_date', $weekStart)
                ->lockForUpdate()
                ->first();

            $repeated = $submission && AuditEvent::query()
                ->where('tenant_id', $tenant->id)
                ->where('action', 'week.recalled')
                ->where('subject_id', $submission->id)
                ->where('context->idempotencyKey', $idempotencyKey)
                ->exists();

            if ($repeated) {
                return $submission;
            }

            if ($submission?->status === WeeklySubmission::STATUS_APPROVED) {
                throw new ConflictException('Semana aprovada: peça a um administrador para reabrir.');
            }

            if ($submission?->status !== WeeklySubmission::STATUS_SUBMITTED) {
                throw new ConflictException('Esta semana não está enviada; não há envio para cancelar.');
            }

            $submission->update(['status' => WeeklySubmission::STATUS_OPEN]);

            $this->audit->record(
                tenant: $tenant,
                action: 'week.recalled',
                subjectType: WeeklySubmission::class,
                subjectId: $submission->id,
                actor: $member,
                context: [
                    'weekStartDate' => $weekStart,
                    'revision' => $submission->revision,
                    'idempotencyKey' => $idempotencyKey,
                ],
            );

            return $submission;
        });
    }

    public function assertWeekStart(string $weekStart): void
    {
        if (! WeekCalendar::isWeekStart($weekStart)) {
            throw ValidationException::withMessages(['weekStartDate' => 'A data informada não é uma segunda-feira.']);
        }
    }

    private function submissionFor(Tenant $tenant, Member $member, string $weekStart): ?WeeklySubmission
    {
        return WeeklySubmission::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $member->id)
            ->where('week_start_date', $weekStart)
            ->first();
    }

    /**
     * Título/tipo mais recentes de cada work item (append-only: pega a
     * captura mais nova), indexados por "projeto:workItem".
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @return Collection<string, WorkItemSnapshot>
     */
    private function latestSnapshots(Tenant $tenant, Collection $entries): Collection
    {
        if ($entries->isEmpty()) {
            return collect();
        }

        return WorkItemSnapshot::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('project_id', $entries->pluck('project_id')->unique())
            ->whereIn('devops_work_item_id', $entries->pluck('devops_work_item_id')->unique())
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (WorkItemSnapshot $snapshot) => $snapshot->project_id.':'.$snapshot->devops_work_item_id)
            ->keyBy(fn (WorkItemSnapshot $snapshot) => $snapshot->project_id.':'.$snapshot->devops_work_item_id);
    }
}
