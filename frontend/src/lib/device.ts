export function getDeviceId(): string {
  if (typeof window === 'undefined') return '';
  let id = localStorage.getItem('lms_device_id');
  if (!id) {
    id = `dev-${Math.random().toString(36).slice(2, 10)}-${Date.now().toString(36)}`;
    localStorage.setItem('lms_device_id', id);
  }
  return id;
}

export function getDeviceName(): string {
  if (typeof window === 'undefined') return '';
  const nav = navigator as any;
  const ua = nav.userAgentData?.platform || navigator.platform || 'device';
  return `${ua} · ${navigator.vendor || ''}`.trim().slice(0, 180) || 'device';
}

function readCookie(name: string): string | null {
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

function ensureCsrfCookie(originalFetch: typeof fetch): Promise<void> {
  if (readCookie('XSRF-TOKEN')) return Promise.resolve();
  if (csrfInFlight) return csrfInFlight;

  csrfInFlight = originalFetch('/api/session/csrf', { credentials: 'same-origin', cache: 'no-store' })
    .then(() => undefined)
    .catch(() => undefined)
    .finally(() => {
      csrfInFlight = null;
    });

  return csrfInFlight;
}

function isSameOriginApi(input: RequestInfo | URL): boolean {
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
 * Attach the security headers every same-origin API call needs:
 *  - X-Device-Id (device binding for mobile-issued tokens + login policy)
 *  - X-XSRF-TOKEN on mutations (CSRF for the HttpOnly session cookie)
 *
 * Applied through a single window.fetch patch so the ~80 raw fetch call
 * sites do not need individual changes. Cookies are sent automatically for
 * same-origin requests.
 *
 * Anti-fraud extensions (e.g. FrameAnt) monkey-patch window.fetch and throw
 * "Failed to fetch" for requests they dislike — even same-origin ones. To
 * stay resilient we transparently retry through XMLHttpRequest (which
 * extensions do not patch) whenever the patched fetch throws.
 */
let patched = false;

function fetchViaXhr(input: RequestInfo | URL, init?: RequestInit): Promise<Response> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const url = typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;
    const method = (init?.method || (input instanceof Request ? input.method : 'GET') || 'GET').toUpperCase();

    xhr.open(method, url);

    const headers = new Headers(init?.headers || (input instanceof Request ? input.headers : undefined));
    headers.forEach((value, key) => xhr.setRequestHeader(key, value));

    let body: any = null;
    if (init?.body) {
      body = init.body;
    } else if (input instanceof Request) {
      body = input.body;
    }

    xhr.onload = () => {
      resolve(new Response(xhr.response, {
        status: xhr.status,
        statusText: xhr.statusText,
        headers: new Headers(xhr.getAllResponseHeaders().split(/\r?\n/).filter(Boolean).map((line) => line.split(': ')) as any),
      }));
    };
    xhr.onerror = () => reject(new Error('Failed to fetch'));
    xhr.onabort = () => reject(new Error('Aborted'));
    xhr.send(body as any);
  });
}

export function patchFetchSecurity(): void {
  if (typeof window === 'undefined' || patched) return;
  patched = true;

  const originalFetch = window.fetch.bind(window);

  const buildInit = async (input: RequestInfo | URL, init?: RequestInit): Promise<RequestInit> => {
    const headers = new Headers(init?.headers || (input instanceof Request ? input.headers : undefined));
    const method = requestMethod(input, init);

    if (isSameOriginApi(input)) {
      const unsafe = method === 'POST' || method === 'PUT' || method === 'PATCH' || method === 'DELETE';

      if (unsafe && !readCookie('XSRF-TOKEN')) {
        await ensureCsrfCookie(originalFetch);
      }

      const xsrf = readCookie('XSRF-TOKEN');
      if (unsafe && xsrf && !headers.has('X-XSRF-TOKEN')) {
        headers.set('X-XSRF-TOKEN', xsrf);
      }

      if (!headers.has('X-Device-Id')) {
        headers.set('X-Device-Id', getDeviceId());
      }
    } else if (headers.has('Authorization') && !headers.has('X-Device-Id')) {
      // Legacy/non-API authenticated calls (kept for mobile-web style usage).
      headers.set('X-Device-Id', getDeviceId());
    }

    return { ...init, headers, credentials: init?.credentials ?? 'same-origin' };
  };

  window.fetch = (input: RequestInfo | URL, init?: RequestInit) => {
    const run = async () => {
      const requestInit = await buildInit(input, init);

      try {
        const result = originalFetch(input, requestInit);

        // إضافات مثل FrameAnt ترمي "Failed to fetch" بشكل متزامن —
        // نلتقطها ونعيد عبر XHR.
        return await result.catch(() => fetchViaXhr(input, requestInit));
      } catch {
        return fetchViaXhr(input, requestInit);
      }
    };

    return run();
  };
}

/** @deprecated use patchFetchSecurity() */
export function patchFetchDeviceHeader(): void {
  patchFetchSecurity();
}
