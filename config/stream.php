<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Signing key for protected media tokens
    |--------------------------------------------------------------------------
    |
    | Dedicated key used to sign short-lived media URLs (video streaming and
    | file downloads). Kept separate from APP_KEY so it can be rotated without
    | invalidating application sessions. Falls back to APP_KEY for backward
    | compatibility when not configured.
    |
    */

    'signing_key' => env('VIDEO_SIGNING_KEY', env('APP_KEY', '')),

    /*
    | Previous signing key used during rotation. Tokens signed with the
    | previous key stay valid while this value is set; remove it after the
    | token TTL has passed.
    */

    'previous_signing_key' => env('VIDEO_SIGNING_KEY_PREVIOUS', ''),

    /*
    | Default playback TTL for signed Bunny Stream URLs (seconds). 15 minutes
    | is a good balance: the player refreshes the token automatically before
    | expiry.
    */

    'signed_url_ttl' => (int) env('SIGNED_URL_TTL', 900),

    /*
    | Upload ticket expiry (seconds). The browser must finish uploading before
    | this deadline or the upload URL is rejected by Bunny.
    */

    'upload_ticket_ttl' => (int) env('UPLOAD_TICKET_TTL', 3600),
];