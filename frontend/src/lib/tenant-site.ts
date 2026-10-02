/**
 * Server-side site payload fetch for tenant pages. Public endpoints only
 * (active tenants, public branding) — no auth, no PII.
 */

const API =
  process.env.NEXT_REWRITE_API_TARGET ||
  (process.env.NEXT_PUBLIC_API_URL?.startsWith('http') ? process.env.NEXT_PUBLIC_API_URL : undefined) ||
  'http://localhost:8080/api/v1';

export async function fetchTenantSite(slug: string): Promise<Record<string, any> | null> {
  try {
    const res = await fetch(`${API}/public/${encodeURIComponent(slug)}`, {
      headers: { Accept: 'application/json' },
      next: { revalidate: 60 },
    });
    if (!res.ok) return null;
    const data = await res.json();
    return data?.site ?? null;
  } catch {
    return null;
  }
}