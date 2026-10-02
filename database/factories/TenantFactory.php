<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'slug' => $this->faker->unique()->slug(2),
            'email' => $this->faker->companyEmail(),
            'status' => 'active',
            'locale' => 'en',
            'timezone' => 'UTC',
            'max_students' => 100,
            'storage_limit_mb' => 5000,
        ];
    }
}
