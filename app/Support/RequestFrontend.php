<?php

namespace App\Support;

use App\Services\TenantDomainResolver;
use Illuminate\Http\Request;

/**
 * Resolve the correct PUBLIC frontend base URL for an outgoing link.
 *
 * A student who signs up / resets a password on a teacher's own domain must be
 * sent back to THAT domain, not the platform — otherwise the verification or
 * reset link would drop them on the platform host and lose the teacher's
 * context (and branding). The host is taken from the request Origin/Referer and
 * is only honoured after the domain resolver confirms it is a live tenant
 * address; anything else falls back to the configured platform frontend URL.
 */
class RequestFrontend
{
    /** Hostname from the request Origin (preferred) or Referer, lowercased. */
    public static function originHost(Request $request): ?string
    {
        $value = $request->headers->get('origin') ?: $request->headers->get('referer');

        if (! is_string($value) || $value === '') {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * The verified live teacher host for this request, or null when the request
     * did not originate from a teacher's own (non-platform) domain.
     */
    public static function tenantHost(Request $request): ?string
    {
        $host = self::originHost($request);

        if ($host === null) {
            return null;
        }

        $resolver = app(TenantDomainResolver::class);

        return $resolver->isPlatformHost($host) || ! $resolver->belongsToActiveTenant($host)
            ? null
            : $host;
    }

    /**
     * Base URL (no trailing slash) to embed in an email/link: the teacher's own
     * HTTPS origin when the request came from that teacher's domain, otherwise
     * the configured platform frontend URL.
     */
    public static function baseUrl(?Request $request = null): string
    {
        $request ??= app()->bound(Request::class) ? app(Request::class) : null;

        if ($request instanceof Request) {
            $host = self::tenantHost($request);

            if ($host !== null) {
                return 'https://'.$host;
            }
        }

        return rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
    }
}
