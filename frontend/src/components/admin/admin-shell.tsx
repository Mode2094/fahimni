'use client';
import { useAuth } from '@/store';
import { usePathname, useRouter } from 'next/navigation';
import { useEffect } from 'react';
import { PanelShell } from '@/components/layout/panel-shell';
import { AuthUnavailable } from '@/components/auth/auth-unavailable';
import { dashboardPathFor } from '@/lib/auth-routes';

export default function AdminShell({ children }: { children: React.ReactNode }) {
  const { user, initialized, status } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  const isLogin = pathname === '/admin/login';

  useEffect(() => {
    if (isLogin || !initialized) return;

    if (!user) {
      // Only an explicit 401 sends the user to login. A network/5xx failure
      // keeps the shell on the recoverable offline screen instead.
      if (status === 'unauthenticated') router.replace('/admin/login');
      return;
    }

    if (!user.is_super_admin) router.replace(dashboardPathFor(user));
  }, [initialized, status, user, router, isLogin]);

  if (isLogin) return <>{children}</>;
  if (!initialized) return <div suppressHydrationWarning className="min-h-screen bg-background" aria-hidden="true" />;
  if (!user) return status === 'unavailable' ? <AuthUnavailable /> : <div suppressHydrationWarning className="min-h-screen bg-background" aria-hidden="true" />;

  return <PanelShell variant="admin">{children}</PanelShell>;
}
