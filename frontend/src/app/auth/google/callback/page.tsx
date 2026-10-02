'use client';

import { Suspense, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Loader2 } from 'lucide-react';
import { useAuth } from '@/store';

function GoogleCallbackInner() {
  const router = useRouter();
  const [msg, setMsg] = useState('');
  const [need2fa, setNeed2fa] = useState(false);
  const [code, setCode] = useState('');
  const [loading, setLoading] = useState(false);

  const routeByUser = () => {
    const user = useAuth.getState().user;
    if (user?.is_super_admin) router.push('/admin/dashboard');
    else if (user?.roles?.includes('Teacher')) router.push('/teacher/dashboard');
    else router.push('/my-learning');
  };

  useEffect(() => {
    // Non-sensitive step markers only — no tokens are ever placed in the URL.
    // The OAuth callback already set the HttpOnly session cookie.
    const params = new URLSearchParams(window.location.search);
    const step = params.get('step');
    const error = params.get('error');

    if (step === '2fa') {
      setNeed2fa(true);
      return;
    }

    if (error || step !== 'done') {
      const messages: Record<string, string> = {
        invalid_state: 'انتهت صلاحية طلب تسجيل الدخول — أعد المحاولة من البداية',
        google_failed: 'فشل الاتصال بحساب Google — حاول مجدداً',
        inactive: 'هذا الحساب غير مفعّل',
        not_authorized: 'هذا الحساب غير مصرح له بالدخول كمسؤول',
        no_email: 'حساب Google بلا بريد إلكتروني',
      };
      setMsg(messages[error || ''] || 'تعذر تسجيل الدخول عبر Google');
      setTimeout(() => router.push('/'), 2400);
      return;
    }

    // Hydrate the user from the HttpOnly session and route by role.
    useAuth.getState().load().then(() => {
      if (!useAuth.getState().user) {
        setMsg('تعذر إنشاء الجلسة — حاول مجدداً');
        setTimeout(() => router.push('/'), 2400);
        return;
      }
      routeByUser();
    });
  }, [router]);

  const confirm2fa = async () => {
    setLoading(true);
    setMsg('');
    try {
      await useAuth.getState().login2fa(code);
      routeByUser();
    } catch (error: any) {
      setMsg(error?.response?.data?.message || error?.message || 'رمز التحقق غير صحيح');
    }
    setLoading(false);
  };

  if (need2fa) {
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
