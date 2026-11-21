'use client';

import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Loader2, RefreshCw, WifiOff } from 'lucide-react';

/**
 * Full-page state for "the session check could not reach the server and no
 * cached profile is available". It is deliberately recoverable: the local
 * cookie/session is untouched, so a retry (or coming back online) restores
 * the app without a login.
 */
export function AuthUnavailable() {
  const loading = useAuth((s) => s.loading);
  const refreshSession = useAuth((s) => s.refreshSession);
  const { locale } = useLocale();
  const ar = locale === 'ar';

  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-6 text-center">
      <div className="max-w-sm space-y-4">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-500/10 text-amber-600">
          <WifiOff className="h-7 w-7" />
        </div>
        <div className="space-y-1.5">
          <h1 className="text-lg font-bold">
            {ar ? 'تعذر الاتصال بالخادم' : 'Cannot reach the server'}
          </h1>
          <p className="text-sm text-muted-foreground">
            {ar
              ? 'جلستك لم تُحذف. تحقق من الشبكة ثم أعد المحاولة.'
              : 'Your session was not cleared. Check your connection and retry.'}
          </p>
        </div>
        <button
          type="button"
          onClick={() => void refreshSession()}
          disabled={loading}
          className="inline-flex items-center gap-2 rounded-xl bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-60"
        >
          {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
          {ar ? 'إعادة المحاولة' : 'Retry'}
        </button>
      </div>
    </div>
  );
}
