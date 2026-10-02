<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Section;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class SectionFactory extends Factory
{
    protected $model = Section::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'course_id' => Course::factory(),
            'title' => $this->faker->sentence(3),
            'sort_order' => 0,
            'is_published' => true,
        ];
    }
}
