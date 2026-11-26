/**
 * Server-side SEO helpers for a teacher's public site (`/t/{slug}`).
 *
 * Every tenant page must publish its OWN canonical — without this, the root
 * layout's `alternates.canonical: '/'` leaks into all tenant pages and Google
 * collapses them into the homepage.
 */
import type { Metadata } from 'next';
import { headers } from 'next/headers';
import { resolveLogoUrl } from '@/lib/logo';
import { DEFAULT_OG_IMAGE, getSiteOrigin } from '@/lib/seo';

export const TENANT_FALLBACK_DESC = 'منصة تعليمية: دورات، طلاب، امتحانات وبث مباشر.';

export type TenantSite = Record<string, any> | null;

/**
 * Public base for a tenant page: the origin + path prefix its URLs live under.
 *
 * On the platform the teacher's site is `/t/{slug}` on the platform origin. On
 * the teacher's OWN host (custom domain / `{slug}.{platform}` subdomain) the
 * proxy stamps `x-tenant-host` and the site is served from the host ROOT, so
 * the canonical/OG must use the teacher's domain — never the internal `/t/…`.
 */
export type TenantPublicBase = { origin: string; prefix: string };

/** Default base when there is no request context (platform origin + `/t/{slug}`). */
function defaultBase(slug: string): TenantPublicBase {
  return { origin: getSiteOrigin(), prefix: `/t/${encodeURIComponent(slug)}` };
}

/** Resolve the public base for the current request from the tenant-host header. */
export async function tenantPublicBase(slug: string): Promise<TenantPublicBase> {
  try {
    const h = await headers();
    if (h.get('x-tenant-host') === '1') {
      const host = h.get('host')?.split(':')[0]?.toLowerCase();
      // Only a plain DNS hostname: the Host header is attacker-controlled and
      // ends up in a URL here.
      if (host && /^[a-z0-9.-]+$/.test(host)) return { origin: `https://${host}`, prefix: '' };
    }
  } catch {
    // Not inside a request (static build): fall back to the platform.
  }
  return defaultBase(slug);
}

/** Strip the `/t/{slug}` prefix so a tenant path can be re-rooted. */
function tenantRelPath(slug: string, path: string): string {
  const clean = path.replace(/\/+$/, '') || '/';
  const prefix = `/t/${slug}`;
  if (clean === prefix) return '/';
  if (clean.startsWith(`${prefix}/`)) return clean.slice(prefix.length);
  return clean;
}

/** Absolute URL for a tenant path under the given base. */
export function tenantUrl(base: TenantPublicBase, slug: string, path: string): string {
  const rel = tenantRelPath(slug, path);
  const joined = `${base.prefix}${rel === '/' ? '' : rel}`;
  return joined ? `${base.origin}${joined}` : `${base.origin}/`;
}

/** Root-relative canonical (no origin) for a tenant path under the given base. */
function tenantCanonical(base: TenantPublicBase, slug: string, path: string): string {
  const rel = tenantRelPath(slug, path);
  return `${base.prefix}${rel === '/' ? '' : rel}` || '/';
}

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
  opts: { title: string; description?: string; path?: string; index?: boolean; base?: TenantPublicBase },
): Metadata {
  const { name, logo, description } = tenantInfo(site);
  const desc = opts.description?.trim() || description;
  const path = opts.path || `/t/${slug}`;
  const base = opts.base ?? defaultBase(slug);
  const canonical = tenantCanonical(base, slug, path);
  const url = tenantUrl(base, slug, path);
  const image = logo && logo !== '/logo.png' ? logo : DEFAULT_OG_IMAGE;

  return {
    title: { absolute: opts.title },
    description: desc,
    alternates: { canonical },
    robots:
      opts.index === false
        ? { index: false, follow: false }
        : { index: true, follow: true },
    openGraph: {
      title: opts.title,
      description: desc,
      type: 'website',
      siteName: name,
      url,
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
export function tenantOrganizationLd(slug: string, site: TenantSite, base?: TenantPublicBase): object[] {
  const { name, description, logo } = tenantInfo(site);
  const url = tenantUrl(base ?? defaultBase(slug), slug, `/t/${slug}`);
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
export function tenantCoursesLd(
  slug: string,
  site: TenantSite,
  courses: any[],
  base?: TenantPublicBase,
): object | null {
  const list = (courses || []).filter((c) => c?.title);
  if (!list.length) return null;
  const { name } = tenantInfo(site);
  const b = base ?? defaultBase(slug);
  const url = tenantUrl(b, slug, `/t/${slug}/courses`);
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
        provider: { '@type': 'EducationalOrganization', name, url: tenantUrl(b, slug, `/t/${slug}`) },
      },
    })),
  };
}

/** Course structured data for a single public course page. */
export function tenantCourseLd(
  slug: string,
  site: TenantSite,
  course: Record<string, any> | null,
  base?: TenantPublicBase,
): object | null {
  if (!course?.title) return null;
  const { name } = tenantInfo(site);
  const b = base ?? defaultBase(slug);
  const url = tenantUrl(b, slug, `/t/${slug}/courses/${course.id}`);
  const price = Number(course.price) || 0;
  return {
    '@context': 'https://schema.org',
    '@type': 'Course',
    name: course.title,
    description: course.short_description || course.description || undefined,
    url,
    inLanguage: 'ar',
    provider: { '@type': 'EducationalOrganization', name, url: tenantUrl(b, slug, `/t/${slug}`) },
    ...(price > 0
      ? {
          offers: {
            '@type': 'Offer',
            price: String(price),
            priceCurrency: course.currency || 'USD',
            availability: 'https://schema.org/InStock',
            url,
          },
        }
      : {}),
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
  base?: TenantPublicBase,
): object | null {
  if (!post?.title) return null;
  const { name, logo } = tenantInfo(site);
  const url = tenantUrl(base ?? defaultBase(slug), slug, `/t/${slug}/blog/${post.slug}`);
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
