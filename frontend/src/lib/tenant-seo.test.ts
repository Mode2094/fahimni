import { describe, it, expect } from 'vitest';
import { tenantMetadata, tenantUrl, tenantOrganizationLd, type TenantPublicBase } from '@/lib/tenant-seo';

const site = { name: 'أكاديمية النور', logo_url: null, site_config: {} };

/**
 * A teacher's public site must publish a canonical on the host it is actually
 * served from. On the platform that is `/t/{slug}`; on the teacher's OWN host
 * (custom domain / subdomain) it is the teacher's domain root — the internal
 * `/t/{slug}` prefix must never leak into the address bar, canonical or OG.
 */
describe('tenantMetadata canonical', () => {
  it('uses /t/{slug} on the platform (no host context)', () => {
    const meta = tenantMetadata('noor', site, { title: 'x', path: '/t/noor/courses' });
    expect(meta.alternates?.canonical).toBe('/t/noor/courses');
  });

  it('re-roots the canonical on the teacher custom domain', () => {
    const base: TenantPublicBase = { origin: 'https://maryammath.com', prefix: '' };
    const meta = tenantMetadata('noor', site, { title: 'x', path: '/t/noor/courses', base });
    expect(meta.alternates?.canonical).toBe('/courses');
    expect(meta.openGraph?.url).toBe('https://maryammath.com/courses');
    expect(JSON.stringify(meta)).not.toContain('/t/noor');
  });

  it('keeps the tenant root as / on the teacher custom domain', () => {
    const base: TenantPublicBase = { origin: 'https://maryammath.com', prefix: '' };
    const meta = tenantMetadata('noor', site, { title: 'x', path: '/t/noor', base });
    expect(meta.alternates?.canonical).toBe('/');
    expect(meta.openGraph?.url).toBe('https://maryammath.com/');
  });
});

describe('tenantUrl', () => {
  it('never emits a double slash at the origin root', () => {
    expect(tenantUrl({ origin: 'https://x.com', prefix: '' }, 'noor', '/t/noor')).toBe('https://x.com/');
  });

  it('appends the re-rooted path', () => {
    expect(tenantUrl({ origin: 'https://x.com', prefix: '' }, 'noor', '/t/noor/blog/hello')).toBe('https://x.com/blog/hello');
  });

  it('falls back to the platform prefix when no base is given', () => {
    const [org, website] = tenantOrganizationLd('noor', site);
    expect((org as any).url).toContain('/t/noor');
    expect((website as any).url).toContain('/t/noor');
  });
});
