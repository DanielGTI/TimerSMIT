<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Models\ApprovalDecision;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\WeeklySubmission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aprovação da semana inteira (US3). Quem decide: aprovadores designados no
 * envio + administradores (ver ApproverResolver). A primeira transição válida
 * vence: a linha da submissão é travada e só `submitted` aceita decisão.
 */
class ApprovalService
{
    public function __construct(
        private readonly ApproverResolver $approvers,
        private readonly TimesheetService $timesheet,
        private readonly AuditService $audit,
    ) {}

    /**
     * Semanas aguardando decisão que este membro pode decidir, mais antigas primeiro.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(Tenant $tenant, Member $member): array
    {
        $query = WeeklySubmission::query()
            ->with(['member', 'revisions'])
            ->where('tenant_id', $tenant->id)
            ->where('status', WeeklySubmission::STATUS_SUBMITTED);

        if (! $this->approvers->isAdmin($member)) {
            $query->whereHas('approverRows', function ($rows) use ($member) {
                $rows->where('approver_id', $member->id)
                    ->whereColumn('weekly_submission_approvers.revision', 'weekly_submissions.revision');
            });
        }

        return $query->orderBy('submitted_at')->orderBy('id')->get()
            ->map(function (WeeklySubmission $submission) use ($member) {
                $revision = $submission->revisions->firstWhere('revision', $submission->revision);

                return [
                    'id' => (string) $submission->id,
                    'weekStartDate' => $submission->week_start_date,
                    'status' => $submission->status,
                    'revision' => $submission->revision,
                    'submittedAt' => $submission->submitted_at?->toIso8601String(),
                    'totalSeconds' => $revision?->total_seconds ?? 0,
                    'entryCount' => $revision?->entry_count ?? 0,
                    'submitter' => $this->person($submission->member),
                    'ownWeek' => $submission->member_id === $member->id,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Últimas decisões (aprovar/rejeitar) tomadas por este membro.
     *
     * @return list<array<string, mixed>>
     */
    public function decidedByMe(Tenant $tenant, Member $member): array
    {
        return ApprovalDecision::query()
            ->with('submission.member')
            ->where('tenant_id', $tenant->id)
            ->where('approver_id', $member->id)
            ->whereIn('decision', [ApprovalDecision::APPROVED, ApprovalDecision::REJECTED])
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (ApprovalDecision $decision) => [
                'id' => (string) $decision->weekly_submission_id,
                'weekStartDate' => $decision->submission->week_start_date,
                'status' => $decision->submission->status,
                'decision' => $decision->decision,
                'reason' => $decision->reason,
                'revision' => $decision->revision,
                'decidedAt' => $decision->decided_at->toIso8601String(),
                'submitter' => $this->person($decision->submission->member),
            ])
            ->values()
            ->all();
    }

    /**
     * Tudo que o revisor precisa: a semana do colaborador, o histórico de
     * decisões e o que ele pode fazer agora.
     *
     * @return array<string, mixed>
     */
    public function detail(Tenant $tenant, Member $viewer, int $submissionId): array
    {
        $submission = $this->find($tenant, $submissionId);

        if (! $this->canView($viewer, $submission)) {
            throw new AuthorizationException;
        }

        return $this->present($tenant, $viewer, $submission);
    }

    public function decide(
        Tenant $tenant,
        Member $decider,
        int $submissionId,
        string $decision,
        ?string $reason,
        ?int $expectedRevision,
        string $idempotencyKey,
    ): WeeklySubmission {
        $reason = $reason === null ? null : trim($reason);

        return DB::transaction(function () use ($tenant, $decider, $submissionId, $decision, $reason, $expectedRevision, $idempotencyKey) {
            $submission = $this->lock($tenant, $submissionId);

            $previous = $submission->decisions()->where('idempotency_key', $idempotencyKey)->first();
            if ($previous && $previous->approver_id === $decider->id) {
                return $submission;
            }

            if (! $this->canDecide($decider, $submission)) {
                throw new AuthorizationException;
            }

            if ($submission->status !== WeeklySubmission::STATUS_SUBMITTED) {
                throw new ConflictException('Esta semana já foi decidida ou não está aguardando aprovação.');
            }

            if ($expectedRevision !== null && $expectedRevision !== $submission->revision) {
                throw new ConflictException('A semana foi reenviada. Recarregue para ver a versão atual.');
            }

            $rejecting = $decision === ApprovalDecision::REJECTED;

            if ($rejecting && ($reason === null || $reason === '')) {
                throw ValidationException::withMessages(['reason' => 'Informe o motivo da rejeição.']);
            }

            $now = Date::now();
            $self = $submission->member_id === $decider->id;

            $submission->update([
                'status' => $rejecting ? WeeklySubmission::STATUS_REJECTED : WeeklySubmission::STATUS_APPROVED,
                'approver_id' => $decider->id,
                'approved_at' => $rejecting ? null : $now,
            ]);

            $this->record($tenant, $submission, $decider, $decision, $reason, $self, $idempotencyKey, $now);

            return $submission;
        });
    }

    /** Administrador reabre uma semana aprovada (volta a `open`), com justificativa. */
    public function reopen(Tenant $tenant, Member $admin, int $submissionId, ?string $reason, string $idempotencyKey): WeeklySubmission
    {
        $reason = $reason === null ? null : trim($reason);

        return DB::transaction(function () use ($tenant, $admin, $submissionId, $reason, $idempotencyKey) {
            $submission = $this->lock($tenant, $submissionId);

            $previous = $submission->decisions()->where('idempotency_key', $idempotencyKey)->first();
            if ($previous && $previous->approver_id === $admin->id) {
                return $submission;
            }

            if (! $this->approvers->isAdmin($admin)) {
                throw new AuthorizationException;
            }

            if ($submission->status !== WeeklySubmission::STATUS_APPROVED) {
                throw new ConflictException('Só é possível reabrir uma semana aprovada.');
            }

            if ($reason === null || $reason === '') {
                throw ValidationException::withMessages(['reason' => 'Informe a justificativa da reabertura.']);
            }

            $now = Date::now();

            $submission->update([
                'status' => WeeklySubmission::STATUS_OPEN,
                'approved_at' => null,
                'approver_id' => null,
            ]);

            $this->record($tenant, $submission, $admin, ApprovalDecision::REOPENED, $reason, $submission->member_id === $admin->id, $idempotencyKey, $now);

            return $submission;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Tenant $tenant, Member $viewer, WeeklySubmission $submission): array
    {
        $submission->loadMissing('member');

        return [
            'submission' => [
                'id' => (string) $submission->id,
                'weekStartDate' => $submission->week_start_date,
                'status' => $submission->status,
                'revision' => $submission->revision,
                'submittedAt' => $submission->submitted_at?->toIso8601String(),
                'submitter' => $this->person($submission->member),
            ],
            'week' => $this->timesheet->week($tenant, $submission->member, $submission->week_start_date),
            'permissions' => [
                'canDecide' => $submission->status === WeeklySubmission::STATUS_SUBMITTED && $this->canDecide($viewer, $submission),
                'canReopen' => $submission->status === WeeklySubmission::STATUS_APPROVED && $this->approvers->isAdmin($viewer),
                'ownWeek' => $submission->member_id === $viewer->id,
            ],
        ];
    }

    private function canDecide(Member $member, WeeklySubmission $submission): bool
    {
        if ($this->approvers->isAdmin($member)) {
            return true;
        }

        if ($submission->member_id === $member->id) {
            return false;
        }

        return $submission->approverRows()
            ->where('revision', $submission->revision)
            ->where('approver_id', $member->id)
            ->exists();
    }

    /** Quem pode decidir, ou já decidiu esta semana, pode abrir o detalhe. */
    private function canView(Member $member, WeeklySubmission $submission): bool
    {
        return $this->canDecide($member, $submission)
            || $submission->decisions()->where('approver_id', $member->id)->exists();
    }

    private function find(Tenant $tenant, int $submissionId): WeeklySubmission
    {
        return WeeklySubmission::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $submissionId)
            ->firstOrFail();
    }

    private function lock(Tenant $tenant, int $submissionId): WeeklySubmission
    {
        return WeeklySubmission::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $submissionId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function record(
        Tenant $tenant,
        WeeklySubmission $submission,
        Member $actor,
        string $decision,
        ?string $reason,
        bool $self,
        string $idempotencyKey,
        $decidedAt,
    ): void {
        $row = ApprovalDecision::query()->create([
            'tenant_id' => $tenant->id,
            'weekly_submission_id' => $submission->id,
            'revision' => $submission->revision,
            'approver_id' => $actor->id,
            'decision' => $decision,
            'reason' => $reason,
            'self_decision' => $self,
            'idempotency_key' => $idempotencyKey,
            'decided_at' => $decidedAt,
        ]);

        $this->audit->record(
            tenant: $tenant,
            action: match ($decision) {
                ApprovalDecision::APPROVED => 'week.approved',
                ApprovalDecision::REJECTED => 'week.rejected',
                default => 'week.reopened',
            },
            subjectType: WeeklySubmission::class,
            subjectId: $submission->id,
            actor: $actor,
            context: [
                'decisionId' => $row->id,
                'weekStartDate' => $submission->week_start_date,
                'submitterId' => $submission->member_id,
                'revision' => $submission->revision,
                'reason' => $reason,
                'selfDecision' => $self,
            ],
        );
    }

    /**
     * @return array{id: string, displayName: string}
     */
    private function person(Member $member): array
    {
        return ['id' => (string) $member->id, 'displayName' => $member->display_name];
    }
}
