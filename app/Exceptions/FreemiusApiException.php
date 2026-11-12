<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a Freemius API call fails transiently (network / timeout /
 * 5xx) so callers can retry safely instead of silently treating the result as
 * "license not found". A definitive 4xx (e.g. 404) is NOT represented here.
 */
class FreemiusApiException extends RuntimeException {}
