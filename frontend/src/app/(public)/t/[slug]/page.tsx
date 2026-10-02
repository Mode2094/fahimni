import type { Metadata, Viewport } from 'next';
import { resolveLogoUrl } from '@/lib/logo';
import { fetchTenantSite } from '@/lib/tenant-site';
import TeacherLanding from './landing';

/**
 * Server page for a teacher's landing: publishes the tenant's PWA metadata
 * (page-level metadata + viewport override the root layout's platform values,
 * so each teacher page has exactly ONE theme-color / apple-touch-icon / title).
 * The manifest stays a single root link (/manifest.webmanifest) — the manifest
 * route resolves the tenant from Referer/Host.
 */
export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const { slug } = await params;
  const site = await fetchTenantSite(slug);

  const name = site?.name?.trim() || 'منصتي';
  const about = site?.site_config?.about_text?.trim();
  const logo = resolveLogoUrl(site?.logo_url);

  return {
    title: name,
    description: about || 'منصة تعليمية: دورات، طلاب، امتحانات وبث مباشر.',
    manifest: '/manifest.webmanifest',
    icons: {
      icon: logo || '/icon.png',
      apple: logo || '/apple-icon.png',
    },
    openGraph: {
      title: name,
      description: about || 'منصة تعليمية: دورات، طلاب، امتحانات وبث مباشر.',
      type: 'website',
      siteName: name,
      images: [{ url: logo || '/logo.png', width: 512, height: 512, alt: name }],
    },
    twitter: {
      card: 'summary',
      title: name,
      description: about || 'منصة تعليمية: دورات، طلاب، امتحانات وبث مباشر.',
      images: [logo || '/logo.png'],
    },
  };
}

export async function generateViewport({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Viewport> {
  const { slug } = await params;
  const site = await fetchTenantSite(slug);

  return {
    themeColor: site?.primary_color || '#2563eb',
    width: 'device-width',
    initialScale: 1,
  };
}

export default function Page({ params }: { params: Promise<{ slug: string }> }) {
  return <TeacherLanding params={params} />;
}