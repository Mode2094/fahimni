<?php

namespace App\Integrations;

use App\Models\Integration;

abstract class BaseDriver
{
    use Concerns\ManagesIntegration;

    public function __construct(protected Integration $integration) {}

    public static function name(): string
    {
        return str(static::class)->afterLast('\\')->before('Driver')->snake()->toString();
    }
}
