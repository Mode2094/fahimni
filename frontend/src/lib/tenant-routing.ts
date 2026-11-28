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
 * Request headers the proxy stamps onto every page served from a verified
 * teacher host, so server components can recover the tenant context that the
 * browser URL no longer carries (the proxy rewrites the path but the address
 * bar keeps the clean, tenant-less path).
 */
export const TENANT_SLUG_HEADER = 'x-tenant-slug';
export const TENANT_HOST_HEADER = 'x-tenant-host';

type TenantSiteMatch = {
  /** The public site root this path belongs to. */
  root: string;
  /** The path to serve under `/t/{slug}` (may differ from the input). */
  clean: string;
};

/**
 * Classify a path as a public tenant-site path, returning the `/t/{slug}`
 * target path, or `null` when it is not part of the public teacher site.
 *
 * `/register/student` is the student sign-up and maps onto the tenant's single
 * registration page (`/t/{slug}/register`). `/register/teacher` — and any other
 * register sub-path — is a PLATFORM route and is left alone: rewriting it used
 * to point at a `register/teacher` page that does not exist under the tenant
 * group, so it 404'd.
 */
function tenantSiteRoot(pathname: string): TenantSiteMatch | null {
  // Drop a trailing slash (except the bare root) so `/courses/` matches.
  const clean = pathname.length > 1 ? pathname.replace(/\/+$/, '') : pathname;
  const segments = clean.split('/');
  const first = segments[1] ?? '';

  if (!TENANT_SITE_ROOTS.has(first)) return null;

  if (first === 'register') {
    const second = segments[2] ?? '';
    // Only the bare registration form (and its `/student` alias) is a tenant
    // page; everything else stays on the platform route.
    if (second && second !== 'student') return null;

    return { root: first, clean: second === 'student' ? '/register' : clean };
  }

  return { root: first, clean };
}

/**
 * Return the `/t/{slug}` path that a public tenant-site path should serve, or
 * `null` when the path is not part of the public teacher site (leave it alone).
 */
export function tenantSitePath(pathname: string, slug: string): string | null {
  if (!slug) return null;

  const match = tenantSiteRoot(pathname);
  if (!match) return null;

  const prefix = `/t/${encodeURIComponent(slug)}`;

  return match.clean === '/' ? prefix : `${prefix}${match.clean}`;
}

/**
 * True when `pathname` is one of the public teacher-site routes that a teacher
 * host serves (home, courses, register, blog, book) — the same decision the
 * proxy makes, available client-side so the route shell never treats a
 * teacher's public page as a signed-in platform route.
 */
export function isTenantSitePath(pathname: string): boolean {
  return tenantSiteRoot(pathname) !== null;
}
