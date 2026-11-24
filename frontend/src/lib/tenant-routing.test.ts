import { describe, it, expect } from 'vitest';
import { tenantSitePath, TENANT_SITE_ROOTS } from '@/lib/tenant-routing';

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
});
