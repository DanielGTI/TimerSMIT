<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RoleAssignment> */
class RoleAssignmentFactory extends Factory
{
    protected $model = RoleAssignment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'member_id' => Member::factory(),
            'project_id' => null,
            'role' => RoleAssignment::ROLE_MEMBER,
        ];
    }
}
