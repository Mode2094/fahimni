import { describe, it, expect } from 'vitest';
import { tenantSitePath, isTenantSitePath, TENANT_SITE_ROOTS } from '@/lib/tenant-routing';

/**
 * A teacher's OWN host must serve the public teacher site (rewritten to
 * /t/{slug}) WITHOUT swallowing the whole app. The signed-in routes must keep
 * their own path, or every page collapses onto the teacher's landing page.
 */
describe('tenantSitePath', () => {
  it('maps the public teacher-site roots onto /t/{slug}', () => {
    expect(tenantSitePath('/', 'ahmad')).toBe('/t/ahmad');
    expect(tenantSitePath('/courses', 'ahmad')).toBe('/t/ahmad/courses');
    expect(tenantSitePath('/register', 'ahmad')).toBe('/t/ahmad/register');
    expect(tenantSitePath('/blog', 'ahmad')).toBe('/t/ahmad/blog');
    expect(tenantSitePath('/book', 'ahmad')).toBe('/t/ahmad/book');
  });

  it('preserves the deep sub-path (deep link / refresh must not 404)', () => {
    expect(tenantSitePath('/courses/42', 'ahmad')).toBe('/t/ahmad/courses/42');
    expect(tenantSitePath('/blog/my-first-post', 'ahmad')).toBe('/t/ahmad/blog/my-first-post');
    expect(tenantSitePath('/courses/42/', 'ahmad')).toBe('/t/ahmad/courses/42');
  });

  it('leaves the signed-in app routes untouched', () => {
    for (const path of [
      '/my-learning',
      '/student/login',
      '/student/dashboard',
      '/course/42/learn',
      '/courses-archive', // not the exact root
      '/exam/42',
      '/exams',
      '/results',
      '/card',
      '/checkout',
      '/profile',
      '/live/42',
      '/certificates',
    ]) {
      expect(tenantSitePath(path, 'ahmad')).toBeNull();
    }
  });

  it('never rewrites without a slug', () => {
    expect(tenantSitePath('/', '')).toBeNull();
    expect(tenantSitePath('/courses', '')).toBeNull();
  });

  it('exposes exactly the public roots', () => {
    expect([...TENANT_SITE_ROOTS].sort()).toEqual(['', 'blog', 'book', 'courses', 'register']);
  });

  it('maps the student sign-up onto the tenant registration page (no 404)', () => {
    // `/register/student` is the student sign-up; the tenant group only has
    // `/t/{slug}/register`, so it must collapse onto that page.
    expect(tenantSitePath('/register/student', 'ahmad')).toBe('/t/ahmad/register');
    expect(tenantSitePath('/register/student/', 'ahmad')).toBe('/t/ahmad/register');
  });

  it('leaves the platform teacher sign-up alone on a teacher host', () => {
    // There is no `/t/{slug}/register/teacher`; rewriting it 404'd.
    expect(tenantSitePath('/register/teacher', 'ahmad')).toBeNull();
    expect(tenantSitePath('/register/anything-else', 'ahmad')).toBeNull();
  });

  it('keeps the teacher course builder reachable on a teacher host', () => {
    // `/courses` is the tenant's PUBLIC catalogue on a teacher host, so the
    // teacher's own builder lives at `/teacher/courses` — which must NEVER be
    // rewritten, or a teacher on their custom domain could not manage courses.
    expect(tenantSitePath('/teacher/courses', 'ahmad')).toBeNull();
    expect(tenantSitePath('/teacher/courses/42', 'ahmad')).toBeNull();
    expect(isTenantSitePath('/teacher/courses')).toBe(false);
  });
});

describe('isTenantSitePath', () => {
  it('is true for the public teacher-site routes and their sub-paths', () => {
    for (const path of ['/', '/courses', '/courses/42', '/register', '/register/student', '/blog', '/blog/post', '/book']) {
      expect(isTenantSitePath(path)).toBe(true);
    }
  });

  it('is false for signed-in app routes and platform-only register sub-paths', () => {
    for (const path of ['/my-learning', '/student/login', '/course/42/learn', '/register/teacher', '/register/studentx', '/checkout', '/profile']) {
      expect(isTenantSitePath(path)).toBe(false);
    }
  });
});
