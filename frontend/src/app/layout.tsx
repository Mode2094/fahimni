import type { Metadata, Viewport } from 'next';
import { connection } from 'next/server';
import { headers } from 'next/headers';
import { Providers } from '@/components/providers';
import { fetchTenantSite } from '@/lib/tenant-site';
import { TENANT_SLUG_HEADER } from '@/lib/tenant-routing';
import type { TenantBranding } from '@/lib/tenant-host';
import { SITE_NAME, getSiteOrigin, DEFAULT_OG_IMAGE, absoluteUrl } from '@/lib/seo';
import './globals.css';

const PLATFORM_METADATA: Metadata = {
  metadataBase: new URL(getSiteOrigin()),
  title: {
    default: `${SITE_NAME} — منصة تعليمية لكل معلم | Online teaching platform for teachers`,
    template: `%s | ${SITE_NAME}`,
  },
  description:
    'منصة فهمني — أنشئ منصتك التعليمية، انشر دوراتك، أدر طلابك وامتحاناتك وبثّ حصصك مباشرة. Online teaching platform, LMS and live classes for independent teachers.',
  applicationName: SITE_NAME,
  robots: {
    index: true,
    follow: true,
    googleBot: { index: true, follow: true, 'max-image-preview': 'large', 'max-snippet': -1 },
  },
  // No blanket `alternates.canonical` here: a root default leaks a
  // `canonical: '/'` into every page that does not override it — including
  // private, `noindex` sections (e.g. /courses, the teacher course manager),
  // which then advertise a conflicting canonical pointing at the homepage.
  // Public pages set their own canonical (the home page sets '/' explicitly).
  verification: {
    google: process.env.NEXT_PUBLIC_GSC_VERIFICATION || undefined,
    other: process.env.NEXT_PUBLIC_BING_VERIFICATION ? { 'msvalidate.01': [process.env.NEXT_PUBLIC_BING_VERIFICATION] } : {},
  },
  openGraph: {
    type: 'website',
    siteName: SITE_NAME,
    url: absoluteUrl('/'),
    title: `${SITE_NAME} — منصة تعليمية لكل معلم`,
    description:
      'أنشئ منصتك التعليمية، انشر دوراتك، أدر طلابك وامتحاناتك وبثّ حصصك مباشرة — كل ذلك في مكان واحد.',
    locale: 'ar_AR',
    images: [{ url: absoluteUrl(DEFAULT_OG_IMAGE), width: 1200, height: 630, alt: SITE_NAME }],
  },
  twitter: {
    card: 'summary_large_image',
    title: `${SITE_NAME} — منصة تعليمية لكل معلم`,
    description: 'منصة تعليمية متكاملة لكل معلم: دورات، طلاب، امتحانات، وبث مباشر.',
    images: [absoluteUrl(DEFAULT_OG_IMAGE)],
  },
  icons: {
    icon: [
      { url: '/icon.png', sizes: 'any', type: 'image/png' },
      { url: '/icon-192.png', sizes: '192x192', type: 'image/png' },
      { url: '/icon-512.png', sizes: '512x512', type: 'image/png' },
    ],
    apple: [{ url: '/apple-icon.png', sizes: '180x180', type: 'image/png' }],
  },
  manifest: '/manifest.webmanifest',
  // iOS ignores most of the web manifest. `appleWebApp` emits the modern
  // `mobile-web-app-capable`; the legacy `apple-mobile-web-app-capable` is
  // added explicitly for older Safari versions. Together they make the app
  // launch full-screen with the right status-bar colour and home-screen title
  // once added via Share → Add to Home Screen.
  appleWebApp: {
    capable: true,
    title: 'فهمني للطلاب',
    statusBarStyle: 'default',
  },
  other: {
    'apple-mobile-web-app-capable': 'yes',
  },
};

/**
 * Metadata for the current request.
 *
 * On a teacher's OWN host (custom domain / subdomain) the browser path is
 * tenant-less after the proxy rewrite, so:
 *  - `metadataBase` must be the TEACHER's origin: the tenant pages publish a
 *    root-relative canonical (`/courses`), which Next resolves against this
 *    base — pointing it at the platform collapsed the teacher's pages onto
 *    `fahimni.me` in Google and in link previews.
 *  - the default title/OG must name the teacher, not the platform, so auth and
 *    app pages that don't set their own title still present the teacher.
 *
 * On the platform host this returns the unchanged platform defaults.
 */
export async function generateMetadata(): Promise<Metadata> {
  const requestHeaders = await headers();
  const tenantSlug = requestHeaders.get(TENANT_SLUG_HEADER);
  if (!tenantSlug) return PLATFORM_METADATA;

  // The Host header is attacker-controlled; only build a URL from a plain DNS
  // hostname, otherwise keep the platform origin.
  const host = requestHeaders.get('host')?.split(':')[0]?.toLowerCase() || '';
  const origin = /^[a-z0-9.-]+$/.test(host) ? `https://${host}` : getSiteOrigin();
  const site = await fetchTenantSite(tenantSlug);
  const name = (typeof site?.name === 'string' && site.name.trim()) || 'منصتي';
  const description =
    (typeof site?.site_config?.about_text === 'string' && site.site_config.about_text.trim()) ||
    PLATFORM_METADATA.description;

  return {
    ...PLATFORM_METADATA,
    metadataBase: new URL(origin),
    title: { default: name, template: `%s | ${name}` },
    description,
    applicationName: name,
    appleWebApp: { capable: true, statusBarStyle: 'default', title: name },
    openGraph: {
      type: 'website',
      siteName: name,
      url: `${origin}/`,
      title: name,
      description,
      locale: 'ar_AR',
      // Relative on purpose: Next resolves it against `metadataBase`, so the
      // preview image is fetched from the TEACHER's host, not the platform.
      images: [{ url: DEFAULT_OG_IMAGE, width: 1200, height: 630, alt: name }],
    },
    twitter: {
      card: 'summary_large_image',
      title: name,
      description,
      images: [DEFAULT_OG_IMAGE],
    },
  };
}

export const viewport: Viewport = {
  themeColor: '#0d9488',
  width: 'device-width',
  initialScale: 1,
  // Opt into the full screen so `env(safe-area-inset-*)` is honoured on iPhone
  // (notch + home indicator). The panel shell pads its own header/drawer and
  // the assistant button respects the bottom inset, so nothing sits under the
  // system UI.
  viewportFit: 'cover',
};

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  // CSP nonces are injected during server-side rendering from the request
  // headers (see src/proxy.ts). A statically prerendered page is built once,
  // with no request and therefore no nonce, so every script would be blocked
  // by the strict `'strict-dynamic'` policy. Awaiting a dynamic API opts the
  // whole app into per-request rendering so the nonce is always present.
  await connection();

  // On a teacher's own host the proxy stamps `x-tenant-slug` (the browser path
  // is tenant-less after the rewrite). Resolve the teacher's branding here so
  // every page — auth, panel, storefront — presents the teacher, not the
  // platform. On the platform host there is no header and this stays null.
  const requestHeaders = await headers();
  const tenantSlug = requestHeaders.get(TENANT_SLUG_HEADER);
  const tenantBranding = await resolveTenantBranding(tenantSlug);

  return (
    <html lang="ar" dir="rtl" suppressHydrationWarning>
      <head>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
        <link
          href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Cairo:wght@400;600;700;800;900&family=Amiri:wght@400;700&family=Changa:wght@400;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap"
          rel="stylesheet"
        />
        <link rel="preconnect" href="https://cdn.jsdelivr.net" crossOrigin="anonymous" />
      </head>
      <body className="min-h-screen bg-background font-sans antialiased" suppressHydrationWarning>
        <Providers tenantBranding={tenantBranding}>{children}</Providers>
      </body>
    </html>
  );
}

/** Build the display branding for a teacher host, or `null` on the platform. */
async function resolveTenantBranding(slug: string | null): Promise<TenantBranding | null> {
  if (!slug) return null;

  const site = await fetchTenantSite(slug);
  if (!site) return null;

  const str = (value: unknown, fallback: string) =>
    typeof value === 'string' && value.trim() ? value : fallback;

  return {
    slug,
    name: str(site.name, 'منصتي'),
    logoUrl: typeof site.logo_url === 'string' && site.logo_url ? site.logo_url : null,
    primary: str(site.primary_color, '#0d9488'),
    accent: str(site.accent_color, '#14b8a6'),
  };
}
