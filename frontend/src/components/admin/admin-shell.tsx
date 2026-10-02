'use client';
import { useAuth } from '@/store';
import { usePathname, useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { PanelShell } from '@/components/layout/panel-shell';

export default function AdminShell({ children }: { children: React.ReactNode }) {
  const { check, user } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const [ready, setReady] = useState(false);

  const isLogin = pathname === '/admin/login';

  useEffect(() => {
    setReady(true);
    if (isLogin) return;
    if (!check()) router.replace('/admin/login');
    else if (user && !user.is_super_admin) router.replace(user.roles?.includes('Teacher') ? '/teacher/dashboard' : '/student/login');
  }, [check(), user, router, isLogin]);

  if (isLogin) return <>{children}</>;
  if (!ready || !check()) return <div suppressHydrationWarning className="min-h-screen bg-background" aria-hidden="true" />;

  return <PanelShell variant="admin">{children}</PanelShell>;
}
