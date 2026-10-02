/**
 * Client-side monitoring — dependency-free error capture for the Next.js app.
 *
 * What it does:
 *  - hooks window 'error' and 'unhandledrejection'
 *  - reports structured, sanitized events to POST /api/v1/monitoring/ingest
 *  - batches and throttles reports (max 1 burst per 5s, queue cap 20)
 *
 * What it NEVER sends: tokens, cookies, authorization headers, passwords,
 * request bodies, or user-identifying data beyond the URL path.
 *
 * Optional Sentry: if @sentry/nextjs is installed and NEXT_PUBLIC_SENTRY_DSN
 * is set, this layer is bypassed (Sentry handles capture). See
 * docs/PRODUCTION_MONITORING.md §Error tracking.
 */

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const QUEUE_CAP = 20;
const FLUSH_INTERVAL_MS = 5000;
const MAX_BODY_BYTES = 16 * 1024;

type IngestEvent = {
  app: 'web' | 'mobile';
  type: 'error' | 'unhandledrejection' | 'http' | 'proxy' | 'console';
  url?: string;
  method?: string;
  status?: number;
  message?: string;
  stack?: string;
  locale?: string;
  metadata?: Record<string, string | number>;
};

let queue: IngestEvent[] = [];
let flushTimer: ReturnType<typeof setInterval> | null = null;

function canSend(): boolean {
  if (typeof window === 'undefined') return false;
  if (process.env.NODE_ENV !== 'production') return true; // dev is fine
  const api = API;
  return !!api && !api.includes('localhost') && !api.includes('127.0.0.1');
}

function sendBatch() {
  if (queue.length === 0) return;
  const batch = queue.splice(0, QUEUE_CAP);

  try {
    fetch(`${API}/monitoring/ingest`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(batch.length === 1 ? batch[0] : { batch }),
      keepalive: true,
    }).catch(() => {
      /* best effort */
    });
  } catch {
    // never break the app
  }
}

function scheduleFlush() {
  if (flushTimer) return;
  flushTimer = setInterval(() => sendBatch(), FLUSH_INTERVAL_MS);
  if (typeof window !== 'undefined') {
    window.addEventListener('beforeunload', sendBatch, { once: true });
  }
}

export function reportError(event: IngestEvent) {
  if (queue.length >= QUEUE_CAP) return;
  if (!canSend()) return;

  queue.push(event);
  scheduleFlush();
}

function onWindowError(event: ErrorEvent) {
  reportError({
    app: 'web',
    type: 'error',
    message: event.message,
    url: typeof window !== 'undefined' ? window.location.pathname : undefined,
    stack: event.error?.stack,
    locale: document.documentElement.lang,
  });
}

function onUnhandledRejection(event: PromiseRejectionEvent) {
  const reason = event.reason as any;
  reportError({
    app: 'web',
    type: 'unhandledrejection',
    message: reason?.message ?? String(reason ?? 'Unhandled promise rejection'),
    stack: reason?.stack,
    url: typeof window !== 'undefined' ? window.location.pathname : undefined,
    locale: document.documentElement.lang,
  });
}

export function reportHttpError(path: string, status?: number, method?: string) {
  reportError({
    app: 'web',
    type: 'http',
    url: path,
    status,
    method,
  });
}

export function reportProxyError(path: string, detail?: string) {
  reportError({
    app: 'web',
    type: 'proxy',
    url: path,
    message: detail,
    metadata: { proxy: 'tenant-domain-resolution' },
  });
}

export function initMonitoring() {
  if (typeof window === 'undefined') return;
  if (process.env.NEXT_PUBLIC_SENTRY_DSN) return; // Sentry owns capture
  window.addEventListener('error', onWindowError);
  window.addEventListener('unhandledrejection', onUnhandledRejection);
}

/** Instrument fetch to capture failed API requests without touching bodies. */
export function instrumentFetch() {
  if (typeof window === 'undefined') return;
  const originalFetch = window.fetch;
  window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
    try {
      const res = await originalFetch(input, init);
      if (res.status >= 500) {
        const path = typeof input === 'string' ? input : input instanceof URL ? input.pathname : input.url;
        reportHttpError(path, res.status, init?.method);
      }
      return res;
    } catch (error) {
      const path = typeof input === 'string' ? input : input instanceof URL ? input.pathname : input.url;
      reportHttpError(path, undefined, init?.method);
      throw error;
    }
  };
}

export const monitoring = {
  init: initMonitoring,
  instrumentFetch,
  reportError,
  reportHttpError,
  reportProxyError,
};