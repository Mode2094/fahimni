'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { RefreshCw, WifiOff } from 'lucide-react';
import { SW_PATH, isUpdateBlockedPath } from '@/lib/pwa';

/**
 * App-wide PWA runtime: registers the service worker, offers a non-disruptive
 * "update ready" reload, shows a connectivity bar, and routes notification
 * clicks to the right page. It never touches account data.
 */
export default function PwaManager() {
  const router = useRouter();
  const path = usePathname();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [offline, setOffline] = useState(false);
  const [updateReady, setUpdateReady] = useState(false);

  const registrationRef = useRef<ServiceWorkerRegistration | null>(null);
  // Set only when the student taps "تحديث", so the controllerchange that
  // follows a skipWaiting reloads exactly once. The controllerchange caused by
  // the FIRST install (clients.claim) must NOT reload the page.
  const updateAppliedRef = useRef(false);

  /* ── Service worker registration + update flow ─────────────────────── */
  useEffect(() => {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return;

    let cancelled = false;

    const onControllerChange = () => {
      if (!updateAppliedRef.current) return;
      updateAppliedRef.current = false;
      window.location.reload();
    };

    navigator.serviceWorker.addEventListener('controllerchange', onControllerChange);

    navigator.serviceWorker
      .register(SW_PATH, { scope: '/', updateViaCache: 'none' })
      .then((registration) => {
        if (cancelled) return;
        registrationRef.current = registration;

        // A worker already waiting when this page loaded → update is ready.
        if (registration.waiting && navigator.serviceWorker.controller) {
          setUpdateReady(true);
        }

        registration.addEventListener('updatefound', () => {
          const installing = registration.installing;
          if (!installing) return;
          installing.addEventListener('statechange', () => {
            if (installing.state === 'installed' && navigator.serviceWorker.controller) {
              setUpdateReady(true);
            }
          });
        });
      })
      .catch(() => {
        /* SW unsupported or blocked — the app still works online */
      });

    return () => {
      cancelled = true;
      navigator.serviceWorker.removeEventListener('controllerchange', onControllerChange);
    };
  }, []);

  /* ── Notification-click routing from the service worker ────────────── */
  useEffect(() => {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return;

    const onMessage = (event: MessageEvent) => {
      const data = event.data || {};
      if (data.type === 'fahimni:navigate' && typeof data.url === 'string') {
        router.push(data.url);
      }
    };
    navigator.serviceWorker.addEventListener('message', onMessage);

    return () => navigator.serviceWorker.removeEventListener('message', onMessage);
  }, [router]);

  /* ── Connectivity state ────────────────────────────────────────────── */
  useEffect(() => {
    const sync = () => setOffline(!navigator.onLine);
    sync();
    window.addEventListener('online', sync);
    window.addEventListener('offline', sync);

    return () => {
      window.removeEventListener('online', sync);
      window.removeEventListener('offline', sync);
    };
  }, []);

  const applyUpdate = useCallback(() => {
    const waiting = registrationRef.current?.waiting;
    if (waiting) {
      updateAppliedRef.current = true; // the next controllerchange reloads once
      waiting.postMessage({ type: 'fahimni:skip-waiting' });
    } else {
      window.location.reload();
    }
    setUpdateReady(false);
  }, []);

  return (
    <>
      {offline && (
        <div
          role="status"
          className="fixed inset-x-0 top-0 z-[70] flex items-center justify-center gap-2 bg-slate-800 px-4 py-2 text-center text-xs font-medium text-white"
          style={{ paddingTop: 'max(0.5rem, env(safe-area-inset-top))' }}
        >
          <WifiOff className="h-3.5 w-3.5 shrink-0" />
          {t(
            'أنت غير متصل — الصفحات المحفوظة تعمل، والمحتوى المباشر يحتاج الإنترنت',
            'You are offline — saved pages work, live content needs the internet',
          )}
        </div>
      )}

      {updateReady && !isUpdateBlockedPath(path) && (
        <div
          role="status"
          className="fixed inset-x-0 bottom-0 z-[70] mx-auto flex max-w-md items-center justify-between gap-3 rounded-t-2xl border border-teal-100 bg-white px-4 py-3 shadow-2xl"
          style={{ paddingBottom: 'max(0.75rem, env(safe-area-inset-bottom))' }}
        >
          <span className="text-sm font-medium">
            {t('يتوفّر إصدار جديد من التطبيق', 'A new app version is ready')}
          </span>
          <Button size="sm" className="shrink-0 gap-1 rounded-full" onClick={applyUpdate}>
            <RefreshCw className="h-3.5 w-3.5" />
            {t('تحديث', 'Update')}
          </Button>
        </div>
      )}
    </>
  );
}
