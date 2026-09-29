<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'devops_project_id' => (string) $this->faker->uuid(),
            'devops_project_name' => $this->faker->unique()->word(),
            'is_enabled' => true,
        ];
    }
}
