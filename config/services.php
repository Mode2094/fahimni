<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup off-site sync (rclone)
    |--------------------------------------------------------------------------
    | BACKUP_RCLONE_REMOTE, e.g. "s3:lms-backups" or "gdrive:LMS".
    | BACKUP_RCLONE_PATH overrides the local folder scanned (default: backups).
    */

    'backup' => [
        'remote' => env('BACKUP_RCLONE_REMOTE', ''),
        'path' => env('BACKUP_RCLONE_PATH', 'backups'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/api/v1/auth/google/callback'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Freemius (exclusive billing gateway for teacher subscriptions)
    |--------------------------------------------------------------------------
    | Freemius is the ONLY gateway used to collect money for platform
    | (teacher) subscriptions. Student course purchases and teacher bookings
    | are a separate concern and must NEVER flow through Freemius.
    |
    | Secrets live ONLY in the environment — never in the database and never
    | in source control. Non-secret overrides (enabled toggle, api base url)
    | can additionally be managed from the admin panel (platform_settings).
    |
    | FREEMIUS_PRODUCT_ID          Product id (Dashboard → Settings → API & Keys)
    | FREEMIUS_API_TOKEN           API Bearer Authorization Token
    | FREEMIUS_PRODUCT_SECRET_KEY  Webhook signing secret (HMAC-SHA256)
    | FREEMIUS_PUBLIC_KEY          Public key (used by the JS checkout SDK)
    | FREEMIUS_API_URL             Official API base URL (override for testing)
    | FREEMIUS_CHECKOUT_URL        Hosted checkout base URL
    |
    | Per-plan mapping (env default; admin may override per plan in metadata):
    |   FREEMIUS_PLAN_{SLUG}_MONTHLY_ID / FREEMIUS_PLAN_{SLUG}_YEARLY_ID
    |   e.g. FREEMIUS_PLAN_STARTER_MONTHLY_ID=12345
    */

    'freemius' => [
        'enabled' => env('FREEMIUS_ENABLED', false),
        'product_id' => (string) env('FREEMIUS_PRODUCT_ID', ''),
        'api_token' => (string) env('FREEMIUS_API_TOKEN', ''),
        'secret_key' => (string) env('FREEMIUS_PRODUCT_SECRET_KEY', ''),
        'public_key' => (string) env('FREEMIUS_PUBLIC_KEY', ''),
        'api_url' => env('FREEMIUS_API_URL', 'https://api.freemius.com'),
        'checkout_url' => env('FREEMIUS_CHECKOUT_URL', 'https://checkout.freemius.com'),
        'sandbox' => (bool) env('FREEMIUS_SANDBOX', false),
    ],
];
