import { NextResponse } from 'next/server';
import { SITE_NAME } from '@/lib/seo';
import { BRAND_NAME_SHORT } from '@/lib/brand';

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
 *      same-origin requests), so fahimni.me/t/ahmad → ahmad's manifest.
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
  short_name: BRAND_NAME_SHORT,
  description: 'منصة تعليمية متكاملة للمعلمين: دورات، طلاب، امتحانات وبث مباشر.',
  start_url: '/app',
  scope: '/',
  id: '/',
  display: 'standalone',
  background_color: '#ffffff',
  theme_color: '#0d9488',
  lang: 'ar',
  dir: 'rtl',
  icons: [
    { src: '/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
    { src: '/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
    { src: '/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
    { src: '/apple-icon.png', sizes: '180x180', type: 'image/png' },
  ],
} as const;

/**
 * One installable platform app, three identities. When the requesting page is a
 * signed-in student area we serve the student identity (name, theme, shortcuts)
 * so "فهمني للطلاب" is what lands on the home screen — while sharing the SAME
 * `id`/`scope` as the platform manifest, so a device never ends up with two
 * colliding Fahimni apps. Teacher storefronts keep their own tenant manifest.
 */
const STUDENT_PATHS =
  /^\/(?:app|my-learning|my-notes|exams|results|certificates|leaderboard|messages|notifications|announcements|my-bookings|profile|community|course|exam|live|book|checkout)(?:\/|$)/;

const STUDENT_MANIFEST = {
  name: 'فهمني للطلاب — Fahimni Students',
  short_name: 'فهمني للطلاب',
  description: 'تطبيق فهمني للطلاب: كورساتك ودروسك وواجباتك واختباراتك — بواجهة مصمّمة للهاتف.',
  start_url: '/app',
  scope: '/',
  id: '/',
  display: 'standalone',
  orientation: 'portrait',
  background_color: '#ffffff',
  theme_color: '#0d9488',
  lang: 'ar',
  dir: 'rtl',
  categories: ['education'],
  prefer_related_applications: false,
  icons: [
    { src: '/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
    { src: '/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
    { src: '/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
    { src: '/apple-icon.png', sizes: '180x180', type: 'image/png' },
  ],
  shortcuts: [
    { name: 'كورساتي', short_name: 'كورساتي', url: '/my-learning' },
    { name: 'الاختبارات', short_name: 'الاختبارات', url: '/exams' },
    { name: 'الرسائل', short_name: 'الرسائل', url: '/messages' },
    { name: 'الإشعارات', short_name: 'الإشعارات', url: '/notifications' },
  ],
} as const;

/** Pathname of the page that requested the manifest (Referer), or ''. */
function refererPath(referer: string): string {
  try {
    return new URL(referer).pathname;
  } catch {
    return referer.startsWith('/') ? referer.split(/[?#]/)[0] : '';
  }
}

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

/**
 * The same URL (/manifest.webmanifest) serves a DIFFERENT document per tenant,
 * resolved from Referer/Host. A shared cache (Cloudflare/CDN) that keys on the
 * URL alone could hand teacher A's name/logo/start_url to teacher B, so the
 * response must never be publicly shareable: `private, no-store` stops shared
 * caches storing it at all, and `Vary` keeps any honouring cache correct.
 */
const MANIFEST_HEADERS = {
  'Content-Type': 'application/manifest+json; charset=utf-8',
  'Cache-Control': 'private, no-store',
  'Vary': 'Referer, Host',
} as const;

export async function GET(request: Request) {
  const referer = request.headers.get('referer') || '';
  const host = (request.headers.get('host') || '').split(':')[0]?.toLowerCase() || '';

  // 0) Student app: any student area installs "فهمني للطلاب" (same app id as
  //    the platform, so the two identities never collide on one device).
  const path = refererPath(referer);
  if (path && STUDENT_PATHS.test(path)) {
    return new NextResponse(JSON.stringify(STUDENT_MANIFEST), {
      headers: MANIFEST_HEADERS,
    });
  }

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
      headers: MANIFEST_HEADERS,
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
      { src: '/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' as const },
      { src: '/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' as const },
      { src: '/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' as const },
      { src: '/apple-icon.png', sizes: '180x180', type: 'image/png' },
    ],
  };

  return new NextResponse(JSON.stringify(manifest), {
    headers: MANIFEST_HEADERS,
  });
}