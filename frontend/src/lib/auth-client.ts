'use client';

import { getDeviceId } from '@/lib/device';

/**
 * Client-side auth helpers for the BFF session architecture.
 *
 * The browser never holds a bearer token: authentication is an HttpOnly
 * session cookie set by /api/session/*. The only JS-readable cookie is
 * XSRF-TOKEN — a CSRF token, not an authentication credential.
 */

export const API_BASE = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const UNSAFE_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);
const SESSION_HINT = 'lms_authed';

export function readCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;

  const row = document.cookie.split('; ').find((entry) => entry.startsWith(name + '='));
  if (!row) return null;

  const raw = row.slice(name.length + 1);

  try {
    return decodeURIComponent(raw);
  } catch {
    return raw;
  }
}

let csrfInFlight: Promise<void> | null = null;

/**
 * Make sure the browser holds a session + XSRF cookie pair. Only performs a
 * network call when the XSRF cookie is missing, and deduplicates concurrent
 * calls (parallel mutations on first load).
 */
export function ensureCsrfCookie(): Promise<void> {
  if (typeof window === 'undefined') return Promise.resolve();
  if (readCookie('XSRF-TOKEN')) return Promise.resolve();
  if (csrfInFlight) return csrfInFlight;

  csrfInFlight = fetch('/api/session/csrf', { credentials: 'same-origin', cache: 'no-store' })
    .then(() => undefined)
    .catch(() => undefined)
    .finally(() => {
      csrfInFlight = null;
    });

  return csrfInFlight;
}

export function isSameOriginApi(input: RequestInfo | URL): boolean {
  if (typeof window === 'undefined') return false;

  try {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;
    const resolved = new URL(url, window.location.origin);

    return resolved.origin === window.location.origin && resolved.pathname.startsWith('/api/');
  } catch {
    return false;
  }
}

function requestMethod(input: RequestInfo | URL, init?: RequestInit): string {
  return (init?.method || (input instanceof Request ? input.method : 'GET') || 'GET').toUpperCase();
}

/**
 * Enrich same-origin API requests: session cookies are sent automatically,
 * mutations get the XSRF header (CSRF), every call carries the device id.
 */
export function enrichApiInit(input: RequestInfo | URL, init: RequestInit = {}): RequestInit {
  const headers = new Headers(input instanceof Request ? input.headers : undefined);
  new Headers(init.headers).forEach((value, key) => headers.set(key, value));

  if (isSameOriginApi(input)) {
    const method = requestMethod(input, init);

    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf && UNSAFE_METHODS.has(method) && !headers.has('X-XSRF-TOKEN')) {
      headers.set('X-XSRF-TOKEN', xsrf);
    }

    const device = getDeviceId();
    if (device && !headers.has('X-Device-Id')) {
      headers.set('X-Device-Id', device);
    }

    if (!headers.has('Accept')) headers.set('Accept', 'application/json');
  }

  return { ...init, headers };
}

/**
 * fetch() wrapper for authenticated app calls: same-origin credentials, CSRF
 * header on mutations, device id, and session cleanup on 401.
 */
export async function authFetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
  const method = requestMethod(input, init);

  if (isSameOriginApi(input) && UNSAFE_METHODS.has(method)) {
    await ensureCsrfCookie();
  }

  const response = await fetch(input, {
    ...enrichApiInit(input, init),
    credentials: 'same-origin',
  });

  if (response.status === 401 && isSameOriginApi(input)) {
    clearStoredUser();
  }

  return response;
}

/** Non-sensitive UI hint that a session cookie may exist. Never a credential. */
export function hasSessionHint(): boolean {
  if (typeof window === 'undefined') return false;

  return localStorage.getItem(SESSION_HINT) === '1';
}

export function setSessionHint(active: boolean): void {
  if (typeof window === 'undefined') return;

  if (active) localStorage.setItem(SESSION_HINT, '1');
  else localStorage.removeItem(SESSION_HINT);
}

/** Clear cached profile data only — never touches HttpOnly credentials. */
export function clearStoredUser(): void {
  if (typeof window === 'undefined') return;

  localStorage.removeItem(SESSION_HINT);
  localStorage.removeItem('user');
  localStorage.removeItem('tenant_id');
}

/**
 * Remove legacy credential artefacts from older builds on first run.
 * Called once at boot.
 */
export function clearLegacyAuthStorage(): void {
  if (typeof window === 'undefined') return;

  for (const key of ['token', 'user_role', 'lms_remembered_password', 'lms_admin_remembered_password']) {
    localStorage.removeItem(key);
  }
  
}
