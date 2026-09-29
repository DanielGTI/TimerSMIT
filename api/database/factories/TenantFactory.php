<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'devops_organization_id' => (string) $this->faker->uuid(),
            'devops_organization_name' => $this->faker->unique()->domainWord(),
            'default_timezone' => 'UTC',
            'is_active' => true,
        ];
    }
}
