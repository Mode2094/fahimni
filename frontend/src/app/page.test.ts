import { describe, it, expect, vi, afterEach } from 'vitest';

import { DEFAULT_OG_IMAGE, SITE_NAME, absoluteUrl, jsonLd } from '@/lib/seo';

// The landing route's metadata is data-driven (the platform logo comes from the
// tenant/platform settings). Pin the domain so canonicals are deterministic.
process.env.NEXT_PUBLIC_PLATFORM_DOMAIN = 'fahimni.me';

afterEach(() => vi.unstubAllGlobals());

describe('Landing page metadata (app/page.tsx)', () => {
  it('exposes Arabic title, description and a relative canonical', async () => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: false, json: async () => ({}) })));

    const { generateMetadata } = await import('./page');
    const meta = await generateMetadata();

    expect(meta.title).toContain('منصة تعليمية');
    expect(meta.title).toContain(SITE_NAME);
    expect(meta.description).toContain('فهمني');
    expect(meta.alternates?.canonical).toBe('/');
  });

  it('uses the platform logo as the OG image when one is configured', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.resolve({ ok: true, json: async () => ({ settings: { logo_url: '/storage/uploads/logo.png' } }) })),
    );

    const { generateMetadata } = await import('./page');
    const meta = await generateMetadata();

    const images = meta.openGraph?.images as any[];
    expect(images[0].url).toBe('https://fahimni.me/storage/uploads/logo.png');
    expect(images[0].alt).toBe(SITE_NAME);
  });

  it('falls back to the default OG image when settings are unreachable', async () => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new Error('offline'))));

    const { generateMetadata } = await import('./page');
    const meta = await generateMetadata();

    const images = meta.openGraph?.images as any[];
    expect(images[0].url).toBe(absoluteUrl(DEFAULT_OG_IMAGE));
    expect(images[0].width).toBe(1200);
    expect(images[0].height).toBe(630);
  });

  it('serialises JSON-LD without an HTML-injection escape hatch', () => {
    const out = jsonLd({ name: '</script><script>alert(1)</script>' });
    expect(out).not.toContain('</script>');
    expect(out).toContain('\\u003c');
  });
});
