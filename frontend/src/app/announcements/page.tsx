'use client';

import { useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Select } from '@/components/ui/select';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Megaphone, Loader2, Bell, Mail, Smartphone, MessageSquare, Send, Trash2, Users, MessagesSquare, Hash } from 'lucide-react';
import { toast } from 'sonner';
import { announcementsApi } from '@/services/endpoints';

const AUDIENCE_LABELS: Record<string, { ar: string; en: string }> = {
  all: { ar: 'الجميع', en: 'Everyone' },
  students: { ar: 'الطلاب', en: 'Students' },
  teachers: { ar: 'المعلمون', en: 'Teachers' },
  admins: { ar: 'الإدارة', en: 'Admins' },
  course: { ar: 'طلاب دورة معينة', en: 'Course students' },
};

const CHANNEL_LABELS: Record<string, { ar: string; en: string }> = {
  database: { ar: 'داخل المنصة', en: 'In-app' },
  mail: { ar: 'بريد', en: 'Email' },
  push: { ar: 'Push', en: 'Push' },
  sms: { ar: 'SMS', en: 'SMS' },
  whatsapp: { ar: 'واتساب', en: 'WhatsApp' },
  telegram: { ar: 'تيليجرام', en: 'Telegram' },
  discord: { ar: 'ديسكورد', en: 'Discord' },
  slack: { ar: 'سلاك', en: 'Slack' },
};

export default function AnnouncementsPage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [announcements, setAnnouncements] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [creating, setCreating] = useState(false);
  const [sendingId, setSendingId] = useState<string | null>(null);
  const [deletingId, setDeletingId] = useState<string | null>(null);
  const [courses, setCourses] = useState<any[]>([]);
  const [isStaff, setIsStaff] = useState(false);

  const [form, setForm] = useState({
    title: '',
    body: '',
    audience: 'all',
    course_id: '',
    channels: ['database', 'mail', 'push'] as string[],
  });

  useEffect(() => {
    announcementsApi.list().then((d) => setAnnouncements(d.announcements || [])).catch(() => {}).finally(() => setLoading(false));
    try {
      const user = JSON.parse(localStorage.getItem('user') || '{}');
      const roles = user.roles || [];
      setIsStaff(user.is_super_admin || roles.includes('Teacher') || roles.includes('Admin') || roles.includes('Owner'));
    } catch { /* */ }
    const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    fetch(`${API}/courses`, { headers: { 'X-Tenant-Id': tid || '', Accept: 'application/json' } })
      .then(r => r.json()).then((d) => setCourses(d.courses || [])).catch(() => {});
  }, []);

  const toggleChannel = (ch: string) => {
    setForm((f) => ({
      ...f,
      channels: f.channels.includes(ch) ? f.channels.filter((c) => c !== ch) : [...f.channels, ch],
    }));
  };

  const create = async () => {
    if (!form.title.trim() || !form.body.trim()) { toast.error(t('أكمل العنوان والنص', 'Fill title and body')); return; }
    if (form.audience === 'course' && !form.course_id) { toast.error(t('اختر الدورة', 'Choose a course')); return; }
    setCreating(true);
    try {
      await announcementsApi.create({ ...form, course_id: form.audience === 'course' ? form.course_id : null });
      setOpen(false);
      setForm({ title: '', body: '', audience: 'all', course_id: '', channels: ['database', 'mail', 'push'] });
      toast.success(t('تم إنشاء الإعلان', 'Announcement created'));
      const d = await announcementsApi.list();
      setAnnouncements(d.announcements || []);
    } catch (e: any) {
      toast.error(e?.response?.data?.message || t('فشل الإنشاء', 'Create failed'));
    }
    setCreating(false);
  };

  const send = async (id: string) => {
    setSendingId(id);
    try {
      const res = await announcementsApi.send(id);
      toast.success(t(`تم الإرسال إلى ${res.recipient_count} مستلم`, `Sent to ${res.recipient_count} recipients`));
      const d = await announcementsApi.list();
      setAnnouncements(d.announcements || []);
    } catch (e: any) {
      toast.error(e?.response?.data?.message || t('فشل الإرسال', 'Send failed'));
    }
    setSendingId(null);
  };

  const remove = async (id: string) => {
    if (!confirm(t('حذف هذا الإعلان نهائياً؟', 'Delete this announcement permanently?'))) return;
    setDeletingId(id);
    try {
      await announcementsApi.remove(id);
      setAnnouncements((prev) => prev.filter((a) => a.id !== id));
      toast.success(t('تم الحذف', 'Deleted'));
    } catch { toast.error(t('فشل الحذف', 'Delete failed')); }
    setDeletingId(null);
  };

  return (
    <div className="space-y-6 max-w-4xl mx-auto">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-amber-500/10"><Megaphone className="h-5 w-5 text-amber-500" /></div>
          <div>
            <h1 className="text-xl sm:text-2xl font-bold">{t('الإعلانات', 'Announcements')}</h1>
            <p className="text-gray-500 text-sm">
              {isStaff
                ? t('أنشئ الإعلانات وأرسلها لطلابك أو للمنصة', 'Create announcements and send them to your students or the platform')
                : t('آخر الإعلانات من معلميك', 'Latest announcements from your teachers')}
            </p>
          </div>
        </div>
        {isStaff && (
          <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
              <Button className="rounded-xl text-white" style={{ background: '#d97706' }}><Megaphone className="h-4 w-4 ms-1" />{t('إعلان جديد', 'New announcement')}</Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>{t('إنشاء إعلان', 'Create announcement')}</DialogTitle>
              </DialogHeader>
              <div className="space-y-4">
                <div>
                  <Label>{t('العنوان', 'Title')}</Label>
                  <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className="mt-1" placeholder={t('مثال: موعد الاختبار النهائي', 'e.g. Final exam date')} />
                </div>
                <div>
                  <Label>{t('النص', 'Message')}</Label>
                  <Textarea value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} className="mt-1" rows={4} />
                </div>
                <div>
                  <Label>{t('الجمهور', 'Audience')}</Label>
                  <div className="flex flex-wrap gap-2 mt-1">
                    {Object.entries(AUDIENCE_LABELS).map(([key, label]) => (
                      <button key={key} onClick={() => setForm({ ...form, audience: key })} className={`px-3 py-1.5 rounded-full text-xs font-medium border transition-colors ${form.audience === key ? 'border-amber-300 bg-amber-50 text-amber-700' : 'border-gray-200 text-gray-500'}`}>
                        {t(label.ar, label.en)}
                      </button>
                    ))}
                  </div>
                </div>
                {form.audience === 'course' && (
                  <div>
                    <Label>{t('الدورة', 'Course')}</Label>
                    <Select value={form.course_id} onChange={(e) => setForm({ ...form, course_id: e.target.value })} className="mt-1 border-gray-200">
                      <option value="">{t('اختر الدورة...', 'Choose a course...')}</option>
                      {courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
                    </Select>
                  </div>
                )}
                <div>
                  <Label>{t('قنوات الإرسال', 'Channels')}</Label>
                  <div className="flex flex-wrap gap-2 mt-1">
                    {Object.entries(CHANNEL_LABELS).map(([key, label]) => (
                      <button key={key} onClick={() => toggleChannel(key)} className={`flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-medium border transition-colors ${form.channels.includes(key) ? 'border-teal-200 bg-teal-50 text-teal-700' : 'border-gray-200 text-gray-400'}`}>
                        {key === 'database' ? <Bell className="h-3.5 w-3.5" /> : key === 'mail' ? <Mail className="h-3.5 w-3.5" /> : key === 'push' ? <Smartphone className="h-3.5 w-3.5" /> : <MessageSquare className="h-3.5 w-3.5" />}
                        {t(label.ar, label.en)}
                      </button>
                    ))}
                  </div>
                </div>
                <Button onClick={create} disabled={creating} className="w-full rounded-xl text-white" style={{ background: '#d97706' }}>
                  {creating ? <Loader2 className="h-4 w-4 animate-spin" /> : t('إنشاء', 'Create')}
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        )}
      </div>

      {loading ? (
        <TableSkeleton rows={5} cols={4} />
      ) : announcements.length === 0 ? (
        <EmptyState icon={Megaphone} title={t('لا توجد إعلانات بعد', 'No announcements yet')} />
      ) : (
        <div className="space-y-4">
          {announcements.map((a) => (
            <Card key={a.id}>
              <CardHeader className="flex-row items-center justify-between gap-3">
                <CardTitle className="text-base flex items-center gap-2 min-w-0">
                  <Megaphone className="h-4 w-4 text-amber-500 shrink-0" />
                  <span className="truncate">{a.title}</span>
                </CardTitle>
                <div className="flex items-center gap-2 shrink-0">
                  <Badge variant="outline" className="text-amber-600 border-amber-200">{t((AUDIENCE_LABELS[a.audience] || AUDIENCE_LABELS.all).ar, (AUDIENCE_LABELS[a.audience] || AUDIENCE_LABELS.all).en)}</Badge>
                  {isStaff && a.status !== 'sent' && (
                    <>
                      <Button size="sm" variant="outline" className="rounded-xl text-green-600 border-green-200" onClick={() => send(a.id)} disabled={sendingId === a.id || !!deletingId}>
                        {sendingId === a.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Send className="h-3.5 w-3.5" />}
                        {t('إرسال', 'Send')}
                      </Button>
                      <Button size="sm" variant="ghost" className="rounded-xl text-red-500" onClick={() => remove(a.id)} disabled={deletingId === a.id} aria-label={t('حذف', 'Delete')} title={t('حذف', 'Delete')}>
                        {deletingId === a.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Trash2 className="h-3.5 w-3.5" />}
                      </Button>
                    </>
                  )}
                </div>
              </CardHeader>
              <CardContent>
                <p className="text-sm text-gray-700 whitespace-pre-wrap leading-relaxed">{a.body}</p>
                <div className="flex items-center justify-between flex-wrap gap-2 mt-4">
                  <div className="flex items-center gap-2">
                    {a.sender && (
                      <div className="flex items-center gap-2">
                        <div className="flex h-7 w-7 items-center justify-center rounded-full bg-amber-100 text-amber-700 text-xs font-bold">{a.sender.name?.[0]}</div>
                        <span className="text-xs text-gray-500">{a.sender.name}</span>
                      </div>
                    )}
                    <Badge className={a.status === 'sent' ? 'bg-green-500' : 'bg-gray-400'}>{a.status === 'sent' ? t('مرسل', 'Sent') : t('مسودة', 'Draft')}</Badge>
                  </div>
                  <div className="flex items-center gap-1.5">
                    {(a.channels || []).map((ch: string) => (
                      <span key={ch} className="flex items-center gap-1 text-[10px] text-gray-400 bg-gray-50 border border-gray-100 rounded-full px-2 py-0.5">
                        {ch === 'mail' ? <Mail className="h-3 w-3" /> : ch === 'push' ? <Smartphone className="h-3 w-3" /> : ch === 'sms' || ch === 'whatsapp' ? <MessageSquare className="h-3 w-3" /> : <Bell className="h-3 w-3" />}
                        {t((CHANNEL_LABELS[ch] || { ar: ch, en: ch }).ar, (CHANNEL_LABELS[ch] || { ar: ch, en: ch }).en)}
                      </span>
                    ))}
                  </div>
                  <p className="text-xs text-gray-400" dir="ltr">{a.published_at ? new Date(a.published_at).toLocaleString(locale === 'ar' ? 'ar' : 'en') : ''}</p>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
