<?php

namespace Database\Factories;

use App\Models\Policy;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Policy> */
class PolicyFactory extends Factory
{
    protected $model = Policy::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'project_id' => null,
            'version' => 1,
            'duration_increment_minutes' => 1,
            'daily_limit_hours' => 24,
            'retroactive_window_days' => 30,
            'comment_required' => false,
            'effective_from' => now()->subDay(),
        ];
    }
}
