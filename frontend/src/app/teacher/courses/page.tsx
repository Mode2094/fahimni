'use client';

/**
 * Canonical teacher course-management route.
 *
 * The builder used to live at `/courses` — a path that, on a teacher's OWN
 * domain, the proxy rewrites to the tenant's PUBLIC catalogue (`/t/{slug}/courses`).
 * A teacher on their custom domain could therefore never reach course
 * management. `/teacher/courses` is outside the public tenant-site roots, so it
 * always resolves to the builder on every host. `/courses` stays as a platform
 * alias (existing bookmarks / tests).
 */
export { default } from '@/app/courses/page';
