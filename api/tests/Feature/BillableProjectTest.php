<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Faturável" é opção de cada projeto (só os que cobram o cliente por hora).
 * Projeto sem a opção: nenhuma hora é faturável — nem nova, nem antiga — e
 * os relatórios não a contam.
 */
class BillableProjectTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Member $member;

    private Member $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-30 15:00:00');

        $this->tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
        $this->project = Project::factory()->for($this->tenant)->create(['devops_project_name' => 'McCain']);
        $this->member = Member::factory()->for($this->tenant)->create();
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

    private function headers(?Member $as = null, string $key = 'billable-key-0000001'): array
    {
        $session = app(SessionTokenService::class)->issue($this->tenant->id, ($as ?? $this->member)->id);

        return ['Authorization' => "Bearer {$session->token}", 'Idempotency-Key' => $key];
    }

    private function create(string $key)
    {
        return $this->postJson('/api/entries', [
            'projectId' => $this->project->devops_project_id,
            'projectName' => $this->project->devops_project_name,
            'workItemId' => 42,
            'localDate' => '2026-09-30',
            'durationSeconds' => 3600,
            'billable' => true,
        ], $this->headers(key: $key));
    }

    public function test_projects_start_without_billable_and_entries_never_become_billable(): void
    {
        $this->create('entry-key-0000000001')->assertCreated()->assertJsonPath('billable', false);
        $this->assertFalse(TimeEntry::query()->firstOrFail()->billable);

        $this->getJson('/api/me', $this->headers())->assertJsonPath('billableProjectIds', []);
        $this->getJson('/api/settings', $this->headers($this->admin))->assertJsonPath('projects.0.usesBillable', false);
    }

    public function test_the_admin_turns_billable_on_for_a_project(): void
    {
        $this->patchJson("/api/settings/projects/{$this->project->id}", ['usesBillable' => true], $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('projects.0.usesBillable', true)
            ->assertJsonPath('projects.0.enabled', true);
        $this->assertTrue(AuditEvent::query()->where('action', 'settings.project_billable_changed')->exists());

        $this->getJson('/api/me', $this->headers())->assertJsonPath('billableProjectIds', [$this->project->devops_project_id]);
        $this->create('entry-key-0000000002')->assertCreated()->assertJsonPath('billable', true);
    }

    public function test_reports_ignore_old_billable_marks_of_projects_without_billable(): void
    {
        // Lançamento antigo marcado como faturável (antes da opção existir).
        TimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'member_id' => $this->member->id,
            'local_date' => '2026-09-29',
            'duration_seconds' => 7200,
        ]);
        TimeEntry::query()->update(['billable' => true]);

        $query = '/api/reports/time?from=2026-09-28&to=2026-10-04';
        $this->getJson($query, $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('totals.billableSeconds', 0)
            ->assertJsonPath('totals.nonBillableSeconds', 7200);
        $this->getJson($query.'&billable=true', $this->headers($this->admin))->assertJsonPath('totals.totalSeconds', 0);
        $this->getJson($query.'&billable=false', $this->headers($this->admin))->assertJsonPath('totals.totalSeconds', 7200);
        $this->getJson('/api/reports/options', $this->headers($this->admin))->assertJsonPath('billableInUse', false);
        $this->assertFalse(collect($this->getJson('/api/me/weeks/2026-09-28', $this->headers())->json('entries'))->first()['billable']);

        $this->project->update(['uses_billable' => true]);
        $this->getJson($query, $this->headers($this->admin))->assertJsonPath('totals.billableSeconds', 7200);
        $this->getJson('/api/reports/options', $this->headers($this->admin))->assertJsonPath('billableInUse', true);
    }
}
