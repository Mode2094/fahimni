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
import { Select } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { EmptyState } from '@/components/ui/empty-state';
import { CardSkeleton } from '@/components/ui/skeleton';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { HandCoins, Plus, Trash2, Loader2, Pencil, Clock, Coins, MessageSquareQuote, GraduationCap } from 'lucide-react';
import { toast } from 'sonner';
import { useSettings } from '@/store/settings';
import AppointmentsTabs from '@/components/teacher/appointments-tabs';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const EMPTY = { type: 'consultation', title: '', description: '', price: '', duration_minutes: 30, is_active: true };

export default function TeacherServicesPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const { settings } = useSettings();
  const currency = settings.currency || 'JOD';

  const [items, setItems] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<any>(null);
  const [form, setForm] = useState<any>({ ...EMPTY });
  const [busyId, setBusyId] = useState('');
  const [removing, setRemoving] = useState('');

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const load = () => {
    fetch(`${API}/teacher/services`, { headers: headers() })
      .then(r => r.json()).then(d => setItems(d.services || [])).catch(() => {})
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (check()) load(); }, [check()]);

  const openAdd = () => { setEditing(null); setForm({ ...EMPTY }); setShowForm(true); };
  const openEdit = (s: any) => { setEditing(s); setForm({ type: s.type, title: s.title, description: s.description || '', price: s.price, duration_minutes: s.duration_minutes, is_active: s.is_active }); setShowForm(true); };

  const save = async () => {
    if (!form.title.trim() || form.price === '') { toast.error(t('أدخل العنوان والسعر', 'Enter title and price')); return; }
    setBusyId('save');
    try {
      const body = { ...form, price: Number(form.price) };
      const res = await fetch(`${API}/teacher/services${editing ? `/${editing.id}` : ''}`, { method: editing ? 'PUT' : 'POST', headers: headers(), body: JSON.stringify(body) });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(editing ? t('تم تحديث الخدمة', 'Service updated') : t('تمت إضافة الخدمة — ستظهر في صفحتك العامة', 'Service added — it now appears on your public page'));
      setShowForm(false); load();
    } catch { toast.error('Failed'); }
    setBusyId('');
  };

  const toggle = async (s: any) => {
    try {
      const res = await fetch(`${API}/teacher/services/${s.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ is_active: !s.is_active }) });
      if (!res.ok) throw new Error();
      load();
    } catch { toast.error('Failed'); }
  };

  const remove = async (id: string) => {
    if (!window.confirm(t('حذف هذه الخدمة نهائياً؟', 'Delete this service permanently?'))) return;
    setRemoving(id);
    try {
      const res = await fetch(`${API}/teacher/services/${id}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم الحذف', 'Deleted')); load();
    } catch { toast.error('Failed'); }
    setRemoving('');
  };

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <HandCoins className="h-6 w-6 text-teal-600" />
          </span>
          <div>
            <AppointmentsTabs />
            <h1 className="text-2xl sm:text-3xl font-bold">{t('الاستشارات والجلسات', 'Consultations & Sessions')}</h1>
            <p className="text-muted-foreground mt-1">{t('قدم استشارات مدفوعة أو جلسات فردية — الطلاب يحجزون ويدفعون أونلاين', 'Offer paid consultations or one-on-one sessions — students book and pay online')}</p>
          </div>
        </div>
        <Button onClick={openAdd} className="gap-2 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}><Plus className="h-4 w-4" /> {t('خدمة جديدة', 'New service')}</Button>
      </div>

      {loading ? <CardSkeleton count={3} /> :
        items.length === 0 ? (
          <EmptyState icon={HandCoins} title={t('لا توجد خدمات بعد', 'No services yet')} description={t('أضف استشارة مدفوعة أو جلسة فردية وحدد سعرها ومدتها', 'Add a paid consultation or individual session with price and duration')}
            action={<Button onClick={openAdd} className="mt-2 gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('خدمة جديدة', 'New service')}</Button>} />
        ) : (
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {items.map(s => (
              <Card key={s.id} className={`border-0 shadow-sm card-hover ${!s.is_active ? 'opacity-60' : ''}`}>
                <CardContent className="p-5">
                  <div className="flex items-start justify-between mb-3">
                    <div className="flex h-11 w-11 items-center justify-center rounded-xl shrink-0" style={{ backgroundColor: s.type === 'consultation' ? '#0d948815' : '#7c3aed15' }}>
                      {s.type === 'consultation' ? <MessageSquareQuote className="h-5 w-5 text-teal-700" /> : <GraduationCap className="h-5 w-5 text-violet-600" />}
                    </div>
                    <Badge className={s.type === 'consultation' ? 'bg-teal-50 text-teal-700 border-0' : 'bg-violet-50 text-violet-600 border-0'}>
                      {s.type === 'consultation' ? t('استشارة', 'Consultation') : t('جلسة فردية', 'Session')}
                    </Badge>
                  </div>
                  <h3 className="font-semibold">{s.title}</h3>
                  {s.description && <p className="text-sm text-gray-500 mt-1 line-clamp-3">{s.description}</p>}
                  <div className="flex items-center gap-4 mt-3 text-sm">
                    <span className="flex items-center gap-1 font-bold text-gray-800"><Coins className="h-4 w-4 text-amber-500" />{s.price} {s.currency}</span>
                    <span className="flex items-center gap-1 text-gray-500"><Clock className="h-4 w-4" />{s.duration_minutes} {t('دقيقة', 'min')}</span>
                  </div>
                  <div className="flex items-center gap-1.5 mt-4">
                    <Button size="sm" variant="outline" className="gap-1.5 rounded-full text-xs text-teal-700" onClick={() => openEdit(s)}><Pencil className="h-3.5 w-3.5" />{t('تعديل', 'Edit')}</Button>
                    <Switch checked={s.is_active} onCheckedChange={() => toggle(s)} label={s.is_active ? t('إيقاف', 'Disable') : t('تفعيل', 'Enable')} />
                    <Button size="sm" variant="ghost" className="rounded-full text-xs text-red-500" onClick={() => remove(s.id)} disabled={removing === s.id} title={t('حذف', 'Delete')} aria-label={t('حذف', 'Delete')}><Trash2 className="h-3.5 w-3.5" /></Button>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

      <Dialog open={showForm} onOpenChange={setShowForm}>
        <DialogContent><DialogHeader><DialogTitle>{editing ? t('تعديل الخدمة', 'Edit service') : t('خدمة جديدة', 'New service')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>{t('النوع', 'Type')}</Label>
              <div className="grid grid-cols-2 gap-2">
                <button onClick={() => setForm({ ...form, type: 'consultation' })} className={`flex flex-col items-center gap-1 px-4 py-3 rounded-xl border text-sm ${form.type === 'consultation' ? 'border-teal-300 bg-teal-50 text-teal-700' : 'border-gray-200 text-gray-500'}`}>
                  <MessageSquareQuote className="h-5 w-5" />{t('استشارة', 'Consultation')}
                </button>
                <button onClick={() => setForm({ ...form, type: 'session' })} className={`flex flex-col items-center gap-1 px-4 py-3 rounded-xl border text-sm ${form.type === 'session' ? 'border-violet-300 bg-violet-50 text-violet-700' : 'border-gray-200 text-gray-500'}`}>
                  <GraduationCap className="h-5 w-5" />{t('جلسة فردية', 'Session')}
                </button>
              </div>
            </div>
            <div className="space-y-2"><Label>{t('العنوان', 'Title')} *</Label><Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className="h-11" placeholder={t('مثال: استشارة توجيه أكاديمي', 'e.g. Academic guidance consultation')} /></div>
            <div className="space-y-2"><Label>{t('الوصف', 'Description')}</Label><Textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="min-h-[70px]" /></div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2"><Label>{t('السعر', 'Price')} ({currency}) *</Label><Input type="number" min="0" step="0.01" value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} className="h-11" /></div>
              <div className="space-y-2">
                <Label>{t('المدة (دقيقة)', 'Duration (min)')}</Label>
                <Select value={form.duration_minutes} onChange={(e) => setForm({ ...form, duration_minutes: Number(e.target.value) })} className="h-11">
                  {[15, 30, 45, 60, 90, 120, 180, 240].map(m => <option key={m} value={m}>{m} {t('دقيقة', 'min')}</option>)}
                </Select>
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowForm(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={save} disabled={busyId === 'save'} className="gap-2 rounded-full">{busyId === 'save' && <Loader2 className="h-4 w-4 animate-spin" />}{editing ? t('حفظ', 'Save') : t('إضافة الخدمة', 'Add service')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
