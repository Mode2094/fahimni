/**
 * Content-Security-Policy construction, shared by the Next.js proxy
 * (`src/proxy.ts`).
 *
 * Kept dependency-free (no Next imports) so the policy can be unit-tested
 * with `node --test` without booting the framework — see
 * `frontend/scripts/csp.test.ts`.
 *
 * The policy is per-request and nonce-based. `'strict-dynamic'` tells CSP3
 * browsers to IGNORE host allowlists and trust only scripts that carry the
 * nonce. Per the Next.js CSP guide
 * (node_modules/next/dist/docs/01-app/02-guides/content-security-policy.md),
 * Next.js parses the nonce out of the `Content-Security-Policy`
 * **request** header and stamps it onto every framework/page/inline script it
 * renders. That only works on a dynamically rendered page — so if the CSP is
 * set on the response only (a mistake this module guards against), or the page
 * is prerendered, no script carries the nonce and the browser blocks them all.
 */

export const CSP_HEADER = 'Content-Security-Policy';

/** Custom request header the proxy also exposes for server components. */
export const NONCE_HEADER = 'x-nonce';

/** Generate a 128-bit base64 nonce — the shape Next.js expects in `'nonce-…'`. */
export function generateNonce(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);

  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);

  return btoa(binary);
}

/**
 * Build the policy for a single request.
 *
 * `isDev` relaxes only `script-src` with `'unsafe-eval'` (React dev tooling);
 * it is never added in production. `style-src` intentionally keeps
 * `'unsafe-inline'` — nonces do not cover the runtime `<style>` injection used
 * by our UI layer, and styles are not an XSS execution vector.
 */
export function buildCsp(nonce: string, isDev: boolean = process.env.NODE_ENV !== 'production'): string {
  return [
    "default-src 'self'",
    "base-uri 'self'",
    "object-src 'none'",
    "frame-ancestors 'self'",
    "form-action 'self' https://checkout.freemius.com https://*.freemius.com https://www.paypal.com https://*.paypal.com",
    `connect-src 'self' https://cdn.jsdelivr.net wss: https://*.b-cdn.net https://video.bunnycdn.com https://*.bunnycdn.com https://www.paypal.com https://www.paypalobjects.com https://*.paypal.com https://*.paypalobjects.com https://api.freemius.com https://*.freemius.com https://checkout.freemius.com`,
    [
      "script-src 'self' 'nonce-" + nonce + "' 'strict-dynamic'",
      isDev ? "'unsafe-eval'" : '',
      'https://cdn.jsdelivr.net',
      'https://www.paypal.com https://www.paypalobjects.com https://www.sandbox.paypal.com',
      'https://*.paypal.com https://*.paypalobjects.com',
      'https://checkout.freemius.com https://*.freemius.com',
    ]
      .filter(Boolean)
      .join(' '),
    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://*.freemius.com",
    "img-src 'self' data: blob: https:",
    "media-src 'self' blob: https: https://*.b-cdn.net https://video.bunnycdn.com",
    "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com",
    "frame-src 'self' https://www.youtube.com https://player.vimeo.com https://www.google.com https://meet.jit.si https://iframe.videodelivery.net https://www.paypal.com https://*.paypal.com https://checkout.freemius.com https://*.freemius.com",
    "worker-src 'self' blob:",
    "manifest-src 'self'",
  ].join('; ');
}

/**
 * Read the nonce back out of a policy string. Mirrors how Next.js extracts it
 * (the `'nonce-{value}'` pattern) so tests can assert the header the browser
 * receives carries the exact nonce stamped on the scripts.
 */
export function extractNonce(csp: string): string | null {
  const match = csp.match(/'nonce-([^']+)'/);

  return match ? match[1] : null;
}
