<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a manual (off-platform) payment reference — typically a CliQ
 * number or a bank transfer reference — was already used to activate a
 * subscription.
 *
 * A payment reference identifies ONE real-world transfer, so replaying it (even
 * from a different `request_id` or a different admin) must never create a
 * second payment, ledger entry, invoice or activation. The database also
 * enforces this via the unique `(gateway, transaction_id)` index; this exception
 * turns that hard failure into a clean, explained 422.
 */
class DuplicatePaymentReferenceException extends RuntimeException
{
    public function __construct(
        public readonly string $reference,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? "The payment reference \"{$reference}\" was already recorded. Reusing it would double-count revenue.",
        );
    }
}
