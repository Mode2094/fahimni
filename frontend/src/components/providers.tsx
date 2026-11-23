'use client';

import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { usePlan } from '@/store/plan';
import { patchFetchSecurity } from '@/lib/device';
import { clearLegacyAuthStorage } from '@/lib/auth-client';
import { initMonitoring, instrumentFetch } from '@/lib/monitoring';
import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { Toaster } from 'sonner';
import { RouteShell } from '@/components/layout/route-shell';
import { SessionStatusBanner } from '@/components/auth/session-status-banner';
import { Analytics, MetaVerification } from '@/components/analytics/analytics';
import PwaManager from '@/components/pwa/pwa-manager';
import { ConfirmDialogHost } from '@/components/ui/confirm-dialog';

const HOUR = 60 * 60 * 1000;

export function Providers({ children }: { children: React.ReactNode }) {
  const user = useAuth((s) => s.user);
  const { locale, setLocale } = useLocale();
  const { settings, load: loadSettings, loaded } = useSettings();
  const { load: loadPlan } = usePlan();
  const router = useRouter();

  useEffect(() => {
    // Purge legacy bearer-token artefacts from older builds (one-time).
    clearLegacyAuthStorage();
    // Every fetch() in the app gets same-origin credentials + CSRF headers.
    patchFetchSecurity();
    // Client-side monitoring (safe, sanitized, no PII).
    initMonitoring();
    instrumentFetch();
    loadSettings();
    // Validate the HttpOnly session (if any) on boot — the store keeps only a
    // non-credential UI hint, so this is the single source of truth.
    void useAuth.getState().load();
  }, []);

  // Installed PWAs created before the manifest changed still launch on `/`.
  // Send them through the session-aware entry point instead of the public
  // landing page, which made a valid session look logged out.
  useEffect(() => {
    const standalone =
      window.matchMedia('(display-mode: standalone)').matches ||
      (navigator as unknown as { standalone?: boolean }).standalone === true;

    if (standalone && window.location.pathname === '/') {
      router.replace('/app');
    }
  }, [router]);

  // Platform settings are global: when the tab regains focus (e.g. the admin
  // changed something in another tab), re-fetch so every page reflects the
  // change immediately — no reload required. The same moment is a natural
  // point to slide the server session forward after a long idle period.
  useEffect(() => {
    const onVisible = () => {
      if (document.visibilityState === 'visible') {
        loadSettings();
        if (user) loadPlan();
        if (Date.now() - useAuth.getState().lastValidatedAt > HOUR) {
          void useAuth.getState().refreshSession();
        }
      }
    };
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('focus', onVisible);
    return () => {
      document.removeEventListener('visibilitychange', onVisible);
      window.removeEventListener('focus', onVisible);
    };
  }, [user, loadSettings, loadPlan]);

  // Coming back online is the other moment a failed session check should be
  // retried — without ever treating the outage as a logout.
  useEffect(() => {
    const onOnline = () => { void useAuth.getState().refreshSession(); };
    window.addEventListener('online', onOnline);
    return () => window.removeEventListener('online', onOnline);
  }, []);

  // Keep a long-lived tab's cookie sliding while the app stays open. The
  // request is single-flight and skipped for signed-out visitors.
  useEffect(() => {
    const id = window.setInterval(() => {
      if (document.visibilityState === 'visible') {
        void useAuth.getState().refreshSession();
      }
    }, 6 * HOUR);

    return () => window.clearInterval(id);
  }, []);

  useEffect(() => {
    if (settings.default_locale && loaded && !localStorage.getItem('locale')) {
      setLocale(settings.default_locale);
    }
  }, [loaded, settings.default_locale, setLocale]);

  useEffect(() => {
    document.documentElement.lang = locale;
    document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
    document.documentElement.classList.remove('rtl', 'ltr');
    document.documentElement.classList.add(locale === 'ar' ? 'rtl' : 'ltr');
  }, [locale]);

  // The plan store needs a valid session + tenant context.
  useEffect(() => {
    if (user) loadPlan();
  }, [user, loadPlan]);

  return (
    <>
      <Analytics />
      <MetaVerification />
      <SessionStatusBanner />
      <RouteShell>{children}</RouteShell>
      <PwaManager />
      <ConfirmDialogHost />
      <Toaster richColors position={locale === 'ar' ? 'bottom-left' : 'bottom-right'} />
    </>
  );
}
