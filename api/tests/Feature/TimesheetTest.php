<?php

namespace Tests\Feature;

use App\Models\ActivityType;
use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use App\Models\WeeklySubmission;
use App\Models\WorkItemSnapshot;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T025 — folha semanal (US2): totais, semana que atravessa o mês, envio com
 * versão dos lançamentos, bloqueio de edição, idempotência e isolamento.
 *
 * Semana de referência: seg 2026-09-28 a dom 2026-10-04 (atravessa setembro/outubro).
 */
class TimesheetTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = '2026-09-28';

    private Tenant $tenant;

    private Project $project;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
        $this->project = Project::factory()->for($this->tenant)->create();
        $this->member = Member::factory()->for($this->tenant)->create();
        RoleAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->member->id,
            'project_id' => $this->project->id,
            'role' => RoleAssignment::ROLE_MEMBER,
        ]);

        // O envio exige alguém que possa decidir; na prática o primeiro membro da
        // organização vira admin (IdentityProvisioningService).
        RoleAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => Member::factory()->for($this->tenant)->create()->id,
            'project_id' => null,
            'role' => RoleAssignment::ROLE_ADMIN,
        ]);

        // As datas dos testes são fixas; congelar o "agora" evita que a janela
        // retroativa (relativa ao dia real) faça os testes quebrarem com o tempo.
        $this->travelTo('2026-09-30 15:00:00');
    }

    private function headers(?Member $member = null, ?Tenant $tenant = null, string $key = 'submit-key-000000001'): array
    {
        $session = app(SessionTokenService::class)->issue(($tenant ?? $this->tenant)->id, ($member ?? $this->member)->id);

        return ['Authorization' => "Bearer {$session->token}", 'Idempotency-Key' => $key];
    }

    private function entry(string $date, int $seconds, array $overrides = []): TimeEntry
    {
        return TimeEntry::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'member_id' => $this->member->id,
            'local_date' => $date,
            'duration_seconds' => $seconds,
        ], $overrides));
    }

    private function submitWeek(string $week = self::WEEK, string $key = 'submit-key-000000001')
    {
        return $this->postJson("/api/me/weeks/{$week}/submit", [], $this->headers(key: $key));
    }

    private function manualEntryPayload(string $date, int $seconds = 1800): array
    {
        return [
            'projectId' => $this->project->devops_project_id,
            'projectName' => $this->project->devops_project_name,
            'workItemId' => 42,
            'localDate' => $date,
            'durationSeconds' => $seconds,
        ];
    }

    // ---------- consulta ----------

    public function test_week_totals_match_the_entry_details(): void
    {
        $this->entry('2026-09-28', 3600, ['devops_work_item_id' => 10]);
        $this->entry('2026-09-28', 1800, ['devops_work_item_id' => 11]);
        $this->entry('2026-09-30', 7200, ['devops_work_item_id' => 10]);

        $response = $this->getJson('/api/me/weeks/'.self::WEEK, $this->headers())->assertOk();

        $response->assertJson([
            'weekStartDate' => self::WEEK,
            'weekEndDate' => '2026-10-04',
            'status' => 'open',
            'totalSeconds' => 12600,
        ]);
        $this->assertCount(7, $response->json('days'));
        $this->assertSame(
            [5400, 0, 7200, 0, 0, 0, 0],
            array_column($response->json('days'), 'totalSeconds'),
        );
        $this->assertSame(
            $response->json('totalSeconds'),
            array_sum(array_column($response->json('entries'), 'durationSeconds')),
        );
        $this->assertSame(
            $response->json('totalSeconds'),
            array_sum(array_column($response->json('days'), 'totalSeconds')),
        );
    }

    public function test_entries_carry_display_details_from_the_latest_snapshot_and_activity(): void
    {
        $type = ActivityType::factory()->for($this->tenant)->create(['name' => 'Testes', 'color' => '#F8D48C']);
        $this->entry('2026-09-29', 600, ['devops_work_item_id' => 10, 'activity_type_id' => $type->id]);
        WorkItemSnapshot::query()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'devops_work_item_id' => 10, 'title' => 'Título antigo', 'work_item_type' => 'Task', 'captured_at' => now()->subDay()]);
        WorkItemSnapshot::query()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'devops_work_item_id' => 10, 'title' => 'Título novo', 'work_item_type' => 'Bug', 'captured_at' => now()]);

        $entry = $this->getJson('/api/me/weeks/'.self::WEEK, $this->headers())->assertOk()->json('entries.0');

        $this->assertSame('Título novo', $entry['workItemTitle']);
        $this->assertSame('Bug', $entry['workItemType']);
        $this->assertSame('Testes', $entry['activityTypeName']);
        $this->assertSame('#F8D48C', $entry['activityTypeColor']);
        $this->assertSame($this->project->devops_project_name, $entry['projectName']);
    }

    public function test_week_crossing_the_month_attributes_each_day_to_its_own_month(): void
    {
        $this->entry('2026-09-30', 3600);
        $this->entry('2026-10-01', 1800);
        $this->entry('2026-10-04', 900);

        $week = $this->getJson('/api/me/weeks/'.self::WEEK, $this->headers())->assertOk();
        $september = $this->getJson('/api/me/months/2026-09', $this->headers())->assertOk();
        $october = $this->getJson('/api/me/months/2026-10', $this->headers())->assertOk();

        $this->assertSame(3600, $september->json('totalSeconds'));
        $this->assertSame(2700, $october->json('totalSeconds'));
        $this->assertSame(
            $week->json('totalSeconds'),
            $september->json('totalSeconds') + $october->json('totalSeconds'),
        );
        $this->assertSame(['2026-09-30'], array_column($september->json('days'), 'date'));
        $this->assertSame(['2026-10-01', '2026-10-04'], array_column($october->json('days'), 'date'));
        // A unidade de aprovação continua sendo a semana inteira, nos dois meses.
        $this->assertContains(self::WEEK, array_column($september->json('weeks'), 'weekStartDate'));
        $this->assertContains(self::WEEK, array_column($october->json('weeks'), 'weekStartDate'));
    }

    public function test_deleted_entries_do_not_count(): void
    {
        $this->entry('2026-09-28', 3600);
        $this->entry('2026-09-28', 1800)->delete();

        $this->getJson('/api/me/weeks/'.self::WEEK, $this->headers())
            ->assertOk()
            ->assertJson(['totalSeconds' => 3600]);
    }

    public function test_week_start_must_be_a_monday_and_month_must_be_valid(): void
    {
        $this->getJson('/api/me/weeks/2026-09-30', $this->headers())->assertStatus(422);
        $this->getJson('/api/me/months/2026-13', $this->headers())->assertStatus(404);
        $this->getJson('/api/me/weeks/abc', $this->headers())->assertStatus(404);
    }

    public function test_only_my_own_entries_appear_and_other_tenants_never_do(): void
    {
        $this->entry('2026-09-28', 3600);

        $colleague = Member::factory()->for($this->tenant)->create();
        TimeEntry::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'member_id' => $colleague->id, 'local_date' => '2026-09-28', 'duration_seconds' => 999]);

        $otherTenant = Tenant::factory()->create();
        $stranger = Member::factory()->for($otherTenant)->create();
        TimeEntry::factory()->create(['tenant_id' => $otherTenant->id, 'member_id' => $stranger->id, 'local_date' => '2026-09-28', 'duration_seconds' => 555]);

        $this->getJson('/api/me/weeks/'.self::WEEK, $this->headers())->assertJson(['totalSeconds' => 3600]);
        $this->getJson('/api/me/weeks/'.self::WEEK, $this->headers($stranger, $otherTenant))->assertJson(['totalSeconds' => 555]);
    }

    public function test_requires_a_session(): void
    {
        $this->getJson('/api/me/weeks/'.self::WEEK)->assertStatus(401);
        $this->postJson('/api/me/weeks/'.self::WEEK.'/submit')->assertStatus(401);
    }

    // ---------- envio ----------

    public function test_submit_moves_the_week_to_submitted_and_records_the_version_of_the_entries(): void
    {
        $a = $this->entry('2026-09-28', 3600);
        $b = $this->entry('2026-09-29', 1800);

        $response = $this->submitWeek()->assertOk();

        $response->assertJson(['status' => 'submitted', 'revision' => 1, 'totalSeconds' => 5400]);
        $this->assertNotNull($response->json('submittedAt'));

        $submission = WeeklySubmission::query()->firstOrFail();
        $this->assertSame(self::WEEK, $submission->week_start_date);
        $revision = $submission->revisions()->firstOrFail();
        $this->assertSame(5400, $revision->total_seconds);
        $this->assertSame(2, $revision->entry_count);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($revision->entries_snapshot, 'id'));
        $this->assertSame(1, $revision->entries_snapshot[0]['revision']);
    }

    public function test_submit_is_audited(): void
    {
        $this->entry('2026-09-28', 3600);

        $this->submitWeek()->assertOk();

        $event = AuditEvent::query()->where('action', 'week.submitted')->firstOrFail();
        $this->assertSame($this->member->id, $event->actor_member_id);
        $this->assertSame(self::WEEK, $event->context['weekStartDate']);
        $this->assertSame(3600, $event->context['totalSeconds']);
    }

    public function test_repeating_the_same_submit_returns_the_same_result_without_a_new_revision(): void
    {
        $this->entry('2026-09-28', 3600);

        $this->submitWeek(key: 'same-key-0000000001')->assertOk();
        $this->submitWeek(key: 'same-key-0000000001')->assertOk()->assertJson(['revision' => 1]);

        $this->assertSame(1, WeeklySubmission::query()->firstOrFail()->revisions()->count());
    }

    public function test_submitting_an_already_submitted_week_with_a_new_key_is_a_conflict(): void
    {
        $this->entry('2026-09-28', 3600);
        $this->submitWeek(key: 'first-key-000000001')->assertOk();

        $this->submitWeek(key: 'second-key-00000001')->assertStatus(409);
    }

    public function test_cannot_submit_a_week_without_entries(): void
    {
        $this->submitWeek()->assertStatus(422);
        $this->assertSame(0, WeeklySubmission::query()->count());
    }

    public function test_cannot_submit_while_a_timer_is_running(): void
    {
        $this->entry('2026-09-28', 3600);
        TimerSession::factory()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'member_id' => $this->member->id]);

        $this->submitWeek()->assertStatus(409);
        $this->assertSame(0, WeeklySubmission::query()->count());
    }

    public function test_submit_requires_the_idempotency_key(): void
    {
        $this->entry('2026-09-28', 3600);

        $this->postJson('/api/me/weeks/'.self::WEEK.'/submit', [], ['Authorization' => $this->headers()['Authorization']])
            ->assertStatus(422);
    }

    // ---------- bloqueio de edição ----------

    public function test_a_submitted_week_blocks_creating_editing_and_deleting_entries(): void
    {
        $entry = $this->entry('2026-09-28', 3600);
        $this->submitWeek()->assertOk();

        $this->postJson('/api/entries', $this->manualEntryPayload('2026-09-29'), $this->headers(key: 'entry-key-0000000001'))
            ->assertStatus(409);
        $this->patchJson("/api/entries/{$entry->id}", ['durationSeconds' => 60], $this->headers() + ['If-Match' => 1])
            ->assertStatus(409);
        $this->deleteJson("/api/entries/{$entry->id}", [], $this->headers())
            ->assertStatus(409);

        $this->assertSame(3600, $entry->fresh()->duration_seconds);
        $this->assertNull($entry->fresh()->deleted_at);
        $this->assertSame(1, TimeEntry::query()->count());
    }

    public function test_stopping_a_timer_into_a_submitted_week_is_a_conflict_and_the_timer_stays_active(): void
    {
        $this->entry('2026-09-28', 3600);
        $this->submitWeek()->assertOk();

        // Timer iniciado depois do envio (semana bloqueada) e que corre até hoje-fictício.
        $timer = TimerSession::factory()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'member_id' => $this->member->id,
            'started_at_utc' => '2026-09-30 13:00:00',
        ]);
        $this->travelTo('2026-09-30 14:00:00');

        $this->postJson('/api/me/timer/stop', ['timerId' => $timer->id], $this->headers(key: 'stop-key-00000000001'))
            ->assertStatus(409);

        $this->assertSame(TimerSession::STATUS_ACTIVE, $timer->fresh()->status);
        $this->assertSame(1, TimeEntry::query()->count());
    }

    public function test_other_weeks_stay_editable_when_one_week_is_submitted(): void
    {
        $this->entry('2026-09-28', 3600);
        $this->submitWeek()->assertOk();

        $this->travelTo('2026-10-06 15:00:00');
        $this->postJson('/api/entries', $this->manualEntryPayload('2026-10-05'), $this->headers(key: 'entry-key-0000000002'))
            ->assertCreated();
    }

    public function test_someone_elses_submitted_week_does_not_lock_mine(): void
    {
        $colleague = Member::factory()->for($this->tenant)->create();
        WeeklySubmission::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $colleague->id, 'week_start_date' => self::WEEK]);

        $this->travelTo('2026-09-30 15:00:00');
        $this->postJson('/api/entries', $this->manualEntryPayload('2026-09-29'), $this->headers(key: 'entry-key-0000000003'))
            ->assertCreated();
    }

    public function test_a_rejected_week_can_be_corrected_and_resubmitted_keeping_the_history(): void
    {
        $entry = $this->entry('2026-09-28', 3600);
        $this->submitWeek(key: 'first-key-000000001')->assertOk();
        // A rejeição é da US3; aqui só o estado resultante.
        WeeklySubmission::query()->firstOrFail()->update(['status' => WeeklySubmission::STATUS_REJECTED]);

        $this->patchJson("/api/entries/{$entry->id}", ['durationSeconds' => 5400], $this->headers() + ['If-Match' => 1])
            ->assertOk();
        $this->submitWeek(key: 'second-key-00000001')
            ->assertOk()
            ->assertJson(['status' => 'submitted', 'revision' => 2, 'totalSeconds' => 5400]);

        $revisions = WeeklySubmission::query()->firstOrFail()->revisions()->orderBy('revision')->get();
        $this->assertSame([3600, 5400], $revisions->pluck('total_seconds')->all());
        $this->assertSame(2, $revisions->last()->entries_snapshot[0]['revision']);
    }

    public function test_an_approved_week_is_locked(): void
    {
        $entry = $this->entry('2026-09-28', 3600);
        WeeklySubmission::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->member->id,
            'week_start_date' => self::WEEK,
            'status' => WeeklySubmission::STATUS_APPROVED,
        ]);

        $this->deleteJson("/api/entries/{$entry->id}", [], $this->headers())->assertStatus(409);
    }

    // ---------- cancelar envio ----------

    private function recallWeek(string $key = 'recall-key-00000001', ?Member $member = null)
    {
        return $this->postJson('/api/me/weeks/'.self::WEEK.'/recall', [], $this->headers($member, key: $key));
    }

    private function admin(): Member
    {
        return Member::query()->findOrFail(
            RoleAssignment::query()->where('role', RoleAssignment::ROLE_ADMIN)->value('member_id'),
        );
    }

    public function test_recalling_a_submitted_week_reopens_it_for_new_entries_and_a_new_submit(): void
    {
        $this->entry('2026-09-28', 3600);
        $this->submitWeek(key: 'first-key-000000001')->assertOk();

        $this->recallWeek()->assertOk()->assertJson(['status' => 'open', 'revision' => 1]);

        $this->postJson('/api/entries', $this->manualEntryPayload('2026-10-02', 1800), $this->headers(key: 'entry-key-0000000001'))
            ->assertCreated();
        $this->submitWeek(key: 'second-key-00000001')
            ->assertOk()
            ->assertJson(['status' => 'submitted', 'revision' => 2, 'totalSeconds' => 5400]);

        $event = AuditEvent::query()->where('action', 'week.recalled')->firstOrFail();
        $this->assertSame($this->member->id, $event->actor_member_id);
        $this->assertSame(self::WEEK, $event->context['weekStartDate']);
        $this->assertSame(1, $event->context['revision']);
    }

    public function test_repeating_the_same_recall_returns_the_same_result(): void
    {
        $this->entry('2026-09-28', 3600);
        $this->submitWeek()->assertOk();

        $this->recallWeek(key: 'same-recall-0000001')->assertOk();
        $this->recallWeek(key: 'same-recall-0000001')->assertOk()->assertJson(['status' => 'open']);
        $this->recallWeek(key: 'other-recall-000001')->assertStatus(409);

        $this->assertSame(1, AuditEvent::query()->where('action', 'week.recalled')->count());
    }

    public function test_only_a_submitted_week_can_be_recalled(): void
    {
        // Nunca enviada.
        $this->recallWeek(key: 'recall-open-0000001')->assertStatus(409);

        $submission = WeeklySubmission::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->member->id,
            'week_start_date' => self::WEEK,
            'status' => WeeklySubmission::STATUS_REJECTED,
        ]);
        $this->recallWeek(key: 'recall-rejected-001')->assertStatus(409);

        $submission->update(['status' => WeeklySubmission::STATUS_APPROVED]);
        $this->recallWeek(key: 'recall-approved-001')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Semana aprovada: peça a um administrador para reabrir.');
        $this->assertSame(WeeklySubmission::STATUS_APPROVED, $submission->fresh()->status);
    }

    public function test_a_recalled_week_leaves_the_approval_queue_and_a_late_decision_is_a_conflict(): void
    {
        $this->entry('2026-09-28', 3600);
        $this->submitWeek()->assertOk();
        $submission = WeeklySubmission::query()->firstOrFail();
        $admin = $this->admin();

        $this->getJson('/api/approvals', $this->headers($admin))->assertOk()->assertJsonCount(1);

        $this->recallWeek()->assertOk();

        $this->getJson('/api/approvals', $this->headers($admin))->assertOk()->assertJsonCount(0);
        $this->postJson("/api/approvals/{$submission->id}/decision", ['decision' => 'approve', 'revision' => 1], $this->headers($admin, key: 'decide-key-00000001'))
            ->assertStatus(409);
        $this->assertSame(WeeklySubmission::STATUS_OPEN, $submission->fresh()->status);
    }

    public function test_recall_only_touches_my_own_week_and_requires_the_idempotency_key(): void
    {
        $this->entry('2026-09-28', 3600);
        $this->submitWeek()->assertOk();
        $colleague = Member::factory()->for($this->tenant)->create();

        // O colega não tem semana enviada; a minha continua enviada.
        $this->recallWeek(member: $colleague)->assertStatus(409);
        $this->assertSame(WeeklySubmission::STATUS_SUBMITTED, WeeklySubmission::query()->firstOrFail()->status);

        $this->postJson('/api/me/weeks/'.self::WEEK.'/recall', [], ['Authorization' => $this->headers()['Authorization']])
            ->assertStatus(422);
    }

    // ---------- dono do lançamento ----------

    public function test_a_colleague_cannot_edit_or_delete_my_entry(): void
    {
        $entry = $this->entry('2026-09-28', 3600);
        $colleague = Member::factory()->for($this->tenant)->create();

        $this->patchJson("/api/entries/{$entry->id}", ['durationSeconds' => 60], $this->headers($colleague) + ['If-Match' => 1])
            ->assertStatus(404);
        $this->deleteJson("/api/entries/{$entry->id}", [], $this->headers($colleague))
            ->assertStatus(404);

        $this->assertSame(3600, $entry->fresh()->duration_seconds);
        $this->assertNull($entry->fresh()->deleted_at);
    }
}
