/**
 * Server-side SEO helpers for a teacher's public site (`/t/{slug}`).
 *
 * Every tenant page must publish its OWN canonical — without this, the root
 * layout's `alternates.canonical: '/'` leaks into all tenant pages and Google
 * collapses them into the homepage.
 */
import type { Metadata } from 'next';
import { resolveLogoUrl } from '@/lib/logo';
import { absoluteUrl, DEFAULT_OG_IMAGE, getSiteOrigin } from '@/lib/seo';

export const TENANT_FALLBACK_DESC = 'منصة تعليمية: دورات، طلاب، امتحانات وبث مباشر.';

export type TenantSite = Record<string, any> | null;

export function tenantInfo(site: TenantSite) {
  const name = site?.name?.trim() || 'منصتي';
  const about = site?.site_config?.about_text?.trim();
  const logo = resolveLogoUrl(site?.logo_url);
  return { name, about, logo, description: about || TENANT_FALLBACK_DESC };
}

/** Build page metadata with a correct per-tenant canonical + robots. */
export function tenantMetadata(
  slug: string,
  site: TenantSite,
  opts: { title: string; description?: string; path?: string; index?: boolean },
): Metadata {
  const { name, logo, description } = tenantInfo(site);
  const desc = opts.description?.trim() || description;
  const path = opts.path || `/t/${slug}`;
  const image = logo && logo !== '/logo.png' ? logo : DEFAULT_OG_IMAGE;

  return {
    title: { absolute: opts.title },
    description: desc,
    alternates: { canonical: path },
    robots:
      opts.index === false
        ? { index: false, follow: false }
        : { index: true, follow: true },
    openGraph: {
      title: opts.title,
      description: desc,
      type: 'website',
      siteName: name,
      url: absoluteUrl(path),
      images: [{ url: image, width: 512, height: 512, alt: name }],
    },
    twitter: {
      card: 'summary',
      title: opts.title,
      description: desc,
      images: [image],
    },
  };
}

export function tenantOrigin(slug: string): string {
  return `${getSiteOrigin()}/t/${slug}`;
}

/** EducationalOrganization + WebSite structured data for the tenant root. */
export function tenantOrganizationLd(slug: string, site: TenantSite): object[] {
  const { name, description, logo } = tenantInfo(site);
  const url = tenantOrigin(slug);
  return [
    {
      '@context': 'https://schema.org',
      '@type': 'EducationalOrganization',
      name,
      url,
      description,
      image: logo,
    },
    {
      '@context': 'https://schema.org',
      '@type': 'WebSite',
      name,
      url,
      inLanguage: 'ar',
    },
  ];
}

/** Course list structured data (only for courses actually published). */
export function tenantCoursesLd(slug: string, site: TenantSite, courses: any[]): object | null {
  const list = (courses || []).filter((c) => c?.title);
  if (!list.length) return null;
  const { name } = tenantInfo(site);
  const url = `${tenantOrigin(slug)}/courses`;
  return {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    name: `دورات ${name}`,
    itemListElement: list.map((c, i) => ({
      '@type': 'ListItem',
      position: i + 1,
      item: {
        '@type': 'Course',
        name: c.title,
        description: c.short_description || c.description || undefined,
        url,
        inLanguage: 'ar',
        provider: { '@type': 'EducationalOrganization', name, url: tenantOrigin(slug) },
      },
    })),
  };
}

/** FAQPage structured data (only for questions the teacher actually published). */
export function tenantFaqLd(site: TenantSite): object | null {
  const cfg = site?.site_config;
  if (!cfg?.show_faq) return null;
  const items = (cfg?.faq || []).filter((f: any) => f?.q && f?.a);
  if (!items.length) return null;
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: items.map((f: any) => ({
      '@type': 'Question',
      name: f.q,
      acceptedAnswer: { '@type': 'Answer', text: f.a },
    })),
  };
}

/** Article structured data for a tenant blog post. */
export function tenantArticleLd(
  slug: string,
  site: TenantSite,
  post: Record<string, any>,
): object | null {
  if (!post?.title) return null;
  const { name, logo } = tenantInfo(site);
  const url = `${tenantOrigin(slug)}/blog/${post.slug}`;
  return {
    '@context': 'https://schema.org',
    '@type': 'BlogPosting',
    headline: post.title,
    description: post.excerpt || undefined,
    image: post.cover_image || logo,
    datePublished: post.published_at || undefined,
    dateModified: post.updated_at || post.published_at || undefined,
    inLanguage: 'ar',
    mainEntityOfPage: { '@type': 'WebPage', '@id': url },
    author: { '@type': 'Organization', name },
    publisher: { '@type': 'EducationalOrganization', name, logo: { '@type': 'ImageObject', url: logo } },
  };
}
