<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_09_30_000006_grant_admin_to_first_member_of_tenants_without_admin.php');
        $migration->up();
    }

    public function test_oldest_member_of_a_tenant_without_admin_becomes_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $first = Member::factory()->for($tenant)->create();
        $second = Member::factory()->for($tenant)->create();

        $this->runBackfill();

        $this->assertDatabaseHas('role_assignments', ['tenant_id' => $tenant->id, 'member_id' => $first->id, 'project_id' => null, 'role' => RoleAssignment::ROLE_ADMIN]);
        $this->assertDatabaseMissing('role_assignments', ['member_id' => $second->id]);
    }

    public function test_tenant_that_already_has_an_admin_is_left_alone(): void
    {
        $tenant = Tenant::factory()->create();
        $first = Member::factory()->for($tenant)->create();
        $chosenAdmin = Member::factory()->for($tenant)->create();
        RoleAssignment::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $chosenAdmin->id, 'role' => RoleAssignment::ROLE_ADMIN]);

        $this->runBackfill();

        $this->assertSame(1, RoleAssignment::query()->where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseMissing('role_assignments', ['member_id' => $first->id]);
    }

    public function test_running_it_twice_does_not_duplicate_and_empty_tenants_are_skipped(): void
    {
        $tenant = Tenant::factory()->create();
        Member::factory()->for($tenant)->create();
        Tenant::factory()->create();

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame(1, RoleAssignment::query()->count());
    }
}
