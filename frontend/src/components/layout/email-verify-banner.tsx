'use client';

import { useEffect, useState } from 'react';
import { Mail, X } from 'lucide-react';
import { useLocale } from '@/store/locale';

/**
 * Optional email-verification reminder.
 *
 * Verification is OPTIONAL on Fahimni: an unverified account can sign in, open
 * its courses, activate cards and watch lessons normally, and password recovery
 * works through the email on file. So this is only a quiet, dismissible nudge —
 * never a gate. Dismissing it is remembered per account, and the reminder stays
 * reachable any time from /verify-email.
 */
export function EmailVerifyBanner({ userId }: { userId?: string | null }) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const key = `email_verify_dismissed:${userId || 'anon'}`;
  // Start hidden so the server-rendered markup matches the first client paint.
  const [dismissed, setDismissed] = useState(true);

  useEffect(() => {
    if (!userId) return;
    try {
      setDismissed(localStorage.getItem(key) === '1');
    } catch {
      setDismissed(false);
    }
  }, [key, userId]);

  if (!userId || dismissed) return null;

  const dismiss = () => {
    try {
      localStorage.setItem(key, '1');
    } catch {
      // Storage disabled: the reminder simply reappears on the next visit.
    }
    setDismissed(true);
  };

  return (
    <div role="status" className="flex items-center gap-2 border-b border-slate-200/70 bg-slate-50 px-3 py-1.5 text-[11px] text-slate-500">
      <Mail className="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden="true" />
      <span className="flex-1 min-w-0">
        {t('تأكيد البريد الإلكتروني اختياري — حسابك يعمل بالكامل بدونه.', 'Email verification is optional — your account works fully without it.')}{' '}
        <a href="/verify-email" className="font-medium text-teal-700 underline underline-offset-2">
          {t('تأكيد البريد', 'Verify email')}
        </a>
      </span>
      <button
        type="button"
        onClick={dismiss}
        aria-label={t('إخفاء التذكير', 'Dismiss reminder')}
        className="shrink-0 rounded-full p-1 text-slate-400 transition-colors hover:bg-slate-200 hover:text-slate-600"
      >
        <X className="h-3.5 w-3.5" aria-hidden="true" />
      </button>
    </div>
  );
}
