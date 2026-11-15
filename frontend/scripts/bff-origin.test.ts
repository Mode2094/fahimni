/**
 * Regression tests for the BFF → Laravel `Origin` header.
 *
 *   cd frontend && node --test scripts/bff-origin.test.ts
 *
 * Root cause pinned here: behind nginx/Caddy, Next.js resolves `request.url`
 * to its own listen address (`http://<container>:3000`), never the public host.
 * Forwarding that internal origin made Sanctum's stateful-domain check fail, so
 * Laravel never started a session and `/api/v1/auth/me` answered 401 — the
 * «تعذر إنشاء الجلسة» screen after Google sign-in. The origin must be rebuilt
 * from the proxy headers instead.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import { requestOrigin, isTrustedHost } from '../src/lib/request-origin.ts';

// Pin the platform domain so the host allowlist is deterministic in CI (the
// build step sets a *different* placeholder, and the unit step must not depend
// on whatever the shell happens to export).
delete process.env.NEXT_PUBLIC_SITE_URL;
process.env.NEXT_PUBLIC_PLATFORM_DOMAIN = 'fahimni.me';

const here = dirname(fileURLToPath(import.meta.url));
const src = (rel: string) => readFileSync(join(here, '..', rel), 'utf8');

/** A minimal `NextRequest`-shaped stub: `url` + case-insensitive `headers.get`. */
function req(headers: Record<string, string>, url = 'http://localhost:3000/api/session/me') {
  const lowered = new Map(Object.entries(headers).map(([k, v]) => [k.toLowerCase(), v]));

  return { url, headers: { get: (name: string) => lowered.get(name.toLowerCase()) ?? null } };
}

test('behind nginx it rebuilds the public https origin from the proxy headers', () => {
  assert.equal(
    requestOrigin(req({ host: 'fahimni.me', 'x-forwarded-host': 'fahimni.me', 'x-forwarded-proto': 'https' })),
    'https://fahimni.me',
  );
});

test('the internal listen origin is never forwarded to Laravel', () => {
  // request.url is the container address; the host header is the public one.
  const origin = requestOrigin(req({ host: 'fahimni.me', 'x-forwarded-proto': 'https' }));

  assert.equal(origin, 'https://fahimni.me');
  assert.notEqual(origin, 'http://localhost:3000');
});

test('it keeps the port for local development hosts', () => {
  assert.equal(
    requestOrigin(req({ host: 'localhost:3000' }, 'http://localhost:3000/api/session/me')),
    'http://localhost:3000',
  );
});

test('x-forwarded-host wins over host', () => {
  assert.equal(
    requestOrigin(req({ host: 'frontend:3000', 'x-forwarded-host': 'www.fahimni.me', 'x-forwarded-proto': 'https' })),
    'https://www.fahimni.me',
  );
});

test('the first value of a comma-joined proxy header is used', () => {
  assert.equal(
    requestOrigin(req({
      host: 'fahimni.me',
      'x-forwarded-host': 'fahimni.me, internal',
      'x-forwarded-proto': 'https, http',
    })),
    'https://fahimni.me',
  );
});

test('without a host header it falls back to the resolved request url', () => {
  assert.equal(requestOrigin(req({}, 'https://fahimni.me/api/session/me')), 'https://fahimni.me');
});

test('every session route derives the origin from requestOrigin, not request.url', () => {
  for (const route of ['me', 'login', 'logout', 'register', '2fa', 'csrf', 'refresh']) {
    const file = src(`src/app/api/session/${route}/route.ts`);

    assert.match(file, /requestOrigin\(request\)/, `${route} route must use requestOrigin(request)`);
    assert.doesNotMatch(
      file,
      /new URL\(request\.url\)\.origin/,
      `${route} route must not forward the internal request.url origin`,
    );
  }
});

test('the BFF pins Origin and Referer on every Laravel call', () => {
  const bff = src('src/lib/bff.ts');

  assert.ok(bff.includes('Origin: origin'), 'bff must send an explicit Origin');
  assert.ok(bff.includes('Referer: `' + '${origin}/`'), 'bff must pin Referer (Sanctum checks it first)');
});

// ── Host-header spoofing ─────────────────────────────────────────────────
// The forwarded Origin decides whether Sanctum treats the call as stateful, so
// an attacker-supplied Host / X-Forwarded-Host must never be able to point it
// at an arbitrary domain (or a look-alike of ours).

test('a spoofed x-forwarded-host is ignored in favour of the real host', () => {
  // Client sends a fake XFH but nginx forwards the true Host.
  assert.equal(
    requestOrigin(req({ host: 'fahimni.me', 'x-forwarded-host': 'evil.com', 'x-forwarded-proto': 'https' })),
    'https://fahimni.me',
  );
});

test('a spoofed Host alone falls back to the canonical origin, never the attacker host', () => {
  const origin = requestOrigin(req({ host: 'evil.com', 'x-forwarded-proto': 'https' }));

  assert.equal(origin, 'https://fahimni.me');
  assert.ok(!origin.includes('evil.com'));
});

test('look-alike hosts (suffix, not subdomain) are rejected', () => {
  // "fahimni.me.evil.com" is NOT a subdomain of fahimni.me.
  assert.equal(requestOrigin(req({ host: 'fahimni.me.evil.com', 'x-forwarded-proto': 'https' })), 'https://fahimni.me');
  assert.equal(requestOrigin(req({ host: 'notfahimni.me', 'x-forwarded-proto': 'https' })), 'https://fahimni.me');
  assert.equal(isTrustedHost('fahimni.me.evil.com'), false);
  assert.equal(isTrustedHost('notfahimni.me'), false);
});

test('real tenant subdomains of the platform domain stay trusted', () => {
  assert.equal(isTrustedHost('teacher.fahimni.me'), true);
  assert.equal(
    requestOrigin(req({ host: 'teacher.fahimni.me', 'x-forwarded-proto': 'https' })),
    'https://teacher.fahimni.me',
  );
});

test('an unexpected scheme cannot leak into the origin', () => {
  assert.equal(requestOrigin(req({ host: 'fahimni.me', 'x-forwarded-proto': 'javascript' })), 'https://fahimni.me');
});

test('localhost and loopback stay trusted for local development', () => {
  assert.equal(isTrustedHost('localhost:3000'), true);
  assert.equal(isTrustedHost('127.0.0.1'), true);
});

test('nginx overwrites X-Forwarded-Host on both Next.js proxy locations', () => {
  const conf = src('../docker/nginx/default.conf');
  const occurrences = conf.match(/proxy_set_header X-Forwarded-Host \$host;/g) ?? [];

  assert.ok(
    occurrences.length >= 2,
    'both /api/session/ and / must pin X-Forwarded-Host to the real $host',
  );
});
