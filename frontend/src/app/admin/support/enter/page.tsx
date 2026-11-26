'use client';

import { useEffect, useState } from 'react';
import { Loader2, ShieldCheck, ShieldAlert } from 'lucide-react';
import { authFetch } from '@/lib/auth-client';
import { useLocale } from '@/store/locale';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

/**
 * One-time entry point for a Super-Admin SUPPORT SESSION, opened in a new tab
 * by the admin panel with a single-use token.
 *
 * It redeems the token server-side (which swaps THIS browser session to the
 * chosen teacher while preserving the admin identity for a guaranteed return),
 * then hard-navigates to the teacher dashboard so every panel re-resolves the
 * session. A leaked/replayed URL can never establish a session — the token is
 * consumed on first use and expires in two minutes.
 */
export default function AdminSupportEnterPage() {
  const { locale } = useLocale();
  const ar = locale === 'ar';

  const [error, setError] = useState('');

  useEffect(() => {
    const token = new URLSearchParams(window.location.search).get('token') || '';

    if (!token) {
      setError(ar ? 'رابط الدخول غير مكتمل (لا يوجد رمز).' : 'The entry link is incomplete (no token).');
      return;
    }

    let cancelled = false;

    (async () => {
      try {
        const res = await authFetch(`${API}/admin/support/activate`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token }),
        });
        const d = await res.json().catch(() => ({}));

        if (!res.ok) {
          if (!cancelled) setError(d.message || (ar ? 'تعذّر بدء جلسة الدعم.' : 'Could not start the support session.'));
          return;
        }

        // Full navigation: the session is now the teacher's; the layout and
        // every cached profile must be re-resolved from the server.
        window.location.replace(d.redirect || '/teacher/dashboard');
      } catch {
        if (!cancelled) setError(ar ? 'تعذّر الاتصال بالخادم.' : 'Could not reach the server.');
      }
    })();

    return () => { cancelled = true; };
  }, [ar]);

  return (
    <div className="flex min-h-[60vh] items-center justify-center p-6" data-testid="admin-support-enter">
      <div className="w-full max-w-md rounded-2xl border bg-white p-6 text-center space-y-3">
        {error ? (
          <>
            <ShieldAlert className="mx-auto h-10 w-10 text-rose-500" />
            <div className="font-semibold">{ar ? 'تعذّر الدخول إلى لوحة المعلم' : 'Could not enter the teacher panel'}</div>
            <p className="text-sm text-gray-500">{error}</p>
            <a href="/admin/dashboard" className="inline-block rounded-full bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">
              {ar ? 'العودة إلى الإدارة' : 'Back to admin'}
            </a>
          </>
        ) : (
          <>
            <ShieldCheck className="mx-auto h-10 w-10 text-sky-600" />
            <div className="font-semibold">{ar ? 'جارٍ فتح لوحة المعلم…' : 'Opening the teacher panel…'}</div>
            <p className="text-sm text-gray-500 flex items-center justify-center gap-2">
              <Loader2 className="h-4 w-4 animate-spin" />
              {ar ? 'يتم تفعيل جلسة الدعم الإداري.' : 'Activating the admin support session.'}
            </p>
          </>
        )}
      </div>
    </div>
  );
}
