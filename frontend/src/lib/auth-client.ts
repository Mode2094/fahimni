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

/** Read the current workspace selection without making it an auth credential. */
export function getStoredTenantId(): string | null {
  if (typeof window === 'undefined') return null;

  try {
    const tenantId = localStorage.getItem('tenant_id')?.trim();
    return tenantId || null;
  } catch {
    // Private browsing/storage restrictions must not prevent public/API reads.
    return null;
  }
}

export function setStoredTenantId(tenantId: string | null): void {
  if (typeof window === 'undefined') return;

  try {
    if (tenantId) localStorage.setItem('tenant_id', tenantId);
    else localStorage.removeItem('tenant_id');
  } catch {
    // The server-side session and validated membership remain authoritative.
  }
}

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
 * calls (parallel mutations on first load). `force` re-fetches after a 419
 * (the server rotated the session/CSRF pair).
 */
export function ensureCsrfCookie(force = false): Promise<void> {
  if (typeof window === 'undefined') return Promise.resolve();
  if (!force && readCookie('XSRF-TOKEN')) return Promise.resolve();
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
    const url = typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;
    const pathname = new URL(url, window.location.origin).pathname;
    const method = requestMethod(input, init);

    // BFF session endpoints have their own context. Tenant-scoped Laravel API
    // calls share the persisted workspace selection; the server still validates
    // membership before using it.
    if ((pathname === '/api/v1' || pathname.startsWith('/api/v1/')) && !headers.has('X-Tenant-Id')) {
      const tenantId = getStoredTenantId();
      if (tenantId) headers.set('X-Tenant-Id', tenantId);
    }

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
 * header on mutations, device id, a single retry after a rotated CSRF token,
 * and a global "session expired" signal on a real 401.
 *
 * It deliberately does NOT clear local state itself: a 401 from a login
 * attempt is not an expired session, and a transient 5xx must never look
 * like a logout. The auth store owns that decision.
 */
export async function authFetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
  const method = requestMethod(input, init);
  const sameOrigin = isSameOriginApi(input);
  const unsafe = sameOrigin && UNSAFE_METHODS.has(method);

  if (unsafe) {
    await ensureCsrfCookie();
  }

  const run = () => fetch(input, {
    ...enrichApiInit(input, init),
    credentials: 'same-origin',
  });

  let response = await run();

  // The session/CSRF pair can rotate while a tab is open (another tab logged
  // in or out). Laravel rejects the stale token before the controller runs,
  // so one retry with a fresh pair is safe and invisible to the user.
  if (response.status === 419 && unsafe) {
    await ensureCsrfCookie(true);
    response = await run();
  }

  if (response.status === 401 && sameOrigin && !isSessionEndpoint(input)) {
    // An expired Super-Admin support session is not a logout: the server has
    // already restored the original admin session, so the app must return to
    // the admin panel instead of dropping the admin's local state.
    let code: string | null = null;

    try {
      code = (await response.clone().json())?.code ?? null;
    } catch {
      // Non-JSON 401 — treat it as an ordinary unauthorized response.
    }

    window.dispatchEvent(new CustomEvent(
      code === 'SUPPORT_EXPIRED' ? 'auth:support-expired' : 'auth:unauthorized',
      { detail: { at: Date.now() } },
    ));
  }

  return response;
}

function isSessionEndpoint(input: RequestInfo | URL): boolean {
  if (typeof window === 'undefined') return false;

  try {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;

    return new URL(url, window.location.origin).pathname.startsWith('/api/session/');
  } catch {
    return false;
  }
}

/** Non-sensitive UI hint that a session cookie may exist. Never a credential. */
export function hasSessionHint(): boolean {
  if (typeof window === 'undefined') return false;

  try {
    return localStorage.getItem(SESSION_HINT) === '1';
  } catch {
    return false;
  }
}

export function setSessionHint(active: boolean): void {
  if (typeof window === 'undefined') return;

  try {
    if (active) localStorage.setItem(SESSION_HINT, '1');
    else localStorage.removeItem(SESSION_HINT);
  } catch {
    // The HttpOnly cookie is the source of truth.
  }
}

/** Clear cached profile data only — never touches HttpOnly credentials. */
export function clearStoredUser(): void {
  if (typeof window === 'undefined') return;

  try {
    localStorage.removeItem(SESSION_HINT);
    localStorage.removeItem('user');
  } catch {
    // Keep the caller's auth flow working when storage is unavailable.
  }
  setStoredTenantId(null);
}

/**
 * Remove legacy credential artefacts from older builds on first run.
 * Called once at boot.
 */
export function clearLegacyAuthStorage(): void {
  if (typeof window === 'undefined') return;

  try {
    for (const key of ['token', 'user_role', 'lms_remembered_password', 'lms_admin_remembered_password']) {
      localStorage.removeItem(key);
    }
  } catch {
    // Legacy storage cleanup is best effort.
  }
}
