import { NextRequest } from 'next/server';
import { jsonWithCookies, statefulPost } from '@/lib/bff';
import { requestOrigin } from '@/lib/request-origin';

export const dynamic = 'force-dynamic';
export const runtime = 'nodejs';

/**
 * POST /api/session/complete-profile — completes an authenticated teacher's
 * mandatory profile (name + phone). The session already exists, so the
 * browser's cookie is forwarded and kept; a failure never replaces it.
 */
export async function POST(request: NextRequest) {
  const origin = requestOrigin(request);

  const payload = await request.json().catch(() => null);
  if (!payload || typeof payload !== 'object') {
    return jsonWithCookies(422, { message: 'Invalid request body.' }, []);
  }

  try {
    const { status, data, cookies } = await statefulPost('/auth/complete-profile', origin, payload, {
      cookieHeader: request.headers.get('cookie'),
      deviceId: request.headers.get('x-device-id'),
    });

    return jsonWithCookies(status, data, cookies);
  } catch {
    return jsonWithCookies(502, { message: 'Authentication service unavailable.' }, []);
  }
}
