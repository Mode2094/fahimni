'use client';

import { useEffect, useState, useCallback } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import {
  GraduationCap, Loader2, Search, UserX, UserCheck, RefreshCcw, Mail, BookOpen,
  Plus, Trash2, Eye, KeyRound, Smartphone, Coins,
} from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminStudentsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [students, setStudents] = useState<any[]>([]);
  const [stats, setStats] = useState<any>(null);
  const [tenants, setTenants] = useState<any[]>([]);
  const [courses, setCourses] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [tenantFilter, setTenantFilter] = useState('');
  const [activeFilter, setActiveFilter] = useState('');
  const [detail, setDetail] = useState<any>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ name: '', email: '', password: '', tenant_id: '', course_id: '' });
  const [generatedPassword, setGeneratedPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [busyId, setBusyId] = useState<string | null>(null);

  const headers = () => ({ 'Content-Type': 'application/json', Accept: 'application/json' });

  const load = useCallback(() => {
    const q = new URLSearchParams();
    if (search) q.set('search', search);
    if (tenantFilter) q.set('tenant_id', tenantFilter);
    if (activeFilter !== '') q.set('is_active', activeFilter);
    fetch(`${API}/admin/students?${q}`, { headers: headers() })
      .then(r => r.json())
      .then(d => { setStudents(d.students || []); setStats(d.stats || null); })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [search, tenantFilter, activeFilter]);

  useEffect(() => {
    if (!check()) return;
    load();
    fetch(`${API}/admin/tenants/options`, { headers: headers() }).then(r => r.json()).then(d => setTenants(d.tenants || [])).catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [check()]);

  useEffect(() => {
    const id = setTimeout(load, 400);
    return () => clearTimeout(id);
  }, [search, tenantFilter, activeFilter, load]);

  const loadCourses = async (tenantId: string) => {
    if (!tenantId) { setCourses([]); return; }
    try {
      const d = await fetch(`${API}/courses`, { headers: { ...headers(), 'X-Tenant-Id': tenantId } }).then(r => r.json());
      setCourses((d.courses || []).map((c: any) => ({ id: c.id, title: c.title })));
    } catch { setCourses([]); }
  };

  const create = async () => {
    if (!form.name || !form.email) { toast.error(t('أكمل الاسم والبريد', 'Fill name & email')); return; }
    setBusy(true);
    try {
      const res = await fetch(`${API}/admin/students`, {
        method: 'POST', headers: headers(),
        body: JSON.stringify({ name: form.name, email: form.email, password: form.password || undefined, tenant_id: form.tenant_id || undefined, course_id: form.course_id || undefined }),
      });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      setGeneratedPassword(d.generated_password || '');
      toast.success(t('تم إنشاء الطالب', 'Student created'));
      setShowForm(false); setForm({ name: '', email: '', password: '', tenant_id: '', course_id: '' });
      load();
    } catch { toast.error('Failed'); } finally { setBusy(false); }
  };

  const toggleActive = async (s: any) => {
    setBusyId(s.id);
    try {
      const res = await fetch(`${API}/admin/students/${s.id}/status`, { method: 'PUT', headers: headers(), body: JSON.stringify({ is_active: !s.is_active }) });
      if (!res.ok) throw new Error();
      toast.success(t('تم تحديث الحالة', 'Status updated'));
      load();
    } catch { toast.error(t('تعذر الاتصال', 'Cannot connect')); } finally { setBusyId(null); }
  };

  const remove = async (s: any) => {
    if (!window.confirm(t(`حذف الطالب «${s.name}»؟`, `Delete student "${s.name}"?`))) return;
    setBusyId(s.id);
    try {
      const res = await fetch(`${API}/admin/students/${s.id}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم الحذف', 'Deleted'));
      load();
    } catch { toast.error('Failed'); } finally { setBusyId(null); }
  };

  const openDetail = async (id: string) => {
    try {
      const d = await fetch(`${API}/admin/students/${id}`, { headers: headers() }).then(r => r.json());
      setDetail(d);
    } catch {}
  };

  const statCards = [
    { label: t('إجمالي الطلاب', 'Total students'), value: stats?.total ?? 0, color: '' },
    { label: t('نشطون', 'Active'), value: stats?.active ?? 0, color: 'text-emerald-600' },
    { label: t('موقوفون', 'Inactive'), value: stats?.inactive ?? 0, color: 'text-rose-500' },
    { label: t('تسجيلات الكورسات', 'Enrollments'), value: stats?.enrolled ?? 0, color: 'text-teal-700' },
  ];

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#7c3aed12' }}>
            <GraduationCap className="h-6 w-6" style={{ color: '#7c3aed' }} />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('إدارة الطلاب', 'Students')}</h1>
            <p className="text-muted-foreground mt-1">{t('جميع طلاب المنصة عبر كل المعلمين', 'All students across all teachers')}</p>
          </div>
        </div>
        <Button onClick={() => setShowForm(true)} className="gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('إضافة طالب', 'Add Student')}</Button>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {statCards.map((s, i) => (
          <Card key={i} className="group border-0 shadow-sm card-hover overflow-hidden relative">
            <CardContent className="p-5">
              <p className="text-sm text-muted-foreground">{s.label}</p>
              <p className={`text-2xl font-black tracking-tight mt-1.5 ${s.color || ''}`}>{s.value}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="flex flex-wrap gap-3 items-center">
        <div className="relative flex-1 min-w-[220px] max-w-sm">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
          <Input placeholder={t('بحث بالاسم أو البريد...', 'Search name or email...')} value={search} onChange={(e) => setSearch(e.target.value)} className="ps-9 h-10" />
        </div>
        <Select value={tenantFilter} onChange={(e) => setTenantFilter(e.target.value)} className="w-44">
          <option value="">{t('كل المعلمين', 'All teachers')}</option>
          {tenants.map((tn) => <option key={tn.id} value={tn.id}>{tn.name}</option>)}
        </Select>
        <Select value={activeFilter} onChange={(e) => setActiveFilter(e.target.value)} className="w-40">
          <option value="">{t('الكل', 'All')}</option>
          <option value="1">{t('نشط فقط', 'Active only')}</option>
          <option value="0">{t('موقوف فقط', 'Inactive only')}</option>
        </Select>
        <Button variant="outline" size="sm" className="gap-2" onClick={load}><RefreshCcw className="h-4 w-4" /> {t('تحديث', 'Refresh')}</Button>
      </div>

      {loading ? <Card><CardContent className="p-4"><TableSkeleton rows={5} cols={4} /></CardContent></Card> :
        students.length === 0 ? (
          <EmptyState icon={GraduationCap} title={t('لا يوجد طلاب', 'No students')} description={t('سجل أول طالب ليظهر هنا', 'Add your first student to get started')} action={<Button onClick={() => setShowForm(true)} className="gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('إضافة طالب', 'Add Student')}</Button>} />
        ) : (
          <Card><div className="divide-y">
            {students.map((s) => (
              <div key={s.id} className="p-4 flex flex-wrap items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 text-white font-bold text-sm shrink-0">{(s.name || '?')[0]}</div>
                <div className="flex-1 min-w-[160px]">
                  <button className="font-medium hover:text-teal-700 text-start" onClick={() => openDetail(s.id)}>{s.name}</button>
                  <p className="text-xs text-gray-500 flex items-center gap-1"><Mail className="h-3 w-3" /> {s.email || '—'}</p>
                  <p className="text-[11px] text-gray-400 mt-0.5">
                    {t('تسجيلات', 'Enrollments')}: {s.enrollments_count ?? 0}
                    {(s.tenants || []).length > 0 && <> · {(s.tenants || []).map((tn: any) => tn.name).join('، ')}</>}
                    · {t('انضم', 'Joined')}: {s.created_at?.split('T')[0]}
                  </p>
                </div>
                <Badge className={`text-xs ${s.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>{s.is_active ? t('نشط', 'Active') : t('موقوف', 'Inactive')}</Badge>
                <div className="flex items-center gap-1.5">
                  <Button variant="outline" size="sm" className="text-xs gap-1" onClick={() => openDetail(s.id)}><Eye className="h-3.5 w-3.5" /> {t('تفاصيل', 'Details')}</Button>
                  <Button variant={s.is_active ? 'ghost' : 'outline'} size="sm" className={`text-xs gap-1 ${s.is_active ? 'text-red-500' : 'text-green-600'}`} onClick={() => toggleActive(s)} disabled={busyId === s.id}>
                    {busyId === s.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : (s.is_active ? <UserX className="h-3.5 w-3.5" /> : <UserCheck className="h-3.5 w-3.5" />)}
                    {s.is_active ? t('إيقاف', 'Suspend') : t('تفعيل', 'Activate')}
                  </Button>
                  <Button variant="ghost" size="sm" className="text-xs gap-1 text-red-500" onClick={() => remove(s)} disabled={busyId === s.id}><Trash2 className="h-3.5 w-3.5" /> {t('حذف', 'Delete')}</Button>
                </div>
              </div>
            ))}
          </div></Card>
        )}

      {/* Add student dialog */}
      <Dialog open={showForm} onOpenChange={(o) => { setShowForm(o); setGeneratedPassword(''); }}>
        <DialogContent><DialogHeader><DialogTitle>{t('إضافة طالب', 'Add Student')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2"><Label>{t('الاسم', 'Name')}</Label><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="h-11" /></div>
            <div className="space-y-2"><Label>{t('البريد الإلكتروني', 'Email')}</Label><Input type="email" dir="ltr" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="h-11" /></div>
            <div className="space-y-2">
              <Label>{t('كلمة المرور (اختياري — تُولّد تلقائيًا)', 'Password (optional — auto-generated)')}</Label>
              <Input dir="ltr" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} className="h-11" placeholder="••••••••" />
            </div>
            <div className="space-y-2">
              <Label>{t('المنصة (المعلم)', 'Platform (teacher)')}</Label>
              <Select value={form.tenant_id} onChange={(e) => { setForm({ ...form, tenant_id: e.target.value, course_id: '' }); loadCourses(e.target.value); }} className="h-11">
                <option value="">{t('بدون ربط', 'No platform')}</option>
                {tenants.map((tn) => <option key={tn.id} value={tn.id}>{tn.name}</option>)}
              </Select>
            </div>
            {form.tenant_id && (
              <div className="space-y-2">
                <Label>{t('تسجيل في كورس (اختياري)', 'Enroll in course (optional)')}</Label>
                <Select value={form.course_id} onChange={(e) => setForm({ ...form, course_id: e.target.value })} className="h-11">
                  <option value="">{t('بدون كورس', 'No course')}</option>
                  {courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
                </Select>
              </div>
            )}
            {generatedPassword && (
              <div className="p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-start gap-2">
                <KeyRound className="h-4 w-4 shrink-0 mt-0.5" />
                <div>{t('كلمة المرور المولّدة', 'Generated password')}: <b dir="ltr" className="font-mono">{generatedPassword}</b><br />
                  <span className="text-xs">{t('شاركها مع الطالب — غير قابلة للاسترجاع', 'Share it with the student — not recoverable')}</span></div>
              </div>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowForm(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={create} disabled={busy}>{busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />} {t('إنشاء', 'Create')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Student details dialog */}
      <Dialog open={!!detail} onOpenChange={(v) => { if (!v) setDetail(null); }}>
        <DialogContent className="max-w-2xl">
          {detail?.student && (
            <>
              <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                  <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 text-white font-bold">{(detail.student.name || '?')[0]}</span>
                  {detail.student.name}
                </DialogTitle>
              </DialogHeader>
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-3 text-sm">
                  <div className="p-3 rounded-xl bg-gray-50"><p className="text-xs text-gray-400">{t('البريد', 'Email')}</p><p className="mt-0.5" dir="ltr">{detail.student.email || '—'}</p></div>
                  <div className="p-3 rounded-xl bg-gray-50"><p className="text-xs text-gray-400">{t('الحالة', 'Status')}</p><p className="mt-0.5">{detail.student.is_active ? t('نشط', 'Active') : t('موقوف', 'Inactive')}</p></div>
                  <div className="p-3 rounded-xl bg-gray-50"><p className="text-xs text-gray-400">{t('التسجيلات', 'Enrollments')}</p><p className="mt-0.5">{detail.student.enrollments_count ?? 0}</p></div>
                  <div className="p-3 rounded-xl bg-gray-50"><p className="text-xs text-gray-400">{t('النقاط', 'Points')}</p><p className="mt-0.5 flex items-center gap-1"><Coins className="h-3.5 w-3.5 text-amber-500" />{detail.total_points ?? 0}</p></div>
                  {(detail.student.tenants || []).length > 0 && (
                    <div className="p-3 rounded-xl bg-gray-50 col-span-2">
                      <p className="text-xs text-gray-400">{t('المنصات', 'Platforms')}</p>
                      <p className="mt-0.5">{(detail.student.tenants || []).map((tn: any) => tn.name).join('، ') || '—'}</p>
                    </div>
                  )}
                </div>

                <div>
                  <h4 className="font-semibold mb-2">{t('الكورسات المسجل بها', 'Enrolled courses')}</h4>
                  {detail.enrollments?.length === 0 && <p className="text-sm text-gray-400">{t('لا يوجد تسجيلات', 'No enrollments')}</p>}
                  <div className="space-y-2">
                    {(detail.enrollments || []).map((e: any) => (
                      <div key={e.id} className="flex items-center gap-3 p-3 rounded-xl border border-gray-100">
                        <BookOpen className="h-4 w-4 text-teal-600 shrink-0" />
                        <div className="flex-1">
                          <p className="text-sm font-medium">{e.course_title || '—'}</p>
                          <p className="text-[11px] text-gray-400">{e.tenant_name || ''} · {e.enrolled_at?.split('T')[0]}</p>
                        </div>
                        <span className={`text-xs ${Number(e.progress_percent) >= 100 ? 'text-green-600' : 'text-gray-400'}`}>{e.progress_percent ?? 0}%</span>
                      </div>
                    ))}
                  </div>
                </div>

                {(detail.devices || []).length > 0 && (
                  <div>
                    <h4 className="font-semibold mb-2 flex items-center gap-1.5"><Smartphone className="h-4 w-4 text-teal-600" />{t('الأجهزة', 'Devices')}</h4>
                    <div className="space-y-1.5">
                      {(detail.devices || []).map((d: any) => (
                        <div key={d.id} className="flex items-center justify-between p-3 rounded-xl bg-gray-50 text-sm">
                          <span>{d.device_name || d.device_id || '—'}</span>
                          <span className="text-xs text-gray-400">{d.last_seen_at?.split('T')[0] || ''}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
