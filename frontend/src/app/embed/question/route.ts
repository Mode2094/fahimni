import { buildSandboxCsp } from '@/lib/csp';
import { sandboxHarnessHtml } from '@/lib/sandbox-harness';

/**
 * Isolated document that runs teacher-authored interactive question code.
 *
 * Design notes:
 *  - The response carries its OWN restrictive CSP (`buildSandboxCsp`): the app
 *    proxy deliberately skips this path so the app's nonce policy is never
 *    weakened.
 *  - The CSP `sandbox` directive forces an opaque origin even if the URL is
 *    opened directly, so the document can never read app cookies/storage.
 *  - The document is static (our harness only); teacher content arrives later
 *    over the validated postMessage protocol — nothing user-controlled is
 *    reflected here.
 */
export const dynamic = 'force-dynamic';

export function GET() {
  return new Response(sandboxHarnessHtml, {
    status: 200,
    headers: {
      'Content-Type': 'text/html; charset=utf-8',
      'Content-Security-Policy': buildSandboxCsp(),
      'X-Content-Type-Options': 'nosniff',
      'Referrer-Policy': 'no-referrer',
      'X-Frame-Options': 'SAMEORIGIN',
      'Cache-Control': 'no-store, no-transform',
    },
  });
}
