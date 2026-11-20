'use client';

import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import {
  CalendarDays, Plus, Loader2, Users, CheckCircle2, Archive, ArrowRightLeft, AlertTriangle, Power,
} from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

type Seats = { used: number; limit: number | null; remaining: number | null; unlimited: boolean };
type Term = {
  id: string; name: string; status: string; derived_status: string;
  starts_at: string | null; ends_at: string | null; enrollments_count?: number;
};
type PreviewStudent = { user_id: string; name: string; email: string; courses: { id: string; title: string }[] };
type Preview = {
  students_count: number; students: PreviewStudent[]; seats: Seats;
  seats_before: number; seats_after: number; seats_freed: number;
};

export default function TermsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [terms, setTerms] = useState<Term[]>([]);
  const [seats, setSeats] = useState<Seats | null>(null);
  const [loading, setLoading] = useState(true);

  const [createOpen, setCreateOpen] = useState(false);
  const [form, setForm] = useState({ name: '', starts_at: '', ends_at: '' });
  const [busy, setBusy] = useState(false);

  // End / renew wizard
  const [endTarget, setEndTarget] = useState<Term | null>(null);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [continueIds, setContinueIds] = useState<Set<string>>(new Set());
  const [nextMode, setNextMode] = useState<'none' | 'existing' | 'new'>('none');
  const [nextTermId, setNextTermId] = useState('');
  const [nextForm, setNextForm] = useState({ name: '', starts_at: '', ends_at: '' });

  const headers = () => {
    const tid = localStorage.getItem('tenant_id');
    return { 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const jsonHeaders = () => ({ ...headers(), 'Content-Type': 'application/json' });

  const load = () => {
    setLoading(true);
    fetch(`${API}/terms`, { headers: headers() })
      .then((r) => r.json())
      .then((d) => { setTerms(d.terms || []); setSeats(d.seats || null); })
      .catch(() => toast.error(t('تعذّر تحميل الفصول', 'Failed to load terms')))
      .finally(() => setLoading(false));
  };

  useEffect(() => { if (check()) load(); }, [check()]);

  const otherTerms = useMemo(() => terms.filter((x) => x.id !== endTarget?.id && x.status !== 'archived'), [terms, endTarget]);

  const createTerm = async () => {
    if (!form.name.trim()) return;
    setBusy(true);
    try {
      const res = await fetch(`${API}/terms`, { method: 'POST', headers: jsonHeaders(), body: JSON.stringify({
        name: form.name.trim(),
        starts_at: form.starts_at || undefined,
        ends_at: form.ends_at || undefined,
      }) });
      if (!res.ok) throw new Error((await res.json()).message || 'failed');
      toast.success(t('تم إنشاء الفصل', 'Term created'));
      setCreateOpen(false);
      setForm({ name: '', starts_at: '', ends_at: '' });
      load();
    } catch { toast.error(t('فشل إنشاء الفصل', 'Could not create term')); } finally { setBusy(false); }
  };

  const activate = async (term: Term) => {
    setBusy(true);
    try {
      const res = await fetch(`${API}/terms/${term.id}/activate`, { method: 'POST', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم تفعيل الفصل', 'Term activated'));
      load();
    } catch { toast.error(t('فشل التفعيل', 'Failed')); } finally { setBusy(false); }
  };

  const openEnd = async (term: Term) => {
    setEndTarget(term);
    setPreview(null);
    setContinueIds(new Set());
    setNextMode('none');
    setNextTermId('');
    setPreviewLoading(true);
    try {
      const res = await fetch(`${API}/terms/${term.id}/end-preview`, { headers: headers() });
      if (!res.ok) throw new Error();
      setPreview((await res.json()).preview);
    } catch { toast.error(t('تعذّر تحميل المعاينة', 'Preview failed')); } finally { setPreviewLoading(false); }
  };

  const toggleContinue = (id: string) => {
    setContinueIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };

  const confirmEnd = async () => {
    if (!endTarget) return;
    if (continueIds.size && nextMode === 'none') {
      toast.error(t('اختر الفصل القادم للطلاب المستمرين', 'Choose a next term for continuing students'));
      return;
    }
    setBusy(true);
    try {
      const payload: Record<string, unknown> = { continue: [...continueIds] };
      if (continueIds.size && nextMode === 'existing' && nextTermId) payload.next_term_id = nextTermId;
      if (continueIds.size && nextMode === 'new' && nextForm.name.trim()) payload.create_next = {
        name: nextForm.name.trim(), starts_at: nextForm.starts_at || undefined, ends_at: nextForm.ends_at || undefined,
      };
      const res = await fetch(`${API}/terms/${endTarget.id}/end`, { method: 'POST', headers: jsonHeaders(), body: JSON.stringify(payload) });
      const body = await res.json();
      if (!res.ok) throw new Error(body.message || 'failed');
      toast.success(t(`تم إنهاء الفصل — تحرر ${body.summary?.seats_freed ?? 0} مقعد`, `Term ended — ${body.summary?.seats_freed ?? 0} seat(s) freed`));
      setEndTarget(null); setPreview(null);
      load();
    } catch (e) { toast.error((e as Error).message || t('فشل إنهاء الفصل', 'Failed to end term')); } finally { setBusy(false); }
  };

  const statusBadge = (term: Term) => {
    const map: Record<string, { ar: string; en: string; cls: string }> = {
      upcoming: { ar: 'قادم', en: 'Upcoming', cls: 'bg-sky-100 text-sky-700' },
      active: { ar: 'حالي', en: 'Active', cls: 'bg-emerald-100 text-emerald-700' },
      completed: { ar: 'منتهي', en: 'Completed', cls: 'bg-amber-100 text-amber-700' },
      archived: { ar: 'مؤرشف', en: 'Archived', cls: 'bg-gray-200 text-gray-600' },
    };
    const s = map[term.derived_status] || map.upcoming;
    return <Badge className={`${s.cls} border-0 rounded-full`}>{t(s.ar, s.en)}</Badge>;
  };

  const seatsLine = seats && (
    <div className="flex items-center gap-2 rounded-full bg-teal-50 px-4 py-2 text-sm font-semibold text-teal-700">
      <Users className="h-4 w-4" />
      {seats.unlimited
        ? t(`${seats.used} طالب (غير محدود)`, `${seats.used} students (unlimited)`)
        : t(`${seats.used} / ${seats.limit} طالب · متاح ${seats.remaining}`, `${seats.used} / ${seats.limit} students · ${seats.remaining} available`)}
    </div>
  );

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <CalendarDays className="h-6 w-6 text-teal-600" />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('الفصول الدراسية', 'Academic Terms')}</h1>
            <p className="text-muted-foreground mt-1">{t('أنشئ فصلًا، ثم أنهِه لتجديد مقاعد الطلاب دون فقدان أي بيانات', 'Create a term, then end it to renew student seats with no data loss')}</p>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          {seatsLine}
          <Button className="gap-2 rounded-full" onClick={() => setCreateOpen(true)}><Plus className="h-4 w-4" />{t('فصل جديد', 'New Term')}</Button>
        </div>
      </div>

      {loading ? <TableSkeleton rows={4} cols={3} /> : terms.length === 0 ? (
        <EmptyState icon={CalendarDays} title={t('لا توجد فصول بعد', 'No terms yet')} description={t('ابدأ بإنشاء فصل دراسي لتنظيم تسجيلات الطلاب.', 'Create a term to organise student enrollments.')} />
      ) : (
        <div className="grid gap-4">
          {terms.map((term) => (
            <Card key={term.id} className="border-0 shadow-sm">
              <CardContent className="p-5">
                <div className="flex flex-wrap items-center gap-4">
                  <div className="flex-1 min-w-[200px]">
                    <div className="flex items-center gap-2">
                      <p className="font-bold text-lg">{term.name}</p>
                      {statusBadge(term)}
                    </div>
                    <p className="text-xs text-gray-500 mt-1">
                      {term.starts_at ? new Date(term.starts_at).toLocaleDateString() : '—'} → {term.ends_at ? new Date(term.ends_at).toLocaleDateString() : '—'}
                      {' · '}{term.enrollments_count ?? 0} {t('تسجيل', 'enrollments')}
                    </p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {term.status !== 'active' && term.status !== 'archived' && (
                      <Button size="sm" variant="outline" className="gap-1.5 rounded-full" onClick={() => activate(term)} disabled={busy}>
                        <Power className="h-3.5 w-3.5" />{t('تفعيل', 'Activate')}
                      </Button>
                    )}
                    {term.status !== 'archived' && (
                      <Button size="sm" className="gap-1.5 rounded-full bg-teal-600 hover:bg-teal-700" onClick={() => openEnd(term)}>
                        <Archive className="h-3.5 w-3.5" />{t('إنهاء الفصل وتجديد المقاعد', 'End & renew seats')}
                      </Button>
                    )}
                    {term.status === 'archived' && (
                      <span className="inline-flex items-center gap-1.5 text-xs text-gray-400"><CheckCircle2 className="h-3.5 w-3.5" />{t('تم إنهاؤه', 'Ended')}</span>
                    )}
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Create dialog */}
      <Dialog open={createOpen} onOpenChange={(v) => !v && setCreateOpen(false)}>
        <DialogContent>
          <DialogHeader><DialogTitle>{t('فصل دراسي جديد', 'New academic term')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>{t('اسم الفصل', 'Term name')}</Label>
              <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder={t('مثال: الفصل الأول 2026', 'e.g. Term 1 2026')} className="h-11" />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-2">
                <Label>{t('تاريخ البداية', 'Starts at')}</Label>
                <Input type="date" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} className="h-11" />
              </div>
              <div className="space-y-2">
                <Label>{t('تاريخ النهاية', 'Ends at')}</Label>
                <Input type="date" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} className="h-11" />
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setCreateOpen(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={createTerm} disabled={busy || !form.name.trim()} className="gap-1.5 rounded-full">{busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}{t('إنشاء', 'Create')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* End / renew wizard */}
      <Dialog open={!!endTarget} onOpenChange={(v) => { if (!v) { setEndTarget(null); setPreview(null); } }}>
        <DialogContent className="max-w-2xl">
          <DialogHeader><DialogTitle>{t('إنهاء الفصل وتجديد مقاعد الطلاب', 'End term & renew student seats')}</DialogTitle></DialogHeader>
          {previewLoading || !preview ? (
            <div className="flex justify-center py-12"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>
          ) : (
            <div className="space-y-5 max-h-[65vh] overflow-y-auto pe-1">
              <div className="grid grid-cols-3 gap-3 text-center">
                <div className="p-3 rounded-xl bg-gray-50"><p className="text-2xl font-black">{preview.seats_before}</p><p className="text-xs text-gray-500">{t('مقاعد مستخدمة الآن', 'Seats now')}</p></div>
                <div className="p-3 rounded-xl bg-emerald-50"><p className="text-2xl font-black text-emerald-600">{preview.seats_freed}</p><p className="text-xs text-gray-500">{t('مقاعد ستتحرر', 'Seats freed')}</p></div>
                <div className="p-3 rounded-xl bg-teal-50"><p className="text-2xl font-black text-teal-700">{preview.seats_after}</p><p className="text-xs text-gray-500">{t('المستخدمة بعد التنفيذ', 'Used after')}</p></div>
              </div>

              <div className="flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-700">
                <AlertTriangle className="h-4 w-4 mt-0.5 shrink-0" />
                <span>{t('لا يُحرَّر مقعد طالب ما زال مسجّلًا في دورة سارية أخرى. الاختبارات والدرجات والشهادات تبقى محفوظة.', 'A student still active in another course keeps their seat. Grades, attempts and certificates are preserved.')}</span>
              </div>

              <div>
                <h3 className="font-semibold text-sm mb-2">{t('طلاب الفصل (' + preview.students_count + ')', 'Term students')} — {t('حدّد المستمرين', 'mark continuing')}</h3>
                <div className="space-y-2">
                  {preview.students.map((s) => {
                    const cont = continueIds.has(s.user_id);
                    return (
                      <div key={s.user_id} className={`flex items-center gap-3 p-3 rounded-xl border ${cont ? 'border-teal-200 bg-teal-50/50' : ''}`}>
                        <input type="checkbox" className="h-4 w-4 rounded border-gray-300 text-teal-600" checked={cont} onChange={() => toggleContinue(s.user_id)} aria-label={s.name} />
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-medium truncate">{s.name} <span className="text-gray-400 font-normal">· {s.email}</span></p>
                          <p className="text-[11px] text-gray-400 truncate">{s.courses.map((c) => c.title).join(' · ')}</p>
                        </div>
                        {cont && <Badge className="bg-teal-600 text-white border-0 rounded-full">{t('مستمر', 'Continuing')}</Badge>}
                      </div>
                    );
                  })}
                  {preview.students.length === 0 && <p className="text-sm text-gray-400">{t('لا يوجد طلاب في هذا الفصل', 'No students in this term')}</p>}
                </div>
              </div>

              {continueIds.size > 0 && (
                <div className="space-y-3 rounded-xl border p-4">
                  <p className="text-sm font-semibold flex items-center gap-1.5"><ArrowRightLeft className="h-4 w-4 text-teal-600" />{t('الفصل القادم للمستمرين (' + continueIds.size + ')', `Next term (${continueIds.size})`)}</p>
                  <div className="flex gap-2">
                    <Button size="sm" variant={nextMode === 'new' ? 'default' : 'outline'} className="rounded-full" onClick={() => setNextMode('new')}>{t('فصل جديد', 'New term')}</Button>
                    <Button size="sm" variant={nextMode === 'existing' ? 'default' : 'outline'} className="rounded-full" onClick={() => setNextMode('existing')} disabled={otherTerms.length === 0}>{t('فصل موجود', 'Existing term')}</Button>
                  </div>
                  {nextMode === 'new' && (
                    <div className="grid gap-2 sm:grid-cols-3">
                      <Input placeholder={t('اسم الفصل القادم', 'Next term name')} value={nextForm.name} onChange={(e) => setNextForm({ ...nextForm, name: e.target.value })} className="h-10" />
                      <Input type="date" value={nextForm.starts_at} onChange={(e) => setNextForm({ ...nextForm, starts_at: e.target.value })} className="h-10" />
                      <Input type="date" value={nextForm.ends_at} onChange={(e) => setNextForm({ ...nextForm, ends_at: e.target.value })} className="h-10" />
                    </div>
                  )}
                  {nextMode === 'existing' && (
                    <div className="space-y-2">
                      {otherTerms.map((x) => (
                        <button key={x.id} type="button" onClick={() => setNextTermId(x.id)} className={`w-full text-start text-sm rounded-lg border p-2.5 ${nextTermId === x.id ? 'border-teal-500 bg-teal-50' : ''}`}>{x.name}</button>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => { setEndTarget(null); setPreview(null); }}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={confirmEnd} disabled={busy || previewLoading || !preview} className="gap-1.5 rounded-full bg-teal-600 hover:bg-teal-700">
              {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Archive className="h-4 w-4" />}{t('تأكيد الإنهاء', 'Confirm & end')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
