import { NextRequest } from 'next/server';
import { jsonWithCookies, statefulPost } from '@/lib/bff';

export const dynamic = 'force-dynamic';
export const runtime = 'nodejs';

/**
 * POST /api/session/2fa — completes a pending 2FA challenge.
 *
 * The pending challenge lives in the Laravel session (set by the login
 * response), so the browser only sends the code + its session cookie.
 */
export async function POST(request: NextRequest) {
  const origin = new URL(request.url).origin;

  const payload = await request.json().catch(() => null);
  if (!payload || typeof payload !== 'object') {
    return jsonWithCookies(422, { message: 'Invalid request body.' }, []);
  }

  try {
    const { status, data, cookies } = await statefulPost('/auth/2fa/confirm', origin, payload, {
      cookieHeader: request.headers.get('cookie'),
      deviceId: request.headers.get('x-device-id'),
    });

    return jsonWithCookies(status, data, cookies);
  } catch {
    return jsonWithCookies(502, { message: 'Authentication service unavailable.' }, []);
  }
}
