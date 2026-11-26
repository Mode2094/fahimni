import { NextRequest } from 'next/server';
import { jsonWithCookies, statefulPost } from '@/lib/bff';
import { resolveRequestOrigin } from '@/lib/request-origin';

export const dynamic = 'force-dynamic';
export const runtime = 'nodejs';

const BINDING_COOKIE = 'oauth_handoff';

/** Expire the host-only binding secret — it is valid for one redemption only. */
function expireBindingCookie(origin: string): string {
  const secure = origin.startsWith('https://') ? '; Secure' : '';

  return `${BINDING_COOKIE}=; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Path=/${secure}; HttpOnly; SameSite=Lax`;
}

/**
 * POST /api/session/google/redeem
 *
 * Exchanges a single-use Google handoff ticket (from the URL fragment) for a
 * host-only session on THIS host. The browser's HttpOnly `oauth_handoff` cookie
 * proves it is the same browser that started the flow, so a ticket cannot be
 * fixed onto another session. No token is ever accepted from — or returned in —
 * the URL; only HttpOnly cookies cross the wire.
 */
export async function POST(request: NextRequest) {
  const origin = await resolveRequestOrigin(request);

  const payload = await request.json().catch(() => null);
  const ticket = typeof payload?.ticket === 'string' ? payload.ticket : '';
  const binding = request.cookies.get(BINDING_COOKIE)?.value ?? '';

  if (!ticket) {
    return jsonWithCookies(422, { message: 'Missing sign-in ticket.' }, [expireBindingCookie(origin)]);
  }

  try {
    const { status, data, cookies } = await statefulPost('/auth/google/redeem', origin, {
      ticket,
      binding,
    });

    return jsonWithCookies(status, data, [...cookies, expireBindingCookie(origin)]);
  } catch {
    return jsonWithCookies(502, { message: 'Sign-in service unavailable.' }, [expireBindingCookie(origin)]);
  }
}
