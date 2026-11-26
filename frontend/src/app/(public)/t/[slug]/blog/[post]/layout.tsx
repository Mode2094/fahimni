import type { Metadata } from 'next';
import { fetchTenantSite, fetchTenantPost } from '@/lib/tenant-site';
import { tenantInfo, tenantMetadata, tenantArticleLd, tenantPublicBase } from '@/lib/tenant-seo';
import { JsonLd } from '@/components/seo/json-ld';

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string; post: string }>;
}): Promise<Metadata> {
  const { slug, post } = await params;
  const [site, data, base] = await Promise.all([
    fetchTenantSite(slug),
    fetchTenantPost(slug, post),
    tenantPublicBase(slug),
  ]);
  const { name } = tenantInfo(site);

  if (!data?.title) {
    // Unpublished/unknown post → keep it out of the index entirely.
    return tenantMetadata(slug, site, {
      title: `المدونة — ${name}`,
      path: `/t/${slug}/blog/${post}`,
      index: false,
      base,
    });
  }

  return tenantMetadata(slug, site, {
    title: data.title,
    description: data.excerpt || undefined,
    path: `/t/${slug}/blog/${data.slug || post}`,
    base,
  });
}

export default async function BlogPostLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ slug: string; post: string }>;
}) {
  const { slug, post } = await params;
  const [site, data, base] = await Promise.all([
    fetchTenantSite(slug),
    fetchTenantPost(slug, post),
    tenantPublicBase(slug),
  ]);

  return (
    <>
      {data?.title ? <JsonLd data={tenantArticleLd(slug, site, data, base)} /> : null}
      {children}
    </>
  );
}
