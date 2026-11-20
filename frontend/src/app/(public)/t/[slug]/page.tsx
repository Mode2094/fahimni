import type { Metadata, Viewport } from 'next';
import { fetchTenantSite, fetchTenantCourses } from '@/lib/tenant-site';
import {
  tenantInfo,
  tenantMetadata,
  tenantOrganizationLd,
  tenantCoursesLd,
  tenantFaqLd,
} from '@/lib/tenant-seo';
import { JsonLd } from '@/components/seo/json-ld';
import TeacherLanding from './landing';

/**
 * Server page for a teacher's landing: publishes the tenant's PWA metadata
 * (page-level metadata + viewport override the root layout's platform values,
 * so each teacher page has exactly ONE theme-color / apple-touch-icon / title)
 * plus a correct per-tenant canonical so Google never collapses tenant pages
 * into the platform homepage.
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
  const { name, description, logo } = tenantInfo(site);

  return {
    ...tenantMetadata(slug, site, { title: name, description, path: `/t/${slug}` }),
    manifest: '/manifest.webmanifest',
    icons: {
      icon: logo || '/icon.png',
      apple: logo || '/apple-icon.png',
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

export default async function Page({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const [site, courses] = await Promise.all([
    fetchTenantSite(slug),
    fetchTenantCourses(slug),
  ]);

  return (
    <>
      <JsonLd
        data={[
          ...tenantOrganizationLd(slug, site),
          tenantCoursesLd(slug, site, courses),
          tenantFaqLd(site),
        ]}
      />
      <TeacherLanding params={params} />
    </>
  );
}
