'use client';

import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { usePlan } from '@/store/plan';
import { patchFetchSecurity } from '@/lib/device';
import { clearLegacyAuthStorage } from '@/lib/auth-client';
import { initMonitoring, instrumentFetch } from '@/lib/monitoring';
import { useEffect } from 'react';
import { Toaster } from 'sonner';
import { RouteShell } from '@/components/layout/route-shell';

export function Providers({ children }: { children: React.ReactNode }) {
  const user = useAuth((s) => s.user);
  const { locale, setLocale } = useLocale();
  const { settings, load: loadSettings, loaded } = useSettings();
  const { load: loadPlan } = usePlan();

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

  // Platform settings are global: when the tab regains focus (e.g. the admin
  // changed something in another tab), re-fetch so every page reflects the
  // change immediately — no reload required.
  useEffect(() => {
    const onVisible = () => {
      if (document.visibilityState === 'visible') {
        loadSettings();
        if (user) loadPlan();
      }
    };
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('focus', onVisible);
    return () => {
      document.removeEventListener('visibilitychange', onVisible);
      window.removeEventListener('focus', onVisible);
    };
  }, [user, loadSettings, loadPlan]);

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
      <RouteShell>{children}</RouteShell>
      <Toaster richColors position={locale === 'ar' ? 'bottom-left' : 'bottom-right'} />
    </>
  );
}
