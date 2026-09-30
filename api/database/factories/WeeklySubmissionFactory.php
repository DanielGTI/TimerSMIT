<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Tenant;
use App\Models\WeeklySubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WeeklySubmission> */
class WeeklySubmissionFactory extends Factory
{
    protected $model = WeeklySubmission::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'member_id' => Member::factory(),
            'week_start_date' => '2026-09-28',
            'status' => WeeklySubmission::STATUS_SUBMITTED,
            'revision' => 1,
            'submitted_at' => now(),
        ];
    }
}
