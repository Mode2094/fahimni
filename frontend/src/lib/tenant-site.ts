/**
 * Server-side site payload fetch for tenant pages. Public endpoints only
 * (active tenants, public branding) — no auth, no PII.
 */
import { cache } from 'react';

const API =
  process.env.NEXT_REWRITE_API_TARGET ||
  (process.env.NEXT_PUBLIC_API_URL?.startsWith('http') ? process.env.NEXT_PUBLIC_API_URL : undefined) ||
  'http://localhost:8080/api/v1';

async function getJson(path: string): Promise<any | null> {
  try {
    const res = await fetch(`${API}${path}`, {
      headers: { Accept: 'application/json' },
      next: { revalidate: 60 },
    });
    if (!res.ok) return null;
    return await res.json();
  } catch {
    return null;
  }
}

/** Tenant public site payload (branding + site_config). Deduped per request. */
export const fetchTenantSite = cache(
  async (slug: string): Promise<Record<string, any> | null> => {
    const data = await getJson(`/public/${encodeURIComponent(slug)}`);
    return data?.site ?? null;
  },
);

/** Published courses for a tenant's public site. */
export const fetchTenantCourses = cache(async (slug: string): Promise<any[]> => {
  const data = await getJson(`/public/${encodeURIComponent(slug)}/courses`);
  return Array.isArray(data?.courses) ? data.courses : [];
});

/** Published blog posts for a tenant's public site. */
export const fetchTenantPosts = cache(async (slug: string): Promise<any[]> => {
  const data = await getJson(`/public/${encodeURIComponent(slug)}/posts`);
  return Array.isArray(data?.posts) ? data.posts : [];
});

/** A single published blog post by slug. */
export const fetchTenantPost = cache(
  async (slug: string, post: string): Promise<Record<string, any> | null> => {
    const data = await getJson(
      `/public/${encodeURIComponent(slug)}/posts/${encodeURIComponent(post)}`,
    );
    return data?.post ?? null;
  },
);
