import { NextRequest } from 'next/server';
import { randomBytes } from 'node:crypto';
import { LARAVEL_API_BASE } from '@/lib/bff';
import { resolveRequestOrigin, isPlatformHostName } from '@/lib/request-origin';

export const dynamic = 'force-dynamic';
export const runtime = 'nodejs';

const BINDING_COOKIE = 'oauth_handoff';
const BINDING_TTL_SECONDS = 600;

/**
 * GET /api/session/google/redirect
 *
 * Starts the Google OAuth flow for THIS browser + host.
 *
 * On a platform host (`fahimni.me` / `www.` / `api.`) it just relays Laravel's
 * authorize URL: the central callback sets a session cookie for the platform
 * host, exactly as before.
 *
 * On a teacher's OWN host (custom domain or `{slug}.fahimni.me`) that cookie
 * would land on the platform, so we additionally mint an HttpOnly, host-only
 * `oauth_handoff` binding secret, set it here, and hand its hash to Laravel.
 * The callback then mints a single-use ticket bound to {host, tenant, browser}
 * and redirects back to this host, whose BFF redeems it for a host-only session
 * (see /api/session/google/redeem). The binding is what makes the ticket
 * unusable by any other browser (login-CSRF / session-fixation defence).
 */
export async function GET(request: NextRequest) {
  const origin = await resolveRequestOrigin(request);
  const incoming = request.nextUrl.searchParams;

  const params = new URLSearchParams();
  for (const key of ['role', 'name', 'phone', 'tenant_slug', 'redirect_to']) {
    const value = incoming.get(key);
    if (value !== null && value !== '') params.set(key, value);
  }

  let binding: string | null = null;
  if (!isPlatformHostName(new URL(origin).hostname)) {
    binding = randomBytes(32).toString('hex');
    params.set('binding', binding);
  }

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Cache-Control': 'no-store',
    'Content-Type': 'application/json',
  };

  if (binding) {
    const secure = origin.startsWith('https://') ? '; Secure' : '';
    const cookie = `${BINDING_COOKIE}=${binding}; Max-Age=${BINDING_TTL_SECONDS}; Path=/${secure}; HttpOnly; SameSite=Lax`;
    // `Set-Cookie` may repeat; a single binding cookie is enough, so a plain
    // header is fine here.
    headers['Set-Cookie'] = cookie;
  }

  try {
    const res = await fetch(`${LARAVEL_API_BASE}/auth/google/redirect?${params.toString()}`, {
      headers: { Accept: 'application/json', Origin: origin, Referer: `${origin}/` },
      cache: 'no-store',
      redirect: 'manual',
    });

    const data = await res.json().catch(() => ({}));

    return new Response(JSON.stringify(data), { status: res.status, headers });
  } catch {
    return new Response(JSON.stringify({ message: 'Google sign-in is unavailable right now.' }), {
      status: 502,
      headers: { 'Content-Type': 'application/json' },
    });
  }
}
