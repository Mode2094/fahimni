/**
 * CSP regression tests — run with the built-in Node test runner:
 *
 *   cd frontend && node --test scripts/csp.test.ts
 *
 * These lock in the two root causes of the "dozens of blocked scripts on
 * /teacher" bug, so neither can silently come back:
 *
 *  1. The policy must carry `'nonce-…'` + `'strict-dynamic'` and NO
 *     `'unsafe-inline'` in `script-src` (weakening the policy is not a fix).
 *  2. The proxy must put the CSP on the **request** headers (Next.js reads the
 *     nonce from there) and the root layout must force dynamic rendering —
 *     otherwise the nonce in the response never lands on any script.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import { CSP_HEADER, NONCE_HEADER, buildCsp, extractNonce, generateNonce } from '../src/lib/csp.ts';

const here = dirname(fileURLToPath(import.meta.url));
const src = (rel: string) => readFileSync(join(here, '..', rel), 'utf8');

test('production policy is nonce-based and does not use unsafe-inline for scripts', () => {
  const csp = buildCsp('TESTNONCE', false);
  const scriptSrc = csp
    .split('; ')
    .find((d) => d.startsWith('script-src'))!;

  assert.match(scriptSrc, /'nonce-TESTNONCE'/, 'script-src must carry the nonce');
  assert.match(scriptSrc, /'strict-dynamic'/, 'script-src must keep strict-dynamic');
  assert.doesNotMatch(scriptSrc, /'unsafe-inline'/, 'script-src must never allow unsafe-inline');
  assert.doesNotMatch(scriptSrc, /'unsafe-eval'/, 'unsafe-eval is dev-only');

  // The rest of the hardened policy must not drift.
  assert.match(csp, /default-src 'self'/);
  assert.match(csp, /object-src 'none'/);
  assert.match(csp, /frame-ancestors 'self'/);
  assert.match(csp, /base-uri 'self'/);
});

test('dev policy adds unsafe-eval but never unsafe-inline for scripts', () => {
  const csp = buildCsp('DEVNONCE', true);
  const scriptSrc = csp.split('; ').find((d) => d.startsWith('script-src'))!;

  assert.match(scriptSrc, /'unsafe-eval'/);
  assert.doesNotMatch(scriptSrc, /'unsafe-inline'/);
});

test('extractNonce returns exactly the nonce the policy advertises', () => {
  for (let i = 0; i < 50; i++) {
    const nonce = generateNonce();

    assert.equal(extractNonce(buildCsp(nonce, false)), nonce);
  }
});

test('generateNonce is 128-bit base64 and unique per request', () => {
  const seen = new Set<string>();

  for (let i = 0; i < 200; i++) {
    const nonce = generateNonce();

    // 16 bytes → 24 base64 chars (with padding).
    assert.equal(nonce.length, 24);
    assert.match(nonce, /^[A-Za-z0-9+/]{22}==$/);
    assert.ok(!seen.has(nonce), 'nonce must never repeat');
    seen.add(nonce);
  }
});

test('different requests never share a nonce', () => {
  assert.notEqual(extractNonce(buildCsp(generateNonce(), false)), extractNonce(buildCsp(generateNonce(), false)));
});

test('proxy sends the CSP on the request headers (Next.js parses the nonce there)', () => {
  const proxy = src('src/proxy.ts');

  assert.ok(proxy.includes('CSP_HEADER'), 'proxy must set the CSP constant');
  assert.match(
    proxy,
    /requestHeaders\.set\(\s*CSP_HEADER\s*,\s*csp\s*\)/,
    'proxy must set the CSP header on the REQUEST headers so Next.js can stamp the nonce',
  );
  assert.match(proxy, new RegExp(`requestHeaders\\.set\\(\\s*NONCE_HEADER`), 'proxy must expose x-nonce');
});

test('root layout forces dynamic rendering so a nonce exists per request', () => {
  const layout = src('src/app/layout.tsx');

  assert.match(layout, /from 'next\/server'/, 'layout must import from next/server');
  assert.match(layout, /await connection\(\)/, 'layout must await connection() to opt into dynamic rendering');
});
