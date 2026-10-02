import { NextResponse } from 'next/server';
import { SITE_NAME } from '@/lib/seo';

/**
 * Single manifest entry for every installable app on the platform.
 *
 * One <link rel="manifest"> is emitted by the root layout, so there is never
 * a head-order conflict between the platform and a tenant app. The tenant is
 * resolved server-side from two signals, both attacker-visible but only ever
 * able to select a PUBLIC site payload:
 *
 *   1. Referer  — same-origin manifest fetches carry the page URL
 *      (Referrer-Policy: strict-origin-when-cross-origin keeps the path on
 *      same-origin requests), so tadreesly.com/t/ahmad → ahmad's manifest.
 *   2. Host     — on a custom domain or teacher subdomain the page URL has no
 *      /t/ path, so the Host header is resolved through the public domain
 *      lookup (verified custom domains, then {slug}.{platform}).
 *
 * If neither resolves, the platform manifest is served (existing behavior).
 */

const API =
  process.env.NEXT_REWRITE_API_TARGET ||
  (process.env.NEXT_PUBLIC_API_URL?.startsWith('http') ? process.env.NEXT_PUBLIC_API_URL : undefined) ||
  'http://localhost:8080/api/v1';

const API_ORIGIN = API.replace(/\/api\/v1\/?$/, '');

const PLATFORM_MANIFEST = {
  name: SITE_NAME,
  short_name: SITE_NAME,
  description: 'منصة تعليمية متكاملة للمعلمين: دورات، طلاب، امتحانات وبث مباشر.',
  start_url: '/',
  scope: '/',
  id: '/',
  display: 'standalone',
  background_color: '#ffffff',
  theme_color: '#0d9488',
  lang: 'ar',
  dir: 'rtl',
  icons: [
    { src: '/icon.png', sizes: 'any', type: 'image/png' },
    { src: '/apple-icon.png', sizes: 'any', type: 'image/png' },
  ],
} as const;

type SitePayload = {
  slug?: string;
  name?: string;
  logo_url?: string | null;
  primary_color?: string;
  secondary_color?: string;
  accent_color?: string;
  site_config?: { about_text?: string };
};

async function fetchSite(path: string): Promise<SitePayload | null> {
  try {
    const res = await fetch(`${API}${path}`, {
      headers: { Accept: 'application/json' },
      next: { revalidate: 60 },
    });
    if (!res.ok) return null;
    const data = await res.json();
    return (data?.site as SitePayload) ?? null;
  } catch {
    return null;
  }
}

/** Resolve a relative /storage|/uploads path to the API origin (dev-only; same-origin in prod). */
function resolveIcon(url: string): string {
  if (/^https?:\/\//.test(url)) return url;
  if (url.startsWith('/storage/') || url.startsWith('/uploads/')) return `${API_ORIGIN}${url}`;
  return url;
}

function shortName(name: string): string {
  const chars = Array.from(name.trim());
  return chars.slice(0, 12).join('') || 'منصتي';
}

export const dynamic = 'force-dynamic';

export async function GET(request: Request) {
  const referer = request.headers.get('referer') || '';
  const host = (request.headers.get('host') || '').split(':')[0]?.toLowerCase() || '';

  let site: SitePayload | null = null;

  // 1) Referer-based tenant resolution: same-origin page /t/{slug} → slug.
  const match = referer.match(/\/t\/([^/?#]+)/);
  const slug = match ? decodeURIComponent(match[1]) : '';
  if (slug && /^[\p{L}\p{N}_-]{1,100}$/u.test(slug)) {
    site = await fetchSite(`/public/${encodeURIComponent(slug)}`);
  }

  // 2) Host-based resolution: custom domains and teacher subdomains.
  if (!site && host && /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/.test(host)) {
    site = await fetchSite(`/public/domain/${encodeURIComponent(host)}`);
  }

  if (!site || !site.slug) {
    return new NextResponse(JSON.stringify(PLATFORM_MANIFEST), {
      headers: { 'Content-Type': 'application/manifest+json; charset=utf-8', 'Cache-Control': 'public, max-age=60' },
    });
  }

  const name = site.name?.trim() || 'منصتي';
  const teacherPath = `/t/${encodeURIComponent(site.slug)}`;
  const logo = site.logo_url ? resolveIcon(site.logo_url) : null;

  const manifest = {
    name,
    short_name: shortName(name),
    description:
      site.site_config?.about_text?.trim().slice(0, 200) ||
      'منصة تعليمية: دورات، طلاب، امتحانات وبث مباشر.',
    start_url: teacherPath,
    scope: `${teacherPath}/`,
    id: teacherPath,
    display: 'standalone',
    background_color: '#ffffff',
    theme_color: site.primary_color || '#2563eb',
    lang: 'ar',
    dir: 'rtl',
    icons: [
      ...(logo ? [{ src: logo, sizes: 'any', type: 'image/png', purpose: 'any' as const }] : []),
      { src: '/icon.png', sizes: 'any', type: 'image/png' },
      { src: '/apple-icon.png', sizes: 'any', type: 'image/png' },
    ],
  };

  return new NextResponse(JSON.stringify(manifest), {
    headers: { 'Content-Type': 'application/manifest+json; charset=utf-8', 'Cache-Control': 'public, max-age=60' },
  });
}