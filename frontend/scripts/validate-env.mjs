#!/usr/bin/env node
/**
 * Frontend production build environment validation.
 *
 * Fails the build when the runtime environment would point the browser at a
 * local/dev API or leave the tenant-domain resolver without a platform host.
 *
 * Run automatically before every `next build` (see package.json "prebuild").
 * It is safe to run in any environment: it only reads process.env.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');

function loadDotEnv() {
  const envPath = path.join(root, '.env.local');
  if (!fs.existsSync(envPath)) return {};
  const out = {};
  for (const line of fs.readFileSync(envPath, 'utf8').split('\n')) {
    const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/);
    if (m) out[m[1]] = m[2].replace(/^["']|["']$/g, '');
  }
  return out;
}

const env = { ...process.env, ...loadDotEnv() };
const nodeEnv = env.NODE_ENV || 'development';
const isProduction = nodeEnv === 'production' || !!process.env.CI;

// In production, a leftover developer .env.local must NOT override the real
// build environment (it would silently point the app at a dev/tunnel API).
if (isProduction) {
  const dotenv = loadDotEnv();
  for (const [k, v] of Object.entries(process.env)) {
    if (v !== undefined && dotenv[k] !== undefined) env[k] = v;
  }
}

const errors = [];
const warnings = [];

const apiUrl = env.NEXT_PUBLIC_API_URL || '';
const platformDomain = env.NEXT_PUBLIC_PLATFORM_DOMAIN || '';

if (isProduction) {
  // Explicit escape hatch for LOCAL DEV ONLY: set ALLOW_LOCAL_ORIGINS=1 to
  // build a production Next bundle pointed at a local API (localhost:8080).
  // Never set it in real production deploys.
  const allowLocal = process.env.ALLOW_LOCAL_ORIGINS === '1';
  if (!apiUrl) {
    errors.push('NEXT_PUBLIC_API_URL is required in production build.');
  } else if (/localhost|127\.0\.0\.1/.test(apiUrl)) {
    if (allowLocal) {
      warnings.push(
        `NEXT_PUBLIC_API_URL points at a local address (${apiUrl}) — ` +
        'allowed because ALLOW_LOCAL_ORIGINS=1. Local-dev only.',
      );
    } else {
      errors.push(
        `NEXT_PUBLIC_API_URL points at a local address: ${apiUrl}. ` +
        'A production frontend must talk to the real API origin.',
      );
    }
  }

  if (!platformDomain) {
    errors.push('NEXT_PUBLIC_PLATFORM_DOMAIN is required in production build.');
  }

  if (/localhost|127\.0\.0\.1/.test(platformDomain)) {
    if (allowLocal) {
      warnings.push(
        `NEXT_PUBLIC_PLATFORM_DOMAIN points at a local address (${platformDomain}) — ` +
        'allowed because ALLOW_LOCAL_ORIGINS=1. Local-dev only.',
      );
    } else {
      errors.push(
        `NEXT_PUBLIC_PLATFORM_DOMAIN points at a local address: ${platformDomain}. ` +
        'Tenant-domain resolution (proxy.ts) will misbehave in production.',
      );
    }
  }
} else {
  warnings.push('Development build — production env checks skipped.');
}

if (errors.length > 0) {
  console.error('\n[validate-env] BUILD BLOCKED BY ENVIRONMENT ERRORS:');
  for (const e of errors) console.error(`  ✗ ${e}`);
  console.error('');
  process.exit(1);
}

for (const w of warnings) console.warn(`[validate-env] WARNING: ${w}`);

console.log('[validate-env] OK');