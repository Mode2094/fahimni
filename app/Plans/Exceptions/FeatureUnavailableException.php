<?php

namespace App\Plans\Exceptions;

use RuntimeException;

/**
 * Thrown when a plan feature is not available to the tenant.
 * Rendered as HTTP 403 with a friendly message.
 */
class FeatureUnavailableException extends RuntimeException
{
    public function __construct(string $labelAr)
    {
        parent::__construct("هذه الميزة غير متاحة في خطتك الحالية ({$labelAr}). قم بترقية خطتك لتفعيلها.");
    }
}
