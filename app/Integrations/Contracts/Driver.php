<?php

namespace App\Integrations\Contracts;

use App\Models\Integration;

interface Driver
{
    public static function name(): string;

    public static function label(): string;

    public static function category(): string;

    public function __construct(Integration $integration);

    public function configured(): bool;

    public function enabled(): bool;

    /**
     * @return array{ok: bool, message: string, details?: array}
     */
    public function test(): array;
}
