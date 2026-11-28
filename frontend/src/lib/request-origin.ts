/**
 * Rebuild the origin the browser is actually using, from the proxy headers.
 *
 * Why this exists
 * ---------------
 * Every BFF call (`/api/session/*`) forwards an `Origin` header to Laravel so
 * Sanctum's stateful-domain check (`config('sanctum.stateful')`, driven by
 * `EnsureFrontendRequestsAreStateful`) treats the request as a first-party SPA
 * request. Only then does Laravel attach the session/CSRF middleware and let
 * `$request->hasSession()` be true — the switch between the cookie-session flow
 * and the mobile bearer-token flow.
 *
 * Behind our nginx/Caddy edge, Next.js derives `request.url` from its own
 * listen address (`http://<container>:3000`) rather than the public host, so
 * `new URL(request.url).origin` produced an internal origin that is *not* in
 * `SANCTUM_STATEFUL_DOMAINS`. Laravel then treated the call as a stateless API
 * request: no session was started, `/api/v1/auth/me` answered 401, and after
 * the Google callback the UI showed «تعذر إنشاء الجلسة».
 *
 * Rebuilding the public origin from `x-forwarded-host`/`host` and
 * `x-forwarded-proto` (both set by our nginx) is the fix. This module stays
 * dependency-free so it can be unit-tested without a Next.js runtime.
 *
 * Host-header spoofing
 * --------------------
 * The headers are only trusted when the hostname is one we control: localhost,
 * the platform domain (`NEXT_PUBLIC_PLATFORM_DOMAIN`, subdomains included) — or
 * `NEXT_PUBLIC_SITE_URL`'s host. Anything else (a client-supplied
 * `Host`/`X-Forwarded-Host` pointing at an arbitrary domain) is discarded and
 * the canonical origin is used instead, so a spoofed header can never redirect
 * the forwarded `Origin` to a host Sanctum would otherwise treat as stateful.
 */

/** Anything header-shaped: `NextRequest`, `Request`, or `Headers`. */
export interface OriginRequestLike {
  url: string;
  headers: { get(name: string): string | null };
}

const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];
const DEFAULT_PLATFORM_DOMAIN = 'fahimni.me';

/** First value of a possibly comma-joined proxy header (`"https, http"`). */
function firstHeaderValue(value: string | null): string {
  return (value ?? '').split(',')[0]?.trim() ?? '';
}

function safeProtocol(url: string): string {
  try {
    return new URL(url).protocol.replace(/:$/, '');
  } catch {
    return 'https';
  }
}

function safeOrigin(url: string): string {
  try {
    return new URL(url).origin;
  } catch {
    return '';
  }
}

/** Platform domains (comma-separated env, lowercased) we answer for. */
function platformHosts(): string[] {
  const raw = (process.env.NEXT_PUBLIC_PLATFORM_DOMAIN || '').trim() || DEFAULT_PLATFORM_DOMAIN;
  const list = raw
    .split(',')
    .map((h) => h.trim().toLowerCase())
    .filter(Boolean);

  return list.length ? list : [DEFAULT_PLATFORM_DOMAIN];
}

/** Hostname without port, tolerant of IPv6 literals (`[::1]:3000`). */
function hostnameOf(host: string): string {
  const h = host.trim().toLowerCase();

  if (h.startsWith('[')) {
    const end = h.indexOf(']');

    return end > 0 ? h.slice(1, end) : h.slice(1);
  }

  return h.split(':')[0] ?? '';
}

/**
 * True when `host` belongs to us: loopback, a platform domain, or any of its
 * subdomains (teacher sites / tenant subdomains). No wildcard for foreign
 * domains — `evil.fahimni.me.attacker.com` does not pass the suffix check
 * because the label boundary is enforced by the leading dot.
 */
export function isTrustedHost(host: string | null | undefined): boolean {
  if (!host) return false;
  const name = hostnameOf(host);
  if (!name) return false;
  if (LOCAL_HOSTS.includes(name)) return true;

  return platformHosts().some((domain) => name === domain || name.endsWith(`.${domain}`));
}

function sanitizeProtocol(proto: string): string {
  return proto === 'http' || proto === 'https' ? proto : 'https';
}

/** The single canonical origin to fall back to when nothing is trusted. */
function canonicalOrigin(): string {
  const explicit = (process.env.NEXT_PUBLIC_SITE_URL || '').trim();
  if (explicit) return explicit.replace(/\/+$/, '');

  return `https://${platformHosts()[0]}`;
}

/** Build the public origin from the request's scheme + a chosen host. */
function buildOrigin(request: OriginRequestLike, host: string): string {
  const proto = sanitizeProtocol(
    firstHeaderValue(request.headers.get('x-forwarded-proto')) || safeProtocol(request.url),
  );

  return `${proto}://${host}`;
}

/** The proxy host header we consider first (x-forwarded-host wins over host). */
function candidateHost(request: OriginRequestLike): string {
  const candidates = [
    firstHeaderValue(request.headers.get('x-forwarded-host')),
    firstHeaderValue(request.headers.get('host')),
  ];

  return candidates.find((candidate) => candidate) ?? '';
}

/**
 * Internal base for the server-to-server tenant-domain lookup. Kept local (and
 * dependency-free) so this module stays unit-testable without a Next runtime.
 */
const INTERNAL_API_BASE = (
  process.env.INTERNAL_API_URL ||
  process.env.NEXT_REWRITE_API_TARGET ||
  'http://localhost:8080/api/v1'
).replace(/\/+$/, '');

/** A plain DNS name: no scheme, no path, no wildcard, at least one dot. */
function isDnsHostname(host: string): boolean {
  const name = hostnameOf(host);

  return /^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/.test(name);
}

/**
 * Server-side confirmation that a hostname is a LIVE teacher address (a
 * VERIFIED custom domain of an ACTIVE tenant, or a live `{slug}.{platform}`
 * subdomain). The backend is the single authority — the public lookup 200s for
 * exactly those hosts and 404s for everything else.
 */
async function isVerifiedTenantHost(host: string): Promise<boolean> {
  try {
    const res = await fetch(
      `${INTERNAL_API_BASE}/public/domain/${encodeURIComponent(hostnameOf(host))}`,
      { headers: { Accept: 'application/json' }, cache: 'no-store' },
    );

    return res.ok;
  } catch {
    return false;
  }
}

export function requestOrigin(request: OriginRequestLike): string {
  const host = candidateHost(request);

  if (host && isTrustedHost(host)) {
    return buildOrigin(request, host);
  }

  // No trusted host header: a spoofed or unexpected Host must never decide the
  // origin we forward to Sanctum, so pin the canonical origin instead.
  return canonicalOrigin() || safeOrigin(request.url);
}

/**
 * Like `requestOrigin`, but also trusts a foreign host once the backend has
 * confirmed it is a live teacher domain.
 *
 * A teacher's own domain (`teacher.com`) is unknown at deploy time, so it can
 * never sit in the static `SANCTUM_STATEFUL_DOMAINS` list. The BFF must forward
 * the REAL origin for such a request (so Sanctum's stateful check — and the
 * server-side trust middleware — can recognise it), but only after the domain
 * has been verified server-side. `requestOrigin`'s host-spoofing protection is
 * preserved: an arbitrary/unverified `Host`/`X-Forwarded-Host` still falls back
 * to the canonical origin.
 */
export async function resolveRequestOrigin(request: OriginRequestLike): Promise<string> {
  const host = candidateHost(request);

  if (host && isTrustedHost(host)) {
    return buildOrigin(request, host);
  }

  if (host && isDnsHostname(host) && (await isVerifiedTenantHost(host))) {
    return buildOrigin(request, host);
  }

  return canonicalOrigin() || safeOrigin(request.url);
}

/**
 * True when the browser is on a platform host (apex / `www.` / `api.`) or a
 * loopback dev host — the only origins where the central Google OAuth callback
 * can hand back a session cookie without a cross-domain handoff.
 *
 * A teacher's custom domain (or `{slug}.{platform}` subdomain) is NOT a platform
 * host: Google sign-in there would land the student on the platform domain with
 * no session, so the UI keeps it clearly unavailable there and the email/password
 * flow remains the supported path.
 */
export function isPlatformBrowserHost(): boolean {
  if (typeof window === 'undefined') return true;

  return isPlatformHostName(window.location.hostname);
}

/**
 * True when `host` is one of the platform's OWN hosts (apex / `www.` / `api.`)
 * or a loopback dev host. A teacher subdomain (`teacher.fahimni.me`) is NOT a
 * platform host — it is a tenant site and needs the same cross-host handoff as
 * a custom domain. Server-safe (takes the host as a string).
 */
export function isPlatformHostName(host: string | null | undefined): boolean {
  const name = hostnameOf(host ?? '');

  if (!name) return false;
  if (LOCAL_HOSTS.includes(name)) return true;

  return platformHosts().some(
    (domain) => name === domain || name === `www.${domain}` || name === `api.${domain}`,
  );
}
