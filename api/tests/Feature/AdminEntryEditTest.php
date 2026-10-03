<?php

namespace Tests\Feature;

use App\Models\ActivityType;
use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\WeeklySubmission;
use App\Models\WorkItemSnapshot;
use App\Services\SessionTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O administrador corrige (ou exclui) lançamentos de qualquer pessoa pelo
 * relatório detalhado, enquanto a semana estiver aberta. Quem não é
 * administrador não tem acesso, e toda correção fica na auditoria.
 */
class AdminEntryEditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Member $member;

    private Member $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-03 10:00:00');

        $this->tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
        $this->project = Project::factory()->for($this->tenant)->create(['devops_project_name' => 'SARC']);
        $this->member = Member::factory()->for($this->tenant)->create(['display_name' => 'Willian']);
        $this->admin = Member::factory()->for($this->tenant)->create();

        RoleAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->member->id,
            'project_id' => $this->project->id,
            'role' => RoleAssignment::ROLE_MEMBER,
        ]);
        RoleAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->admin->id,
            'project_id' => null,
            'role' => RoleAssignment::ROLE_ADMIN,
        ]);
    }

    private function headers(Member $as, int $revision = 1): array
    {
        $session = app(SessionTokenService::class)->issue($this->tenant->id, $as->id);

        return ['Authorization' => "Bearer {$session->token}", 'If-Match' => (string) $revision];
    }

    /** Lançamento de 09:28 a 14:00 (4h32) em 01/09, como o do print. */
    private function entry(array $attributes = []): TimeEntry
    {
        $start = CarbonImmutable::parse('2026-09-01 09:28', 'America/Sao_Paulo');

        return TimeEntry::factory()->create($attributes + [
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'member_id' => $this->member->id,
            'devops_work_item_id' => 15647,
            'local_date' => '2026-09-01',
            'duration_seconds' => 16320,
            'started_at_utc' => $start->utc(),
            'ended_at_utc' => $start->addSeconds(16320)->utc(),
            'billable' => false,
        ]);
    }

    public function test_the_admin_corrects_date_time_activity_and_note_of_someone_elses_entry(): void
    {
        $entry = $this->entry();
        $activity = ActivityType::factory()->for($this->tenant)->create(['name' => 'Desenvolvimento']);

        $this->patchJson("/api/admin/entries/{$entry->id}", [
            'localDate' => '2026-09-08',
            'startTime' => '10:00',
            'durationSeconds' => 3 * 3600,
            'activityTypeId' => $activity->id,
            'note' => 'Corrigido pelo DP',
        ], $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('localDate', '2026-09-08')
            ->assertJsonPath('startTime', '10:00')
            ->assertJsonPath('endTime', '13:00')
            ->assertJsonPath('durationSeconds', 10800)
            ->assertJsonPath('activityTypeId', (string) $activity->id)
            ->assertJsonPath('note', 'Corrigido pelo DP')
            ->assertJsonPath('revision', 2);

        $entry->refresh();
        $this->assertSame($this->member->id, $entry->member_id);
        $this->assertSame('2026-09-07', substr((string) $entry->week_start_date, 0, 10));

        $audit = AuditEvent::query()->where('action', 'time_entry.admin_updated')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->actor_member_id);
        $this->assertSame('2026-09-01', $audit->context['before']['localDate']);
        $this->assertSame('09:28', $audit->context['before']['startTime']);
        $this->assertSame('2026-09-08', $audit->context['after']['localDate']);

        // A grade detalhada traz a revisão e diz que o administrador pode editar.
        $this->getJson('/api/reports/time/detail?from=2026-09-01&to=2026-09-30', $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('canEditEntries', true)
            ->assertJsonPath('rows.0.revision', 2);
    }

    public function test_the_admin_moves_the_entry_to_another_work_item_of_another_project(): void
    {
        $entry = $this->entry();
        $other = Project::factory()->for($this->tenant)->create(['devops_project_name' => 'TopDoc']);

        $this->patchJson("/api/admin/entries/{$entry->id}", [
            'workItemId' => 777,
            'projectId' => $other->devops_project_id,
            'projectName' => 'TopDoc',
            'title' => 'Tela de login',
            'workItemType' => 'Task',
        ], $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('workItemId', 777)
            ->assertJsonPath('startTime', '09:28');

        $entry->refresh();
        $this->assertSame($other->id, $entry->project_id);
        $this->assertTrue(WorkItemSnapshot::query()->where('devops_work_item_id', 777)->where('title', 'Tela de login')->exists());
    }

    public function test_only_admins_can_correct_or_delete_entries_of_others(): void
    {
        $entry = $this->entry();

        $this->patchJson("/api/admin/entries/{$entry->id}", ['note' => 'x'], $this->headers($this->member))->assertForbidden();
        $this->deleteJson("/api/admin/entries/{$entry->id}", [], $this->headers($this->member))->assertForbidden();
        $this->getJson('/api/reports/time/detail?from=2026-09-01&to=2026-09-30', $this->headers($this->member))
            ->assertJsonPath('canEditEntries', false);
    }

    public function test_locked_weeks_stale_revisions_and_daily_limits_are_refused(): void
    {
        $entry = $this->entry();

        $this->patchJson("/api/admin/entries/{$entry->id}", ['note' => 'x'], $this->headers($this->admin, 5))->assertStatus(409);

        // Muda para um dia que já tem 22h: passa do limite diário de 24h.
        $this->entry(['local_date' => '2026-09-02', 'started_at_utc' => null, 'ended_at_utc' => null, 'duration_seconds' => 22 * 3600]);
        $this->patchJson("/api/admin/entries/{$entry->id}", ['localDate' => '2026-09-02', 'startTime' => null], $this->headers($this->admin))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('durationSeconds');

        // Passa da meia-noite.
        $this->patchJson("/api/admin/entries/{$entry->id}", ['startTime' => '22:00'], $this->headers($this->admin))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('startTime');

        // Semana aprovada (na data atual ou na nova): bloqueada.
        WeeklySubmission::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->member->id,
            'week_start_date' => '2026-09-14',
            'status' => WeeklySubmission::STATUS_APPROVED,
        ]);
        $this->patchJson("/api/admin/entries/{$entry->id}", ['localDate' => '2026-09-15'], $this->headers($this->admin))
            ->assertStatus(409);

        WeeklySubmission::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->member->id,
            'week_start_date' => '2026-08-31',
            'status' => WeeklySubmission::STATUS_SUBMITTED,
        ]);
        $this->patchJson("/api/admin/entries/{$entry->id}", ['note' => 'x'], $this->headers($this->admin))->assertStatus(409);
        $this->deleteJson("/api/admin/entries/{$entry->id}", [], $this->headers($this->admin))->assertStatus(409);

        $this->assertSame(1, $entry->refresh()->revision);
    }

    public function test_the_admin_deletes_a_wrong_entry(): void
    {
        $entry = $this->entry();

        $this->deleteJson("/api/admin/entries/{$entry->id}", [], $this->headers($this->admin))->assertNoContent();

        $this->assertSoftDeleted($entry);
        $audit = AuditEvent::query()->where('action', 'time_entry.admin_deleted')->firstOrFail();
        $this->assertSame($this->member->id, $audit->context['memberId']);
        $this->assertSame(16320, $audit->context['before']['durationSeconds']);

        // Lançamento de outra organização: 404.
        $foreign = TimeEntry::factory()->create();
        $this->deleteJson("/api/admin/entries/{$foreign->id}", [], $this->headers($this->admin))->assertNotFound();
    }
}
