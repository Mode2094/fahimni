import type { Metadata, Viewport } from 'next';
import { connection } from 'next/server';
import { Providers } from '@/components/providers';
import { SITE_NAME, getSiteOrigin, DEFAULT_OG_IMAGE, absoluteUrl } from '@/lib/seo';
import './globals.css';

export const metadata: Metadata = {
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
};

export const viewport: Viewport = {
  themeColor: '#0d9488',
  width: 'device-width',
  initialScale: 1,
};

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  // CSP nonces are injected during server-side rendering from the request
  // headers (see src/proxy.ts). A statically prerendered page is built once,
  // with no request and therefore no nonce, so every script would be blocked
  // by the strict `'strict-dynamic'` policy. Awaiting a dynamic API opts the
  // whole app into per-request rendering so the nonce is always present.
  await connection();

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
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
