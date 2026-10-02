'use client';

import { useEffect, useState, useCallback } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Bell, Mail, Smartphone, MessageSquare, Send, MessagesSquare, Hash, Megaphone, CheckCheck, Settings2, X } from 'lucide-react';
import { toast } from 'sonner';
import { notificationsApi, preferencesApi } from '@/services/endpoints';
import { Switch } from '@/components/ui/switch';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';

const CHANNEL_META: Record<string, { icon: any; ar: string; en: string }> = {
  database: { icon: Bell, ar: 'داخل المنصة', en: 'In-app' },
  mail: { icon: Mail, ar: 'بريد إلكتروني', en: 'Email' },
  push: { icon: Smartphone, ar: 'إشعارات Push', en: 'Push' },
  sms: { icon: MessageSquare, ar: 'رسائل SMS', en: 'SMS' },
  whatsapp: { icon: MessageSquare, ar: 'واتساب', en: 'WhatsApp' },
  telegram: { icon: Send, ar: 'تيليجرام', en: 'Telegram' },
  discord: { icon: MessagesSquare, ar: 'ديسكورد', en: 'Discord' },
  slack: { icon: Hash, ar: 'سلاك', en: 'Slack' },
};

const TYPE_LABELS: Record<string, { ar: string; en: string }> = {
  'enrollment.created': { ar: 'التسجيل في دورة', en: 'Course enrollment' },
  'lesson.completed': { ar: 'إكمال درس', en: 'Lesson completed' },
  'exam.graded': { ar: 'تصحيح امتحان', en: 'Exam graded' },
  'subscription.activated': { ar: 'تفعيل اشتراك', en: 'Subscription activated' },
  'payment.completed': { ar: 'اكتمال دفعة', en: 'Payment completed' },
  'announcement.published': { ar: 'إعلانات عامة', en: 'Announcements' },
  'chat.message': { ar: 'رسائل المحادثة', en: 'Chat messages' },
};

/* ترجمة إشعارات قديمة مخزنة بالإنجليزية حسب نوعها */
function translateLegacyNotification(n: any): any {
  const t = String(n.title || '');
  const b = String(n.body || '');
  if (n.type === 'enrollment.created' && t.startsWith('Enrolled in')) {
    const course = t.replace('Enrolled in ', '');
    return { ...n, title: `تم تسجيلك في: ${course}`, body: `تم تسجيلك في الكورس: ${course}` };
  }
  if (n.type === 'lesson.completed' && t.startsWith('Lesson completed')) {
    return { ...n, title: `أكملت الدرس: ${t.replace('Lesson completed: ', '')}`, body: `أنهيت الدرس: ${b.replace('You completed the lesson: ', '')}` };
  }
  if (n.type === 'exam.graded' && (t.startsWith('Exam passed') || t.startsWith('Exam failed'))) {
    const passed = t.startsWith('Exam passed');
    return { ...n, title: `${passed ? 'نجحت في الاختبار' : 'لم تنجح في الاختبار'}: ${t.replace(/^Exam (passed|failed): /, '')}` };
  }
  if (n.type === 'payment.completed' && t.startsWith('Payment received')) {
    return { ...n, title: `تم استلام دفعة: ${t.replace('Payment received: ', '')}` };
  }
  if (n.type === 'subscription.activated' && t.startsWith('Subscription activated')) {
    return { ...n, title: `تم تفعيل اشتراكك: ${t.replace('Subscription activated: ', '')}` };
  }
  return n;
}

export default function NotificationsPage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [tab, setTab] = useState<'notifications' | 'preferences'>('notifications');
  const [notifications, setNotifications] = useState<any[]>([]);
  const [unread, setUnread] = useState(0);
  const [loading, setLoading] = useState(true);
  const [preferences, setPreferences] = useState<any[]>([]);
  const [saving, setSaving] = useState<string | null>(null);

  const refreshUnread = useCallback(() => {
    notificationsApi.unreadCount().then(setUnread).catch(() => {});
  }, []);

  const load = useCallback(() => {
    notificationsApi.list(50).then((d) => {
      setNotifications(d.notifications || []);
      refreshUnread();
    }).catch(() => {}).finally(() => setLoading(false));
  }, [refreshUnread]);

  const loadPreferences = useCallback(() => {
    preferencesApi.list().then((d) => setPreferences(d || [])).catch(() => {});
  }, []);

  useEffect(() => { load(); loadPreferences(); }, [load, loadPreferences]);

  const markRead = async (id: string) => {
    await notificationsApi.markAsRead(id);
    setNotifications((prev) => prev.map((n) => (n.id === id ? { ...n, read_at: new Date().toISOString(), read_in_app: true } : n)));
    refreshUnread();
  };

  const markAll = async () => {
    await notificationsApi.markAllAsRead();
    setNotifications((prev) => prev.map((n) => ({ ...n, read_at: new Date().toISOString(), read_in_app: true })));
    setUnread(0);
    toast.success(t('تمت قراءة الكل', 'All marked as read'));
  };

  const toggleChannel = async (type: string, channel: string, checked: boolean) => {
    const pref = preferences.find((p) => p.notification_type === type);
    const channels = pref?.channels?.includes(channel)
      ? (pref.channels || []).filter((c: string) => c !== channel)
      : [...(pref?.channels?.length ? pref.channels : ['database']), channel];
    if (!channels.length) { toast.error(t('يلزم قناة واحدة على الأقل', 'At least one channel is required')); return; }
    setSaving(type);
    try {
      await preferencesApi.update({ notification_type: type, is_enabled: pref ? pref.is_enabled : true, channels });
      setPreferences((prev) => prev.map((p) => (p.notification_type === type ? { ...p, channels } : p)));
    } catch { toast.error(t('فشل الحفظ', 'Save failed')); }
    setSaving(null);
  };

  const toggleEnabled = async (type: string, checked: boolean) => {
    const pref = preferences.find((p) => p.notification_type === type);
    const channels = pref?.channels?.length ? pref.channels : ['database'];
    setSaving(type);
    try {
      await preferencesApi.update({ notification_type: type, is_enabled: checked, channels });
      setPreferences((prev) => prev.map((p) => (p.notification_type === type ? { ...p, is_enabled: checked } : p)));
    } catch { toast.error(t('فشل الحفظ', 'Save failed')); }
    setSaving(null);
  };

  return (
    <div className="space-y-6 max-w-4xl mx-auto">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10"><Bell className="h-6 w-6 text-teal-600" /></span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('الإشعارات', 'Notifications')}</h1>
            <p className="text-muted-foreground text-sm">{t('كل تنبيهاتك في مكان واحد', 'All your alerts in one place')}</p>
          </div>
        </div>
        <div className="flex gap-1.5 p-1 rounded-full bg-slate-100/80">
          <Button variant={tab === 'notifications' ? 'default' : 'ghost'} size="sm" className="rounded-full" style={tab === 'notifications' ? { background: '#0d9488' } : {}} onClick={() => setTab('notifications')}>
            <Bell className="h-4 w-4 ms-1" />{t('الإشعارات', 'Notifications')}
            {unread > 0 && <Badge className="ms-1 bg-white/20 rounded-full">{unread}</Badge>}
          </Button>
          <Button variant={tab === 'preferences' ? 'default' : 'ghost'} size="sm" className="rounded-full" style={tab === 'preferences' ? { background: '#0d9488' } : {}} onClick={() => setTab('preferences')}>
            <Settings2 className="h-4 w-4 ms-1" />{t('التفضيلات', 'Preferences')}
          </Button>
        </div>
      </div>

      {tab === 'notifications' && (
        <Card>
          <CardHeader className="flex-row items-center justify-between">
            <CardTitle className="text-base flex items-center gap-2"><Bell className="h-4 w-4" />{t('آخر التنبيهات', 'Latest alerts')}</CardTitle>
            {unread > 0 && (
              <Button variant="ghost" size="sm" className="rounded-xl text-teal-700" onClick={markAll}>
                <CheckCheck className="h-4 w-4 ms-1" />{t('قراءة الكل', 'Mark all read')}
              </Button>
            )}
          </CardHeader>
          <CardContent className="space-y-2 p-4">
            {loading ? (
              <TableSkeleton rows={6} cols={3} />
            ) : notifications.length === 0 ? (
              <EmptyState icon={Bell} title={t('لا توجد إشعارات بعد', 'No notifications yet')} description={t('ستظهر هنا تنبيهات دوراتك واختباراتك', 'Alerts about your courses and exams will show here')} />
            ) : (
              notifications.map((raw) => {
                const n = locale === 'ar' ? translateLegacyNotification(raw) : raw;
                return (
                <div key={n.id} onClick={() => !n.read_at && markRead(n.id)} className={`flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-colors ${n.read_at ? 'border-gray-100 bg-white' : 'border-teal-100 bg-teal-50/60'}`}>
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white shadow-sm">
                    {n.type === 'chat.message' ? <MessageSquare className="h-4 w-4 text-violet-500" />
                      : n.type === 'announcement.published' ? <Megaphone className="h-4 w-4 text-amber-500" />
                      : <Bell className="h-4 w-4 text-teal-600" />}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between gap-2">
                      <p className="font-semibold text-sm truncate">{n.title}</p>
                      {!n.read_at && <span className="h-2 w-2 shrink-0 rounded-full bg-teal-500" />}
                    </div>
                    {n.body && <p className="text-sm text-gray-500 mt-0.5 line-clamp-2">{n.body}</p>}
                    <p className="text-xs text-gray-400 mt-1" dir="ltr">{new Date(n.created_at).toLocaleString(locale === 'ar' ? 'ar' : 'en')}</p>
                  </div>
                  {n.read_at && <CheckCheck className="h-4 w-4 text-gray-300 mt-1 shrink-0" />}
                </div>
                );
              })
            )}
          </CardContent>
        </Card>
      )}

      {tab === 'preferences' && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base flex items-center gap-2"><Settings2 className="h-4 w-4" />{t('قنوات الاستلام', 'Delivery channels')}</CardTitle>
            <p className="text-sm text-gray-500">{t('اختر القنوات التي تريد استقبال كل نوع من التنبيهات عبرها', 'Choose which channels you want to receive each type of alert on')}</p>
          </CardHeader>
          <CardContent className="p-4 space-y-3">
            {preferences.map((pref) => {
              const labels = TYPE_LABELS[pref.notification_type] || { ar: pref.notification_type, en: pref.notification_type };
              return (
                <div key={pref.notification_type} className={`p-4 rounded-xl border transition-colors ${pref.is_enabled ? 'border-gray-200' : 'border-gray-100 bg-gray-50 opacity-60'}`}>
                    <div className="flex items-center justify-between gap-3 mb-3">
                      <div>
                        <p className="font-semibold text-sm">{t(labels.ar, labels.en)}</p>
                        <p className="text-xs text-gray-400 mt-0.5 font-mono" dir="ltr">{pref.notification_type}</p>
                      </div>
                      <Switch
                        checked={pref.is_enabled}
                        onCheckedChange={(v) => toggleEnabled(pref.notification_type, v)}
                        disabled={saving === pref.notification_type}
                        label={t('تفعيل', 'Enable')}
                      />
                    </div>
                  <div className="flex flex-wrap gap-2">
                    {Object.entries(CHANNEL_META).map(([ch, meta]) => {
                      const Icon = meta.icon;
                      const active = pref.channels?.includes(ch);
                      return (
                        <button
                          key={ch}
                          onClick={() => toggleChannel(pref.notification_type, ch, !active)}
                          className={`flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium border transition-colors ${active ? 'border-teal-200 bg-teal-50 text-teal-700' : 'border-gray-200 text-gray-400 hover:border-gray-300'}`}
                        >
                          <Icon className="h-3.5 w-3.5" />{t(meta.ar, meta.en)}
                          {active && <X className="h-3 w-3 text-teal-400" />}
                        </button>
                      );
                    })}
                  </div>
                </div>
              );
            })}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
