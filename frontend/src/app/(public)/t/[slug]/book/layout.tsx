import type { Metadata } from 'next';
import { fetchTenantSite } from '@/lib/tenant-site';
import { tenantInfo, tenantMetadata } from '@/lib/tenant-seo';

/** Booking form — transactional page, never indexed. */
export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const { slug } = await params;
  const site = await fetchTenantSite(slug);
  const { name } = tenantInfo(site);
  return tenantMetadata(slug, site, {
    title: `حجز موعد — ${name}`,
    path: `/t/${slug}/book`,
    index: false,
  });
}

export default function BookLayout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}
