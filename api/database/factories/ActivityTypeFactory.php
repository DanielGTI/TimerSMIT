<?php

namespace Database\Factories;

use App\Models\ActivityType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ActivityType> */
class ActivityTypeFactory extends Factory
{
    protected $model = ActivityType::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->unique()->word(),
            'is_enabled' => true,
            'is_default_billable' => true,
        ];
    }
}
