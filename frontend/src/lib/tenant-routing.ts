/**
 * Tenant routing for a teacher's OWN host (custom domain or `{slug}.{platform}`
 * subdomain).
 *
 * A teacher's public site lives under `/t/{slug}` on the platform domain. On
 * the teacher's own host there is no `/t/` prefix, so the proxy rewrites those
 * public pages to the tenant route. It must NOT swallow the whole app: once a
 * student is signed in, the deep pages (`my-learning`, `course/{id}/learn`,
 * exams, results, `card`, `checkout`, …) are ordinary platform routes that
 * resolve their tenant from the session/localStorage. Rewriting every path to
 * `/t/{slug}` is the bug where the entire teacher site collapsed onto the
 * landing page.
 *
 * Only these public tenant-site roots are rewritten; every other path is left
 * untouched so the real app route is served.
 */
export const TENANT_SITE_ROOTS: ReadonlySet<string> = new Set(['', 'courses', 'register', 'blog', 'book']);

/**
 * Return the `/t/{slug}` path that a public tenant-site path should serve, or
 * `null` when the path is not part of the public teacher site (leave it alone).
 */
export function tenantSitePath(pathname: string, slug: string): string | null {
  if (!slug) return null;

  // Drop a trailing slash (except the bare root) so `/courses/` matches.
  const clean = pathname.length > 1 ? pathname.replace(/\/+$/, '') : pathname;
  const first = clean.split('/')[1] ?? '';

  if (!TENANT_SITE_ROOTS.has(first)) return null;

  const prefix = `/t/${encodeURIComponent(slug)}`;

  return clean === '/' ? prefix : `${prefix}${clean}`;
}
