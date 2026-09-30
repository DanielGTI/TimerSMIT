<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TimeEntry> */
class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'project_id' => Project::factory(),
            'member_id' => Member::factory(),
            'devops_work_item_id' => $this->faker->numberBetween(1, 100000),
            'timer_session_id' => null,
            'activity_type_id' => null,
            'local_date' => now()->toDateString(),
            'timezone' => 'America/Sao_Paulo',
            'duration_seconds' => $this->faker->numberBetween(60, 28800),
            'source' => TimeEntry::SOURCE_MANUAL,
            'billable' => true,
            'note' => null,
            'revision' => 1,
        ];
    }
}
