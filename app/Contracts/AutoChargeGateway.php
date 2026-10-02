<?php

namespace App\Contracts;

/**
 * Gateways capable of charging a previously saved payment method
 * (merchant-initiated renewal without customer interaction).
 */
interface AutoChargeGateway
{
    /**
     * Charge a saved payment method identified by $token.
     *
     * @param  array{cart_id: string, amount: float, currency: string, description: string, custom_data?: array}  $params
     * @return array{success: bool, tran_ref: string|null, response_code: string|null, message: string}
     */
    public static function chargeSaved(string $token, array $params): array;

    /**
     * Extract a saveable payment method from a gateway callback / capture /
     * webhook payload. Returns null when the payload carries no reusable
     * method (e.g. a wallet approval that cannot be vaulted).
     *
     * @return array{token: string, brand: string|null, last4: string|null}|null
     */
    public static function extractSavedMethod(array $result): ?array;
}
