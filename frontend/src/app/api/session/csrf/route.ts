import { NextRequest, NextResponse } from 'next/server';
import { csrfHandshake } from '@/lib/bff';

export const dynamic = 'force-dynamic';
export const runtime = 'nodejs';

/**
 * GET /api/session/csrf — bootstraps the session + XSRF cookie pair so the
 * browser can make same-origin mutation requests. No secrets involved: the
 * XSRF token is a CSRF token, not an authentication credential.
 */
export async function GET(request: NextRequest) {
  const origin = new URL(request.url).origin;

  try {
    const { cookies } = await csrfHandshake(origin);

    const response = new NextResponse(null, { status: 204 });
    response.headers.set('Cache-Control', 'no-store');

    for (const cookie of cookies) {
      response.headers.append('Set-Cookie', cookie);
    }

    return response;
  } catch {
    return NextResponse.json({ message: 'Service unavailable.' }, { status: 502 });
  }
}
