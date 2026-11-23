import type { Metadata } from 'next';
import { fetchTenantSite } from '@/lib/tenant-site';
import { tenantInfo, tenantMetadata } from '@/lib/tenant-seo';

/** Enrollment form — transactional page, never indexed. */
export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const { slug } = await params;
  const site = await fetchTenantSite(slug);
  const { name } = tenantInfo(site);
  return tenantMetadata(slug, site, {
    title: `التسجيل كطالب — ${name}`,
    path: `/t/${slug}/register`,
    index: false,
  });
}

export default function RegisterLayout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}
