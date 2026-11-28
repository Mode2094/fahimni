import type { Metadata } from 'next';
import { fetchTenantSite } from '@/lib/tenant-site';
import { tenantInfo, tenantMetadata, tenantPublicBase } from '@/lib/tenant-seo';

/** Booking form — transactional page, never indexed. */
export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const { slug } = await params;
  const [site, base] = await Promise.all([fetchTenantSite(slug), tenantPublicBase(slug)]);
  const { name } = tenantInfo(site);
  return tenantMetadata(slug, site, {
    title: `حجز موعد — ${name}`,
    path: `/t/${slug}/book`,
    index: false,
    base,
  });
}

export default function BookLayout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}
