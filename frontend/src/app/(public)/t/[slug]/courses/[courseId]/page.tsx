import { fetchTenantSite, fetchTenantCourse } from '@/lib/tenant-site';
import { tenantCourseLd, tenantPublicBase } from '@/lib/tenant-seo';
import { JsonLd } from '@/components/seo/json-ld';
import CourseDetail from './course-detail';

/**
 * Public course page for a teacher's own site. All content (branding,
 * curriculum, price) comes from the real backend — a course from another
 * tenant or an unpublished course 404s and renders an empty state instead of
 * leaking anything.
 */
export default async function Page({
  params,
}: {
  params: Promise<{ slug: string; courseId: string }>;
}) {
  const { slug, courseId } = await params;
  const [site, course, base] = await Promise.all([
    fetchTenantSite(slug),
    fetchTenantCourse(slug, courseId),
    tenantPublicBase(slug),
  ]);

  const ld = tenantCourseLd(slug, site, course, base);

  return (
    <>
      {ld && <JsonLd data={ld} />}
      <CourseDetail slug={slug} courseId={courseId} initialSite={site} />
    </>
  );
}
