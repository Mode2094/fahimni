import { afterEach, describe, expect, it, vi } from 'vitest';

import { SITE_NAME } from '@/lib/seo';
import { TENANT_SLUG_HEADER } from '@/lib/tenant-routing';

// Deterministic platform origin for the fallback assertions.
process.env.NEXT_PUBLIC_PLATFORM_DOMAIN = 'fahimni.me';

const { headerStore } = vi.hoisted(() => ({ headerStore: new Map<string, string>() }));

vi.mock('next/headers', () => ({
  headers: async () => ({ get: (key: string) => headerStore.get(key) ?? null }),
}));

vi.mock('@/lib/tenant-site', () => ({
  fetchTenantSite: async (slug: string) =>
    slug === 'mrym'
      ? { name: 'مريم ابراهيم القطاونه', site_config: { about_text: 'نبذة عن الأكاديمية' } }
      : null,
}));

afterEach(() => headerStore.clear());

/**
 * On a teacher's OWN host the tenant pages publish a ROOT-RELATIVE canonical
 * (`/courses`) that Next resolves against the root `metadataBase`. Pointing
 * that base at the platform collapsed every teacher page onto `fahimni.me` in
 * Google and in link previews, and the default title named the platform
 * instead of the teacher on auth/app pages.
 */
describe('root metadata on a teacher host', () => {
  it('keeps the platform defaults on the platform host', async () => {
    const { generateMetadata } = await import('./layout');
    const meta = await generateMetadata();

    expect(String(meta.metadataBase)).toBe('https://fahimni.me/');
    expect(String((meta.title as any).default)).toContain(SITE_NAME);
    expect(meta.openGraph?.url).toBe('https://fahimni.me/');
  });

  it('points metadataBase and the title at the teacher on a custom host', async () => {
    headerStore.set(TENANT_SLUG_HEADER, 'mrym');
    headerStore.set('host', 'www.maryammath.com');

    const { generateMetadata } = await import('./layout');
    const meta = await generateMetadata();

    expect(String(meta.metadataBase)).toBe('https://www.maryammath.com/');
    expect((meta.title as any).default).toBe('مريم ابراهيم القطاونه');
    expect((meta.title as any).template).toBe('%s | مريم ابراهيم القطاونه');
    expect(meta.openGraph?.url).toBe('https://www.maryammath.com/');
    expect(meta.openGraph?.siteName).toBe('مريم ابراهيم القطاونه');
    expect(meta.description).toBe('نبذة عن الأكاديمية');
    // The platform identity must not leak into a teacher's head.
    expect(JSON.stringify({ ...meta, metadataBase: String(meta.metadataBase) })).not.toContain('fahimni.me');
  });

  it('never builds the base from a spoofed/odd Host header', async () => {
    headerStore.set(TENANT_SLUG_HEADER, 'mrym');
    headerStore.set('host', 'evil host/../x');

    const { generateMetadata } = await import('./layout');
    const meta = await generateMetadata();

    expect(String(meta.metadataBase)).toBe('https://fahimni.me/');
  });
});
