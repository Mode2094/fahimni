'use client';

import { Eye, LogOut } from 'lucide-react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';

/**
 * Persistent banner shown while a platform admin is previewing another account.
 *
 * That mode is strictly read-only (enforced on the server), so this banner
 * makes the restriction obvious and gives a one-click, always-available way to
 * return to the admin account.
 */
export default function ViewAsBanner() {
  const { viewAs, stopViewAs } = useAuth();
  const { locale } = useLocale();

  if (!viewAs?.active) return null;

  const ar = locale === 'ar';

  return (
    <div
      role="status"
      data-testid="view-as-banner"
      className="flex flex-wrap items-center justify-center gap-2 border-b border-amber-300 bg-amber-100 px-3 py-2 text-center text-xs font-medium text-amber-900 sm:text-sm"
    >
      <Eye className="h-4 w-4 shrink-0" />
      <span>
        {ar
          ? `أنت تعاين حساب «${viewAs.user_name || '—'}» بوضع القراءة فقط — لا يمكن إجراء أي تعديل.`
          : `You are previewing “${viewAs.user_name || '—'}” in read-only mode — no changes can be made.`}
      </span>
      <button
        type="button"
        onClick={stopViewAs}
        className="ms-1 inline-flex items-center gap-1 rounded-full bg-amber-900 px-3 py-1 text-xs font-bold text-white hover:bg-amber-800"
      >
        <LogOut className="h-3.5 w-3.5" />
        {ar ? 'الخروج من المعاينة' : 'Exit preview'}
      </button>
    </div>
  );
}
