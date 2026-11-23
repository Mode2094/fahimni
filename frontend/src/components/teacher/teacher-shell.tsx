'use client';
import { useAuth } from '@/store';
import { usePlan } from '@/store/plan';
import { usePathname, useRouter } from 'next/navigation';
import { useEffect } from 'react';
import { PanelShell } from '@/components/layout/panel-shell';
import { AuthUnavailable } from '@/components/auth/auth-unavailable';
import { dashboardPathFor } from '@/lib/auth-routes';

export default function TeacherShell({ children }: { children: React.ReactNode }) {
  const { user, initialized, status } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  const isLogin = pathname === '/teacher/login';
  const isProfile = pathname === '/teacher/complete-profile';

  const needsProfile = !!user && !user.is_super_admin
    && !!user.requires_profile_completion && user.roles?.includes('Teacher');

  useEffect(() => {
    if (isLogin || !initialized) return;

    if (!user) {
      if (status === 'unauthenticated') router.replace('/teacher/login');
      return;
    }

    if (!user.roles?.includes('Teacher') && !user.is_super_admin) {
      router.replace(dashboardPathFor(user));
      return;
    }

    // Teacher with no phone yet: everything funnels through the completion
    // step, and a completed teacher never sees it again.
    if (needsProfile && !isProfile) {
      router.replace('/teacher/complete-profile');
      return;
    }
    if (!needsProfile && isProfile && !user.is_super_admin) {
      router.replace('/teacher/dashboard');
    }
  }, [initialized, status, user, router, isLogin, isProfile, needsProfile]);

  // Force plan selection for a workspace with no live plan/trial: a
  // pending-payment teacher must never land on the panel (and the dashboard
  // must never present them as "unlimited"). `active` is the explicit signal
  // from /teacher/plan; we only act once it is known, so a transient fetch
  // failure can never bounce a legitimate teacher.
  const planActive = usePlan((s) => s.active);
  const planLoaded = usePlan((s) => s.loaded);

  useEffect(() => {
    if (isLogin || isProfile || needsProfile || !user || user.is_super_admin) return;
    if (!planLoaded) void usePlan.getState().load();
  }, [user, isLogin, isProfile, needsProfile, planLoaded]);

  const needsPlan = !!user && user.roles?.includes('Teacher') && !user.is_super_admin
    && !needsProfile && planLoaded && planActive === false;

  useEffect(() => {
    if (isLogin || isProfile) return;
    if (needsPlan) router.replace('/pricing');
  }, [needsPlan, isLogin, isProfile, router]);

  if (isLogin) return <>{children}</>;
  if (!initialized) return <div suppressHydrationWarning className="min-h-screen bg-background" aria-hidden="true" />;
  if (!user) return status === 'unavailable' ? <AuthUnavailable /> : <div suppressHydrationWarning className="min-h-screen bg-background" aria-hidden="true" />;

  // The completion step renders standalone; a pending or current teacher never
  // reaches the panel shell until their profile is complete.
  if (isProfile) return <>{children}</>;
  if (needsProfile) return <div suppressHydrationWarning className="min-h-screen bg-background" aria-hidden="true" />;
  if (needsPlan) return <div suppressHydrationWarning className="min-h-screen bg-background" aria-hidden="true" />;

  return (
    <PanelShell variant="teacher">
      {children}
    </PanelShell>
  );
}
