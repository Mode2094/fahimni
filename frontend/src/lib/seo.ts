import type { Metadata } from 'next';
import { BRAND_NAME, BRAND_NAME_EN } from '@/lib/brand';

export const SITE_NAME = BRAND_NAME;
export const SITE_NAME_EN = BRAND_NAME_EN;

/** Public primary origin (scheme + host). Env-controlled so production canonicals are correct. */
export function getSiteOrigin(): string {
  const domain = process.env.NEXT_PUBLIC_PLATFORM_DOMAIN?.trim() || 'fahimni.me';
  return `https://${domain.replace(/^https?:\/\//, '').replace(/\/$/, '')}`;
}

export function absoluteUrl(path: string): string {
  if (/^https?:\/\//.test(path)) return path;
  return `${getSiteOrigin()}${path.startsWith('/') ? path : `/${path}`}`;
}

export const DEFAULT_OG_IMAGE = '/og.png';

/** Shared Open Graph + Twitter base so every public page stays on-brand. */
export function ogBase(title: string, description: string, path: string, locale: 'ar' | 'en' = 'ar'): Metadata['openGraph'] {
  return {
    title,
    description,
    type: 'website',
    siteName: SITE_NAME,
    url: absoluteUrl(path),
    locale: locale === 'ar' ? 'ar_AR' : 'en_US',
    images: [{ url: absoluteUrl(DEFAULT_OG_IMAGE), width: 1200, height: 630, alt: SITE_NAME }],
  };
}

export function twitterBase(title: string, description: string): Metadata['twitter'] {
  return {
    card: 'summary_large_image',
    title,
    description,
    images: [absoluteUrl(DEFAULT_OG_IMAGE)],
  };
}

/** robots metadata for private / logged-in sections — never rely on robots.txt alone. */
export const NOINDEX: Metadata['robots'] = { index: false, follow: false };

export function jsonLd(data: unknown): string {
  return JSON.stringify(data).replace(/</g, '\\u003c');
}