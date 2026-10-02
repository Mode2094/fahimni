import { NextRequest } from 'next/server';
import { jsonWithCookies, statefulPost } from '@/lib/bff';

export const dynamic = 'force-dynamic';
export const runtime = 'nodejs';

/** POST /api/session/login — credentials in, HttpOnly session cookie out. */
export async function POST(request: NextRequest) {
  const origin = new URL(request.url).origin;

  const payload = await request.json().catch(() => null);
  if (!payload || typeof payload !== 'object') {
    return jsonWithCookies(422, { message: 'Invalid request body.' }, []);
  }

  try {
    const { status, data, cookies } = await statefulPost('/auth/login', origin, payload, {
      deviceId: request.headers.get('x-device-id'),
    });

    return jsonWithCookies(status, data, cookies);
  } catch {
    return jsonWithCookies(502, { message: 'Authentication service unavailable.' }, []);
  }
}
