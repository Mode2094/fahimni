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
 */

/** Anything header-shaped: `NextRequest`, `Request`, or `Headers`. */
export interface OriginRequestLike {
  url: string;
  headers: { get(name: string): string | null };
}

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

export function requestOrigin(request: OriginRequestLike): string {
  const host =
    firstHeaderValue(request.headers.get('x-forwarded-host')) ||
    firstHeaderValue(request.headers.get('host'));

  if (host) {
    const proto =
      firstHeaderValue(request.headers.get('x-forwarded-proto')) ||
      safeProtocol(request.url);

    return `${proto}://${host}`;
  }

  // No proxy/host header at all (e.g. a direct in-process call): fall back to
  // whatever Next.js resolved. Never the preferred path behind our edge.
  return safeOrigin(request.url);
}
