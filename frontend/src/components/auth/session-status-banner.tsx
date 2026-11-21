'use client';

import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Loader2, RefreshCw, WifiOff } from 'lucide-react';

/**
 * A transient validation failure is not a logout. This banner makes that
 * explicit and offers a manual retry while the store keeps the cached
 * profile (and the HttpOnly cookie) intact.
 */
export function SessionStatusBanner() {
  const status = useAuth((s) => s.status);
  const user = useAuth((s) => s.user);
  const loading = useAuth((s) => s.loading);
  const refreshSession = useAuth((s) => s.refreshSession);
  const { locale } = useLocale();

  if (status !== 'unavailable') return null;

  const ar = locale === 'ar';

  return (
    <div
      role="status"
      aria-live="polite"
      className="fixed inset-x-0 top-0 z-[70] flex items-center justify-center gap-2 bg-amber-500 px-3 py-2 text-center text-xs font-medium text-white shadow-md sm:text-sm"
    >
      <WifiOff className="h-4 w-4 shrink-0" />
      <span>
        {user
          ? (ar ? 'تعذر الاتصال بالخادم — جلستك محفوظة ولن يتم تسجيل خروجك.' : 'Server unreachable — your session is saved and you stay signed in.')
          : (ar ? 'تعذر الاتصال بالخادم. تحقق من الشبكة وأعد المحاولة.' : 'Cannot reach the server. Check your connection and retry.')}
      </span>
      <button
        type="button"
        onClick={() => void refreshSession()}
        disabled={loading}
        className="inline-flex items-center gap-1 rounded-full bg-white/20 px-2.5 py-1 font-semibold transition hover:bg-white/30 disabled:opacity-60"
      >
        {loading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}
        {ar ? 'إعادة المحاولة' : 'Retry'}
      </button>
    </div>
  );
}
