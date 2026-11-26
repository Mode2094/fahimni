import { NextResponse } from 'next/server';
import type { NextRequest } from 'next/server';
import { CSP_HEADER, NONCE_HEADER, SANDBOX_PATH, buildCsp, generateNonce, withNoTransform } from './lib/csp';
import { tenantSitePath, TENANT_HOST_HEADER, TENANT_SLUG_HEADER } from './lib/tenant-routing';

// Server-side fetch: must be an absolute URL inside the Docker network
// (nginx) or localhost in dev — never the browser-relative '/api/v1'.
const API = process.env.INTERNAL_API_URL || process.env.NEXT_REWRITE_API_TARGET || 'http://localhost:8080/api/v1';
const LOCAL_HOSTS = ['localhost', '127.0.0.1'];
const PLATFORM_HOSTS = (process.env.NEXT_PUBLIC_PLATFORM_DOMAIN || '')
  .split(',')
  .map((h) => h.trim().toLowerCase())
  .filter(Boolean);

/** Server-side structured error report to the ingest endpoint (no PII). */
function reportProxyFailure(path: string, detail: string) {
  try {
    const body = JSON.stringify({
      app: 'web',
      type: 'proxy',
      url: path,
      message: detail.slice(0, 500),
      metadata: { proxy: 'tenant-domain-resolution' },
    });
    if (body.length <= 16 * 1024) {
      fetch(`${API}/monitoring/ingest`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body,
      }).catch(() => {});
    }
  } catch {
    // never break the request path
  }
}

/**
 * Strict CSP with a per-request nonce — see `./lib/csp.ts` for the policy and
 * the nonce contract. The policy is identical for every route; only the nonce
 * changes per request.
 */
export async function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const host = request.headers.get('host')?.split(':')[0]?.toLowerCase() || '';

  // Skip API/BFF (JSON), assets, storage and file-like paths — CSP is only
  // meaningful on HTML documents. The interactive-question sandbox document
  // sets its OWN policy (see SANDBOX_PATH + buildSandboxCsp), so the app's
  // nonce policy must not be applied to it.
  if (
    pathname === SANDBOX_PATH ||
    pathname.startsWith('/api') ||
    pathname.startsWith('/_next') ||
    pathname.startsWith('/storage') ||
    pathname.includes('.')
  ) {
    return NextResponse.next();
  }

  const nonce = generateNonce();
  const csp = buildCsp(nonce);
  const requestHeaders = new Headers(request.headers);
  // Next.js parses the nonce out of the CSP **request** header and stamps it
  // onto the framework/page/inline scripts it renders. Without this line the
  // response advertises a nonce that no script carries, and `'strict-dynamic'`
  // makes the browser block every script. `x-nonce` is for server components.
  requestHeaders.set(NONCE_HEADER, nonce);
  requestHeaders.set(CSP_HEADER, csp);

  const withCsp = (response: NextResponse): NextResponse => {
    response.headers.set(CSP_HEADER, csp);
    // `no-transform` stops Cloudflare's Email Address Obfuscation from
    // rewriting our HTML (it would replace emails with a "[email protected]"
    // placeholder + a decoder script that our nonce CSP blocks, breaking the
    // address and causing a React hydration mismatch). See withNoTransform().
    response.headers.set('Cache-Control', withNoTransform(response.headers.get('Cache-Control')));

    return response;
  };

  const passThrough = () => withCsp(NextResponse.next({ request: { headers: requestHeaders } }));

  // Only handle custom domains / teacher subdomains (not localhost, and not
  // the bare platform host — but teacher subdomains like teacher.fahimni.me
  // DO go through the domain lookup below).
  if (LOCAL_HOSTS.includes(host) || host.endsWith('localhost') || !host) {
    return passThrough();
  }
  if (PLATFORM_HOSTS.some((h) => host === h || host === 'www.' + h)) {
    return passThrough();
  }

  // The Host header is attacker-controlled and is interpolated into the
  // API URL path — only allow plain DNS hostnames.
  if (!/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/.test(host)) {
    reportProxyFailure(pathname, 'invalid-host-header');

    return passThrough();
  }

  // Resolve the tenant for this host: a verified custom domain or a live
  // `{slug}.{platform}` subdomain. The lookup 200s only for those, so an
  // arbitrary/unverified Host never receives tenant context.
  let slug: string | null = null;
  try {
    const res = await fetch(`${API}/public/domain/${encodeURIComponent(host)}`, { headers: { Accept: 'application/json' } });
    if (res.ok) {
      const data = await res.json();
      if (typeof data?.site?.slug === 'string' && data.site.slug) slug = data.site.slug;
    } else if (res.status >= 500) {
      reportProxyFailure(pathname, `domain-lookup-${res.status}`);
    }
  } catch {
    reportProxyFailure(pathname, 'domain-lookup-unreachable');
  }

  if (!slug) return passThrough();

  // Stamp the tenant context onto EVERY page on this host — not only the
  // rewritten public pages. The browser URL keeps the clean, tenant-less path
  // (`/courses`, `/student/login`) while the server rewrite serves `/t/{slug}`,
  // so the route shell and branding read the tenant from these headers instead
  // of guessing from the path.
  requestHeaders.set(TENANT_SLUG_HEADER, slug);
  requestHeaders.set(TENANT_HOST_HEADER, '1');

  // Only the PUBLIC teacher-site pages are rewritten under /t/{slug}. The
  // signed-in app routes (my-learning, course learn, exams, results, card,
  // checkout, …) keep their own path — otherwise every page becomes the
  // teacher's home. Deep links therefore resolve to the real app route and a
  // hard refresh never 404s or bounces to the landing page.
  if (!pathname.startsWith('/t/')) {
    const rewritten = tenantSitePath(pathname, slug);

    if (rewritten) {
      const url = request.nextUrl.clone();
      url.pathname = rewritten;

      return withCsp(NextResponse.rewrite(url, { request: { headers: requestHeaders } }));
    }
  }

  return passThrough();
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico).*)'],
};
