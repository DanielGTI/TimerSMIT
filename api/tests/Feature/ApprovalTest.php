<?php

namespace Tests\Feature;

use App\Models\ApprovalDecision;
use App\Models\ApproverAssignment;
use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\WeeklySubmission;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T030 — aprovação da semana (US3): quem decide, rejeição com motivo,
 * reenvio mantendo histórico, primeira decisão vence, isolamento e reabertura.
 */
class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = '2026-09-28';

    private Tenant $tenant;

    private Project $project;

    private Member $employee;

    private Member $admin;

    private Member $approver;

    private Member $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
        $this->project = Project::factory()->for($this->tenant)->create();
        $this->employee = $this->member();
        $this->admin = $this->member();
        $this->approver = $this->member();
        $this->stranger = $this->member();

        $this->role($this->employee, RoleAssignment::ROLE_MEMBER, $this->project);
        $this->role($this->admin, RoleAssignment::ROLE_ADMIN);
        // Gestor sem designação: ter o papel não basta para decidir.
        $this->role($this->stranger, RoleAssignment::ROLE_MANAGER);

        ApproverAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->employee->id,
            'approver_id' => $this->approver->id,
        ]);

        $this->travelTo('2026-09-30 15:00:00');
    }

    private function member(?Tenant $tenant = null): Member
    {
        return Member::factory()->for($tenant ?? $this->tenant)->create();
    }

    private function role(Member $member, string $role, ?Project $project = null): void
    {
        RoleAssignment::factory()->create([
            'tenant_id' => $member->tenant_id,
            'member_id' => $member->id,
            'project_id' => $project?->id,
            'role' => $role,
        ]);
    }

    private function headers(Member $member, ?string $key = null): array
    {
        $session = app(SessionTokenService::class)->issue($member->tenant_id, $member->id);

        return ['Authorization' => "Bearer {$session->token}", 'Idempotency-Key' => $key ?? (string) Str::uuid()];
    }

    private function entry(Member $member, string $date = '2026-09-28', int $seconds = 3600, ?Project $project = null): TimeEntry
    {
        return TimeEntry::factory()->create([
            'tenant_id' => $member->tenant_id,
            'project_id' => ($project ?? $this->project)->id,
            'member_id' => $member->id,
            'local_date' => $date,
            'duration_seconds' => $seconds,
        ]);
    }

    /** Lança 1h e envia a semana do colaborador; devolve o id da submissão. */
    private function submittedWeek(?Member $member = null): int
    {
        $member ??= $this->employee;
        $this->entry($member);
        $this->postJson('/api/me/weeks/'.self::WEEK.'/submit', [], $this->headers($member))->assertOk();

        return WeeklySubmission::query()->where('member_id', $member->id)->firstOrFail()->id;
    }

    private function decide(Member $by, int $id, string $decision, array $extra = [], ?string $key = null)
    {
        return $this->postJson("/api/approvals/{$id}/decision", ['decision' => $decision] + $extra, $this->headers($by, $key));
    }

    // ---------- envio resolve quem decide ----------

    public function test_submit_needs_someone_who_can_decide(): void
    {
        $tenant = Tenant::factory()->create();
        $project = Project::factory()->for($tenant)->create();
        $lonely = $this->member($tenant);
        $this->role($lonely, RoleAssignment::ROLE_MEMBER, $project);
        $this->entry($lonely, project: $project);

        $this->postJson('/api/me/weeks/'.self::WEEK.'/submit', [], $this->headers($lonely))
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Nenhum aprovador configurado'));
        $this->assertSame(0, WeeklySubmission::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_submit_records_the_designated_approvers_of_that_revision(): void
    {
        $id = $this->submittedWeek();

        $rows = WeeklySubmission::query()->findOrFail($id)->approverRows;
        $this->assertSame([$this->approver->id], $rows->pluck('approver_id')->all());
        $this->assertSame([1], $rows->pluck('revision')->all());
    }

    public function test_a_designation_for_another_project_does_not_apply(): void
    {
        $other = Project::factory()->for($this->tenant)->create();
        ApproverAssignment::query()->delete();
        ApproverAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->employee->id,
            'project_id' => $other->id,
            'approver_id' => $this->approver->id,
        ]);

        $id = $this->submittedWeek();

        $this->assertSame(0, WeeklySubmission::query()->findOrFail($id)->approverRows()->count());
        $this->getJson('/api/approvals', $this->headers($this->approver))->assertOk()->assertExactJson([]);
        $this->getJson('/api/approvals', $this->headers($this->admin))->assertOk()->assertJsonCount(1);
    }

    public function test_a_project_designation_applies_when_the_week_has_that_project(): void
    {
        $other = Project::factory()->for($this->tenant)->create();
        ApproverAssignment::query()->delete();
        ApproverAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->employee->id,
            'project_id' => $other->id,
            'approver_id' => $this->approver->id,
        ]);
        $this->entry($this->employee, '2026-09-28', 600, $other);

        $this->submittedWeek();

        $this->getJson('/api/approvals', $this->headers($this->approver))->assertOk()->assertJsonCount(1);
    }

    // ---------- caixa de aprovações ----------

    public function test_inbox_shows_the_week_only_to_people_who_can_decide_it(): void
    {
        $id = $this->submittedWeek();

        $approverView = $this->getJson('/api/approvals', $this->headers($this->approver))->assertOk();
        $approverView->assertJsonCount(1)->assertJsonPath('0.id', (string) $id)
            ->assertJsonPath('0.submitter.id', (string) $this->employee->id)
            ->assertJsonPath('0.totalSeconds', 3600)
            ->assertJsonPath('0.entryCount', 1);

        $this->getJson('/api/approvals', $this->headers($this->admin))->assertOk()->assertJsonCount(1);
        $this->getJson('/api/approvals', $this->headers($this->stranger))->assertOk()->assertExactJson([]);
        $this->getJson('/api/approvals', $this->headers($this->employee))->assertOk()->assertExactJson([]);
    }

    public function test_inbox_never_leaks_other_tenants(): void
    {
        $this->submittedWeek();
        $otherTenant = Tenant::factory()->create();
        $foreignAdmin = $this->member($otherTenant);
        $this->role($foreignAdmin, RoleAssignment::ROLE_ADMIN);

        $this->getJson('/api/approvals', $this->headers($foreignAdmin))->assertOk()->assertExactJson([]);
    }

    public function test_decided_view_lists_my_decisions(): void
    {
        $id = $this->submittedWeek();
        $this->decide($this->approver, $id, 'approve')->assertOk();

        $this->getJson('/api/approvals', $this->headers($this->approver))->assertOk()->assertExactJson([]);
        $this->getJson('/api/approvals?view=decided', $this->headers($this->approver))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.decision', 'approved')
            ->assertJsonPath('0.id', (string) $id);
    }

    public function test_detail_is_for_people_who_can_decide_or_already_did(): void
    {
        $id = $this->submittedWeek();

        $this->getJson("/api/approvals/{$id}", $this->headers($this->stranger))->assertStatus(403);
        $this->getJson("/api/approvals/{$id}", $this->headers($this->employee))->assertStatus(403);

        $detail = $this->getJson("/api/approvals/{$id}", $this->headers($this->approver))->assertOk();
        $detail->assertJsonPath('permissions.canDecide', true)
            ->assertJsonPath('permissions.canReopen', false)
            ->assertJsonPath('submission.submitter.displayName', $this->employee->display_name)
            ->assertJsonPath('week.totalSeconds', 3600)
            ->assertJsonCount(1, 'week.entries');
    }

    // ---------- aprovar / rejeitar ----------

    public function test_approve_locks_the_week_and_records_who_and_when(): void
    {
        $id = $this->submittedWeek();
        $entry = TimeEntry::query()->firstOrFail();

        $this->decide($this->approver, $id, 'approve', ['revision' => 1])
            ->assertOk()
            ->assertJsonPath('submission.status', 'approved')
            ->assertJsonPath('permissions.canDecide', false);

        $submission = WeeklySubmission::query()->findOrFail($id);
        $this->assertSame($this->approver->id, $submission->approver_id);
        $this->assertNotNull($submission->approved_at);

        $decision = ApprovalDecision::query()->firstOrFail();
        $this->assertSame([ApprovalDecision::APPROVED, 1, false], [$decision->decision, $decision->revision, $decision->self_decision]);

        $event = AuditEvent::query()->where('action', 'week.approved')->firstOrFail();
        $this->assertSame($this->approver->id, $event->actor_member_id);

        $this->deleteJson("/api/entries/{$entry->id}", [], $this->headers($this->employee))->assertStatus(409);
    }

    public function test_reject_requires_a_reason(): void
    {
        $id = $this->submittedWeek();

        $this->decide($this->approver, $id, 'reject')->assertStatus(422);
        $this->decide($this->approver, $id, 'reject', ['reason' => '   '])->assertStatus(422);

        $this->assertSame(WeeklySubmission::STATUS_SUBMITTED, WeeklySubmission::query()->findOrFail($id)->status);
        $this->assertSame(0, ApprovalDecision::query()->count());
    }

    public function test_reject_correct_resubmit_and_approve_keeps_the_whole_history(): void
    {
        $id = $this->submittedWeek();
        $entry = TimeEntry::query()->firstOrFail();

        $this->decide($this->approver, $id, 'reject', ['reason' => 'Faltou o dia 29'])->assertOk()
            ->assertJsonPath('submission.status', 'rejected');
        $this->getJson('/api/approvals', $this->headers($this->approver))->assertOk()->assertExactJson([]);

        // O colaborador vê o motivo na própria folha e corrige.
        $week = $this->getJson('/api/me/weeks/'.self::WEEK, $this->headers($this->employee))->assertOk();
        $week->assertJsonPath('status', 'rejected')
            ->assertJsonPath('decisions.0.decision', 'rejected')
            ->assertJsonPath('decisions.0.reason', 'Faltou o dia 29')
            ->assertJsonPath('decisions.0.approverName', $this->approver->display_name);
        $this->patchJson("/api/entries/{$entry->id}", ['durationSeconds' => 7200], $this->headers($this->employee) + ['If-Match' => 1])->assertOk();
        $this->postJson('/api/me/weeks/'.self::WEEK.'/submit', [], $this->headers($this->employee))
            ->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('status', 'submitted');

        // A nova revisão volta para a caixa do aprovador, com a versão corrigida.
        $inbox = $this->getJson('/api/approvals', $this->headers($this->approver))->assertOk();
        $inbox->assertJsonCount(1)->assertJsonPath('0.revision', 2)->assertJsonPath('0.totalSeconds', 7200);

        $this->decide($this->approver, $id, 'approve', ['revision' => 2])->assertOk();

        $decisions = $this->getJson('/api/me/weeks/'.self::WEEK, $this->headers($this->employee))->json('decisions');
        $this->assertSame(['rejected', 'approved'], array_column($decisions, 'decision'));
        $this->assertSame([1, 2], array_column($decisions, 'revision'));
    }

    public function test_the_first_valid_decision_wins(): void
    {
        $second = $this->member();
        $id = $this->submittedWeek();
        ApproverAssignment::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->employee->id, 'approver_id' => $second->id]);
        WeeklySubmission::query()->findOrFail($id)->approverRows()->create(['revision' => 1, 'approver_id' => $second->id]);

        $this->decide($this->approver, $id, 'approve')->assertOk();
        $this->decide($second, $id, 'reject', ['reason' => 'tarde demais'])->assertStatus(409);
        $this->decide($this->admin, $id, 'reject', ['reason' => 'tarde demais'])->assertStatus(409);

        $this->assertSame(WeeklySubmission::STATUS_APPROVED, WeeklySubmission::query()->findOrFail($id)->status);
        $this->assertSame(1, ApprovalDecision::query()->count());
    }

    public function test_repeating_the_same_decision_request_is_idempotent(): void
    {
        $id = $this->submittedWeek();

        $this->decide($this->approver, $id, 'approve', key: 'decision-key-00000001')->assertOk();
        $this->decide($this->approver, $id, 'approve', key: 'decision-key-00000001')
            ->assertOk()->assertJsonPath('submission.status', 'approved');

        $this->assertSame(1, ApprovalDecision::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'week.approved')->count());
    }

    public function test_deciding_an_outdated_revision_is_a_conflict(): void
    {
        $id = $this->submittedWeek();

        $this->decide($this->approver, $id, 'approve', ['revision' => 99])->assertStatus(409);

        $this->assertSame(WeeklySubmission::STATUS_SUBMITTED, WeeklySubmission::query()->findOrFail($id)->status);
    }

    // ---------- quem não pode ----------

    public function test_manager_without_designation_cannot_decide(): void
    {
        $id = $this->submittedWeek();

        $this->decide($this->stranger, $id, 'approve')->assertStatus(403);

        $this->assertSame(WeeklySubmission::STATUS_SUBMITTED, WeeklySubmission::query()->findOrFail($id)->status);
        $this->assertSame(0, ApprovalDecision::query()->count());
    }

    public function test_a_non_admin_cannot_approve_their_own_week(): void
    {
        $id = $this->submittedWeek();
        // Mesmo que alguém o coloque na lista de aprovadores da própria semana.
        WeeklySubmission::query()->findOrFail($id)->approverRows()->create(['revision' => 1, 'approver_id' => $this->employee->id]);

        $this->decide($this->employee, $id, 'approve')->assertStatus(403);
    }

    public function test_an_admin_can_approve_their_own_week_and_it_is_marked(): void
    {
        $this->role($this->admin, RoleAssignment::ROLE_MEMBER, $this->project);
        $id = $this->submittedWeek($this->admin);

        $this->decide($this->admin, $id, 'approve')->assertOk()->assertJsonPath('submission.status', 'approved');

        $this->assertTrue(ApprovalDecision::query()->firstOrFail()->self_decision);
        $this->assertTrue(AuditEvent::query()->where('action', 'week.approved')->firstOrFail()->context['selfDecision']);
    }

    public function test_a_designation_pointing_to_the_employee_themselves_is_ignored(): void
    {
        ApproverAssignment::query()->delete();
        ApproverAssignment::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->employee->id, 'approver_id' => $this->employee->id]);

        $id = $this->submittedWeek();

        $this->assertSame(0, WeeklySubmission::query()->findOrFail($id)->approverRows()->count());
        $this->decide($this->employee, $id, 'approve')->assertStatus(403);
    }

    public function test_submissions_of_another_tenant_do_not_exist_for_me(): void
    {
        $id = $this->submittedWeek();
        $otherTenant = Tenant::factory()->create();
        $foreignAdmin = $this->member($otherTenant);
        $this->role($foreignAdmin, RoleAssignment::ROLE_ADMIN);

        $this->getJson("/api/approvals/{$id}", $this->headers($foreignAdmin))->assertStatus(404);
        $this->decide($foreignAdmin, $id, 'approve')->assertStatus(404);
        $this->postJson("/api/approvals/{$id}/reopen", ['reason' => 'x'], $this->headers($foreignAdmin))->assertStatus(404);
    }

    public function test_unknown_decision_value_is_rejected(): void
    {
        $id = $this->submittedWeek();

        $this->decide($this->approver, $id, 'maybe')->assertStatus(422);
    }

    // ---------- reabertura ----------

    public function test_admin_reopens_an_approved_week_with_a_reason_and_it_is_audited(): void
    {
        $id = $this->submittedWeek();
        $this->decide($this->approver, $id, 'approve')->assertOk();

        $this->postJson("/api/approvals/{$id}/reopen", ['reason' => 'Esqueceu de lançar o deploy'], $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('submission.status', 'open');

        $submission = WeeklySubmission::query()->findOrFail($id);
        $this->assertNull($submission->approved_at);
        $this->assertNull($submission->approver_id);

        $this->assertSame([ApprovalDecision::APPROVED, ApprovalDecision::REOPENED], ApprovalDecision::query()->orderBy('id')->pluck('decision')->all());
        $event = AuditEvent::query()->where('action', 'week.reopened')->firstOrFail();
        $this->assertSame($this->admin->id, $event->actor_member_id);
        $this->assertSame('Esqueceu de lançar o deploy', $event->context['reason']);

        // A semana volta a aceitar lançamentos e pode ser reenviada (revisão 2).
        $this->postJson('/api/entries', [
            'projectId' => $this->project->devops_project_id,
            'projectName' => $this->project->devops_project_name,
            'workItemId' => 42,
            'localDate' => '2026-09-29',
            'durationSeconds' => 1800,
        ], $this->headers($this->employee))->assertCreated();
        $this->postJson('/api/me/weeks/'.self::WEEK.'/submit', [], $this->headers($this->employee))
            ->assertOk()->assertJsonPath('revision', 2);
    }

    public function test_reopen_needs_a_reason(): void
    {
        $id = $this->submittedWeek();
        $this->decide($this->approver, $id, 'approve')->assertOk();

        $this->postJson("/api/approvals/{$id}/reopen", [], $this->headers($this->admin))->assertStatus(422);
        $this->postJson("/api/approvals/{$id}/reopen", ['reason' => ' '], $this->headers($this->admin))->assertStatus(422);

        $this->assertSame(WeeklySubmission::STATUS_APPROVED, WeeklySubmission::query()->findOrFail($id)->status);
    }

    public function test_only_admins_reopen_and_only_approved_weeks(): void
    {
        $id = $this->submittedWeek();

        // Ainda não aprovada.
        $this->postJson("/api/approvals/{$id}/reopen", ['reason' => 'x'], $this->headers($this->admin))->assertStatus(409);

        $this->decide($this->approver, $id, 'approve')->assertOk();

        // Nem o aprovador designado nem o colaborador reabrem.
        $this->postJson("/api/approvals/{$id}/reopen", ['reason' => 'x'], $this->headers($this->approver))->assertStatus(403);
        $this->postJson("/api/approvals/{$id}/reopen", ['reason' => 'x'], $this->headers($this->employee))->assertStatus(403);
        $this->assertSame(WeeklySubmission::STATUS_APPROVED, WeeklySubmission::query()->findOrFail($id)->status);
    }

    public function test_decisions_require_the_idempotency_key(): void
    {
        $id = $this->submittedWeek();

        $this->postJson("/api/approvals/{$id}/decision", ['decision' => 'approve'], ['Authorization' => $this->headers($this->approver)['Authorization']])
            ->assertStatus(422);
    }
}
