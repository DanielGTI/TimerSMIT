<?php

namespace Tests\Feature;

use App\Models\ActivityType;
use App\Models\ApproverAssignment;
use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\Policy;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use App\Models\WeeklySubmission;
use App\Services\IdentityProvisioningService;
use App\Services\SessionTokenService;
use App\Services\VerifiedDevOpsIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T038 — configuração da organização (US5): só administrador; regras
 * versionadas que valem para operações novas sem reclassificar o histórico;
 * projetos, atividades, papéis e aprovadores.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Member $admin;

    private Member $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
        $this->project = Project::factory()->for($this->tenant)->create(['devops_project_name' => 'Projeto A']);
        $this->admin = $this->member('Ana Admin');
        $this->employee = $this->member('Eva Colaboradora');

        $this->role($this->admin, RoleAssignment::ROLE_ADMIN);
        $this->role($this->employee, RoleAssignment::ROLE_MEMBER, $this->project);

        $this->travelTo('2026-09-30 15:00:00');
    }

    private function member(string $name, ?Tenant $tenant = null): Member
    {
        return Member::factory()->for($tenant ?? $this->tenant)->create(['display_name' => $name]);
    }

    private function role(Member $member, string $role, ?Project $project = null): RoleAssignment
    {
        return RoleAssignment::factory()->create([
            'tenant_id' => $member->tenant_id,
            'member_id' => $member->id,
            'project_id' => $project?->id,
            'role' => $role,
        ]);
    }

    private function headers(Member $member): array
    {
        $session = app(SessionTokenService::class)->issue($member->tenant_id, $member->id);

        return ['Authorization' => "Bearer {$session->token}", 'Idempotency-Key' => (string) Str::uuid()];
    }

    private function asAdmin(string $method, string $uri, array $data = [])
    {
        return $this->json($method, '/api/settings'.$uri, $data, $this->headers($this->admin));
    }

    private function policy(array $overrides = []): array
    {
        return array_merge([
            'durationIncrementMinutes' => 1,
            'dailyLimitHours' => 24,
            'retroactiveWindowDays' => 30,
            'commentRequired' => false,
        ], $overrides);
    }

    private function logTime(Member $as, array $overrides = [])
    {
        return $this->postJson('/api/entries', array_merge([
            'projectId' => $this->project->devops_project_id,
            'projectName' => $this->project->devops_project_name,
            'workItemId' => 42,
            'localDate' => '2026-09-30',
            'durationSeconds' => 1800,
        ], $overrides), $this->headers($as));
    }

    // ---------- acesso ----------

    public function test_only_administrators_can_use_settings(): void
    {
        $manager = $this->member('Gil Gerente');
        $this->role($manager, RoleAssignment::ROLE_MANAGER);
        $projectAdmin = $this->member('Admin de um projeto só');
        $this->role($projectAdmin, RoleAssignment::ROLE_ADMIN, $this->project);

        foreach ([$this->employee, $manager, $projectAdmin] as $who) {
            $this->getJson('/api/settings', $this->headers($who))->assertStatus(403);
            $this->putJson('/api/settings/policy', $this->policy(), $this->headers($who))->assertStatus(403);
            $this->postJson('/api/settings/role-assignments', ['memberId' => $who->id, 'role' => 'admin'], $this->headers($who))->assertStatus(403);
        }

        $this->getJson('/api/settings')->assertStatus(401);
        $this->assertSame(0, Policy::query()->count());
    }

    public function test_overview_shows_defaults_members_roles_and_the_default_activities(): void
    {
        $overview = $this->asAdmin('GET', '')->assertOk();

        $overview->assertJsonPath('organization.timezone', 'America/Sao_Paulo')
            ->assertJsonPath('policy.version', 0)
            ->assertJsonPath('policy.durationIncrementMinutes', 1)
            ->assertJsonPath('policy.dailyLimitHours', 24)
            ->assertJsonPath('policy.retroactiveWindowDays', 30)
            ->assertJsonPath('policy.commentRequired', false);
        $this->assertCount(12, $overview->json('activityTypes'));
        $this->assertSame(['Projeto A'], array_column($overview->json('projects'), 'name'));

        $eva = collect($overview->json('members'))->firstWhere('name', 'Eva Colaboradora');
        $this->assertSame([['role' => 'member', 'projectName' => 'Projeto A']], array_map(fn ($r) => ['role' => $r['role'], 'projectName' => $r['projectName']], $eva['roles']));
    }

    // ---------- regras de lançamento ----------

    public function test_a_policy_change_creates_a_new_version_and_keeps_the_old_one(): void
    {
        $this->asAdmin('PUT', '/policy', $this->policy(['dailyLimitHours' => 8]))->assertOk()->assertJsonPath('policy.version', 1);
        $this->travel(1)->minutes();
        $this->asAdmin('PUT', '/policy', $this->policy(['dailyLimitHours' => 10, 'commentRequired' => true]))
            ->assertOk()
            ->assertJsonPath('policy.version', 2)
            ->assertJsonPath('policy.dailyLimitHours', 10)
            ->assertJsonPath('policy.commentRequired', true);

        $this->assertSame([8, 10], Policy::query()->orderBy('version')->pluck('daily_limit_hours')->all());

        $event = AuditEvent::query()->where('action', 'settings.policy_updated')->orderByDesc('id')->firstOrFail();
        $this->assertSame($this->admin->id, $event->actor_member_id);
        $this->assertSame(8, $event->context['before']['dailyLimitHours']);
        $this->assertSame(10, $event->context['after']['dailyLimitHours']);
    }

    public function test_policy_values_are_validated(): void
    {
        foreach ([
            ['durationIncrementMinutes' => 7],
            ['dailyLimitHours' => 0],
            ['dailyLimitHours' => 25],
            ['retroactiveWindowDays' => -1],
            ['retroactiveWindowDays' => 366],
            ['commentRequired' => 'talvez'],
        ] as $invalid) {
            $this->asAdmin('PUT', '/policy', $this->policy($invalid))->assertStatus(422);
        }

        $this->asAdmin('PUT', '/policy', ['dailyLimitHours' => 8])->assertStatus(422);
        $this->assertSame(0, Policy::query()->count());
    }

    public function test_required_comment_is_enforced_from_the_change_on_and_history_is_untouched(): void
    {
        $before = $this->logTime($this->employee)->assertCreated()->json();

        $this->asAdmin('PUT', '/policy', $this->policy(['commentRequired' => true]))->assertOk();

        $this->logTime($this->employee)->assertStatus(422);
        $this->logTime($this->employee, ['note' => 'Reunião'])->assertCreated();

        // O lançamento antigo continua exatamente como era.
        $this->assertNull(TimeEntry::query()->findOrFail($before['id'])->note);
        $this->assertSame(1, TimeEntry::query()->findOrFail($before['id'])->revision);

        $this->asAdmin('PUT', '/policy', $this->policy(['commentRequired' => false]))->assertOk();
        $this->logTime($this->employee)->assertCreated();
    }

    public function test_editing_does_not_bypass_the_current_rules(): void
    {
        $entry = $this->logTime($this->employee, ['note' => 'ok'])->assertCreated()->json();
        $this->asAdmin('PUT', '/policy', $this->policy(['commentRequired' => true, 'durationIncrementMinutes' => 15, 'dailyLimitHours' => 2]))->assertOk();

        // Duração fora do incremento, total do dia acima do limite.
        $this->patchJson("/api/entries/{$entry['id']}", ['durationSeconds' => 20 * 60], $this->headers($this->employee) + ['If-Match' => 1])->assertStatus(422);
        $this->patchJson("/api/entries/{$entry['id']}", ['durationSeconds' => 3 * 3600], $this->headers($this->employee) + ['If-Match' => 1])->assertStatus(422);
        // Dentro das regras, passa.
        $this->patchJson("/api/entries/{$entry['id']}", ['durationSeconds' => 3600], $this->headers($this->employee) + ['If-Match' => 1])->assertOk();
    }

    public function test_daily_limit_and_retroactive_window_follow_the_new_values(): void
    {
        $this->asAdmin('PUT', '/policy', $this->policy(['dailyLimitHours' => 1, 'retroactiveWindowDays' => 5]))->assertOk();

        $this->logTime($this->employee, ['durationSeconds' => 3600])->assertCreated();
        $this->logTime($this->employee, ['durationSeconds' => 60])->assertStatus(422);
        $this->logTime($this->employee, ['localDate' => '2026-09-20'])->assertStatus(422);
        $this->logTime($this->employee, ['localDate' => '2026-09-26'])->assertCreated();
    }

    public function test_manual_entries_must_follow_the_duration_increment_but_timers_are_exempt(): void
    {
        $this->asAdmin('PUT', '/policy', $this->policy(['durationIncrementMinutes' => 15]))->assertOk();

        $this->logTime($this->employee, ['durationSeconds' => 20 * 60])->assertStatus(422);
        $this->logTime($this->employee, ['durationSeconds' => 30 * 60])->assertCreated();

        // Timer guarda os segundos exatos: nada de arredondar o que foi medido.
        $timer = TimerSession::factory()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'member_id' => $this->employee->id,
            'started_at_utc' => now()->subSeconds(437),
        ]);
        $this->postJson('/api/me/timer/stop', ['timerId' => $timer->id], $this->headers($this->employee))
            ->assertOk()
            ->assertJsonPath('0.durationSeconds', 437);
    }

    // ---------- fuso ----------

    public function test_changing_the_timezone_keeps_historical_entries_as_they_were(): void
    {
        $old = $this->logTime($this->employee)->assertCreated()->json('id');

        $this->asAdmin('PUT', '/organization', ['timezone' => 'Europe/Lisbon'])->assertOk()->assertJsonPath('organization.timezone', 'Europe/Lisbon');

        $new = $this->logTime($this->employee, ['localDate' => '2026-09-29'])->assertCreated()->json('id');

        $this->assertSame(['America/Sao_Paulo', '2026-09-30'], [TimeEntry::query()->findOrFail($old)->timezone, TimeEntry::query()->findOrFail($old)->local_date]);
        $this->assertSame('Europe/Lisbon', TimeEntry::query()->findOrFail($new)->timezone);

        $event = AuditEvent::query()->where('action', 'settings.timezone_updated')->firstOrFail();
        $this->assertSame(['America/Sao_Paulo', 'Europe/Lisbon'], [$event->context['before'], $event->context['after']]);
    }

    public function test_an_invalid_timezone_is_rejected(): void
    {
        $this->asAdmin('PUT', '/organization', ['timezone' => 'Marte/Olympus'])->assertStatus(422);
        $this->asAdmin('PUT', '/organization', [])->assertStatus(422);
        $this->assertSame('America/Sao_Paulo', $this->tenant->fresh()->default_timezone);
    }

    // ---------- projetos ----------

    public function test_a_disabled_project_blocks_new_time_but_keeps_history(): void
    {
        $this->logTime($this->employee)->assertCreated();

        $this->asAdmin('PATCH', "/projects/{$this->project->id}", ['enabled' => false])->assertOk();

        $this->logTime($this->employee)->assertStatus(403);
        $this->postJson('/api/me/timer', [
            'projectId' => $this->project->devops_project_id,
            'projectName' => $this->project->devops_project_name,
            'workItemId' => 42,
        ], $this->headers($this->employee))->assertStatus(403);
        // O que já foi lançado continua aparecendo.
        $this->getJson('/api/me/weeks/2026-09-28', $this->headers($this->employee))->assertOk()->assertJsonPath('totalSeconds', 1800);

        $this->asAdmin('PATCH', "/projects/{$this->project->id}", ['enabled' => true])->assertOk();
        $this->logTime($this->employee)->assertCreated();

        $this->assertSame(['settings.project_disabled', 'settings.project_enabled'], AuditEvent::query()->whereIn('action', ['settings.project_disabled', 'settings.project_enabled'])->orderBy('id')->pluck('action')->all());
    }

    public function test_projects_of_other_organizations_cannot_be_touched(): void
    {
        $foreign = Project::factory()->for(Tenant::factory()->create())->create();

        $this->asAdmin('PATCH', "/projects/{$foreign->id}", ['enabled' => false])->assertStatus(404);
        $this->assertTrue($foreign->fresh()->is_enabled);
    }

    // ---------- atividades ----------

    public function test_activity_types_can_be_created_renamed_and_disabled(): void
    {
        $created = $this->asAdmin('POST', '/activity-types', ['name' => 'Consultoria', 'color' => '#112233', 'defaultBillable' => true])->assertOk();
        $type = collect($created->json('activityTypes'))->firstWhere('name', 'Consultoria');
        $this->assertSame(['#112233', true, true], [$type['color'], $type['defaultBillable'], $type['enabled']]);

        $this->asAdmin('PATCH', "/activity-types/{$type['id']}", ['name' => 'Consultoria técnica', 'color' => '#445566'])->assertOk();
        $this->assertSame('Consultoria técnica', ActivityType::query()->findOrFail($type['id'])->name);

        // Usa enquanto habilitada; depois de desabilitada, recusa só para lançamentos novos.
        $used = $this->logTime($this->employee, ['activityTypeId' => $type['id']])->assertCreated()->json('id');
        $this->asAdmin('PATCH', "/activity-types/{$type['id']}", ['enabled' => false])->assertOk();

        $this->logTime($this->employee, ['activityTypeId' => $type['id']])->assertStatus(422);
        $week = $this->getJson('/api/me/weeks/2026-09-28', $this->headers($this->employee))->assertOk();
        $this->assertSame('Consultoria técnica', collect($week->json('entries'))->firstWhere('id', $used)['activityTypeName']);
    }

    public function test_activity_type_input_is_validated(): void
    {
        $this->asAdmin('POST', '/activity-types', ['name' => 'Consultoria'])->assertOk();
        $this->asAdmin('POST', '/activity-types', ['name' => 'Consultoria'])->assertStatus(422);
        // Os nomes do conjunto padrão também contam.
        $this->asAdmin('POST', '/activity-types', ['name' => 'Testes'])->assertStatus(422);
        $this->asAdmin('POST', '/activity-types', ['name' => 'Cor ruim', 'color' => 'azul'])->assertStatus(422);
        $this->asAdmin('POST', '/activity-types', [])->assertStatus(422);

        $other = ActivityType::query()->where('name', 'Deployment')->firstOrFail();
        $this->asAdmin('PATCH', "/activity-types/{$other->id}", ['name' => 'Testes'])->assertStatus(422);

        $foreign = ActivityType::factory()->for(Tenant::factory()->create())->create();
        $this->asAdmin('PATCH', "/activity-types/{$foreign->id}", ['enabled' => false])->assertStatus(404);
    }

    // ---------- papéis ----------

    public function test_granting_a_role_gives_access_and_revoking_takes_it_away(): void
    {
        $newcomer = $this->member('Nando Novo');
        $this->logTime($newcomer)->assertStatus(403);

        $granted = $this->asAdmin('POST', '/role-assignments', ['memberId' => $newcomer->id, 'role' => 'member', 'projectId' => $this->project->id])->assertOk();
        $this->logTime($newcomer)->assertCreated();

        $assignment = collect(collect($granted->json('members'))->firstWhere('name', 'Nando Novo')['roles'])->first();
        $this->asAdmin('DELETE', "/role-assignments/{$assignment['id']}")->assertOk();
        $this->logTime($newcomer)->assertStatus(403);

        $this->assertSame(['role.granted', 'role.revoked'], AuditEvent::query()->whereIn('action', ['role.granted', 'role.revoked'])->orderBy('id')->pluck('action')->all());
    }

    public function test_granting_the_same_role_twice_is_idempotent(): void
    {
        $payload = ['memberId' => $this->employee->id, 'role' => 'manager'];

        $this->asAdmin('POST', '/role-assignments', $payload)->assertOk();
        $this->asAdmin('POST', '/role-assignments', $payload)->assertOk();

        $this->assertSame(1, RoleAssignment::query()->where('member_id', $this->employee->id)->where('role', 'manager')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'role.granted')->count());
    }

    public function test_the_last_administrator_cannot_be_removed(): void
    {
        $adminRole = RoleAssignment::query()->where('member_id', $this->admin->id)->where('role', 'admin')->firstOrFail();

        $this->asAdmin('DELETE', "/role-assignments/{$adminRole->id}")->assertStatus(409);
        $this->assertTrue(RoleAssignment::query()->whereKey($adminRole->id)->exists());

        // Com outro administrador, pode.
        $second = $this->member('Segundo Admin');
        $this->asAdmin('POST', '/role-assignments', ['memberId' => $second->id, 'role' => 'admin'])->assertOk();
        $this->asAdmin('DELETE', "/role-assignments/{$adminRole->id}")->assertOk();
        $this->getJson('/api/settings', $this->headers($this->admin))->assertStatus(403);
    }

    public function test_role_input_is_validated_and_scoped_to_the_organization(): void
    {
        $foreignMember = $this->member('De fora', Tenant::factory()->create());
        $foreignProject = Project::factory()->for(Tenant::factory()->create())->create();
        $foreignRole = RoleAssignment::factory()->create(['tenant_id' => $foreignMember->tenant_id, 'member_id' => $foreignMember->id, 'role' => 'member']);

        $this->asAdmin('POST', '/role-assignments', ['memberId' => $this->employee->id, 'role' => 'dono'])->assertStatus(422);
        $this->asAdmin('POST', '/role-assignments', ['memberId' => $foreignMember->id, 'role' => 'member'])->assertStatus(404);
        $this->asAdmin('POST', '/role-assignments', ['memberId' => $this->employee->id, 'role' => 'member', 'projectId' => $foreignProject->id])->assertStatus(404);
        $this->asAdmin('DELETE', "/role-assignments/{$foreignRole->id}")->assertStatus(404);
        $this->assertTrue(RoleAssignment::query()->whereKey($foreignRole->id)->exists());
    }

    // ---------- aprovadores ----------

    public function test_designating_an_approver_is_used_when_the_week_is_submitted(): void
    {
        $approver = $this->member('Ana Aprovadora');
        $this->asAdmin('POST', '/approver-assignments', ['memberId' => $this->employee->id, 'approverId' => $approver->id])
            ->assertOk()
            ->assertJsonPath('designations.0.approverName', 'Ana Aprovadora')
            ->assertJsonPath('designations.0.memberName', 'Eva Colaboradora');

        $this->logTime($this->employee, ['localDate' => '2026-09-29'])->assertCreated();
        $this->postJson('/api/me/weeks/2026-09-28/submit', [], $this->headers($this->employee))->assertOk();

        $this->getJson('/api/approvals', $this->headers($approver))->assertOk()->assertJsonCount(1);
    }

    public function test_designation_validation_and_isolation(): void
    {
        $foreign = $this->member('De fora', Tenant::factory()->create());

        $this->asAdmin('POST', '/approver-assignments', ['memberId' => $this->employee->id, 'approverId' => $this->employee->id])->assertStatus(422);
        $this->asAdmin('POST', '/approver-assignments', ['memberId' => $this->employee->id, 'approverId' => $foreign->id])->assertStatus(404);
        $this->asAdmin('POST', '/approver-assignments', ['memberId' => $foreign->id, 'approverId' => $this->admin->id])->assertStatus(404);

        $foreignAssignment = ApproverAssignment::factory()->create(['tenant_id' => $foreign->tenant_id, 'member_id' => $foreign->id, 'approver_id' => $foreign->id]);
        $this->asAdmin('DELETE', "/approver-assignments/{$foreignAssignment->id}")->assertStatus(404);
    }

    public function test_reassigning_pending_weeks_only_happens_when_asked(): void
    {
        $approver = $this->member('Ana Aprovadora');
        $this->logTime($this->employee, ['localDate' => '2026-09-29'])->assertCreated();
        $this->postJson('/api/me/weeks/2026-09-28/submit', [], $this->headers($this->employee))->assertOk();

        // Sem pedir, a semana que já foi enviada não muda de mãos.
        $this->asAdmin('POST', '/approver-assignments', ['memberId' => $this->employee->id, 'approverId' => $approver->id])->assertOk();
        $this->getJson('/api/approvals', $this->headers($approver))->assertOk()->assertExactJson([]);

        // Pedindo, a pendência vai para o aprovador designado (e a decisão já tomada nunca mudaria).
        $other = $this->member('Bia Aprovadora');
        $this->asAdmin('POST', '/approver-assignments', ['memberId' => $this->employee->id, 'approverId' => $other->id, 'applyToPending' => true])->assertOk();
        $this->getJson('/api/approvals', $this->headers($approver))->assertOk()->assertJsonCount(1);
        $this->getJson('/api/approvals', $this->headers($other))->assertOk()->assertJsonCount(1);
        $this->assertSame(1, AuditEvent::query()->where('action', 'approver.reassigned')->count());

        // Remover a designação e reatribuir tira a pendência dela.
        $designation = ApproverAssignment::query()->where('approver_id', $other->id)->firstOrFail();
        $this->asAdmin('DELETE', "/approver-assignments/{$designation->id}", ['applyToPending' => true])->assertOk();
        $this->getJson('/api/approvals', $this->headers($other))->assertOk()->assertExactJson([]);
        $this->getJson('/api/approvals', $this->headers($approver))->assertOk()->assertJsonCount(1);
    }

    public function test_reassigning_never_touches_decided_weeks(): void
    {
        $approver = $this->member('Ana Aprovadora');
        ApproverAssignment::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->employee->id, 'approver_id' => $approver->id]);
        $this->logTime($this->employee, ['localDate' => '2026-09-29'])->assertCreated();
        $this->postJson('/api/me/weeks/2026-09-28/submit', [], $this->headers($this->employee))->assertOk();
        $submission = WeeklySubmission::query()->firstOrFail();
        $this->postJson("/api/approvals/{$submission->id}/decision", ['decision' => 'approve'], $this->headers($approver))->assertOk();

        $rowsBefore = $submission->approverRows()->count();
        $designation = ApproverAssignment::query()->firstOrFail();
        $this->asAdmin('DELETE', "/approver-assignments/{$designation->id}", ['applyToPending' => true])->assertOk();

        $this->assertSame($rowsBefore, $submission->approverRows()->count());
        $this->assertSame(WeeklySubmission::STATUS_APPROVED, $submission->fresh()->status);
    }

    // ---------- lista de pessoas do Azure DevOps ----------

    private function person(string $name, ?string $id = null): array
    {
        return ['identityId' => $id ?? (string) Str::uuid(), 'displayName' => $name];
    }

    public function test_syncing_people_creates_the_missing_ones_without_any_access(): void
    {
        $nova = $this->person('Nina Nova');

        $response = $this->asAdmin('POST', '/people/sync', ['people' => [
            $this->person('Ana Admin', $this->admin->devops_identity_id),
            $nova,
        ]])->assertOk();

        $created = Member::query()->where('devops_identity_id', $nova['identityId'])->firstOrFail();
        $this->assertSame($this->tenant->id, $created->tenant_id);
        $this->assertSame(0, RoleAssignment::query()->where('member_id', $created->id)->count(), 'sem papel = sem acesso');

        $row = collect($response->json('members'))->firstWhere('name', 'Nina Nova');
        $this->assertTrue($row['directoryActive']);
        $this->assertSame([], $row['roles']);
        $this->assertNotNull($response->json('peopleSyncedAt'));
        $this->assertDatabaseHas('audit_events', ['action' => 'settings.people_synced']);
    }

    public function test_people_who_left_the_list_are_marked_inactive_but_never_deleted(): void
    {
        $this->asAdmin('POST', '/people/sync', ['people' => [
            $this->person('Ana Admin', $this->admin->devops_identity_id),
        ]])->assertOk();

        $eva = $this->employee->fresh();
        $this->assertFalse($eva->directory_active);
        $this->assertSame(1, RoleAssignment::query()->where('member_id', $eva->id)->count(), 'papéis ficam');

        // Voltando à lista, volta a ficar ativa.
        $this->asAdmin('POST', '/people/sync', ['people' => [
            $this->person('Ana Admin', $this->admin->devops_identity_id),
            $this->person('Eva Colaboradora', $this->employee->devops_identity_id),
        ]])->assertOk();
        $this->assertTrue($this->employee->fresh()->directory_active);
    }

    public function test_syncing_updates_names_ignores_case_and_does_not_duplicate(): void
    {
        $id = strtoupper($this->employee->devops_identity_id);

        $this->asAdmin('POST', '/people/sync', ['people' => [
            $this->person('Ana Admin', $this->admin->devops_identity_id),
            $this->person('Eva Souza', $id),
            $this->person('Eva Souza (repetida)', strtolower($id)),
        ]])->assertOk();

        $this->assertSame(1, Member::query()->where('tenant_id', $this->tenant->id)->where('display_name', 'like', 'Eva%')->count());
        $this->assertSame('Eva Souza', $this->employee->fresh()->display_name);
    }

    public function test_a_synced_person_who_later_signs_in_is_the_same_member(): void
    {
        $nova = $this->person('Nina Nova', strtoupper((string) Str::uuid()));
        $this->asAdmin('POST', '/people/sync', ['people' => [$this->person('Ana Admin', $this->admin->devops_identity_id), $nova]])->assertOk();
        $before = Member::query()->count();

        $provisioned = app(IdentityProvisioningService::class)->resolve(
            new VerifiedDevOpsIdentity(strtolower($nova['identityId']), $this->tenant->aad_tenant_id),
            $this->tenant->devops_organization_id,
            $this->tenant->devops_organization_name,
        );

        $this->assertSame($before, Member::query()->count());
        $this->assertSame('Nina Nova', $provisioned->member->display_name);
    }

    public function test_sync_rejects_empty_or_malformed_lists_and_changes_nothing(): void
    {
        $this->asAdmin('POST', '/people/sync', ['people' => []])->assertStatus(422);
        $this->asAdmin('POST', '/people/sync', ['people' => [['identityId' => 'não-é-guid', 'displayName' => 'X']]])->assertStatus(422);
        $this->asAdmin('POST', '/people/sync', ['people' => [['identityId' => (string) Str::uuid(), 'displayName' => '']]])->assertStatus(422);

        $this->assertNull($this->employee->fresh()->directory_active);
    }

    public function test_only_administrators_can_sync_people_and_other_tenants_are_untouched(): void
    {
        $this->json('POST', '/api/settings/people/sync', ['people' => [$this->person('X')]], $this->headers($this->employee))->assertForbidden();

        $other = Tenant::factory()->create();
        $stranger = $this->member('Pessoa de outra org', $other);

        $this->asAdmin('POST', '/people/sync', ['people' => [$this->person('Ana Admin', $this->admin->devops_identity_id)]])->assertOk();

        $this->assertNull($stranger->fresh()->directory_active, 'outra organização não é afetada');
    }
}
