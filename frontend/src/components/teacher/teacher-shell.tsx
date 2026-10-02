'use client';
import { useAuth } from '@/store';
import { usePathname, useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { PanelShell } from '@/components/layout/panel-shell';
import AskMe from '@/components/assistant/ask-me';

export default function TeacherShell({ children }: { children: React.ReactNode }) {
  const { check, user } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const [ready, setReady] = useState(false);

  const isLogin = pathname === '/teacher/login';

  useEffect(() => {
    setReady(true);
    if (isLogin) return;
    if (!check()) router.replace('/teacher/login');
    else if (user && !user.roles?.includes('Teacher') && !user.is_super_admin) router.replace('/student/login');
  }, [check(), user, router, isLogin]);

  if (isLogin) return <>{children}</>;
  if (!ready || !check()) return <div suppressHydrationWarning className="min-h-screen bg-background" aria-hidden="true" />;

  return (
    <PanelShell variant="teacher">
      {children}
      <AskMe />
    </PanelShell>
  );
}
