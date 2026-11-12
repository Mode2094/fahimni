import type { NextConfig } from 'next';

// Server-side target for the same-origin /api/v1 rewrite. In the Docker stack
// the browser talks only to nginx, and nginx sends /api/ straight to PHP — so
// this is set to http://nginx:80/api/v1 (NEXT_REWRITE_API_TARGET) in compose.
// In local dev it stays http://localhost:8080/api/v1.
const API_REWRITE_TARGET = process.env.NEXT_REWRITE_API_TARGET || 'http://localhost:8080/api/v1';

const nextConfig: NextConfig = {
  // Do not advertise the framework version.
  poweredByHeader: false,
  // Dev-only: allow serving through a rotating Cloudflare tunnel origin
  // (trycloudflare.com) — the host changes on every restart, so a fixed list
  // would break the moment the tunnel is re-created.
  allowedDevOrigins: ['*'],
  // Same-origin API proxy: the browser talks only to this origin, so
  // CORS and browser-extension fetch blockers can never break API calls.
  async rewrites() {
    return [
      { source: '/api/v1/:path*', destination: API_REWRITE_TARGET + '/:path*' },
    ];
  },
  async headers() {
    return [
      {
        source: '/(.*)',
        headers: [
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'X-Frame-Options', value: 'SAMEORIGIN' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
          { key: 'Permissions-Policy', value: 'geolocation=(),midi=(),sync-xhr=(),camera=(self),microphone=(self),magnetometer=(),gyroscope=(),fullscreen=(self),payment=(self "https://checkout.freemius.com")' },
          // Content-Security-Policy is set per-request with a nonce in
          // src/proxy.ts (middleware) — never here, to avoid a second
          // conflicting policy.
        ],
      },
    ];
  },
};

export default nextConfig;
