/**
 * Server-only BFF helpers.
 *
 * The browser never receives a Sanctum token: login/2FA/logout run
 * server-to-server against Laravel and only HttpOnly cookies cross the wire.
 * Cookies are the Laravel session + XSRF token (the latter is intentionally
 * JS-readable — it is a CSRF token, never an authentication credential).
 */
import 'server-only';
import { NextResponse } from 'next/server';

export const LARAVEL_API_BASE = (
  process.env.INTERNAL_API_URL ||
  process.env.NEXT_REWRITE_API_TARGET ||
  'http://localhost:8080/api/v1'
).replace(/\/+$/, '');

const AUTH_PATHS = new Set(['/auth/login', '/auth/register', '/auth/2fa/confirm', '/auth/logout']);

export type CookieJar = Map<string, string>;

export function parseCookieHeader(header: string | null): CookieJar {
  const jar: CookieJar = new Map();

  if (!header) return jar;

  for (const pair of header.split(';')) {
    const idx = pair.indexOf('=');
    if (idx <= 0) continue;
    jar.set(pair.slice(0, idx).trim(), pair.slice(idx + 1).trim());
  }

  return jar;
}

export function jarToHeader(jar: CookieJar): string {
  return [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
}

/** Collect Set-Cookie headers (full strings) keyed by cookie name. */
export function collectSetCookies(headers: Headers): Map<string, string> {
  const out = new Map<string, string>();
  const list = typeof headers.getSetCookie === 'function' ? headers.getSetCookie() : [];

  for (const cookie of list) {
    const name = cookie.split('=', 1)[0]?.trim();
    if (name) out.set(name, cookie);
  }

  return out;
}

export function xsrfFromJar(jar: CookieJar): string | null {
  const raw = jar.get('XSRF-TOKEN');
  if (!raw) return null;

  try {
    return decodeURIComponent(raw);
  } catch {
    return raw;
  }
}

export function jsonWithCookies(
  status: number,
  body: unknown,
  cookies: Iterable<string>,
): NextResponse {
  const response = NextResponse.json(body, { status });
  response.headers.set('Cache-Control', 'no-store');

  for (const cookie of cookies) {
    response.headers.append('Set-Cookie', cookie);
  }

  return response;
}

/**
 * Start a stateful Laravel session and obtain the XSRF pair. A 401 from
 * /auth/me is expected — what matters is the Set-Cookie response.
 */
export async function csrfHandshake(origin: string): Promise<{ jar: CookieJar; cookies: string[] }> {
  const response = await fetch(`${LARAVEL_API_BASE}/auth/me`, {
    headers: { Accept: 'application/json', Origin: origin },
    cache: 'no-store',
    redirect: 'manual',
  });

  const setCookies = collectSetCookies(response.headers);
  const jar: CookieJar = new Map();

  for (const cookie of setCookies.values()) {
    const pair = cookie.split(';', 1)[0];
    const idx = pair.indexOf('=');
    if (idx > 0) jar.set(pair.slice(0, idx).trim(), pair.slice(idx + 1).trim());
  }

  return { jar, cookies: [...setCookies.values()] };
}

/**
 * Perform a stateful POST to Laravel (CSRF handshake + retry on 419) and
 * return the upstream response plus the Set-Cookie strings to forward.
 */
export async function statefulPost(
  path: string,
  origin: string,
  body: unknown,
  opts: { cookieHeader?: string | null; deviceId?: string | null } = {},
): Promise<{ status: number; data: unknown; cookies: string[] }> {
  if (!AUTH_PATHS.has(path)) {
    throw new Error(`statefulPost is restricted to auth paths, got ${path}`);
  }

  const run = async (jar: CookieJar, extraCookies: string[]) => {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Origin: origin,
      Cookie: jarToHeader(jar),
    };

    const xsrf = xsrfFromJar(jar);
    if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;
    if (opts.deviceId) headers['X-Device-Id'] = opts.deviceId;

    const response = await fetch(`${LARAVEL_API_BASE}${path}`, {
      method: 'POST',
      headers,
      body: JSON.stringify(body ?? {}),
      cache: 'no-store',
      redirect: 'manual',
    });

    const setCookies = collectSetCookies(response.headers);

    // Merge: upstream response wins, handshake fills any missing cookie
    // (e.g. a brand-new session without a re-issued XSRF).
    const merged = new Map<string, string>();
    for (const cookie of extraCookies) {
      const name = cookie.split('=', 1)[0]?.trim();
      if (name) merged.set(name, cookie);
    }
    for (const [name, cookie] of setCookies) merged.set(name, cookie);

    const data = await response.json().catch(() => ({}));

    return { status: response.status, data, cookies: [...merged.values()], jar };
  };

  // A provided browser cookie (logout/2FA) replaces the fresh handshake
  // session — otherwise we create a brand-new session for the credentials.
  let baseJar: CookieJar;
  let baseCookieStrings: string[];

  if (opts.cookieHeader && opts.cookieHeader.trim() !== '') {
    baseJar = parseCookieHeader(opts.cookieHeader);
    baseCookieStrings = [];
  } else {
    const handshake = await csrfHandshake(origin);
    baseJar = handshake.jar;
    baseCookieStrings = handshake.cookies;
  }

  let result = await run(baseJar, baseCookieStrings);

  if (result.status === 419) {
    const retry = await csrfHandshake(origin);
    result = await run(retry.jar, retry.cookies);
  }

  return { status: result.status, data: result.data, cookies: result.cookies };
}

/** Forward an authenticated GET to Laravel using the browser's cookies. */
export async function sessionGet(
  path: string,
  origin: string,
  cookieHeader: string | null,
): Promise<{ status: number; data: unknown; cookies: string[] }> {
  const response = await fetch(`${LARAVEL_API_BASE}${path}`, {
    headers: {
      Accept: 'application/json',
      Origin: origin,
      ...(cookieHeader ? { Cookie: cookieHeader } : {}),
    },
    cache: 'no-store',
    redirect: 'manual',
  });

  const data = await response.json().catch(() => ({}));
  const setCookies = collectSetCookies(response.headers);

  return { status: response.status, data, cookies: [...setCookies.values()] };
}
