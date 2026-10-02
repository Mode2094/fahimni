import type { Metadata } from 'next';
import HomeClient from '@/components/home/home-client';
import { SITE_NAME, absoluteUrl, DEFAULT_OG_IMAGE, jsonLd, getSiteOrigin } from '@/lib/seo';

const API_TARGET = process.env.NEXT_REWRITE_API_TARGET || '/api/v1';

async function platformLogo(): Promise<string | null> {
  try {
    const res = await fetch(`${API_TARGET}/platform-settings`, { cache: 'no-store' });
    if (!res.ok) return null;
    const d = await res.json();
    const logo = d.settings?.logo_url as string | undefined;
    return logo && logo.startsWith('/storage/') ? absoluteUrl(logo) : null;
  } catch {
    return null;
  }
}

export async function generateMetadata(): Promise<Metadata> {
  const logo = await platformLogo();
  const ogImage = logo || absoluteUrl(DEFAULT_OG_IMAGE);
  return {
    title: 'منصة تعليمية لكل معلم — أنشئ منصتك وانشر دوراتك أونلاين',
    description:
      'منصة Tadreesly — أنشئ منصتك التعليمية خلال دقائق، انشر الدورات المسجلة، أدر الطلاب والامتحانات، بثّ الحصص المباشرة واحصل على موقعك الخاص. منصة تعليمية متكاملة للمعلمين المستقلين.',
    alternates: { canonical: '/' },
    openGraph: {
      type: 'website',
      siteName: SITE_NAME,
      url: absoluteUrl('/'),
      title: `${SITE_NAME} — منصة تعليمية لكل معلم`,
      description:
        'أنشئ منصتك التعليمية خلال دقائق: دورات، طلاب، امتحانات، وبث مباشر — كل ذلك في مكان واحد.',
      locale: 'ar_AR',
      images: [{ url: ogImage, width: 512, height: 512, alt: SITE_NAME }],
    },
    twitter: {
      card: 'summary',
      title: `${SITE_NAME} — منصة تعليمية لكل معلم`,
      description: 'أنشئ منصتك التعليمية خلال دقائق: دورات، طلاب، امتحانات، وبث مباشر.',
      images: [ogImage],
    },
  };
}

export default async function Page() {
  const logo = (await platformLogo()) || absoluteUrl(DEFAULT_OG_IMAGE);
  const organizationLd = {
    '@context': 'https://schema.org',
    '@type': 'Organization',
    name: SITE_NAME,
    url: getSiteOrigin(),
    logo,
    sameAs: [],
  };

const softwareLd = {
    '@context': 'https://schema.org',
    '@type': 'SoftwareApplication',
    name: SITE_NAME,
    url: getSiteOrigin(),
    applicationCategory: 'EducationalApplication',
    operatingSystem: 'Web',
    inLanguage: ['ar', 'en'],
    description:
      'منصة تعليمية متكاملة للمعلمين: إنشاء الدورات، إدارة الطلاب، الامتحانات، والصفوف المباشرة.',
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(organizationLd) }} />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(softwareLd) }} />
      <HomeClient />
    </>
  );
}