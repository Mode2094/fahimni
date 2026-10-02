import { NextRequest } from 'next/server';
import { jsonWithCookies, sessionGet } from '@/lib/bff';

export const dynamic = 'force-dynamic';
export const runtime = 'nodejs';

/** GET /api/session/me — current user from the HttpOnly session. */
export async function GET(request: NextRequest) {
  const origin = new URL(request.url).origin;

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
