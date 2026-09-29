<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * T011 — nenhum usuário de um tenant/projeto deve enxergar dados de outro,
 * mesmo conhecendo o ID do recurso (FR-001, FR-015, SC-005).
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_scoped_to_tenant_only_sees_its_own_tenant_via_me_endpoint(): void
    {
        $tenantA = Tenant::factory()->create();
        $memberA = Member::factory()->for($tenantA)->create();

        $tenantB = Tenant::factory()->create();
        $memberB = Member::factory()->for($tenantB)->create();

        $tokens = app(SessionTokenService::class);
        $sessionA = $tokens->issue($tenantA->id, $memberA->id);
        $sessionB = $tokens->issue($tenantB->id, $memberB->id);

        $this->getJson('/api/me', ['Authorization' => "Bearer {$sessionA->token}"])
            ->assertOk()
            ->assertJson([
                'tenantId' => (string) $tenantA->id,
                'memberId' => (string) $memberA->id,
            ]);

        $this->getJson('/api/me', ['Authorization' => "Bearer {$sessionB->token}"])
            ->assertOk()
            ->assertJson([
                'tenantId' => (string) $tenantB->id,
                'memberId' => (string) $memberB->id,
            ]);
    }

    public function test_request_without_session_is_rejected(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_member_cannot_view_project_of_another_tenant_even_knowing_its_id(): void
    {
        $tenantA = Tenant::factory()->create();
        $projectA = Project::factory()->for($tenantA)->create();
        $memberA = Member::factory()->for($tenantA)->create();
        RoleAssignment::factory()->create([
            'tenant_id' => $tenantA->id,
            'member_id' => $memberA->id,
            'project_id' => $projectA->id,
            'role' => RoleAssignment::ROLE_MEMBER,
        ]);

        $tenantB = Tenant::factory()->create();
        $projectB = Project::factory()->for($tenantB)->create();

        $this->assertTrue(Gate::forUser($memberA)->allows('view', $projectA));
        $this->assertFalse(Gate::forUser($memberA)->allows('view', $projectB));
    }

    public function test_member_cannot_view_sibling_project_in_same_tenant_without_role_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        $projectWithAccess = Project::factory()->for($tenant)->create();
        $projectWithoutAccess = Project::factory()->for($tenant)->create();
        $member = Member::factory()->for($tenant)->create();

        RoleAssignment::factory()->create([
            'tenant_id' => $tenant->id,
            'member_id' => $member->id,
            'project_id' => $projectWithAccess->id,
            'role' => RoleAssignment::ROLE_MEMBER,
        ]);

        $this->assertTrue(Gate::forUser($member)->allows('view', $projectWithAccess));
        $this->assertFalse(Gate::forUser($member)->allows('view', $projectWithoutAccess));
    }

    public function test_org_wide_role_assignment_grants_access_to_every_project_in_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $projectOne = Project::factory()->for($tenant)->create();
        $projectTwo = Project::factory()->for($tenant)->create();
        $admin = Member::factory()->for($tenant)->create();

        RoleAssignment::factory()->create([
            'tenant_id' => $tenant->id,
            'member_id' => $admin->id,
            'project_id' => null,
            'role' => RoleAssignment::ROLE_ADMIN,
        ]);

        $this->assertTrue(Gate::forUser($admin)->allows('view', $projectOne));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $projectTwo));
    }
}
