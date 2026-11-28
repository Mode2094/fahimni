'use client';

import { Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { AlertCircle, Loader2 } from 'lucide-react';
import { useAuth } from '@/store';
import { setSessionHint, setStoredTenantId } from '@/lib/auth-client';
import { postLoginPath } from '@/lib/auth-routes';
import { track } from '@/lib/analytics';

type Stage = 'working' | 'error';

/** Login page per role — the «العودة لتسجيل الدخول» target. */
const LOGIN_PATH: Record<string, string> = {
  admin: '/admin/login',
  student: '/student/login',
  teacher: '/teacher/login',
};

/** Non-sensitive `?error=` markers from the central callback (never a token). */
const ERROR_MESSAGES: Record<string, string> = {
  two_factor_required:
    'حسابك محمي بالتحقق بخطوتين ولا يمكن إكماله عبر Google على هذا الدومين — استخدم البريد وكلمة المرور ثم أدخل رمز التحقق.',
  invalid_state: 'انتهت صلاحية طلب تسجيل الدخول — أعد المحاولة من جديد.',
  google_failed: 'تعذّر الاتصال بحساب Google — حاول مرّة أخرى.',
  inactive: 'هذا الحساب غير مُفعّل. تواصل مع الدعم.',
  not_authorized: 'هذا الحساب غير مصرّح له بالدخول.',
  no_email: 'حساب Google لا يحتوي على بريد إلكتروني.',
  teacher_registration_disabled: 'تسجيل المعلمين غير متاح حاليًا.',
  retry: 'حدث تعارض مؤقت أثناء إنشاء الحساب — أعد المحاولة.',
};

const SESSION_FAILED_MESSAGE =
  'تعذّر إنشاء الجلسة على هذا الدومين. أعد المحاولة أو سجّل الدخول بالبريد وكلمة المرور.';

/** Parse a `#key=value&…` fragment into a map (fragments never hit the server). */
function readFragment(): URLSearchParams {
  const raw = typeof window === 'undefined' ? '' : window.location.hash.replace(/^#/, '');

  return new URLSearchParams(raw);
}

function GoogleCompleteInner() {
  const router = useRouter();
  const [stage, setStage] = useState<Stage>('working');
  const [msg, setMsg] = useState('');
  const [role, setRole] = useState('student');
  const [canRetry, setCanRetry] = useState(false);
  const started = useRef(false);
  // Kept so «إعادة المحاولة» can re-send the SAME ticket after a transport
  // failure, even though the fragment is cleared from the address bar.
  const ticketRef = useRef('');

  const loginHref = LOGIN_PATH[role] || LOGIN_PATH.student;

  const routeByUser = useCallback(
    (user: any, redirectTo: string | null): boolean => {
      if (!user) return false;
      router.replace(postLoginPath(user, redirectTo));
      return true;
    },
    [router],
  );

  const fail = useCallback((message: string) => {
    setMsg(message);
    setStage('error');
  }, []);

  const redeem = useCallback(
    async (ticket: string) => {
      setStage('working');
      setMsg('');

      try {
        const res = await fetch('/api/session/google/redeem', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ ticket }),
          credentials: 'same-origin',
          cache: 'no-store',
        });

        const data = await res.json().catch(() => ({}));

        if (!res.ok || !data?.user) {
          setCanRetry(false);
          fail(data?.message || 'تعذّر إكمال تسجيل الدخول عبر Google.');
          return;
        }

        // Mirror the password/register flow: a JS-readable hint + the user is
        // cached for the UI, but the HttpOnly session cookie is authoritative.
        try {
          localStorage.setItem('user', JSON.stringify(data.user));
        } catch {
          /* session cookie remains authoritative */
        }
        setSessionHint(true);
        setStoredTenantId(data.tenant_id ? String(data.tenant_id) : null);

        const user = {
          ...data.user,
          requires_profile_completion: Boolean(data.needs_profile_completion),
        };
        useAuth.setState({ user, loading: false, initialized: true });

        if (data.needs_profile_completion && (data.user.roles || []).includes('Teacher')) {
          track('trial_start', { content_name: 'teacher_trial' });
        }

        if (!routeByUser(user, data.redirect_to ?? null)) {
          setCanRetry(true);
          fail(SESSION_FAILED_MESSAGE);
        }
      } catch {
        setCanRetry(true);
        fail(SESSION_FAILED_MESSAGE);
      }
    },
    [fail, routeByUser],
  );

  useEffect(() => {
    if (started.current) return;
    started.current = true;

    const params = new URLSearchParams(window.location.search);
    const error = params.get('error');
    const roleHint = params.get('role');
    if (roleHint && LOGIN_PATH[roleHint]) setRole(roleHint);

    if (error) {
      setCanRetry(false);
      fail(ERROR_MESSAGES[error] || 'تعذّر تسجيل الدخول عبر Google.');
      return;
    }

    const ticket = readFragment().get('ticket') || '';
    ticketRef.current = ticket;

    // The ticket is single-use and already read: drop it from the address bar
    // right away so it cannot be copied out of the URL or kept in history.
    if (ticket && typeof window !== 'undefined') {
      window.history.replaceState(null, '', window.location.pathname + window.location.search);
    }

    if (!ticket) {
      setCanRetry(false);
      fail('رابط تسجيل الدخول غير مكتمل — أعد المحاولة من جديد.');
      return;
    }

    void redeem(ticket);
  }, [fail, redeem]);

  if (stage === 'error') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50 px-4">
        <div className="w-full max-w-sm rounded-2xl border border-gray-100 bg-white p-6 text-center shadow-sm">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-red-50">
            <AlertCircle className="h-6 w-6 text-red-500" aria-hidden="true" />
          </div>
          <p className="text-sm font-semibold text-gray-800">تعذّر إكمال تسجيل الدخول</p>
          <p className="mt-2 text-sm leading-6 text-gray-500">{msg}</p>
          <div className="mt-5 flex flex-col gap-2">
            {canRetry ? (
              <button
                onClick={() => void redeem(ticketRef.current)}
                className="w-full rounded-xl bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700"
              >
                إعادة المحاولة
              </button>
            ) : null}
            <Link
              href={loginHref}
              className="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
            >
              العودة لتسجيل الدخول
            </Link>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex flex-col items-center justify-center gap-4 bg-slate-50">
      <Loader2 className="h-10 w-10 animate-spin text-teal-600" />
      <p className="text-sm text-gray-500">{msg || 'جارٍ تسجيل الدخول عبر Google…'}</p>
    </div>
  );
}

export default function GoogleCompletePage() {
  return (
    <Suspense fallback={<div className="min-h-screen flex items-center justify-center"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>}>
      <GoogleCompleteInner />
    </Suspense>
  );
}
