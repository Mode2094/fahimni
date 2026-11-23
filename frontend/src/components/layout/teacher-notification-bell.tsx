'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Bell, CheckCheck, Loader2 } from 'lucide-react';
import { useLocale } from '@/store/locale';
import { teacherNotificationsApi } from '@/services/endpoints';
import { CATEGORY_META, categoryLabel, formatDateTime } from '@/lib/platform-notifications';
import { cn } from '@/lib/utils';

/**
 * Header bell for the teacher panel: unread platform notifications from the
 * Super Admin. Shows a tidy, mobile-friendly dropdown with a link to the full
 * page. Never a floating element; sits in the panel header next to the
 * language toggle.
 */
export function TeacherNotificationBell() {
  const { locale } = useLocale();
  const router = useRouter();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [open, setOpen] = useState(false);
  const [unread, setUnread] = useState(0);
  const [items, setItems] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const boxRef = useRef<HTMLDivElement | null>(null);

  const loadCount = useCallback(() => {
    teacherNotificationsApi.unreadCount().then(setUnread).catch(() => {});
  }, []);

  useEffect(() => {
    loadCount();
    const i = setInterval(loadCount, 30000);
    return () => clearInterval(i);
  }, [loadCount]);

  useEffect(() => {
    if (!open) return;
    setLoading(true);
    teacherNotificationsApi.list(8)
      .then((d) => {
        setItems(d.notifications || []);
        setUnread(d.meta?.unread_count ?? 0);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const onClick = (e: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, [open]);

  const openItem = async (id: string) => {
    try { await teacherNotificationsApi.markRead(id); } catch { /* */ }
    setOpen(false);
    loadCount();
    router.push(`/teacher/notifications?open=${id}`);
  };

  const markAll = async () => {
    try { await teacherNotificationsApi.markAllRead(); } catch { /* */ }
    setItems((prev) => prev.map((n) => ({ ...n, read_at: n.read_at || new Date().toISOString() })));
    setUnread(0);
  };

  return (
    <div className="relative" ref={boxRef}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="relative flex h-9 w-9 items-center justify-center rounded-xl text-muted-foreground hover:bg-muted"
        aria-label={t('إشعارات الإدارة', 'Platform notifications')}
        aria-haspopup="menu"
        aria-expanded={open}
        data-testid="teacher-notif-bell"
      >
        <Bell className="h-4 w-4" />
        {unread > 0 && (
          <span className="absolute -top-0.5 -end-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-bold text-white">
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div
          role="menu"
          className="absolute end-0 z-50 mt-2 w-[min(92vw,22rem)] overflow-hidden rounded-2xl border bg-card shadow-2xl"
        >
          <div className="flex items-center justify-between border-b px-4 py-3">
            <span className="text-sm font-bold">{t('إشعارات الإدارة', 'Platform notifications')}</span>
            {unread > 0 && (
              <button
                type="button"
                onClick={markAll}
                className="flex items-center gap-1 text-xs font-semibold text-teal-700 hover:underline"
              >
                <CheckCheck className="h-3.5 w-3.5" /> {t('تحديد الكل كمقروء', 'Mark all read')}
              </button>
            )}
          </div>

          <div className="max-h-[60vh] overflow-y-auto">
            {loading ? (
              <div className="flex items-center justify-center py-8 text-muted-foreground"><Loader2 className="h-5 w-5 animate-spin" /></div>
            ) : items.length === 0 ? (
              <p className="px-4 py-8 text-center text-sm text-muted-foreground">{t('لا توجد إشعارات', 'No notifications')}</p>
            ) : (
              items.map((n) => (
                <button
                  key={n.id}
                  type="button"
                  onClick={() => openItem(n.id)}
                  data-testid="teacher-notif-item"
                  data-read={n.read_at ? 'true' : 'false'}
                  className={cn(
                    'flex w-full flex-col gap-1 border-b px-4 py-3 text-start transition-colors last:border-b-0 hover:bg-muted/60',
                    !n.read_at && 'bg-teal-500/[0.04]',
                  )}
                >
                  <span className="flex items-center gap-2">
                    {!n.read_at && <span className="h-2 w-2 shrink-0 rounded-full bg-teal-600" />}
                    <span className="truncate text-sm font-semibold">{n.title}</span>
                    <span className={cn('ms-auto shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold', CATEGORY_META[n.category]?.className || 'bg-gray-100 text-gray-500')}>
                      {categoryLabel(n.category, locale)}
                    </span>
                  </span>
                  <span className="line-clamp-2 text-xs text-muted-foreground">{n.body}</span>
                  <span className="flex items-center gap-2 text-[10px] text-muted-foreground">
                    <span>{formatDateTime(n.published_at || n.created_at)}</span>
                  </span>
                </button>
              ))
            )}
          </div>

          <button
            type="button"
            onClick={() => { setOpen(false); router.push('/teacher/notifications'); }}
            className="block w-full border-t px-4 py-3 text-center text-sm font-semibold text-teal-700 hover:bg-muted/60"
          >
            {t('جميع الإشعارات', 'View all notifications')}
          </button>
        </div>
      )}
    </div>
  );
}
