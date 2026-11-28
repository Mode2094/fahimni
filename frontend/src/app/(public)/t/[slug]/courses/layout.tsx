import type { Metadata } from 'next';
import { fetchTenantSite } from '@/lib/tenant-site';
import { tenantInfo, tenantMetadata, tenantPublicBase } from '@/lib/tenant-seo';

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const { slug } = await params;
  const [site, base] = await Promise.all([fetchTenantSite(slug), tenantPublicBase(slug)]);
  const { name } = tenantInfo(site);
  return tenantMetadata(slug, site, {
    title: `جميع الكورسات — ${name}`,
    description: `تصفّح جميع الدورات المتاحة في ${name}.`,
    path: `/t/${slug}/courses`,
    base,
  });
}

export default function CoursesLayout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}
