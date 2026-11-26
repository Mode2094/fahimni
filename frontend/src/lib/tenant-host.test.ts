import { describe, it, expect } from 'vitest';
import { tenantBasePath } from '@/lib/tenant-host';

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
