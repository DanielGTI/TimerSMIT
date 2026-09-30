<?php

namespace Tests\Feature;

use App\Models\ActivityType;
use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tipos de atividade (US1): listagem por tenant com conjunto padrão e
 * uso nos lançamentos sem cruzar tenants.
 */
class ActivityTypeTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(Tenant $tenant, Member $member): array
    {
        $session = app(SessionTokenService::class)->issue($tenant->id, $member->id);

        return ['Authorization' => "Bearer {$session->token}"];
    }

    private function setUpAuthorizedMember(): array
    {
        $tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
        $project = Project::factory()->for($tenant)->create();
        $member = Member::factory()->for($tenant)->create();
        RoleAssignment::factory()->create([
            'tenant_id' => $tenant->id,
            'member_id' => $member->id,
            'project_id' => $project->id,
            'role' => RoleAssignment::ROLE_MEMBER,
        ]);

        return [$tenant, $project, $member];
    }

    public function test_listing_requires_a_session(): void
    {
        $this->getJson('/api/activity-types')->assertStatus(401);
    }

    public function test_new_tenant_gets_the_default_set_on_first_listing(): void
    {
        [$tenant, , $member] = $this->setUpAuthorizedMember();

        $response = $this->getJson('/api/activity-types', $this->authHeader($tenant, $member));

        $response->assertOk()->assertJsonCount(12);
        $names = array_column($response->json(), 'name');
        $this->assertContains('Desenvolvimento', $names);
        $this->assertContains('Reunião Cliente', $names);
        $this->assertSame('#A6D8F5', collect($response->json())->firstWhere('name', 'Desenvolvimento')['color']);
    }

    public function test_listing_twice_does_not_duplicate_the_defaults(): void
    {
        [$tenant, , $member] = $this->setUpAuthorizedMember();

        $this->getJson('/api/activity-types', $this->authHeader($tenant, $member))->assertOk();
        $this->getJson('/api/activity-types', $this->authHeader($tenant, $member))->assertOk()->assertJsonCount(12);

        $this->assertSame(12, ActivityType::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_listing_never_shows_types_of_another_tenant_or_disabled_ones(): void
    {
        [$tenant, , $member] = $this->setUpAuthorizedMember();
        $otherTenant = Tenant::factory()->create();
        ActivityType::factory()->for($otherTenant)->create(['name' => 'Secreta de outro tenant']);
        ActivityType::factory()->for($tenant)->create(['name' => 'Desabilitada', 'is_enabled' => false]);
        ActivityType::factory()->for($tenant)->create(['name' => 'Habilitada']);

        $names = array_column(
            $this->getJson('/api/activity-types', $this->authHeader($tenant, $member))->assertOk()->json(),
            'name',
        );

        // Já existem tipos no tenant: os padrões não são recriados por cima.
        $this->assertSame(['Habilitada'], $names);
    }

    public function test_manual_entry_stores_the_chosen_activity_type(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();
        $type = ActivityType::factory()->for($tenant)->create();

        $response = $this->postJson('/api/entries', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
            'localDate' => now('America/Sao_Paulo')->toDateString(),
            'durationSeconds' => 1800,
            'activityTypeId' => $type->id,
            'billable' => true,
        ], array_merge($this->authHeader($tenant, $member), ['Idempotency-Key' => str_repeat('a', 20)]));

        $response->assertCreated()->assertJson(['activityTypeId' => (string) $type->id, 'billable' => true]);
        $this->assertSame($type->id, TimeEntry::query()->firstOrFail()->activity_type_id);
    }

    public function test_activity_type_of_another_tenant_is_rejected_on_manual_entry(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();
        $foreign = ActivityType::factory()->for(Tenant::factory()->create())->create();

        $response = $this->postJson('/api/entries', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
            'localDate' => now('America/Sao_Paulo')->toDateString(),
            'durationSeconds' => 1800,
            'activityTypeId' => $foreign->id,
        ], array_merge($this->authHeader($tenant, $member), ['Idempotency-Key' => str_repeat('b', 20)]));

        $response->assertStatus(422);
        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_disabled_activity_type_is_rejected_on_timer_start(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();
        $disabled = ActivityType::factory()->for($tenant)->create(['is_enabled' => false]);

        $response = $this->postJson('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
            'activityTypeId' => $disabled->id,
        ], array_merge($this->authHeader($tenant, $member), ['Idempotency-Key' => str_repeat('c', 20)]));

        $response->assertStatus(422);
        $this->assertSame(0, TimerSession::query()->count());
    }

    public function test_timer_carries_its_activity_type_into_the_generated_entries(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();
        $type = ActivityType::factory()->for($tenant)->create();
        $headers = $this->authHeader($tenant, $member);

        $started = $this->postJson('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
            'activityTypeId' => $type->id,
        ], array_merge($headers, ['Idempotency-Key' => str_repeat('d', 20)]))
            ->assertCreated()
            ->assertJson(['activityTypeId' => (string) $type->id]);

        $this->travel(5)->minutes();

        $stopped = $this->postJson('/api/me/timer/stop', ['timerId' => $started->json('id')], array_merge($headers, ['Idempotency-Key' => str_repeat('e', 20)]));

        $stopped->assertOk();
        $this->assertSame((string) $type->id, $stopped->json('0.activityTypeId'));
    }
}
