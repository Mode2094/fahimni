'use client';

import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { safeInternalPath } from '@/lib/auth-routes';
import { isPlatformBrowserHost } from '@/lib/request-origin';

/** زر تسجيل الدخول عبر Google — يبدأ OAuth بالدور المطلوب. */
export default function GoogleLoginButton({
  role,
  label,
  formData,
  onClick,
  variant = 'outline',
  tenantSlug,
  redirectTo,
}: {
  role: 'teacher' | 'student' | 'admin';
  label?: string;
  formData?: { name?: string; phone?: string };
  onClick?: () => boolean | void | Promise<boolean | void>;
  variant?: 'outline' | 'primary';
  tenantSlug?: string | null;
  redirectTo?: string | null;
}) {
  const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

  // Google OAuth runs a single central callback on the platform domain. On a
  // teacher's own domain / subdomain the resulting session would land on the
  // platform host with no cookie here, so the flow is kept clearly unavailable
  // there — email/password stays the supported path. Default to available so
  // SSR/hydration match; the real host is known only in the browser.
  const [onPlatformHost, setOnPlatformHost] = useState(true);

  useEffect(() => {
    setOnPlatformHost(isPlatformBrowserHost());
  }, []);

  const start = async () => {
    if (!isPlatformBrowserHost()) {
      toast.error('تسجيل الدخول عبر Google غير متاح على الدومين المخصص — استخدم البريد وكلمة المرور.');
      return;
    }

    if (onClick) {
      const ok = await onClick();
      if (ok === false) return;
    }

    try {
      const params = new URLSearchParams({ role });
      if (formData?.name) params.set('name', formData.name);
      if (formData?.phone) params.set('phone', formData.phone);
      if (role === 'student') {
        if (tenantSlug) params.set('tenant_slug', tenantSlug);
        const safeRedirect = safeInternalPath(redirectTo);
        if (safeRedirect) params.set('redirect_to', safeRedirect);
      }

      const res = await fetch(`${API}/auth/google/redirect?${params.toString()}`);
      const d = await res.json();
      if (d.url) {
        window.location.href = d.url;
      } else {
        toast.error(d.message || 'Google OAuth غير مضبوط — أضف GOOGLE_CLIENT_ID في الإعدادات');
      }
    } catch {
      toast.error('تعذر بدء تسجيل الدخول عبر Google');
    }
  };

  const classes =
    variant === 'primary'
      ? 'flex w-full items-center justify-center gap-2.5 rounded-xl border-2 border-teal-500/70 bg-white px-4 py-3 text-base font-bold text-slate-800 shadow-md shadow-teal-500/10 ring-2 ring-teal-500/10 transition hover:bg-teal-50/40 hover:shadow-lg'
      : 'flex w-full items-center justify-center gap-2.5 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50';

  return (
    <button
      type="button"
      onClick={start}
      disabled={!onPlatformHost}
      aria-disabled={!onPlatformHost}
      title={!onPlatformHost ? 'تسجيل Google غير متاح على الدومين المخصص — استخدم البريد وكلمة المرور.' : undefined}
      className={`${classes}${!onPlatformHost ? ' cursor-not-allowed opacity-50' : ''}`}
    >
      <svg className="h-5 w-5" viewBox="0 0 48 48" aria-hidden="true">
        <path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3l5.7-5.7C34.3 6.5 29.4 4 24 4 13 4 4 13 4 24s9 20 20 20 20-9 20-20c0-1.3-.1-2.6-.4-3.9z" />
        <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.9 1.2 8 3l5.7-5.7C34.3 6.5 29.4 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" />
        <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z" />
        <path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C36.9 39.2 44 34 44 24c0-1.3-.1-2.6-.4-3.9z" />
      </svg>
      {label || 'المتابعة عبر Google'}
    </button>
  );
}
