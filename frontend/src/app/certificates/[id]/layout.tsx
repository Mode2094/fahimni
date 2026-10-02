import type { Metadata } from 'next';
import { SITE_NAME, absoluteUrl, DEFAULT_OG_IMAGE } from '@/lib/seo';

export async function generateMetadata({ params }: { params: Promise<{ id: string }> }): Promise<import('next').Metadata> {
  const { id } = await params;
  const title = 'التحقق من الشهادة';
  return {
    title,
    description: 'تحقق من صحة شهادة تعليمية صادرة عبر منصة Tadreesly.',
    alternates: { canonical: `/certificates/${id}` },
    robots: { index: true, follow: true },
    openGraph: {
      title,
      description: 'تحقق من صحة شهادة تعليمية صادرة عبر منصة Tadreesly.',
      type: 'website',
      siteName: SITE_NAME,
      url: absoluteUrl(`/certificates/${id}`),
      images: [{ url: absoluteUrl(DEFAULT_OG_IMAGE), width: 512, height: 512, alt: SITE_NAME }],
    },
  };
}

export default function Layout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}