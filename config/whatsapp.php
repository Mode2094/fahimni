<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp (Meta WhatsApp Business Cloud API)
    |--------------------------------------------------------------------------
    | Drivers:
    |   - "log"    : writes messages to the log (default, no setup required)
    |   - "graph"  : sends via the WhatsApp Cloud API
    |
    */

    'driver' => env('WHATSAPP_DRIVER', 'log'),

    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),

    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),

    'from' => env('WHATSAPP_FROM'),

    'api_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v20.0'),

    'timeout' => 10,
];
