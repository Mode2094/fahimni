'use client';

import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { EmptyState } from '@/components/ui/empty-state';
import { CardSkeleton } from '@/components/ui/skeleton';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import {
  CalendarClock, Plus, Trash2, Loader2, Pencil, XCircle, CheckCircle2, Clock, User, Video, Link2, CalendarDays, HandCoins, ExternalLink,
} from 'lucide-react';
import { toast } from 'sonner';
import AppointmentsTabs from '@/components/teacher/appointments-tabs';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const DAYS: Record<number, [string, string]> = {
  1: ['الاثنين', 'Monday'], 2: ['الثلاثاء', 'Tuesday'], 3: ['الأربعاء', 'Wednesday'],
  4: ['الخميس', 'Thursday'], 5: ['الجمعة', 'Friday'], 6: ['السبت', 'Saturday'], 7: ['الأحد', 'Sunday'],
};

const STATUS_MAP: Record<string, [string, string]> = {
  pending: ['قيد الانتظار', 'bg-amber-500'],
  confirmed: ['مؤكد', 'bg-emerald-500'],
  completed: ['منجز', 'bg-teal-500'],
  cancelled: ['ملغي', 'bg-gray-400'],
};

export default function TeacherAppointmentsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [avail, setAvail] = useState<any[]>([]);
  const [bookings, setBookings] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<any>(null);
  const [form, setForm] = useState({ day_of_week: 1, start_time: '09:00', end_time: '17:00', slot_minutes: 30 });
  const [tab, setTab] = useState<'bookings' | 'availability'>('bookings');
  const [busyId, setBusyId] = useState('');
  const [removing, setRemoving] = useState('');

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const load = () => {
    fetch(`${API}/teacher/bookings?per_page=100`, { headers: headers() })
      .then(r => r.json()).then(d => setBookings(d.bookings?.data || d.bookings || [])).catch(() => {});
    fetch(`${API}/teacher/availabilities`, { headers: headers() })
      .then(r => r.json()).then(d => setAvail(d.availabilities || [])).catch(() => {})
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (check()) load(); }, [check()]);

  const openAdd = () => { setEditing(null); setForm({ day_of_week: 1, start_time: '09:00', end_time: '17:00', slot_minutes: 30 }); setShowForm(true); };
  const openEdit = (a: any) => { setEditing(a); setForm({ day_of_week: a.day_of_week, start_time: a.start_time, end_time: a.end_time, slot_minutes: a.slot_minutes || 30 }); setShowForm(true); };

  const saveAvailability = async () => {
    setBusyId('save');
    try {
      const body = { ...form };
      const res = await fetch(`${API}/teacher/availabilities${editing ? `/${editing.id}` : ''}`, { method: editing ? 'PUT' : 'POST', headers: headers(), body: JSON.stringify(body) });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(editing ? t('تم تحديث الموعد المتاح', 'Availability updated') : t('تمت إضافة فترة التوفر', 'Availability added'));
      setShowForm(false); load();
    } catch { toast.error('Failed'); }
    setBusyId('');
  };

  const toggleAvailability = async (a: any) => {
    try {
      const res = await fetch(`${API}/teacher/availabilities/${a.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ is_active: !a.is_active }) });
      if (!res.ok) throw new Error();
      load();
    } catch { toast.error('Failed'); }
  };

  const removeAvailability = async (id: string) => {
    if (!window.confirm(t('حذف فترة التوفر هذه؟', 'Delete this availability slot?'))) return;
    setRemoving(id);
    try {
      const res = await fetch(`${API}/teacher/availabilities/${id}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم الحذف', 'Deleted')); load();
    } catch { toast.error('Failed'); }
    setRemoving('');
  };

  const setStatus = async (b: any, status: string, meeting_url?: string) => {
    setBusyId(b.id);
    try {
      const body: any = { status };
      if (meeting_url !== undefined) body.meeting_url = meeting_url;
      const res = await fetch(`${API}/teacher/bookings/${b.id}/status`, { method: 'PUT', headers: headers(), body: JSON.stringify(body) });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(t('تم تحديث الحالة', 'Status updated'));
      load();
    } catch { toast.error('Failed'); }
    setBusyId('');
  };

  const confirmWithLink = async (b: any) => {
    const url = prompt(t('رابط الاجتماع (اختياري)', 'Meeting link (optional)'));
    if (url === null) return;
    await setStatus(b, 'confirmed', url || undefined);
  };

  const fmtDateTime = (iso: string) => {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  };

  const groupedAvail = useMemo(() => {
    const sorted = [...avail].sort((a, b) => a.day_of_week - b.day_of_week || (a.start_time < b.start_time ? -1 : 1));
    return sorted;
  }, [avail]);

  const statusBadge = (s: string) => {
    const [label, cls] = STATUS_MAP[s] || ['—', 'bg-gray-400'];
    return <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-white text-[11px] font-bold ${cls}`}>{t(label, label)}</span>;
  };

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <CalendarClock className="h-6 w-6 text-teal-600" />
          </span>
          <div>
            <AppointmentsTabs />
            <h1 className="text-2xl sm:text-3xl font-bold">{t('حجز المواعيد', 'Appointments')}</h1>
            <p className="text-muted-foreground mt-1">{t('حدد أوقات توفرك وأدر حجوزات الطلاب — مع أو بدون جلسات مدفوعة', 'Set your availability and manage student bookings — with or without paid sessions')}</p>
          </div>
        </div>
          {tab === 'availability' && <Button onClick={openAdd} className="gap-2 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}><Plus className="h-4 w-4" /> {t('فترة توفر', 'Add availability')}</Button>}
      </div>

      <div className="flex gap-2 rounded-full bg-slate-100/80 p-1 w-fit">
        <button aria-selected={tab === 'bookings'} onClick={() => setTab('bookings')} className={`px-5 py-2 rounded-full text-sm font-bold transition-all ${tab === 'bookings' ? 'bg-white text-teal-700 shadow-sm' : 'text-gray-500 hover:text-gray-800'}`}>{t('الحجوزات', 'Bookings')}</button>
        <button aria-selected={tab === 'availability'} onClick={() => setTab('availability')} className={`px-5 py-2 rounded-full text-sm font-bold transition-all ${tab === 'availability' ? 'bg-white text-teal-700 shadow-sm' : 'text-gray-500 hover:text-gray-800'}`}>{t('أوقات التوفر', 'Availability')}</button>
      </div>

      {loading ? <CardSkeleton count={3} /> : tab === 'availability' ? (
        <div className="space-y-3">
          {groupedAvail.length === 0 ? (
            <EmptyState icon={CalendarClock} title={t('لا توجد أوقات توفر', 'No availability set')} description={t('أضف فترات توفرك ليتمكن الطلاب من حجز المواعيد', 'Add your availability so students can book appointments')}
              action={<Button onClick={openAdd} className="mt-2 gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('فترة توفر', 'Add availability')}</Button>} />
          ) : (
            groupedAvail.map(a => (
              <Card key={a.id} className="border-0 shadow-sm">
                <CardContent className="p-4 flex items-center gap-4 flex-wrap">
                  <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-50 shrink-0">
                    <CalendarDays className="h-5 w-5 text-teal-700" />
                  </div>
                  <div className="flex-1 min-w-[200px]">
                    <p className="font-semibold">{DAYS[a.day_of_week]?.[locale === 'ar' ? 0 : 1] || a.day_of_week}</p>
                    <p className="text-sm text-gray-500 flex items-center gap-1"><Clock className="h-3 w-3" />{a.start_time} – {a.end_time} · {t('كل', 'every')} {a.slot_minutes} {t('دقيقة', 'min')}</p>
                  </div>
                  <Badge className={a.is_active ? 'bg-emerald-50 text-emerald-600 border-0' : 'bg-gray-100 text-gray-500 border-0'}>{a.is_active ? t('نشط', 'Active') : t('موقوف', 'Inactive')}</Badge>
                  <div className="flex items-center gap-1.5">
                    <Button size="sm" variant="outline" className="rounded-full text-xs text-teal-700" onClick={() => openEdit(a)}><Pencil className="h-3.5 w-3.5" />{t('تعديل', 'Edit')}</Button>
                    <Switch checked={a.is_active} onCheckedChange={() => toggleAvailability(a)} label={a.is_active ? t('إيقاف', 'Disable') : t('تفعيل', 'Enable')} />
                    <Button size="sm" variant="ghost" className="rounded-full text-xs text-red-500" onClick={() => removeAvailability(a.id)} disabled={removing === a.id} title={t('حذف', 'Delete')} aria-label={t('حذف', 'Delete')}><Trash2 className="h-3.5 w-3.5" /></Button>
                  </div>
                </CardContent>
              </Card>
            ))
          )}
        </div>
      ) : (
        <div className="space-y-3">
          {bookings.length === 0 ? (
            <EmptyState icon={CalendarClock} title={t('لا توجد حجوزات بعد', 'No bookings yet')} description={t('عندما يحجز الطلاب مواعيدك ستظهر هنا', 'Bookings from your students will appear here')} />
          ) : (
            bookings.map(b => (
              <Card key={b.id} className="border-0 shadow-sm">
                <CardContent className="p-5">
                  <div className="flex flex-wrap items-start gap-4">
                    <div className="flex h-11 w-11 items-center justify-center rounded-xl shrink-0" style={{ backgroundColor: b.kind === 'service' ? '#7c3aed15' : '#0d948815' }}>
                      {b.kind === 'service' ? <HandCoins className="h-5 w-5 text-violet-600" /> : <CalendarClock className="h-5 w-5 text-teal-700" />}
                    </div>
                    <div className="flex-1 min-w-[220px]">
                      <div className="flex items-center gap-2 flex-wrap">
                        <h3 className="font-semibold">{b.service?.title || t('موعد', 'Appointment')}</h3>
                        {statusBadge(b.status)}
                        {b.kind === 'service' && <Badge className="bg-violet-50 text-violet-600 border-0 text-[10px]">{t('مدفوع', 'Paid')}</Badge>}
                      </div>
                      <p className="text-sm text-gray-600 mt-1 flex items-center gap-1.5"><User className="h-3.5 w-3.5 text-gray-400" />{b.student?.name}{b.student?.phone ? ` · ${b.student.phone}` : ''}</p>
                      <p className="text-xs text-gray-500 flex items-center gap-1.5 mt-0.5"><Clock className="h-3 w-3" />{fmtDateTime(b.starts_at)}</p>
                      {b.notes && <p className="text-xs text-gray-500 mt-1 italic">“{b.notes}”</p>}
                      {b.kind === 'service' && b.price > 0 && (
                        <p className="text-xs text-gray-500 mt-1"><HandCoins className="h-3 w-3 inline" /> {b.price} {b.currency} {b.payment ? `· ${t('دفع', 'paid')}: ${b.payment.status}` : ''}</p>
                      )}
                      {b.meeting_url && (
                        <div className="mt-2 flex items-center gap-1.5 text-xs text-teal-700">
                          <Video className="h-3.5 w-3.5" />
                          <a href={b.meeting_url} target="_blank" rel="noreferrer" className="underline truncate" dir="ltr">{b.meeting_url}</a>
                          <ExternalLink className="h-3 w-3" />
                        </div>
                      )}
                    </div>
                    <div className="flex items-center gap-1.5 flex-wrap">
                      {b.status === 'pending' && (
                        <>
                          <Button size="sm" className="gap-1.5 rounded-full bg-emerald-600 hover:bg-emerald-700 text-white" onClick={() => confirmWithLink(b)} disabled={busyId === b.id}><CheckCircle2 className="h-3.5 w-3.5" />{t('تأكيد', 'Confirm')}</Button>
                          <Button size="sm" variant="outline" className="gap-1.5 rounded-full text-xs text-amber-600" onClick={() => setStatus(b, 'cancelled')} disabled={busyId === b.id}><XCircle className="h-3.5 w-3.5" />{t('رفض', 'Decline')}</Button>
                        </>
                      )}
                      {b.status === 'confirmed' && (
                        <>
                          <Button size="sm" className="gap-1.5 rounded-full bg-teal-600 hover:bg-teal-700 text-white" onClick={() => setStatus(b, 'completed')} disabled={busyId === b.id}><CheckCircle2 className="h-3.5 w-3.5" />{t('إنهاء', 'Complete')}</Button>
                          <Button size="sm" variant="outline" className="gap-1.5 rounded-full text-xs text-teal-700" onClick={() => confirmWithLink(b)} disabled={busyId === b.id}><Link2 className="h-3.5 w-3.5" />{t('رابط الاجتماع', 'Meeting link')}</Button>
                        </>
                      )}
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))
          )}
        </div>
      )}

      <Dialog open={showForm} onOpenChange={setShowForm}>
        <DialogContent><DialogHeader><DialogTitle>{editing ? t('تعديل فترة التوفر', 'Edit availability') : t('فترة توفر جديدة', 'New availability')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>{t('اليوم', 'Day')} *</Label>
              <Select value={form.day_of_week} onChange={(e) => setForm({ ...form, day_of_week: Number(e.target.value) })} className="h-11">
                {Object.entries(DAYS).map(([k, v]) => <option key={k} value={k}>{locale === 'ar' ? v[0] : v[1]}</option>)}
              </Select>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2"><Label>{t('من', 'From')} *</Label><Input type="time" value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value })} className="h-11" /></div>
              <div className="space-y-2"><Label>{t('إلى', 'To')} *</Label><Input type="time" value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value })} className="h-11" /></div>
            </div>
            <div className="space-y-2">
              <Label>{t('مدة كل حجز (دقيقة)', 'Slot duration (minutes)')}</Label>
              <Select value={form.slot_minutes} onChange={(e) => setForm({ ...form, slot_minutes: Number(e.target.value) })} className="h-11">
                {[15, 30, 45, 60, 90, 120].map(m => <option key={m} value={m}>{m} {t('دقيقة', 'min')}</option>)}
              </Select>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowForm(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={saveAvailability} disabled={busyId === 'save'} className="gap-2 rounded-full">{busyId === 'save' && <Loader2 className="h-4 w-4 animate-spin" />}{t('حفظ', 'Save')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
