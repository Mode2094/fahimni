'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/store';
import { dashboardPathFor } from '@/lib/auth-routes';
import { AuthUnavailable } from '@/components/auth/auth-unavailable';
import { Loader2 } from 'lucide-react';
import PlatformLogo from '@/components/brand/platform-logo';

/**
 * Session-aware PWA entry point.
 *
 * The manifest launches installed apps here instead of `/`, so a returning
 * user goes straight to their role dashboard (student / teacher / admin)
 * after the HttpOnly session is validated. Signed-out visitors land on the
 * shared login page; an outage shows the recoverable offline state.
 */
export default function AppLaunchPage() {
  const router = useRouter();
  const user = useAuth((s) => s.user);
  const initialized = useAuth((s) => s.initialized);
  const status = useAuth((s) => s.status);

  useEffect(() => {
    if (!initialized) return;

    if (user) {
      router.replace(dashboardPathFor(user));
      return;
    }

    if (status === 'unauthenticated') {
      router.replace('/student/login?from=%2Fapp');
    }
  }, [initialized, user, status, router]);

  if (initialized && !user && status === 'unavailable') {
    return <AuthUnavailable />;
  }

  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-background text-center">
      <PlatformLogo className="h-16 w-16 rounded-3xl object-contain shadow-lg" />
      <Loader2 className="h-6 w-6 animate-spin text-teal-600" />
      <p className="text-sm text-muted-foreground">جارٍ استعادة جلستك…</p>
    </div>
  );
}
