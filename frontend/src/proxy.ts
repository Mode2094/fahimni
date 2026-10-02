import { NextResponse } from 'next/server';
import type { NextRequest } from 'next/server';

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

function generateNonce(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);

  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);

  return btoa(binary);
}

/**
 * Strict CSP with a per-request nonce. `'strict-dynamic'` lets the nonced
 * Next.js runtime load the dynamically-injected SDKs (Paddle, PayPal, KaTeX)
 * while blocking injected inline scripts. `'unsafe-eval'` is dev-only
 * (Turbopack/React Refresh); the production build does not need it.
 */
function buildCsp(nonce: string): string {
  const isDev = process.env.NODE_ENV !== 'production';

  return [
    "default-src 'self'",
    "base-uri 'self'",
    "object-src 'none'",
    "frame-ancestors 'self'",
    "form-action 'self' https://*.paddle.com https://checkout.paddle.com https://www.paypal.com https://*.paypal.com",
    `connect-src 'self' https://cdn.jsdelivr.net wss: https://*.b-cdn.net https://video.bunnycdn.com https://*.bunnycdn.com https://www.paypal.com https://www.paypalobjects.com https://*.paypal.com https://*.paypalobjects.com https://*.paddle.com https://checkout.paddle.com`,
    [
      "script-src 'self' 'nonce-" + nonce + "' 'strict-dynamic'",
      isDev ? "'unsafe-eval'" : '',
      'https://cdn.jsdelivr.net',
      'https://www.paypal.com https://www.paypalobjects.com https://www.sandbox.paypal.com',
      'https://*.paypal.com https://*.paypalobjects.com',
      'https://cdn.paddle.com https://*.paddle.com',
    ]
      .filter(Boolean)
      .join(' '),
    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://*.paddle.com",
    "img-src 'self' data: blob: https:",
    "media-src 'self' blob: https: https://*.b-cdn.net https://video.bunnycdn.com",
    "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com",
    "frame-src 'self' https://www.youtube.com https://player.vimeo.com https://www.google.com https://meet.jit.si https://iframe.videodelivery.net https://www.paypal.com https://*.paypal.com https://*.paddle.com https://checkout.paddle.com",
    "worker-src 'self' blob:",
    "manifest-src 'self'",
  ].join('; ');
}

export async function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const host = request.headers.get('host')?.split(':')[0]?.toLowerCase() || '';

  // Skip API/BFF (JSON), assets, storage and file-like paths — CSP is only
  // meaningful on HTML documents.
  if (
    pathname.startsWith('/api') ||
    pathname.startsWith('/_next') ||
    pathname.startsWith('/storage') ||
    pathname.includes('.')
  ) {
    return NextResponse.next();
  }

  const nonce = generateNonce();
  const requestHeaders = new Headers(request.headers);
  requestHeaders.set('x-nonce', nonce);

  const withCsp = (response: NextResponse): NextResponse => {
    response.headers.set('Content-Security-Policy', buildCsp(nonce));

    return response;
  };

  const passThrough = () => withCsp(NextResponse.next({ request: { headers: requestHeaders } }));

  // Only handle custom domains / teacher subdomains (not localhost, and not
  // the bare platform host — but teacher subdomains like teacher.tadreesly.com
  // DO go through the domain lookup below).
  if (LOCAL_HOSTS.includes(host) || host.endsWith('localhost') || !host) {
    return passThrough();
  }
  if (PLATFORM_HOSTS.some((h) => host === h || host === 'www.' + h)) {
    return passThrough();
  }

  // If already on a /t/ route, skip
  if (pathname.startsWith('/t/')) {
    return passThrough();
  }

  // The Host header is attacker-controlled and is interpolated into the
  // API URL path — only allow plain DNS hostnames.
  if (!/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/.test(host)) {
    reportProxyFailure(pathname, 'invalid-host-header');

    return passThrough();
  }

  try {
    const res = await fetch(`${API}/public/domain/${encodeURIComponent(host)}`, { headers: { Accept: 'application/json' } });
    if (res.ok) {
      const data = await res.json();
      const slug = data?.site?.slug;
      if (slug) {
        const url = request.nextUrl.clone();
        url.pathname = `/t/${slug}`;

        return withCsp(NextResponse.rewrite(url, { request: { headers: requestHeaders } }));
      }
    } else if (res.status >= 500) {
      reportProxyFailure(pathname, `domain-lookup-${res.status}`);
    }
  } catch (err) {
    // fall through
    reportProxyFailure(pathname, 'domain-lookup-unreachable');
  }

  return passThrough();
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico).*)'],
};
