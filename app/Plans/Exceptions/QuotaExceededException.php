<?php

namespace App\Plans\Exceptions;

use RuntimeException;

/**
 * Thrown when a plan limit is reached (or would be exceeded).
 * Rendered as HTTP 403 with used/limit in the message.
 */
class QuotaExceededException extends RuntimeException
{
    public function __construct(
        string $label,
        int $used,
        int $limit,
        string $usedUnit = '',
        string $limitUnit = '',
    ) {
        $usedLabel = $usedUnit ? $used.' '.$usedUnit : (string) $used;
        $limitLabel = $limitUnit ? $limit.' '.$limitUnit : (string) $limit;

        parent::__construct("لقد وصلت إلى الحد الأقصى من {$label} ({$usedLabel} من {$limitLabel}). قم بترقية خطتك أو حرّر مساحة للمتابعة.");
    }
}
