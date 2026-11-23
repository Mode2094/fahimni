/* eslint-disable no-restricted-globals */
/**
 * Fahimni student PWA service worker.
 *
 * Design rules (locked in by scripts/pwa.test.ts):
 *  - Same-origin only. Every cross-origin request (Bunny video, fonts CDN,
 *    analytics, …) is passed straight to the network and never cached, so
 *    protected video can never be stored on the device.
 *  - Account/API data is NEVER cached. /api/* always goes to the network, so a
 *    shared device can never be shown a previous user's courses or balance.
 *  - HTML navigations are never cached either; a failed navigation falls back
 *    to the static /offline page instead of a stale private document.
 *  - Only versioned, immutable assets (/_next/static/*, icons, fonts) are
 *    cached, using stale-while-revalidate.
 *
 * Bumping CACHE_VERSION (or any byte change here) is what tells the browser a
 * new worker is available; the page then offers the student an "update" button.
 */
const CACHE_VERSION = 'fahimni-pwa-v1';
const SHELL_CACHE = `${CACHE_VERSION}-shell`;
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const OFFLINE_URL = '/offline';
const KEEP = new Set([SHELL_CACHE, STATIC_CACHE]);

const SHELL_ASSETS = [
  OFFLINE_URL,
  '/icon-192.png',
  '/icon-512.png',
  '/icon-maskable-512.png',
  '/apple-icon.png',
];

self.addEventListener('install', (event) => {
  // No skipWaiting() here on purpose: a waiting worker lets the page show an
  // "update ready" button so the student controls when the app reloads.
  event.waitUntil(
    (async () => {
      const cache = await caches.open(SHELL_CACHE);
      await Promise.all(
        SHELL_ASSETS.map((asset) =>
          cache.add(new Request(asset, { cache: 'reload' })).catch(() => {}),
        ),
      );
    })(),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(keys.filter((k) => !KEEP.has(k)).map((k) => caches.delete(k)));
      await self.clients.claim();
    })(),
  );
});

/** Immutable build assets and static files worth caching. */
function isCacheableAsset(url) {
  if (url.origin !== self.location.origin) return false;
  if (url.pathname.startsWith('/_next/static/')) return true;

  return /\.(?:css|js|mjs|woff2?|ttf|otf|png|jpe?g|gif|svg|webp|avif|ico)$/i.test(url.pathname);
}

/** Account data, auth and mutations must always hit the network uncached. */
function isApiRequest(url) {
  return url.origin === self.location.origin && url.pathname.startsWith('/api/');
}

async function staleWhileRevalidate(request) {
  const cache = await caches.open(STATIC_CACHE);
  const cached = await cache.match(request);

  const network = fetch(request)
    .then((response) => {
      // Never store a partial or error response.
      if (response && response.ok && response.status === 200) {
        cache.put(request, response.clone()).catch(() => {});
      }

      return response;
    })
    .catch(() => cached);

  return cached || network;
}

async function handleNavigate(request) {
  try {
    // Network First for documents; the HTML itself is never written to cache.
    return await fetch(request);
  } catch {
    const cache = await caches.open(SHELL_CACHE);
    const offline = await cache.match(OFFLINE_URL);
    if (offline) return offline;

    return new Response(
      '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>غير متصل</title><body style="font-family:system-ui;padding:2rem;text-align:center"><h1>لا يوجد اتصال بالإنترنت</h1><p>تحقّق من الشبكة ثم أعد المحاولة.</p></body></html>',
      { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } },
    );
  }
}

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') return;

  let url;
  try {
    url = new URL(request.url);
  } catch {
    return;
  }

  // Only ever touch real HTTP(S) requests (blob: assets, e.g. exercise
  // sandboxes, are left entirely to the browser).
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return;

  // Cross-origin (video CDN, fonts, analytics): never intercepted, never cached.
  if (url.origin !== self.location.origin) return;

  if (isApiRequest(url)) return; // network-only, browser handles it

  if (request.mode === 'navigate') {
    event.respondWith(handleNavigate(request));

    return;
  }

  if (isCacheableAsset(url)) {
    event.respondWith(staleWhileRevalidate(request));
  }
});

/* ── Web push (FCM-compatible payloads) ─────────────────────────────── */

self.addEventListener('push', (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch {
    payload = { notification: { body: (event.data && event.data.text && event.data.text()) || '' } };
  }

  const notification = payload.notification || {};
  const data = payload.data || {};
  const title = notification.title || data.title || 'فهمني';
  const body = notification.body || data.body || '';
  const url = safeInternalUrl(data.url || data.action_url || '/notifications');

  event.waitUntil(
    self.registration.showNotification(title, {
      body,
      icon: notification.icon || data.icon || '/icon-192.png',
      badge: '/icon-192.png',
      dir: 'rtl',
      lang: 'ar',
      data: { url },
    }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = safeInternalUrl(event.notification.data && event.notification.data.url);

  event.waitUntil(
    (async () => {
      const clientList = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

      for (const client of clientList) {
        if (new URL(client.url).origin === self.location.origin) {
          await client.focus();
          client.postMessage({ type: 'fahimni:navigate', url: target });

          return;
        }
      }

      await self.clients.openWindow(target);
    })(),
  );
});

/** Only ever navigate to a same-origin absolute path. */
function safeInternalUrl(raw) {
  if (typeof raw !== 'string' || !raw.startsWith('/') || raw.startsWith('//')) {
    return '/notifications';
  }

  return raw;
}

/* ── Update handshake driven by the page's "update" button ──────────── */

self.addEventListener('message', (event) => {
  const msg = event.data || {};
  if (msg.type === 'fahimni:skip-waiting') {
    self.skipWaiting();
  }
});
