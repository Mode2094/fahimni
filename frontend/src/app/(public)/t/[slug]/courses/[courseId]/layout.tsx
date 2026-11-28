import type { Metadata } from 'next';
import { fetchTenantSite, fetchTenantCourse } from '@/lib/tenant-site';
import { tenantInfo, tenantMetadata, tenantPublicBase } from '@/lib/tenant-seo';

/**
 * SEO for a single public course page. Indexed only when the course really
 * exists and is published for this tenant — otherwise it is a 404-shaped page
 * that must never be indexed.
 */
export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string; courseId: string }>;
}): Promise<Metadata> {
  const { slug, courseId } = await params;
  const [site, course, base] = await Promise.all([
    fetchTenantSite(slug),
    fetchTenantCourse(slug, courseId),
    tenantPublicBase(slug),
  ]);
  const { name } = tenantInfo(site);

  if (!course) {
    return tenantMetadata(slug, site, {
      title: `الدورة غير متوفرة — ${name}`,
      path: `/t/${slug}/courses/${courseId}`,
      index: false,
      base,
    });
  }

  const description =
    course.short_description || course.description || undefined;

  return tenantMetadata(slug, site, {
    title: `${course.title} — ${name}`,
    description,
    path: `/t/${slug}/courses/${courseId}`,
    base,
  });
}

export default function CourseDetailLayout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}
