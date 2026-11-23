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

/**
 * Route that serves the isolated document for interactive (teacher-authored)
 * HTML questions. It is intentionally OUTSIDE the app's nonce CSP: the doc runs
 * in a `sandbox` iframe with an opaque origin and its own locked-down policy.
 */
export const SANDBOX_PATH = '/embed/question';

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
    `connect-src 'self' https://cdn.jsdelivr.net wss: https://*.b-cdn.net https://video.bunnycdn.com https://*.bunnycdn.com https://www.paypal.com https://www.paypalobjects.com https://*.paypal.com https://*.paypalobjects.com https://api.freemius.com https://*.freemius.com https://checkout.freemius.com https://connect.facebook.net https://www.facebook.com https://www.google-analytics.com https://*.google-analytics.com https://www.googletagmanager.com`,
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
 * Build the Content-Security-Policy for the interactive-question sandbox
 * document, which executes teacher-authored HTML/CSS/JS.
 *
 * This is a SEPARATE policy for a SEPARATE document — it never weakens the
 * app's own nonce + `'strict-dynamic'` policy. The `sandbox` directive forces
 * an opaque origin even if the document is opened directly (top-level), so the
 * teacher's code can never read app cookies, storage, or other questions.
 *
 * Network is denied by default (`connect-src 'none'`, images limited to inline
 * data/blob) so a question cannot exfiltrate an answer to a third party.
 */
export function buildSandboxCsp(): string {
  return [
    "default-src 'none'",
    "base-uri 'none'",
    "object-src 'none'",
    "frame-ancestors 'self'",
    "form-action 'none'",
    "connect-src 'none'",
    "frame-src 'none'",
    "media-src 'none'",
    "worker-src 'none'",
    "manifest-src 'none'",
    // Inline data/blob only — no remote fetch, no exfiltration channel.
    "img-src data: blob:",
    "font-src data:",
    "style-src 'unsafe-inline'",
    // The teacher's JS is the whole point; it is confined to the opaque origin
    // and can only reach the host through the validated postMessage protocol.
    "script-src 'unsafe-inline'",
    'sandbox allow-scripts',
  ].join('; ');
}

/**
 * Append the `no-transform` directive to a `Cache-Control` value.
 *
 * Cloudflare's "Email Address Obfuscation" (Scrape Shield) rewrites every email
 * in an HTML response into a `/cdn-cgi/l/email-protection` link plus a
 * `[email protected]` placeholder, and injects a decoder script that our strict
 * nonce CSP blocks — leaving a literal placeholder on the page and a React
 * hydration mismatch (error #418). Per Cloudflare's documentation, the
 * `Cache-Control: no-transform` directive makes it leave our HTML untouched.
 * Existing directives are preserved; only `no-transform` is appended (once).
 */
export function withNoTransform(cacheControl: string | null | undefined): string {
  const base = (cacheControl || '').trim().replace(/,\s*$/, '');

  if (/(^|,)\s*no-transform\s*(,|$)/i.test(base)) {
    return base;
  }

  return base ? `${base}, no-transform` : 'no-transform';
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
