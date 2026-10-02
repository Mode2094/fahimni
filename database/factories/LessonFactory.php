<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\Section;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'section_id' => Section::factory(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'duration_minutes' => $this->faker->numberBetween(5, 60),
            'sort_order' => 0,
            'is_published' => true,
            'lesson_type' => 'video',
        ];
    }
}
