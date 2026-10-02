<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SMS gateway
    |--------------------------------------------------------------------------
    | Drivers:
    |   - "log"    : writes messages to the log (default, no setup required)
    |   - "twilio" : sends via Twilio (set SMS_TWILIO_SID / SMS_TWILIO_TOKEN)
    |
    */

    'driver' => env('SMS_DRIVER', 'log'),

    'from' => env('SMS_FROM', 'Tadreesly'),

    'twilio' => [
        'sid' => env('SMS_TWILIO_SID'),
        'token' => env('SMS_TWILIO_TOKEN'),
        'from' => env('SMS_TWILIO_FROM'),
    ],
];
