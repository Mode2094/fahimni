'use client';

/**
 * Canonical teacher Course Builder route (a single course's sections, lessons
 * and exams). See the sibling `page.tsx` for why the builder moved off the
 * public `/courses` path onto `/teacher/courses`. On a teacher's own domain
 * `/courses/{id}` is rewritten to the tenant's public course detail, so the
 * editable route must live outside the public tenant-site roots.
 */
export { default } from '@/app/courses/[id]/page';
