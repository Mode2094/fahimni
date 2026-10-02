'use client';

/** زر تسجيل الدخول عبر Google — يبدأ OAuth بالدور المطلوب. */
export default function GoogleLoginButton({ role, label, formData, onClick }: { role: 'teacher' | 'student' | 'admin'; label?: string; formData?: { name?: string; phone?: string }; onClick?: () => boolean | void | Promise<boolean | void> }) {
  const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

  const start = async () => {
    if (onClick) {
      const ok = await onClick();
      if (ok === false) return;
    }

    try {
      const params = new URLSearchParams({ role });
      if (formData?.name) params.set('name', formData.name);
      if (formData?.phone) params.set('phone', formData.phone);

      const res = await fetch(`${API}/auth/google/redirect?${params.toString()}`);
      const d = await res.json();
      if (d.url) {
        window.location.href = d.url;
      } else {
        alert(d.message || 'Google OAuth غير مضبوط — أضف GOOGLE_CLIENT_ID في الإعدادات');
      }
    } catch {
      alert('تعذر بدء تسجيل الدخول عبر Google');
    }
  };

  return (
    <button
      type="button"
      onClick={start}
      className="flex w-full items-center justify-center gap-2.5 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
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
