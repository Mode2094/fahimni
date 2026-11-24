import { NextRequest } from 'next/server';
import { jsonWithCookies, sessionGet } from '@/lib/bff';
import { resolveRequestOrigin } from '@/lib/request-origin';

export const dynamic = 'force-dynamic';
export const runtime = 'nodejs';

/**
 * GET /api/session/refresh — re-validates the session and refreshes the
 * XSRF cookie. Returns the current user, or 401 when the session ended.
 */
export async function GET(request: NextRequest) {
  const origin = await resolveRequestOrigin(request);

  try {
    const { status, data, cookies } = await sessionGet(
      '/auth/me',
      origin,
      request.headers.get('cookie'),
    );

    return jsonWithCookies(status, data, cookies);
  } catch {
    return jsonWithCookies(502, { message: 'Authentication service unavailable.' }, []);
  }
}
