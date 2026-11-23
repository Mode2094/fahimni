import { NextRequest } from 'next/server';
import { jsonWithCookies, statefulPost, withExpiredCookies } from '@/lib/bff';
import { requestOrigin } from '@/lib/request-origin';

export const dynamic = 'force-dynamic';
export const runtime = 'nodejs';

/** POST /api/session/logout — destroys the server-side session. */
export async function POST(request: NextRequest) {
  const origin = requestOrigin(request);

  try {
    const { status, data, cookies } = await statefulPost('/auth/logout', origin, {}, {
      cookieHeader: request.headers.get('cookie'),
    });

    // Already signed out (expired session): treat as success. Expire any
    // cookie the upstream did not clear so a dead session cannot come back.
    if (status === 401) {
      return jsonWithCookies(
        200,
        { message: 'Logged out.' },
        withExpiredCookies([], request.headers.get('cookie'), origin),
      );
    }

    return jsonWithCookies(
      status,
      data,
      withExpiredCookies(cookies, request.headers.get('cookie'), origin),
    );
  } catch {
    // Network/5xx: the server session may still exist, but the browser must
    // not keep a cookie that would silently restore it on the next load.
    return jsonWithCookies(
      200,
      { message: 'Logged out locally.' },
      withExpiredCookies([], request.headers.get('cookie'), origin),
    );
  }
}
