<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a Cloudflare API call fails (transport / timeout / 5xx / a
 * rejected write). The message is SAFE to surface to an admin — it never
 * contains the API token or any request credential.
 */
class CloudflareApiException extends RuntimeException {}
