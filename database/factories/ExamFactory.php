<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Exam;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExamFactory extends Factory
{
    protected $model = Exam::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'course_id' => Course::factory(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'duration_minutes' => 30,
            'pass_mark_percent' => 70,
            'max_attempts' => 3,
            'shuffle_questions' => false,
            'is_published' => true,
        ];
    }
}
