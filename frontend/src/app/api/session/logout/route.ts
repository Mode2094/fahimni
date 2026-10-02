import { NextRequest } from 'next/server';
import { jsonWithCookies, statefulPost } from '@/lib/bff';

export const dynamic = 'force-dynamic';
export const runtime = 'nodejs';

/** POST /api/session/logout — destroys the server-side session. */
export async function POST(request: NextRequest) {
  const origin = new URL(request.url).origin;

  try {
    const { status, data, cookies } = await statefulPost('/auth/logout', origin, {}, {
      cookieHeader: request.headers.get('cookie'),
    });

    // Already signed out (expired session): treat as success. The stale
    // cookie is invalid server-side and is overwritten on the next login.
    if (status === 401) {
      return jsonWithCookies(200, { message: 'Logged out.' }, []);
    }

    return jsonWithCookies(status, data, cookies);
  } catch {
    return jsonWithCookies(502, { message: 'Authentication service unavailable.' }, []);
  }
}
