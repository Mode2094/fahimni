import { describe, it, expect } from 'vitest';
import { tenantBasePath, tenantLoginHref } from '@/lib/tenant-host';

/**
 * The public teacher-site links must be host-relative on the teacher's OWN host
 * (so the address bar keeps the custom domain) and `/t/{slug}`-prefixed on the
 * platform. This is the single source of truth for that decision.
 */
describe('tenantBasePath', () => {
  it('is empty on the teacher own host (custom domain / subdomain)', () => {
    expect(tenantBasePath('ahmad', 'ahmad')).toBe('');
  });

  it('is /t/{slug} on the platform host', () => {
    expect(tenantBasePath(null, 'ahmad')).toBe('/t/ahmad');
  });

  it('is /t/{slug} when the host tenant differs from the link tenant', () => {
    expect(tenantBasePath('other', 'ahmad')).toBe('/t/ahmad');
  });

  it('encodes the slug', () => {
    expect(tenantBasePath(null, 'a b')).toBe('/t/a%20b');
  });
});

/**
 * The student login is one shared platform route. On the platform the tenant
 * travels as `?tenant_slug=`, but on a teacher's OWN host the host itself is the
 * tenant — leaking the internal slug into the teacher's address bar is the bug
 * this locks out.
 */
describe('tenantLoginHref', () => {
  it('drops the slug on the teacher own host', () => {
    expect(tenantLoginHref('mrym', 'mrym')).toBe('/student/login');
  });

  it('carries the tenant on the platform host', () => {
    expect(tenantLoginHref(null, 'mrym')).toBe('/student/login?tenant_slug=mrym');
  });

  it('encodes the slug on the platform', () => {
    expect(tenantLoginHref(null, 'a b')).toBe('/student/login?tenant_slug=a%20b');
  });
});
