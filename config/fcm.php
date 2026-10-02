<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Push notifications (Firebase Cloud Messaging HTTP v1)
    |--------------------------------------------------------------------------
    | Requires a Firebase service account JSON. Set FCM_CREDENTIALS_JSON to the
    | raw JSON string, or FCM_CREDENTIALS_PATH to a file path. When neither is
    | set, push notifications are written to the log instead of being sent.
    |
    */

    'enabled' => (bool) env('FCM_ENABLED', false),

    'credentials_json' => env('FCM_CREDENTIALS_JSON'),

    'credentials_path' => env('FCM_CREDENTIALS_PATH'),

    'project_id' => env('FCM_PROJECT_ID'),

    /*
    | APNs configuration (iOS).
    | FCM_APNS_AUTH_KEY: path to the .p8 key
    | FCM_APNS_TEAM_ID / FCM_APNS_KEY_ID
    */
    'apns_auth_key' => env('FCM_APNS_AUTH_KEY'),
    'apns_team_id' => env('FCM_APNS_TEAM_ID'),
    'apns_key_id' => env('FCM_APNS_KEY_ID'),

    'timeout' => 10,
];
