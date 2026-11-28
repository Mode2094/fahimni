'use client';

import { createContext, useContext } from 'react';

/**
 * Tenant context for a teacher's OWN host (custom domain or `{slug}.{platform}`
 * subdomain). The proxy resolves the tenant server-side and passes this down
 * from the root layout, so the client never has to guess the tenant from the
 * (rewritten) browser path.
 *
 * On the platform host this is `null`: `/t/{slug}` routes carry the slug in the
 * URL and the platform chrome/branding stays in charge.
 */
export type TenantBranding = {
  slug: string;
  /** Teacher/academy display name (falls back to the tenant name). */
  name: string;
  logoUrl: string | null;
  /** Brand colors used by auth/app chrome on the teacher host. */
  primary: string;
  accent: string;
};

type TenantHostValue = {
  /** The tenant slug when served from its own host, otherwise `null`. */
  slug: string | null;
  branding: TenantBranding | null;
};

const TenantHostContext = createContext<TenantHostValue>({ slug: null, branding: null });

export function TenantHostProvider({
  slug,
  branding,
  children,
}: {
  slug: string | null;
  branding: TenantBranding | null;
  children: React.ReactNode;
}) {
  return <TenantHostContext.Provider value={{ slug, branding }}>{children}</TenantHostContext.Provider>;
}

/** The tenant host context (slug + branding), or `{ slug: null }` on the platform. */
export function useTenantHost(): TenantHostValue {
  return useContext(TenantHostContext);
}

/**
 * Base path for a tenant's public-site links.
 *
 * On the teacher's own host the public site lives at the root (`/courses`), so
 * the prefix is empty and the address bar keeps the teacher's domain. On the
 * platform the same pages live under `/t/{slug}`.
 */
export function tenantBasePath(hostSlug: string | null, slug: string): string {
  return hostSlug && hostSlug === slug ? '' : `/t/${encodeURIComponent(slug)}`;
}

/** React hook form of {@link tenantBasePath} for the current host. */
export function useTenantBasePath(slug: string): string {
  const { slug: hostSlug } = useTenantHost();

  return tenantBasePath(hostSlug, slug);
}

/**
 * Href for the student login.
 *
 * On the teacher's OWN host the tenant is resolved from the host, so the
 * internal slug must never appear in the address bar — `?tenant_slug=` is only
 * how the PLATFORM carries the tenant through the one shared login route.
 */
export function tenantLoginHref(hostSlug: string | null, slug: string): string {
  return hostSlug ? '/student/login' : `/student/login?tenant_slug=${encodeURIComponent(slug)}`;
}

/** React hook form of {@link tenantLoginHref} for the current host. */
export function useTenantLoginHref(slug: string): string {
  const { slug: hostSlug } = useTenantHost();

  return tenantLoginHref(hostSlug, slug);
}
