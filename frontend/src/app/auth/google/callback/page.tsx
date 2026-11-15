'use client';

import { Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { AlertCircle, Loader2 } from 'lucide-react';
import { useAuth } from '@/store';

type Stage = 'working' | '2fa' | 'error';

/** Login page per role — the «العودة لتسجيل الدخول» target. */
const LOGIN_PATH: Record<string, string> = {
  admin: '/admin/login',
  student: '/student/login',
  teacher: '/teacher/login',
};

/** OAuth errors arrive as a non-sensitive `?error=` marker (never a token). */
const ERROR_MESSAGES: Record<string, string> = {
  invalid_state: 'انتهت صلاحية طلب تسجيل الدخول — أعد المحاولة من جديد.',
  google_failed: 'تعذّر الاتصال بحساب Google — حاول مرّة أخرى.',
  inactive: 'هذا الحساب غير مُفعّل. تواصل مع الدعم.',
  not_authorized: 'هذا الحساب غير مصرّح له بالدخول كمسؤول.',
  no_email: 'حساب Google لا يحتوي على بريد إلكتروني.',
  teacher_registration_disabled: 'تسجيل المعلمين غير متاح حاليًا. استخدم دعوة تسجيل أو تواصل مع الإدارة.',
  retry: 'حدث تعارض مؤقت أثناء إنشاء الحساب — أعد المحاولة.',
};

const SESSION_FAILED_MESSAGE =
  'تعذّر إنشاء الجلسة. قد تكون منتهية أو حُجبت كوكيز الموقع — حاول مجددًا أو سجّل الدخول من جديد.';

function GoogleCallbackInner() {
  const router = useRouter();
  const [stage, setStage] = useState<Stage>('working');
  const [msg, setMsg] = useState('');
  const [role, setRole] = useState('teacher');
  const [code, setCode] = useState('');
  const [loading, setLoading] = useState(false);
  // For a `step=done` failure the user can retry the session lookup in place;
  // for an OAuth error retrying means starting the flow again.
  const [canRetrySession, setCanRetrySession] = useState(false);
  const started = useRef(false);

  const loginHref = LOGIN_PATH[role] || LOGIN_PATH.teacher;

  /** Send an authenticated user to the dashboard their real role allows. */
  const routeByUser = useCallback((): boolean => {
    const user = useAuth.getState().user;

    if (!user) return false;

    if (user.is_super_admin) router.replace('/admin/dashboard');
    else if (user.roles?.includes('Teacher')) router.replace('/teacher/dashboard');
    else router.replace('/my-learning');

    return true;
  }, [router]);

  const fail = useCallback((message: string) => {
    setMsg(message);
    setStage('error');
  }, []);

  /** Hydrate the user from the HttpOnly session and route by real role. */
  const hydrateSession = useCallback(async () => {
    setStage('working');
    setMsg('');

    try {
      await useAuth.getState().load();
    } catch {
      /* load() already swallows errors; treat a throw as "no session". */
    }

    if (routeByUser()) return;

    // Do NOT bounce to the landing page: show a clear, actionable error.
    setCanRetrySession(true);
    fail(SESSION_FAILED_MESSAGE);
  }, [fail, routeByUser]);

  useEffect(() => {
    if (started.current) return;
    started.current = true;

    const params = new URLSearchParams(window.location.search);
    const step = params.get('step');
    const error = params.get('error');
    const roleHint = params.get('role');

    if (roleHint && LOGIN_PATH[roleHint]) setRole(roleHint);

    if (step === '2fa') {
      setStage('2fa');
      return;
    }

    if (error || step !== 'done') {
      const messages: Record<string, string> = ERROR_MESSAGES;
      setCanRetrySession(false);
      fail(messages[error || ''] || 'تعذّر تسجيل الدخول عبر Google.');
      return;
    }

    // Success marker: the OAuth callback already set the HttpOnly session
    // cookie — no token ever travels in the URL.
    void hydrateSession();
  }, [fail, hydrateSession]);

  const confirm2fa = async () => {
    setLoading(true);
    setMsg('');
    try {
      await useAuth.getState().login2fa(code);
      if (!routeByUser()) {
        setLoading(false);
        setCanRetrySession(true);
        fail(SESSION_FAILED_MESSAGE);
        return;
      }
    } catch (error: any) {
      setMsg(error?.response?.data?.message || error?.message || 'رمز التحقق غير صحيح');
    }
    setLoading(false);
  };

  if (stage === '2fa') {
    return (
      <div className="min-h-screen flex flex-col items-center justify-center gap-4 bg-slate-50 px-4">
        <p className="text-sm font-semibold text-gray-700">أدخل رمز التحقق بخطوتين (2FA)</p>
        <input
          value={code}
          onChange={(e) => setCode(e.target.value)}
          inputMode="numeric"
          autoComplete="one-time-code"
          maxLength={6}
          className="w-48 rounded-xl border border-gray-200 px-4 py-2 text-center text-lg tracking-widest"
          placeholder="000000"
        />
        <button
          onClick={confirm2fa}
          disabled={loading || code.length < 6}
          className="rounded-xl bg-teal-600 px-6 py-2 text-sm font-semibold text-white disabled:opacity-50"
        >
          {loading ? 'جارٍ التحقق…' : 'تأكيد'}
        </button>
        {msg && <p className="text-sm text-red-500">{msg}</p>}
        <Link href={loginHref} className="text-xs text-gray-500 underline">
          العودة لتسجيل الدخول
        </Link>
      </div>
    );
  }

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
            {canRetrySession ? (
              <button
                onClick={() => void hydrateSession()}
                className="w-full rounded-xl bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700"
              >
                إعادة المحاولة
              </button>
            ) : (
              <Link
                href={loginHref}
                className="w-full rounded-xl bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700"
              >
                إعادة المحاولة
              </Link>
            )}
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

export default function GoogleCallbackPage() {
  return (
    <Suspense fallback={<div className="min-h-screen flex items-center justify-center"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>}>
      <GoogleCallbackInner />
    </Suspense>
  );
}
