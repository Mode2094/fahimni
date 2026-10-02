'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { EmptyState } from '@/components/ui/empty-state';
import { CardSkeleton } from '@/components/ui/skeleton';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import {
  Video, Plus, Trash2, Loader2, Calendar, Users, Pencil, Play, Square, XCircle,
  Copy, Share2, ExternalLink, Link2, MonitorPlay,
} from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const EMPTY = { title: '', description: '', meeting_url: '', scheduled_at: '', max_participants: '100', record_enabled: false, duration_minutes: '60' };

export default function LiveSessionsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [sessions, setSessions] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<any>(null);
  const [form, setForm] = useState<any>({ ...EMPTY });
  const [busyId, setBusyId] = useState('');

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const load = () => {
    fetch(`${API}/teacher/live-sessions`, { headers: headers() })
      .then(r => r.json()).then(d => setSessions(d.sessions || [])).catch(() => {})
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (check()) load(); }, [check]);

  const openAdd = () => { setEditing(null); setForm({ ...EMPTY }); setShowForm(true); };
  const openEdit = (s: any) => {
    setEditing(s);
    setForm({ title: s.title || '', description: s.description || '', meeting_url: s.meeting_url || '', scheduled_at: s.scheduled_at ? s.scheduled_at.slice(0, 16) : '', max_participants: String(s.max_participants ?? '100'), record_enabled: !!s.record_enabled, duration_minutes: String(s.duration_minutes ?? 60) });
    setShowForm(true);
  };

  const save = async () => {
    if (!form.title.trim()) { toast.error(t('أدخل عنوان الجلسة', 'Enter session title')); return; }
    if (!/^https:\/\/meet\.google\.com\/[a-z0-9-]+(\/)?$/i.test(form.meeting_url.trim())) { toast.error(t('ألصق رابط Google Meet صحيحًا (https://meet.google.com/...)', 'Paste a valid Google Meet link (https://meet.google.com/...)')); return; }
    setBusyId('save');
    try {
      const body = {
        title: form.title, description: form.description, provider: 'google_meet',
        meeting_url: form.meeting_url.trim(),
        scheduled_at: form.scheduled_at || null,
        max_participants: Number(form.max_participants) || 100,
        record_enabled: form.record_enabled,
        duration_minutes: Number(form.duration_minutes) || 60,
      };
      const res = await fetch(`${API}/teacher/live-sessions${editing ? `/${editing.id}` : ''}`, { method: editing ? 'PUT' : 'POST', headers: headers(), body: JSON.stringify(body) });
      if (!res.ok) throw new Error();
      toast.success(editing ? t('تم تحديث الجلسة', 'Session updated') : t('تم إنشاء الجلسة — شارك رابط الانضمام مع طلابك', 'Session created — share the join link with your students'));
      setShowForm(false); load();
    } catch { toast.error('Failed'); }
    setBusyId('');
  };

  const action = async (id: string, act: 'start' | 'end' | 'cancel') => {
    setBusyId(id);
    try {
      const res = await fetch(`${API}/teacher/live-sessions/${id}/${act}`, { method: 'POST', headers: headers() });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(act === 'start' ? t('الحصة جاهزة — افتح رابط Google Meet كمضيف', 'Session ready — open the Google Meet link as host') : act === 'end' ? t('تم إنهاء الحصة', 'Session ended') : t('تم إلغاء الجلسة', 'Session cancelled'));
      load();
    } catch { toast.error('Failed'); }
    setBusyId('');
  };

  const remove = async (id: string) => {
    if (!window.confirm(t('حذف هذه الجلسة نهائياً؟', 'Delete this session permanently?'))) return;
    try {
      const res = await fetch(`${API}/teacher/live-sessions/${id}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم الحذف', 'Deleted')); load();
    } catch { toast.error('Failed'); }
  };

  const copyLink = async (s: any) => {
    const url = s.join_url || `${window.location.origin}${s.join_path}`;
    try {
      await navigator.clipboard.writeText(url);
      toast.success(t('تم نسخ رابط الانضمام', 'Join link copied'));
    } catch {
      window.prompt(t('انسخ الرابط', 'Copy link:'), url);
    }
  };

  const shareWhatsApp = (s: any) => {
    const url = s.join_url || `${window.location.origin}${s.join_path}`;
    const text = encodeURIComponent(`${s.title}\n${url}`);
    window.open(`https://wa.me/?text=${text}`, '_blank');
  };

  const statusBadge = (s: string) => {
    const map: Record<string, [string, string]> = {
      live: [t('مباشر الآن', 'Live now'), 'bg-red-500'],
      scheduled: [t('مجدولة', 'Scheduled'), 'bg-teal-500'],
      ended: [t('منتهية', 'Ended'), 'bg-gray-400'],
      cancelled: [t('ملغاة', 'Cancelled'), 'bg-gray-300'],
    };
    const [label, cls] = map[s] || ['—', 'bg-gray-400'];
    return <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-white text-[11px] font-bold ${cls}`}>{s === 'live' && <span className="h-1.5 w-1.5 rounded-full bg-white animate-pulse" />}{label}</span>;
  };

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-red-500/10">
            <Video className="h-6 w-6 text-red-500" />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('البث المباشر', 'Live Streaming')}</h1>
            <p className="text-muted-foreground mt-1">{t('أنشئ حصة Google Meet، شارك الرابط، وافتحه كمضيف عند الموعد', 'Create a Google Meet session, share the link, open it as host at the scheduled time')}</p>
          </div>
        </div>
        <Button onClick={openAdd} className="gap-2 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}><Plus className="h-4 w-4" /> {t('جلسة جديدة', 'New session')}</Button>
      </div>

      {loading ? <CardSkeleton count={3} /> :
        sessions.length === 0 ? (
          <EmptyState icon={Video} title={t('لا توجد جلسات', 'No sessions')} description={t('أنشئ أول حصة وألصق رابط Google Meet الخاص بك', 'Create your first session and paste your Google Meet link')}
            action={<Button onClick={openAdd} className="mt-2 gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('جلسة جديدة', 'New session')}</Button>} />
        ) : (
          <div className="space-y-3">
            {sessions.map((s) => (
              <Card key={s.id} className={`border-0 shadow-sm overflow-hidden ${s.status === 'live' ? 'ring-2 ring-red-400' : ''}`}>
                <CardContent className="p-5">
                  <div className="flex flex-wrap items-start gap-4">
                    <div className={`flex h-12 w-12 items-center justify-center rounded-xl shrink-0 ${s.status === 'live' ? 'bg-red-50' : 'bg-teal-50'}`}>
                      <MonitorPlay className={`h-6 w-6 ${s.status === 'live' ? 'text-red-500' : 'text-teal-600'}`} />
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2 flex-wrap">
                        <h3 className="font-bold text-lg">{s.title}</h3>
                        {statusBadge(s.status)}
                        <Badge variant="outline" className="text-[10px]">Google Meet</Badge>
                      </div>
                      {s.description && <p className="text-sm text-gray-500 mt-0.5">{s.description}</p>}
                      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-xs text-gray-500">
                        <span className="flex items-center gap-1"><Calendar className="h-3 w-3" />{s.scheduled_at ? new Date(s.scheduled_at).toLocaleString() : t('فوري', 'Now')}</span>
                        <span className="flex items-center gap-1"><Users className="h-3 w-3" />{s.max_participants}</span>
                        {s.record_enabled && <Badge className="bg-red-50 text-red-600 border-0 text-[10px]">● {t('تسجيل', 'Rec')}</Badge>}
                        {s.record_url && <span className="text-gray-400">{t('تسجيل:', 'Rec:')} <a href={s.record_url} target="_blank" className="text-teal-600 underline">{t('عرض', 'view')}</a></span>}
                      </div>
                    </div>
                    <div className="flex items-center gap-1.5 flex-wrap">
                      {s.status !== 'ended' && s.status !== 'cancelled' && (
                        <a href={s.start_url || s.meeting_url || '#'} target="_blank" rel="noopener noreferrer"
                          className="inline-flex items-center gap-1.5 rounded-full bg-teal-600 text-white hover:bg-teal-700 px-4 py-2 text-sm font-semibold">
                          <Video className="h-3.5 w-3.5" />{t('فتح الحصة (كمضيف)', 'Open session (host)')}
                        </a>
                      )}
                      {s.status === 'scheduled' && (
                        <Button size="sm" className="gap-1.5 rounded-full text-white bg-red-500 hover:bg-red-600" onClick={() => action(s.id, 'start')} disabled={busyId === s.id}><Play className="h-3.5 w-3.5" />{t('بدء', 'Start')}</Button>
                      )}
                      {s.status === 'live' && (
                        <Button size="sm" className="gap-1.5 rounded-full bg-slate-800 hover:bg-slate-900 text-white" onClick={() => action(s.id, 'end')} disabled={busyId === s.id}><Square className="h-3.5 w-3.5" />{t('إنهاء', 'End')}</Button>
                      )}
                      {s.status === 'scheduled' && (
                        <Button size="sm" variant="outline" className="gap-1.5 rounded-full text-xs text-amber-600" onClick={() => action(s.id, 'cancel')} disabled={busyId === s.id}><XCircle className="h-3.5 w-3.5" />{t('إلغاء', 'Cancel')}</Button>
                      )}
                      <Button size="sm" variant="outline" className="gap-1.5 rounded-full text-xs" onClick={() => copyLink(s)}><Copy className="h-3.5 w-3.5" />{t('نسخ الرابط', 'Copy link')}</Button>
                      <Button size="sm" variant="outline" className="gap-1.5 rounded-full text-xs text-green-600" onClick={() => shareWhatsApp(s)}><Share2 className="h-3.5 w-3.5" />WhatsApp</Button>
                      <a href={s.join_path} target="_blank"><Button size="sm" variant="outline" className="gap-1.5 rounded-full text-xs text-teal-700"><ExternalLink className="h-3.5 w-3.5" />{t('صفحة الانضمام', 'Join page')}</Button></a>
                      {s.status !== 'live' && s.status !== 'ended' && (
                        <Button size="sm" variant="outline" className="gap-1.5 rounded-full text-xs text-teal-700" onClick={() => openEdit(s)} disabled={busyId === s.id}><Pencil className="h-3.5 w-3.5" />{t('تعديل', 'Edit')}</Button>
                      )}
                      <Button size="sm" variant="ghost" className="gap-1.5 rounded-full text-xs text-red-500" onClick={() => remove(s.id)} disabled={busyId === s.id} title={t('حذف', 'Delete')} aria-label={t('حذف', 'Delete')}><Trash2 className="h-3.5 w-3.5" /></Button>
                    </div>
                  </div>
                  <div className="mt-3 flex items-center gap-2 p-3 rounded-xl bg-gray-50 text-xs flex-wrap">
                    <Link2 className="h-3.5 w-3.5 text-teal-600 shrink-0" />
                    <span className="text-gray-500">{t('رابط الحصة (شاركه مع طلابك)', 'Session link (share with students)')}:</span>
                    {s.meeting_url ? (
                      <a href={s.meeting_url} target="_blank" rel="noopener noreferrer" className="font-mono text-teal-700 truncate max-w-full underline" dir="ltr">{s.meeting_url}</a>
                    ) : (
                      <span className="font-mono text-teal-700 truncate" dir="ltr">{s.join_url}</span>
                    )}
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

      <Dialog open={showForm} onOpenChange={setShowForm}>
        <DialogContent className="max-h-[85vh] overflow-y-auto"><DialogHeader><DialogTitle>{editing ? t('تعديل الجلسة', 'Edit session') : t('حصة جديدة', 'New live session')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2"><Label>{t('عنوان الجلسة', 'Title')} *</Label><Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className="h-11" placeholder={t('مثال: مراجعة شاملة قبل الاختبار', 'e.g. Full review before the exam')} /></div>
            <div className="space-y-2"><Label>{t('الوصف', 'Description')}</Label><Textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="min-h-[60px]" /></div>
            <div className="space-y-2">
              <Label>{t('رابط Google Meet', 'Google Meet link')} *</Label>
              <Input dir="ltr" value={form.meeting_url} onChange={(e) => setForm({ ...form, meeting_url: e.target.value })} className="h-11" placeholder="https://meet.google.com/abc-defg-hij" />
              <p className="text-[11px] text-gray-400 leading-relaxed">{t('افتح Google Meet وأنشئ اجتماعاً ثم الصق الرابط هنا — لا حاجة لربط حساب أو أي إعدادات.', 'Open Google Meet, create a meeting, then paste the link here — no account linking or setup needed.')}</p>
            </div>
            <div className="space-y-2"><Label>{t('الموعد (اختياري)', 'Schedule (optional)')}</Label><Input type="datetime-local" value={form.scheduled_at} onChange={(e) => setForm({ ...form, scheduled_at: e.target.value })} className="h-11" /></div>
            <div className="space-y-2"><Label>{t('المدة المتوقعة (دقيقة)', 'Expected duration (minutes)')}</Label><Input type="number" min={5} max={600} value={form.duration_minutes} onChange={(e) => setForm({ ...form, duration_minutes: e.target.value })} className="h-11" /></div>
            <div className="space-y-2"><Label>{t('الحد الأقصى للمشاركين', 'Max participants')}</Label><Input type="number" value={form.max_participants} onChange={(e) => setForm({ ...form, max_participants: e.target.value })} className="h-11" /></div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowForm(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={save} disabled={busyId === 'save'} className="gap-2 rounded-full">{busyId === 'save' && <Loader2 className="h-4 w-4 animate-spin" />}{editing ? t('حفظ', 'Save') : t('إنشاء الحصة', 'Create session')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}