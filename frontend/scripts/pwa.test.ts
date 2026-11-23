/**
 * Student PWA regression tests — run with the built-in Node test runner:
 *
 *   cd frontend && node --test scripts/pwa.test.ts
 *
 * Two things are locked down here:
 *
 *  1. The pure helpers in src/lib/pwa.ts and src/lib/push.ts, which drive the
 *     install offer and the FCM token extraction. They are imported directly
 *     (Node strips the types), so a change in behaviour fails loudly.
 *
 *  2. The hard security rules baked into public/sw.js. The service worker is
 *     plain JS the bundler never sees, so a future edit could silently start
 *     caching account data or protected video. These assertions make that
 *     impossible without also failing the build.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src = (rel: string) => readFileSync(join(here, '..', rel), 'utf8');

const pwa = await import('../src/lib/pwa.ts');
const push = await import('../src/lib/push.ts');

/* ── src/lib/pwa.ts ─────────────────────────────────────────────────── */

test('isStandalone detects the media query and iOS navigator flag', () => {
  assert.equal(pwa.isStandalone({ matchMedia: () => ({ matches: true }) }), true);
  assert.equal(pwa.isStandalone({ matchMedia: () => ({ matches: false }) }), false);
  assert.equal(
    pwa.isStandalone({ matchMedia: () => ({ matches: false }), navigator: { standalone: true } }),
    true,
  );
});

test('isIosDevice covers iPhone, iPad and touch-Mac (iPadOS)', () => {
  assert.equal(pwa.isIosDevice('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'), true);
  assert.equal(pwa.isIosDevice('Mozilla/5.0 (iPad; CPU OS 16_0 like Mac OS X)'), true);
  assert.equal(pwa.isIosDevice('Mozilla/5.0 (Macintosh; Intel Mac OS X)', 5, 'MacIntel'), true);
  assert.equal(pwa.isIosDevice('Mozilla/5.0 (Macintosh; Intel Mac OS X)', 0, 'MacIntel'), false);
  assert.equal(pwa.isIosDevice('Mozilla/5.0 (Linux; Android 14)'), false);
});

test('shouldOfferInstall respects installed state, dismissal and capability', () => {
  const base = { installed: false, ios: false, nativePrompt: false, now: 1_000_000_000 };

  // Installed app never asks again.
  assert.equal(pwa.shouldOfferInstall({ ...base, installed: true }), false);
  // No native prompt and not iOS → nothing useful to show.
  assert.equal(pwa.shouldOfferInstall(base), false);
  // A native Chromium prompt is enough.
  assert.equal(pwa.shouldOfferInstall({ ...base, nativePrompt: true }), true);
  // On iOS we show the manual Safari steps.
  assert.equal(pwa.shouldOfferInstall({ ...base, ios: true }), true);
  // A recent dismissal keeps it quiet…
  assert.equal(
    pwa.shouldOfferInstall({
      ...base,
      ios: true,
      dismissedAt: base.now - 1000,
      cooldown: 10_000,
    }),
    false,
  );
  // …but only until the cool-down elapses.
  assert.equal(
    pwa.shouldOfferInstall({
      ...base,
      ios: true,
      dismissedAt: base.now - 20_000,
      cooldown: 10_000,
    }),
    true,
  );
});

test('isUpdateBlockedPath never interrupts an exam or a lesson', () => {
  assert.equal(pwa.isUpdateBlockedPath('/exam/123'), true);
  assert.equal(pwa.isUpdateBlockedPath('/course/9/learn'), true);
  assert.equal(pwa.isUpdateBlockedPath('/my-learning'), false);
  assert.equal(pwa.isUpdateBlockedPath('/notifications'), false);
});

test('install dismissal is a no-op without a window (SSR-safe)', () => {
  assert.equal(pwa.readInstallDismissedAt(), null);
});

/* ── src/lib/push.ts ────────────────────────────────────────────────── */

test('fcmTokenFromEndpoint extracts the FCM registration token', () => {
  assert.equal(
    push.fcmTokenFromEndpoint('https://fcm.googleapis.com/fcm/send/abc123:APA91bXYZ'),
    'abc123:APA91bXYZ',
  );
  assert.equal(push.fcmTokenFromEndpoint('https://fcm.googleapis.com/wp/someToken=='), 'someToken==');
});

test('fcmTokenFromEndpoint refuses non-FCM endpoints and garbage', () => {
  assert.equal(push.fcmTokenFromEndpoint('https://updates.push.services.mozilla.com/wpush/v2/x'), null);
  assert.equal(push.fcmTokenFromEndpoint('not a url'), null);
  assert.equal(push.fcmTokenFromEndpoint('https://fcm.googleapis.com/'), null);
});

test('urlBase64ToUint8Array decodes a VAPID-style key (base64url, unpadded)', () => {
  globalThis.window = { atob: (s: string) => Buffer.from(s, 'base64').toString('binary') } as never;
  const bytes = push.urlBase64ToUint8Array('BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U');
  assert.ok(bytes instanceof Uint8Array);
  assert.equal(bytes[0], 4); // first byte of a P-256 uncompressed key
  assert.equal(bytes.length, 65);
});

/* ── public/sw.js security invariants ──────────────────────────────── */

const sw = src('public/sw.js');

test('sw never caches account/API traffic', () => {
  assert.match(sw, /function isApiRequest\(/, 'sw must define an API guard');
  assert.match(sw, /isApiRequest\(url\)\) return;/, 'API requests must fall through to the network');
});

test('sw never handles cross-origin requests (Bunny video stays off-device)', () => {
  assert.match(
    sw,
    /url\.origin !== self\.location\.origin\) return;/,
    'cross-origin requests must not be intercepted',
  );
  assert.match(sw, /url\.protocol !== 'http:'/, 'non-HTTP(S) (blob:) requests must be left alone');
});

test('sw only caches immutable build assets, not navigations', () => {
  assert.match(sw, /startsWith\('\/_next\/static\/'\)/, 'only /_next/static is precacheable');
  // The navigation path is network-first and the HTML is never written to cache.
  const nav = sw.slice(sw.indexOf('async function handleNavigate'));
  assert.match(nav, /await fetch\(request\)/, 'navigations must try the network first');
  assert.doesNotMatch(nav, /cache\.put\(/, 'private HTML must never be cached');
});

test('sw install does not skipWaiting — the student controls the update', () => {
  const install = sw.slice(sw.indexOf("addEventListener('install'"), sw.indexOf("addEventListener('activate'"));
  assert.doesNotMatch(install, /self\.skipWaiting\(/, 'install must not force an immediate takeover');
  assert.match(sw, /addEventListener\('message'/, 'a message handler must apply the update on request');
});

test('sw push/notification handlers exist and only open same-origin paths', () => {
  assert.match(sw, /addEventListener\('push'/, 'a push handler is required for web push');
  assert.match(sw, /addEventListener\('notificationclick'/, 'notification clicks must be handled');
  const guard = sw.slice(sw.indexOf('function safeInternalUrl'));
  assert.match(guard, /startsWith\('\/\/'\)/, 'protocol-relative redirects must be rejected');
});
