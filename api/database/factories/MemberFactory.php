<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Member> */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'devops_identity_id' => (string) $this->faker->uuid(),
            'display_name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
        ];
    }
}
