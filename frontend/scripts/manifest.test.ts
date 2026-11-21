/**
 * PWA manifest regression tests — run with the built-in Node test runner:
 *
 *   cd frontend && node --test scripts/manifest.test.ts
 *
 * The platform and every teacher share ONE manifest URL
 * (/manifest.webmanifest); the served document differs per tenant and is
 * resolved from Referer/Host. These tests lock in the two properties that keep
 * that safe, so a future edit cannot silently reintroduce the bug:
 *
 *  1. The response must never be publicly cached (a shared cache keyed on the
 *     URL alone would hand one teacher's name/logo/start_url to another).
 *  2. It must vary on the Referer and Host headers it is resolved from.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src = (rel: string) => readFileSync(join(here, '..', rel), 'utf8');

test('manifest is never publicly cached across tenants', () => {
  const route = src('src/app/manifest.webmanifest/route.ts');

  assert.doesNotMatch(
    route,
    /Cache-Control'\s*:\s*'public/,
    'manifest must never be served with a public/shared Cache-Control',
  );
  assert.match(
    route,
    /Cache-Control'\s*:\s*'private, no-store'/,
    'manifest must be private, no-store so shared caches cannot bleed identities',
  );
});

test('manifest varies on the headers it is resolved from', () => {
  const route = src('src/app/manifest.webmanifest/route.ts');

  assert.match(route, /'Vary'\s*:\s*'Referer, Host'/, "manifest must set Vary: Referer, Host");
});

test('every response uses the single hardened header set', () => {
  const route = src('src/app/manifest.webmanifest/route.ts');

  const occurrences = route.match(/MANIFEST_HEADERS/g) ?? [];
  // 1 definition + the platform response + the tenant response.
  assert.ok(occurrences.length >= 3, 'both responses must use the shared MANIFEST_HEADERS');
});

test('each teacher app keeps its own identity and start_url', () => {
  const route = src('src/app/manifest.webmanifest/route.ts');

  assert.match(route, /start_url:\s*teacherPath/, 'tenant manifest start_url must be the tenant path');
  assert.match(route, /id:\s*teacherPath/, 'tenant manifest id must be the tenant path (no OS-app collision)');
  // The platform app launches the session-aware /app entry, which restores the
  // signed-in user's role dashboard instead of the public landing page.
  assert.match(route, /start_url:\s*'\/app'/, 'platform manifest must launch the session-aware /app entry');
});
