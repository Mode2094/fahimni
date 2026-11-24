<?php

namespace App\Http\Middleware;

use App\Services\TenantDomainResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Make Sanctum treat a request from a LIVE TEACHER DOMAIN as first-party.
 *
 * Sanctum decides «is this a stateful frontend request?» from the request's
 * Referer/Origin host, matched against `config('sanctum.stateful')`. That list
 * is built once from env, so it can only hold the platform's own hosts: a
 * teacher's own domain (`teacher.com`) or subdomain (`teacher.fahimni.me`) is
 * unknown at deploy time and must never be added with an open wildcard.
 *
 * This middleware runs immediately BEFORE Sanctum's
 * `EnsureFrontendRequestsAreStateful` and appends the request's origin host to
 * the stateful list — but ONLY when that host is a VERIFIED custom domain of an
 * ACTIVE tenant (or a live teacher subdomain), resolved server-side from the
 * database. Anything else is left untouched, so an attacker-supplied
 * Origin/Referer can never make Sanctum open a session for a foreign host.
 *
 * The session cookie stays host-only (`config('session.domain')` is empty), so
 * two teacher domains can never share a session. This only turns the
 * cookie-session flow on for the teacher's own domain — the mobile bearer-token
 * flow and every platform host are unaffected.
 */
class TrustTenantDomainsStatefully
{
    public function __construct(private readonly TenantDomainResolver $domains) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = $this->originHost($request);

        // Platform hosts already live in the static list; skip the lookup.
        if ($host !== null && ! $this->domains->isPlatformHost($host)) {
            try {
                if ($this->domains->belongsToActiveTenant($host)) {
                    $stateful = config('sanctum.stateful', []);

                    if (! in_array($host, $stateful, true)) {
                        $stateful[] = $host;
                        config(['sanctum.stateful' => $stateful]);
                    }
                }
            } catch (\Throwable) {
                // A resolution failure must never break the request path: the
                // request simply stays non-stateful (platform hosts unaffected).
            }
        }

        return $next($request);
    }

    /**
     * The host Sanctum itself matches on: Referer first, then Origin. Both are
     * attacker-visible, so the value is only ever used to *look up* a verified
     * domain — never trusted on its own.
     */
    private function originHost(Request $request): ?string
    {
        $value = $request->headers->get('referer') ?: $request->headers->get('origin');

        if (! is_string($value) || $value === '') {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
