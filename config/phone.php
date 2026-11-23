<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Phone number normalization
    |--------------------------------------------------------------------------
    | Phone numbers are stored in E.164 («+9627…»). When a human enters a
    | national-format number (with a leading 0, e.g. «0791234567») the default
    | dial code below is applied. Jordan (+962) is the platform default.
    */
    'default_dial' => env('DEFAULT_PHONE_DIAL', '+962'),
];
