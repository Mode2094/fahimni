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
    | Lemon Squeezy (Merchant of Record)
    |--------------------------------------------------------------------------
    | Secrets live ONLY in the environment — never in the database and never
    | in source control. Non-secret overrides (store id, api url, enabled
    | toggle) can additionally be managed from the admin panel, which stores
    | them in platform_settings.
    |
    | LEMON_SQUEEZY_API_KEY        API key (Settings → API keys in the LS dashboard)
    | LEMON_SQUEEZY_STORE_ID       Numeric store id (Settings → Stores)
    | LEMON_SQUEEZY_SIGNING_SECRET Webhook signing secret (Settings → Webhooks)
    | LEMON_SQUEEZY_API_URL        Defaults to the official API base URL
    */

    'lemonsqueezy' => [
        'enabled' => env('LEMON_SQUEEZY_ENABLED', false),
        'api_key' => env('LEMON_SQUEEZY_API_KEY', ''),
        'store_id' => env('LEMON_SQUEEZY_STORE_ID', ''),
        'signing_secret' => env('LEMON_SQUEEZY_SIGNING_SECRET', ''),
        'api_url' => env('LEMON_SQUEEZY_API_URL', 'https://api.lemonsqueezy.com'),
    ],
];
