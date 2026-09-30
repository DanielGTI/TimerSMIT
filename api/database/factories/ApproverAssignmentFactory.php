<?php

namespace Database\Factories;

use App\Models\ApproverAssignment;
use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApproverAssignment> */
class ApproverAssignmentFactory extends Factory
{
    protected $model = ApproverAssignment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'member_id' => Member::factory(),
            'project_id' => null,
            'approver_id' => Member::factory(),
        ];
    }
}
