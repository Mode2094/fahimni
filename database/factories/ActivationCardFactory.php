<?php

namespace Database\Factories;

use App\Models\ActivationCard;
use App\Models\Course;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Symfony\Component\Uid\UuidV7;

class ActivationCardFactory extends Factory
{
    protected $model = ActivationCard::class;

    public function definition(): array
    {
        return [
            'id' => UuidV7::generate(),
            'tenant_id' => Tenant::factory(),
            'course_id' => Course::factory(),
            'code' => strtoupper(bin2hex(random_bytes(8))),
            'status' => 'generated',
        ];
    }
}
