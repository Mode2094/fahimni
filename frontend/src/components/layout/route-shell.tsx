'use client';

import { useEffect, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useAuth } from '@/store';
import { PanelShell } from './panel-shell';
import { PublicShell } from './public-shell';
import { isAdminRoute, isStudentRoute, isTeacherRoute, isPublicShellRoute } from './nav-config';

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
  const { user, initialized } = useAuth();
  const [mounted, setMounted] = useState(false);

  const t = (ar: string, en: string) => (user?.locale === 'ar' ? ar : en);

  useEffect(() => { setMounted(true); }, []);

  const needsAuth = isStudentRoute(path);
  useEffect(() => {
    if (mounted && initialized && needsAuth && !user) {
      const from = path ? `?from=${encodeURIComponent(path)}` : '';
      router.replace(`/student/login${from}`);
    }
  }, [mounted, initialized, needsAuth, user, path, router]);

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
    if (!mounted || !initialized || !user) {
      return <div suppressHydrationWarning className="min-h-screen bg-background" aria-hidden="true" />;
    }
    const variant = user?.is_super_admin
      ? 'admin'
      : user?.roles?.includes('Teacher')
        ? 'teacher'
        : 'student';
    return (
      <>
        {user && !user.email_verified_at && (
          <div className="bg-amber-500 text-white text-sm px-4 py-2 text-center">
            <a href="/verify-email" className="underline font-medium">
              {t('فعّل بريدك الإلكتروني لتأمين حسابك', 'Verify your email to secure your account')}
            </a>
          </div>
        )}
        <PanelShell variant={variant}>{children}</PanelShell>
      </>
    );
  }

  return <>{children}</>;
}
