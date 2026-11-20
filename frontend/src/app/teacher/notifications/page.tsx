'use client';

import { useCallback, useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { TableSkeleton } from '@/components/ui/skeleton';
import { Alert } from '@/components/ui/alert';
import { Bell, CheckCheck, Loader2, LifeBuoy, RefreshCcw, Send } from 'lucide-react';
import { toast } from 'sonner';
import { teacherNotificationsApi } from '@/services/endpoints';
import { CATEGORY_META, PLATFORM_SENDER, categoryLabel, formatDateTime } from '@/lib/platform-notifications';
import { cn } from '@/lib/utils';

/**
 * Teacher's archive of platform notifications from the Super Admin.
 *
 * Read-only by design: a teacher can open, read, mark-as-read and reply to the
 * platform team (via the support system). There is no delete or edit control —
 * only the admin can change or pull a notification.
 */
export default function TeacherNotificationsPage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [items, setItems] = useState<any[]>([]);
  const [unread, setUnread] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [detail, setDetail] = useState<any | null>(null);

  const [contactOpen, setContactOpen] = useState(false);
  const [contactBody, setContactBody] = useState('');
  const [sendingContact, setSendingContact] = useState(false);

  const load = useCallback(async () => {
    setError('');
    try {
      const d = await teacherNotificationsApi.list(50);
      setItems(d.notifications || []);
      setUnread(d.meta?.unread_count ?? 0);
    } catch {
      setError(t('تعذر تحميل الإشعارات', 'Failed to load notifications'));
    } finally {
      setLoading(false);
    }
  }, [locale]); // eslint-disable-line react-hooks/exhaustive-deps

  const openDetail = useCallback(async (n: any) => {
    setDetail(n);
    if (!n.read_at) {
      try {
        const r = await teacherNotificationsApi.markRead(n.id);
        setUnread(r.unread_count ?? Math.max(0, unread - 1));
        setItems((prev) => prev.map((x) => (x.id === n.id ? { ...x, read_at: new Date().toISOString() } : x)));
      } catch { /* */ }
    }
  }, [unread]);

  useEffect(() => {
    load().then(() => {
      if (typeof window !== 'undefined') {
        const openId = new URLSearchParams(window.location.search).get('open');
        if (openId) teacherNotificationsApi.get(openId).then(openDetail).catch(() => {});
      }
    });
  }, [load]); // eslint-disable-line react-hooks/exhaustive-deps

  const markAll = async () => {
    try {
      await teacherNotificationsApi.markAllRead();
      setUnread(0);
      setItems((prev) => prev.map((x) => ({ ...x, read_at: x.read_at || new Date().toISOString() })));
      toast.success(t('تم تحديد الكل كمقروء', 'All marked as read'));
    } catch {
      toast.error(t('تعذر التنفيذ', 'Action failed'));
    }
  };

  const sendContact = async () => {
    if (!detail || !contactBody.trim()) return;
    setSendingContact(true);
    try {
      await teacherNotificationsApi.contact(detail.id, contactBody.trim());
      toast.success(t('تم إرسال استفسارك إلى إدارة المنصة', 'Your message was sent to the platform team'));
      setContactOpen(false);
      setContactBody('');
    } catch {
      toast.error(t('تعذر إرسال الرسالة', 'Could not send the message'));
    } finally {
      setSendingContact(false);
    }
  };

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <Bell className="h-6 w-6 text-teal-700" />
          </span>
          <div>
            <h1 className="text-2xl font-bold sm:text-3xl">{t('إشعارات الإدارة', 'Platform Notifications')}</h1>
            <p className="mt-1 text-sm text-muted-foreground">
              {t('أخبار وتحديثات وتنبيهات من إدارة منصة فهمني', 'News, updates and alerts from the Fahimni platform team')}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={load} aria-label={t('تحديث', 'Refresh')}>
            <RefreshCcw className="h-4 w-4" />
          </Button>
          {unread > 0 && (
            <Button size="sm" onClick={markAll} data-testid="mark-all-read">
              <CheckCheck className="h-4 w-4" /> {t('تحديد الكل كمقروء', 'Mark all read')}
            </Button>
          )}
        </div>
      </div>

      {error && <Alert className="border-rose-200 bg-rose-50 text-rose-700">{error}</Alert>}

      {loading ? (
        <TableSkeleton rows={4} cols={2} />
      ) : items.length === 0 ? (
        <EmptyState
          icon={Bell}
          title={t('لا توجد إشعارات بعد', 'No notifications yet')}
          description={t('ستظهر هنا إشعارات إدارة المنصة عند وصولها.', 'Platform announcements will appear here once sent.')}
        />
      ) : (
        <div className="space-y-3">
          {items.map((n) => (
            <Card key={n.id} className={cn('transition-colors', !n.read_at && 'border-teal-500/30 bg-teal-500/[0.03]')}>
              <CardContent className="p-4">
                <button
                  type="button"
                  onClick={() => openDetail(n)}
                  className="flex w-full flex-col gap-2 text-start"
                  data-testid="teacher-notification-row"
                  data-read={n.read_at ? 'true' : 'false'}
                >
                  <span className="flex flex-wrap items-center gap-2">
                    {!n.read_at && <span className="h-2 w-2 rounded-full bg-teal-600" />}
                    <span className="text-base font-bold">{n.title}</span>
                    <span className={cn('rounded-full px-2 py-0.5 text-[10px] font-bold', CATEGORY_META[n.category]?.className || 'bg-gray-100 text-gray-500')}>
                      {categoryLabel(n.category, locale)}
                    </span>
                    <span className="ms-auto text-xs text-muted-foreground">{formatDateTime(n.published_at || n.created_at)}</span>
                  </span>
                  <span className="line-clamp-2 text-sm text-muted-foreground">{n.body}</span>
                  <span className="text-xs font-semibold text-teal-700">{locale === 'ar' ? PLATFORM_SENDER.ar : PLATFORM_SENDER.en}</span>
                </button>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Detail */}
      <Dialog open={!!detail} onOpenChange={(o) => { if (!o) setDetail(null); }}>
        <DialogContent>
          {detail && (
            <>
              <DialogHeader>
                <div className="flex flex-wrap items-center gap-2">
                  <Badge className={CATEGORY_META[detail.category]?.className}>{categoryLabel(detail.category, locale)}</Badge>
                  <span className="text-xs text-muted-foreground">{formatDateTime(detail.published_at || detail.created_at)}</span>
                </div>
                <DialogTitle className="mt-2">{detail.title}</DialogTitle>
                <p className="text-xs font-semibold text-teal-700">{locale === 'ar' ? PLATFORM_SENDER.ar : PLATFORM_SENDER.en}</p>
              </DialogHeader>
              <p className="whitespace-pre-wrap text-sm leading-relaxed">{detail.body}</p>
              <DialogFooter className="mt-6 flex justify-between gap-2">
                <Button variant="outline" onClick={() => { setContactBody(''); setContactOpen(true); }} data-testid="contact-admin">
                  <LifeBuoy className="h-4 w-4" /> {t('تواصل مع الإدارة', 'Contact the platform team')}
                </Button>
                <Button onClick={() => setDetail(null)}>{t('إغلاق', 'Close')}</Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>

      {/* Contact the platform team (reuses the support-ticket system) */}
      <Dialog open={contactOpen} onOpenChange={setContactOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('تواصل مع الإدارة', 'Contact the platform team')}</DialogTitle>
          </DialogHeader>
          <p className="mb-3 text-sm text-muted-foreground">
            {t('سيصل استفسارك إلى فريق دعم منصة فهمني.', 'Your message will reach the Fahimni support team.')}
          </p>
          <Textarea
            rows={5}
            value={contactBody}
            onChange={(e) => setContactBody(e.target.value)}
            placeholder={t('اكتب استفسارك هنا…', 'Write your message here…')}
            data-testid="contact-body"
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setContactOpen(false)} disabled={sendingContact}>
              {t('إلغاء', 'Cancel')}
            </Button>
            <Button onClick={sendContact} disabled={sendingContact || !contactBody.trim()} data-testid="contact-send">
              {sendingContact ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
              {t('إرسال', 'Send')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
