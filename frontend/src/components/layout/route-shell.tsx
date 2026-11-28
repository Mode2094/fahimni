'use client';

import { useEffect, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useAuth } from '@/store';
import { PanelShell } from './panel-shell';
import { EmailVerifyBanner } from './email-verify-banner';
import { PublicShell } from './public-shell';
import { AuthUnavailable } from '@/components/auth/auth-unavailable';
import { isAdminRoute, isStudentRoute, isTeacherRoute, isPublicShellRoute } from './nav-config';
import { isTenantSitePath } from '@/lib/tenant-routing';
import { useTenantHost } from '@/lib/tenant-host';

/**
 * Route-aware shell: picks the right navigation experience per route.
 *
 *  - /admin/* and /teacher/* are wrapped by their own layouts (enterprise
 *    sidebar + mobile drawer) — passed through untouched.
 *  - Student/shared panel routes (my-learning, exams, community, …) get a
 *    role-aware panel shell: desktop sidebar + mobile drawer for teachers &
 *    admins, bottom navigation for students on phones.
 *  - A curated set of public pages (pricing, auth flows, checkout) get a slim
 *    public header + footer so they never feel like dead ends.
 *  - Everything else (landing, teacher public sites, certificate pages) keeps
 *    its own designed chrome.
 */
export function RouteShell({ children }: { children: React.ReactNode }) {
  const path = usePathname();
  const router = useRouter();
  const { user, initialized, status } = useAuth();
  const { slug: tenantHostSlug } = useTenantHost();
  const [mounted, setMounted] = useState(false);

  useEffect(() => { setMounted(true); }, []);

  const needsAuth = isStudentRoute(path);

  // On a teacher's OWN host the proxy rewrites the public teacher-site paths to
  // `/t/{slug}` server-side, so the browser path (`/courses`, `/register`, …)
  // collides with an authenticated platform route of the same name. Render
  // those pages with their own chrome instead of the platform auth gate (which
  // blanked `/courses` for guests) or the platform public shell.
  const tenantSitePage = !!tenantHostSlug && isTenantSitePath(path);

  useEffect(() => {
    // Never redirect while the session check is still running, and never
    // redirect on a network/5xx failure — that would turn an outage into a
    // logout. Only an explicit 401 (status unauthenticated) sends the user
    // to the login page.
    if (mounted && initialized && needsAuth && status === 'unauthenticated' && !user) {
      const from = path ? `?from=${encodeURIComponent(path)}` : '';
      router.replace(`/student/login${from}`);
    }
  }, [mounted, initialized, needsAuth, status, user, path, router]);

  if (tenantSitePage) {
    return <>{children}</>;
  }

  // Admin & teacher namespaces have their own layouts (sidebar stays mounted).
  if (isAdminRoute(path) || path.startsWith('/teacher')) {
    return <>{children}</>;
  }

  if (isPublicShellRoute(path)) {
    return <PublicShell>{children}</PublicShell>;
  }

  // Shared panel routes outside the /teacher namespace (courses, students,
  // analytics, blog, community…) must keep the role-aware sidebar visible.
  if (isTeacherRoute(path) || needsAuth) {
    if (!mounted || !initialized) {
      return <div suppressHydrationWarning className="min-h-screen bg-background" aria-hidden="true" />;
    }

    // The server could not be reached and no profile is cached: show a
    // recoverable message instead of an endless blank screen or a fake
    // logout. The banner above offers a retry.
    if (!user && status === 'unavailable') {
      return <AuthUnavailable />;
    }

    if (!user) {
      return <div suppressHydrationWarning className="min-h-screen bg-background" aria-hidden="true" />;
    }

    const variant = user?.is_super_admin
      ? 'admin'
      : user?.roles?.includes('Teacher')
        ? 'teacher'
        : 'student';
    return (
      <>
        {user && !user.email_verified_at && <EmailVerifyBanner userId={user.id} />}
        <PanelShell variant={variant}>{children}</PanelShell>
      </>
    );
  }

  return <>{children}</>;
}
