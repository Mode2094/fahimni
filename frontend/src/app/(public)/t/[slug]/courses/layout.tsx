import type { Metadata } from 'next';
import { fetchTenantSite } from '@/lib/tenant-site';
import { tenantInfo, tenantMetadata } from '@/lib/tenant-seo';

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const { slug } = await params;
  const site = await fetchTenantSite(slug);
  const { name } = tenantInfo(site);
  return tenantMetadata(slug, site, {
    title: `جميع الكورسات — ${name}`,
    description: `تصفّح جميع الدورات المتاحة في ${name}.`,
    path: `/t/${slug}/courses`,
  });
}

export default function CoursesLayout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}
