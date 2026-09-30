<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TimerSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TimerSession> */
class TimerSessionFactory extends Factory
{
    protected $model = TimerSession::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'project_id' => Project::factory(),
            'member_id' => Member::factory(),
            'devops_work_item_id' => $this->faker->numberBetween(1, 100000),
            'activity_type_id' => null,
            'status' => TimerSession::STATUS_ACTIVE,
            'started_at_utc' => now(),
            'ended_at_utc' => null,
            'idempotency_key' => (string) $this->faker->uuid(),
            'note' => null,
            'billable' => true,
        ];
    }

    public function stopped(): static
    {
        return $this->state(fn () => [
            'status' => TimerSession::STATUS_STOPPED,
            'ended_at_utc' => now(),
        ]);
    }
}
