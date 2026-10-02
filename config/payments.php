<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Refund webhook secret
    |--------------------------------------------------------------------------
    |
    | Refunds are processed inside the gateway dashboard (Click/wallet,
    | PayTabs, PayPal…) and synced to the platform via the refund webhook.
    | This shared secret signs the webhook payload (HMAC-SHA256) in the
    | X-Webhook-Signature header: "sha256=<hex>".
    |
    | Generate: php -r "echo 'base64:'.base64_encode(random_bytes(32));"
    |
    */

    'refund_webhook_secret' => env('REFUND_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Service fee (USD)
    |--------------------------------------------------------------------------
    |
    | A flat $5 USD surcharge added to every online sale (PayPal, Paddle,
    | PayTabs). For PayTabs the amount is converted to JOD using the
    | platform currency_rate. Manual payments are excluded.
    |
    */
    'service_fee_usd' => env('SERVICE_FEE_USD', 5),
];
