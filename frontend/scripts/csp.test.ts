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

import { CSP_HEADER, NONCE_HEADER, SANDBOX_PATH, buildCsp, buildSandboxCsp, extractNonce, generateNonce, withNoTransform } from '../src/lib/csp.ts';

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

test('withNoTransform appends no-transform without dropping existing directives', () => {
  assert.equal(withNoTransform(null), 'no-transform');
  assert.equal(withNoTransform(undefined), 'no-transform');
  assert.equal(withNoTransform(''), 'no-transform');
  assert.equal(withNoTransform('private, no-cache, max-age=0'), 'private, no-cache, max-age=0, no-transform');
  assert.equal(withNoTransform('no-store, no-transform'), 'no-store, no-transform');
  assert.equal(withNoTransform('no-transform'), 'no-transform');
  // trailing comma / whitespace tolerated, and no duplicate directive
  assert.equal(withNoTransform('no-cache, '), 'no-cache, no-transform');
  assert.equal(withNoTransform('public, max-age=0, NO-TRANSFORM'), 'public, max-age=0, NO-TRANSFORM');
});

test('proxy sets no-transform on HTML responses (Cloudflare email-obfuscation opt-out)', () => {
  const proxy = src('src/proxy.ts');

  assert.match(proxy, /withNoTransform/, 'proxy must import/use withNoTransform');
  assert.match(
    proxy,
    /response\.headers\.set\(\s*'Cache-Control'\s*,\s*withNoTransform\(/,
    'withCsp() must emit Cache-Control: no-transform so Cloudflare leaves our HTML unmodified',
  );
});

test('the interactive-question sandbox document uses its OWN locked-down policy', () => {
  const csp = buildSandboxCsp();
  const directives = Object.fromEntries(
    csp.split('; ').map((d) => {
      const i = d.indexOf(' ');
      return i < 0 ? [d, ''] : [d.slice(0, i), d.slice(i + 1)];
    }),
  );

  // Deny everything by default; no network, no frames, no forms.
  assert.equal(directives['default-src'], "'none'");
  assert.equal(directives['connect-src'], "'none'");
  assert.equal(directives['frame-src'], "'none'");
  assert.equal(directives['form-action'], "'none'");
  assert.equal(directives['worker-src'], "'none'");
  assert.equal(directives['object-src'], "'none'");
  assert.equal(directives['base-uri'], "'none'");
  // Only inline data/blob images and data fonts — no remote fetch channel.
  assert.equal(directives['img-src'], 'data: blob:');
  assert.equal(directives['font-src'], 'data:');
  // The sandbox must force an opaque origin and allow scripts only.
  assert.equal(directives['sandbox'], 'allow-scripts');
  assert.doesNotMatch(csp, /allow-same-origin/, 'the sandbox must never gain a same-origin escape');
  // Only frame-ancestors may name our origin; every execution/network directive
  // must stay origin-free so a leak is impossible.
  assert.equal(directives['frame-ancestors'], "'self'");
  for (const key of ['default-src', 'connect-src', 'img-src', 'font-src', 'script-src', 'style-src', 'form-action', 'frame-src', 'media-src', 'worker-src', 'object-src', 'base-uri', 'manifest-src']) {
    assert.doesNotMatch(directives[key] ?? '', /'self'/, `${key} must not trust our origin`);
  }
});

test('the sandbox policy never leaks into the app policy (isolation is additive)', () => {
  const app = buildCsp('N', false);

  assert.doesNotMatch(app, /default-src 'none'/, 'the app policy must keep its own default-src');
  assert.doesNotMatch(app, /sandbox allow-scripts/, 'the app must not run in a sandbox document');
  assert.match(app, /frame-src 'self'/, 'the app must still be able to frame its own /embed document');
});

test('the sandbox route serves the harness with the sandbox CSP and is skipped by the app proxy', () => {
  const route = src('src/app/embed/question/route.ts');
  assert.match(route, /buildSandboxCsp/, 'route must apply the sandbox policy');
  assert.match(route, /sandboxHarnessHtml/, 'route must serve the harness document');
  assert.match(route, /'Content-Security-Policy': buildSandboxCsp\(\)/);
  assert.match(route, /X-Content-Type-Options/, 'route must set nosniff');

  const proxy = src('src/proxy.ts');
  assert.match(proxy, /SANDBOX_PATH/, 'proxy must import the sandbox path');
  assert.match(
    proxy,
    /pathname === SANDBOX_PATH/,
    'proxy must skip the nonce policy for the sandbox document',
  );
});

test('the harness never combines allow-scripts with allow-same-origin and never reflects teacher content', () => {
  const harness = src('src/lib/sandbox-harness.ts');

  // The iframe sandbox attribute lives in the React component; assert both.
  const component = src('src/components/exam/interactive-html-question.tsx');
  assert.match(component, /sandbox="allow-scripts"/, 'iframe must be sandboxed with allow-scripts only');
  assert.doesNotMatch(component, /sandbox="[^"]*allow-same-origin/, 'allow-same-origin removes the isolation guarantee');

  // The harness only trusts messages from the parent window and its own channel.
  assert.match(harness, /event\.source !== window\.parent/);
  assert.match(harness, /fahimni-question/);
});
