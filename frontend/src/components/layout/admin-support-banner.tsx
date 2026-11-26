'use client';

import { ShieldCheck, LogOut } from 'lucide-react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';

/**
 * Persistent banner shown while a platform admin is working inside a teacher's
 * workspace in a writable "support session" (Admin Impersonation).
 *
 * It makes three things unmistakable: that the admin — not the teacher — is
 * acting, that sensitive (money/security) actions must be done from the admin
 * panel, and that there is always a one-click way back to the admin account.
 */
export default function AdminSupportBanner() {
  const { adminSupport, stopAdminSupport } = useAuth();
  const { locale } = useLocale();

  if (!adminSupport?.active) return null;

  const ar = locale === 'ar';
  const teacher = adminSupport.user_name || '—';

  return (
    <div
      role="status"
      data-testid="admin-support-banner"
      className="flex flex-wrap items-center justify-center gap-2 border-b border-sky-300 bg-sky-50 px-3 py-2 text-center text-xs font-medium text-sky-900 sm:text-sm"
    >
      <ShieldCheck className="h-4 w-4 shrink-0" />
      <span>
        {ar
          ? `أنت الآن تدير منصة «${teacher}» بصلاحية السوبر أدمن — كل تعديل يُسجَّل باسمك.`
          : `You are now managing “${teacher}”’s platform as a Super Admin — every change is logged under your name.`}
      </span>
      <button
        type="button"
        onClick={stopAdminSupport}
        className="ms-1 inline-flex items-center gap-1 rounded-full bg-sky-900 px-3 py-1 text-xs font-bold text-white hover:bg-sky-800"
        data-testid="admin-support-exit"
      >
        <LogOut className="h-3.5 w-3.5" />
        {ar ? 'إنهاء الجلسة والعودة للإدارة' : 'End session & return to admin'}
      </button>
    </div>
  );
}
