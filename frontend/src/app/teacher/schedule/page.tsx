'use client';

import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { EmptyState } from '@/components/ui/empty-state';
import { CardSkeleton } from '@/components/ui/skeleton';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import {
  Calendar, Plus, Trash2, Loader2, Pencil, XCircle, CheckCircle2, Repeat, Clock, Link2, ExternalLink, Video,
} from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const EMPTY = { title: '', description: '', lesson_date: '', start_time: '', end_time: '', is_recurring: false, repeat_until: '', provider: 'custom', meeting_url: '' };

const DAYS: Record<number, [string, string]> = {
  0: ['الأحد', 'Sunday'], 1: ['الاثنين', 'Monday'], 2: ['الثلاثاء', 'Tuesday'], 3: ['الأربعاء', 'Wednesday'],
  4: ['الخميس', 'Thursday'], 5: ['الجمعة', 'Friday'], 6: ['السبت', 'Saturday'],
};

export default function TeacherSchedulePage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [items, setItems] = useState<any[]>([]);
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
    fetch(`${API}/teacher/scheduled-lessons`, { headers: headers() })
      .then(r => r.json()).then(d => setItems(d.lessons || [])).catch(() => {})
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (check()) load(); }, [check()]);

  const openAdd = () => { setEditing(null); setForm({ ...EMPTY }); setShowForm(true); };

  const openEdit = (item: any) => {
    const first = items.filter(i => i.id === item.id).sort((a, b) => a.instance_date < b.instance_date ? -1 : 1)[0];
    setEditing(item);
    setForm({
      title: item.title || '', description: item.description || '',
      lesson_date: (first?.instance_date || item.instance_date || '').slice(0, 10),
      start_time: item.start_time || '', end_time: item.end_time || '',
      is_recurring: !!item.is_recurring, repeat_until: item.repeat_until?.slice(0, 10) || '',
      provider: item.provider || 'custom', meeting_url: item.meeting_url || '',
    });
    setShowForm(true);
  };

  const save = async () => {
    if (!form.title.trim() || !form.lesson_date) { toast.error(t('أدخل عنوان الدرس والتاريخ', 'Enter lesson title and date')); return; }
    setBusyId('save');
    try {
      const body = {
        title: form.title, description: form.description, lesson_date: form.lesson_date,
        start_time: form.start_time || null, end_time: form.end_time || null,
        is_recurring: form.is_recurring,
        repeat_until: form.is_recurring && form.repeat_until ? form.repeat_until : null,
        provider: form.provider, meeting_url: form.meeting_url || null,
      };
      const res = await fetch(`${API}/teacher/scheduled-lessons${editing ? `/${editing.id}` : ''}`, { method: editing ? 'PUT' : 'POST', headers: headers(), body: JSON.stringify(body) });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(editing ? t('تم تحديث الدرس المجدول', 'Scheduled lesson updated') : t('تم إنشاء الدرس — سيظهر لطلابك', 'Lesson created — it will appear to your students'));
      setShowForm(false); load();
    } catch { toast.error('Failed'); }
    setBusyId('');
  };

  const action = async (id: string, act: 'complete' | 'cancel') => {
    setBusyId(id);
    try {
      const res = await fetch(`${API}/teacher/scheduled-lessons/${id}/${act}`, { method: 'POST', headers: headers() });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(act === 'complete' ? t('تم إنهاء الدرس', 'Lesson completed') : t('تم إلغاء الدرس', 'Lesson cancelled'));
      load();
    } catch { toast.error('Failed'); }
    setBusyId('');
  };

  const remove = async (id: string) => {
    if (!window.confirm(t('حذف هذا الدرس نهائياً؟', 'Delete this lesson permanently?'))) return;
    try {
      const res = await fetch(`${API}/teacher/scheduled-lessons/${id}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم الحذف', 'Deleted')); load();
    } catch { toast.error('Failed'); }
  };

  const groups = useMemo(() => {
    const map = new Map<string, any[]>();
    items.forEach(i => {
      const arr = map.get(i.id) || [];
      arr.push(i);
      map.set(i.id, arr);
    });
    return Array.from(map.values()).map(g => {
      const sorted = [...g].sort((a, b) => a.instance_date < b.instance_date ? -1 : 1);
      return { lesson: sorted[0], instances: sorted };
    });
  }, [items]);

  const fmtDate = (d: string) => {
    const date = new Date(d + 'T00:00:00');
    return date.toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  };

  const fmtTime = (tm: string) => {
    const [h, m] = tm.split(':').map(Number);
    return new Date(2000, 0, 1, h, m).toLocaleTimeString(locale === 'ar' ? 'ar-EG' : 'en-US', { hour: '2-digit', minute: '2-digit' });
  };

  const statusBadge = (s: string) => {
    const map: Record<string, [string, string]> = {
      scheduled: [t('مجدول', 'Scheduled'), 'bg-teal-500'],
      completed: [t('منتهي', 'Completed'), 'bg-emerald-500'],
      cancelled: [t('ملغي', 'Cancelled'), 'bg-gray-400'],
    };
    const [label, cls] = map[s] || ['—', 'bg-gray-400'];
    return <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-white text-[11px] font-bold ${cls}`}>{label}</span>;
  };

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <Calendar className="h-6 w-6 text-teal-600" />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('الدروس المجدولة', 'Scheduled Lessons')}</h1>
            <p className="text-muted-foreground mt-1">{t('جدول حصصك — التاريخ والوقت ورابط الاجتماع، يظهر لطلابك تلقائياً', 'Your lesson timetable — date, time and meeting link, visible to students automatically')}</p>
          </div>
        </div>
        <Button onClick={openAdd} className="gap-2 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}><Plus className="h-4 w-4" /> {t('درس جديد', 'New lesson')}</Button>
      </div>

      {loading ? <CardSkeleton count={3} /> :
        groups.length === 0 ? (
          <EmptyState icon={Calendar} title={t('لا توجد دروس مجدولة', 'No scheduled lessons')} description={t('أنشئ أول درس مجدول وسيراه طلابك فوراً', 'Create your first scheduled lesson — students will see it instantly')}
            action={<Button onClick={openAdd} className="mt-2 gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('درس جديد', 'New lesson')}</Button>} />
        ) : (
          <div className="space-y-3">
            {groups.map(({ lesson, instances }) => {
              const upcoming = instances.filter(i => !i.is_past && i.status === 'scheduled');
              return (
                <Card key={lesson.id} className="border-0 shadow-sm overflow-hidden">
                  <CardContent className="p-5">
                    <div className="flex flex-wrap items-start gap-4">
                      <div className={`flex h-12 w-12 items-center justify-center rounded-xl shrink-0 ${lesson.is_recurring ? 'bg-violet-50' : 'bg-teal-50'}`}>
                        {lesson.is_recurring ? <Repeat className="h-6 w-6 text-violet-600" /> : <Calendar className="h-6 w-6 text-teal-700" />}
                      </div>
                      <div className="flex-1 min-w-[220px]">
                        <div className="flex items-center gap-2 flex-wrap">
                          <h3 className="font-semibold">{lesson.title}</h3>
                          {statusBadge(lesson.status)}
                          {lesson.is_recurring && <Badge className="bg-violet-50 text-violet-600 border-0 text-[10px]">{t('تكرر أسبوعي', 'Weekly')} · {DAYS[lesson.day_of_week]?.[locale === 'ar' ? 0 : 1]}</Badge>}
                        </div>
                        {lesson.description && <p className="text-sm text-gray-500 mt-0.5">{lesson.description}</p>}
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-xs text-gray-500">
                          {lesson.start_time && <span className="flex items-center gap-1"><Clock className="h-3 w-3" />{fmtTime(lesson.start_time)}{lesson.end_time ? ` – ${fmtTime(lesson.end_time)}` : ''}</span>}
                          {lesson.meeting_url && <a href={lesson.meeting_url} target="_blank" rel="noreferrer" className="flex items-center gap-1 text-teal-600 hover:underline" dir="ltr"><Link2 className="h-3 w-3" />{t('رابط الاجتماع', 'Meeting link')}</a>}
                        </div>
                        <div className="mt-3 space-y-1">
                          {instances.map((inst) => (
                            <div key={inst.instance_key} className={`flex flex-wrap items-center gap-2 rounded-lg px-3 py-1.5 text-xs ${inst.is_today ? 'bg-teal-50 text-teal-700 font-medium' : inst.is_past ? 'bg-gray-50 text-gray-400' : 'bg-gray-50 text-gray-600'}`}>
                              <Calendar className="h-3 w-3 shrink-0" />
                              <span>{fmtDate(inst.instance_date)}</span>
                              {inst.start_time && <span className="flex items-center gap-1"><Clock className="h-3 w-3" />{fmtTime(inst.start_time)}</span>}
                              {inst.is_today && <Badge className="bg-teal-100 text-teal-700 border-0 text-[10px]">{t('اليوم', 'Today')}</Badge>}
                              {inst.is_past && <span className="text-[10px]">{t('انتهى', 'Past')}</span>}
                            </div>
                          ))}
                        </div>
                      </div>
                      <div className="flex items-center gap-1.5 flex-wrap">
                        {upcoming.length > 0 && (
                          <Button size="sm" className="gap-1.5 rounded-full bg-emerald-600 hover:bg-emerald-700 text-white" onClick={() => action(lesson.id, 'complete')} disabled={busyId === lesson.id}><CheckCircle2 className="h-3.5 w-3.5" />{t('إنهاء الدرس', 'Complete')}</Button>
                        )}
                        {lesson.status !== 'cancelled' && (
                          <Button size="sm" variant="outline" className="gap-1.5 rounded-full text-xs text-amber-600" onClick={() => action(lesson.id, 'cancel')} disabled={busyId === lesson.id}><XCircle className="h-3.5 w-3.5" />{t('إلغاء', 'Cancel')}</Button>
                        )}
                        {lesson.status !== 'cancelled' && (
                          <Button size="sm" variant="outline" className="gap-1.5 rounded-full text-xs text-teal-700" onClick={() => openEdit(lesson)} disabled={busyId === lesson.id}><Pencil className="h-3.5 w-3.5" />{t('تعديل', 'Edit')}</Button>
                        )}
                        <Button size="sm" variant="ghost" className="gap-1.5 rounded-full text-xs text-red-500" onClick={() => remove(lesson.id)} disabled={busyId === lesson.id} title={t('حذف', 'Delete')} aria-label={t('حذف', 'Delete')}><Trash2 className="h-3.5 w-3.5" /></Button>
                      </div>
                    </div>
                    {lesson.meeting_url && (
                      <div className="mt-3 flex items-center gap-2 p-3 rounded-xl bg-gray-50 text-xs">
                        <Video className="h-3.5 w-3.5 text-teal-600 shrink-0" />
                        <span className="text-gray-500">{t('رابط الاجتماع (سيراه طلابك)', 'Meeting link (visible to students)')}:</span>
                        <a href={lesson.meeting_url} target="_blank" rel="noreferrer" className="font-mono text-teal-700 truncate underline" dir="ltr">{lesson.meeting_url}</a>
                        <ExternalLink className="h-3 w-3 text-teal-400 shrink-0" />
                      </div>
                    )}
                  </CardContent>
                </Card>
              );
            })}
          </div>
        )}

      <Dialog open={showForm} onOpenChange={setShowForm}>
        <DialogContent className="max-h-[85vh] overflow-y-auto"><DialogHeader><DialogTitle>{editing ? t('تعديل الدرس المجدول', 'Edit scheduled lesson') : t('درس مجدول جديد', 'New scheduled lesson')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2"><Label>{t('عنوان الدرس', 'Title')} *</Label><Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className="h-11" placeholder={t('مثال: درس الرياضيات — الجبر', 'e.g. Math — Algebra')} /></div>
            <div className="space-y-2"><Label>{t('الوصف', 'Description')}</Label><Textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="min-h-[60px]" /></div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2"><Label>{t('التاريخ', 'Date')} *</Label><Input type="date" value={form.lesson_date} onChange={(e) => setForm({ ...form, lesson_date: e.target.value })} className="h-11" /></div>
              <div className="space-y-2"><Label>{t('التكرار الأسبوعي', 'Weekly repeat')}</Label>
                <div className={`flex w-full items-center justify-between gap-2 rounded-lg border px-4 py-2.5 text-sm ${form.is_recurring ? 'border-violet-300 bg-violet-50 text-violet-600' : 'border-gray-200 text-gray-500'}`}>
                  <span className="flex items-center gap-2"><Repeat className="h-4 w-4" />{form.is_recurring ? t('تكرر كل أسبوع', 'Repeats weekly') : t('مرة واحدة', 'One time')}</span>
                  <Switch checked={form.is_recurring} onCheckedChange={(v) => setForm({ ...form, is_recurring: v })} label={t('التكرار الأسبوعي', 'Weekly repeat')} />
                </div>
              </div>
            </div>
            {form.is_recurring && (
              <div className="space-y-2"><Label>{t('التكرار حتى (اختياري)', 'Repeat until (optional)')}</Label><Input type="date" value={form.repeat_until} onChange={(e) => setForm({ ...form, repeat_until: e.target.value })} className="h-11" /></div>
            )}
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2"><Label>{t('وقت البداية', 'Start time')}</Label><Input type="time" value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value })} className="h-11" /></div>
              <div className="space-y-2"><Label>{t('وقت النهاية', 'End time')}</Label><Input type="time" value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value })} className="h-11" /></div>
            </div>
            <div className="space-y-2"><Label>{t('المنصة', 'Platform')}</Label>
              <Select value={form.provider} onChange={(e) => setForm({ ...form, provider: e.target.value })} className="h-11">
                <option value="custom">{t('رابط خاص', 'Custom link')}</option>
                <option value="google_meet">Google Meet</option>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>{t('رابط الاجتماع (سيراه طلابك)', 'Meeting link (visible to students)')}</Label>
              <Input value={form.meeting_url} onChange={(e) => setForm({ ...form, meeting_url: e.target.value })} className="h-11" dir="ltr" placeholder="https://meet.google.com/..." />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowForm(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={save} disabled={busyId === 'save'} className="gap-2 rounded-full">{busyId === 'save' && <Loader2 className="h-4 w-4 animate-spin" />}{editing ? t('حفظ', 'Save') : t('إنشاء الدرس', 'Create lesson')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
