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
import { CardSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import {
  Users, Plus, Search, Trash2, Loader2, Mail, Crown, Ban, CheckCircle2,
  BookOpen, GraduationCap, CreditCard, Ticket, Eye, KeyRound,
  Wallet, TrendingUp, ClipboardList, FileText, Percent, Layers, Timer, Award, Star, Play,
  ExternalLink, Copy,
} from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminTeachersPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [teachers, setTeachers] = useState<any[]>([]);
  const [stats, setStats] = useState<any>({ total: 0, active: 0, inactive: 0, with_subscription: 0 });
  const [plans, setPlans] = useState<any[]>([]);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('');
  const [showForm, setShowForm] = useState(false);
  const [detail, setDetail] = useState<any>(null);
  const [detailTab, setDetailTab] = useState<'overview' | 'courses' | 'students' | 'sales'>('overview');
  const [form, setForm] = useState({ name: '', email: '', password: '', plan_id: '' });
  const [generatedPassword, setGeneratedPassword] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [busyId, setBusyId] = useState<string | null>(null);

  const headers = () => ({ Accept: 'application/json' });

  const load = useCallback(() => {
    const q = new URLSearchParams();
    if (search) q.set('search', search);
    if (statusFilter === 'active' || statusFilter === 'inactive') q.set('is_active', statusFilter === 'active' ? '1' : '0');
    fetch(`${API}/admin/teachers?${q}`, { headers: headers() })
      .then(r => r.json())
      .then(d => { setTeachers(d.teachers || []); if (d.stats) setStats(d.stats); })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [search, statusFilter]);

  useEffect(() => {
    if (!check()) return;
    load();
    fetch(`${API}/admin/plans`, { headers: headers() }).then(r => r.json()).then(d => setPlans(d.plans || [])).catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [check()]);

  useEffect(() => {
    const id = setTimeout(load, 400);
    return () => clearTimeout(id);
  }, [search, statusFilter, load]);

  const create = async () => {
    if (!form.name || !form.email) { toast.error(t('أكمل الاسم والبريد', 'Fill name & email')); return; }
    setBusy(true);
    try {
      const res = await fetch(`${API}/admin/teachers`, {
        method: 'POST', headers: { 'Content-Type': 'application/json', ...headers() },
        body: JSON.stringify({ name: form.name, email: form.email, password: form.password || undefined, plan_id: form.plan_id || undefined }),
      });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      setGeneratedPassword(d.generated_password || '');
      toast.success(t('تم إنشاء المعلم', 'Teacher created'));
      setShowForm(false); setForm({ name: '', email: '', password: '', plan_id: '' });
      load();
    } catch { toast.error('Failed'); } finally { setBusy(false); }
  };

  const toggleStatus = async (teach: any) => {
    setBusyId(teach.id);
    try {
      const res = await fetch(`${API}/admin/teachers/${teach.id}/status`, {
        method: 'PUT', headers: { 'Content-Type': 'application/json', ...headers() },
        body: JSON.stringify({ is_active: !teach.is_active }),
      });
      if (!res.ok) throw new Error();
      toast.success(t('تم تحديث الحالة', 'Status updated'));
      load();
    } catch { toast.error('Failed'); } finally { setBusyId(null); }
  };

  const remove = async (teach: any) => {
    if (!window.confirm(t(`حذف المعلم «${teach.name}»؟ سيتم إيقاف منصته وحسابه.`, `Delete "${teach.name}"? Their platform & account will be suspended.`))) return;
    setBusyId(teach.id);
    try {
      const res = await fetch(`${API}/admin/teachers/${teach.id}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(t('تم الحذف', 'Deleted'));
      load();
    } catch { toast.error('Failed'); } finally { setBusyId(null); }
  };

  const openDetail = async (teach: any) => {
    try {
      setDetailTab('overview');
      const d = await fetch(`${API}/admin/teachers/${teach.id}`, { headers: headers() }).then(r => r.json());
      setDetail(d);
    } catch { toast.error('Failed'); }
  };

  const subBadge = (s: any) => {
    if (!s) return <Badge className="bg-gray-100 text-gray-500 border-0">{t('بدون خطة', 'No plan')}</Badge>;
    const map: Record<string, string> = {
      active: 'bg-emerald-100 text-emerald-700 border-0',
      trialing: 'bg-sky-100 text-sky-700 border-0',
      expired: 'bg-rose-100 text-rose-600 border-0',
      canceled: 'bg-gray-100 text-gray-500 border-0',
      pending: 'bg-amber-100 text-amber-700 border-0',
    };
    const label: Record<string, string> = {
      active: t('نشط', 'Active'), trialing: t('تجربة', 'Trial'), expired: t('منتهي', 'Expired'),
      canceled: t('ملغى', 'Canceled'), pending: t('قيد الدفع', 'Pending'),
    };
    const planName = s.plan_name || s.plan?.name || t('بدون اسم', 'No name');
    return <Badge className={map[s.status] || ''}>{planName} · {label[s.status] || s.status}</Badge>;
  };

  const detailSubscriptions = (): any[] => {
    const list = detail?.tenant?.subscriptions || [];
    return list.length ? list : (detail?.teacher?.subscription ? [detail.teacher.subscription] : []);
  };

  const platformUrl = (slug?: string) => (slug ? `${window.location.origin}/t/${slug}` : '');
  const teacherLoginUrl = () => `${window.location.origin}/teacher/login`;

  const copyText = async (text: string, msg: string) => {
    try {
      await navigator.clipboard.writeText(text);
      toast.success(msg);
    } catch { toast.error(t('تعذر النسخ', 'Cannot copy')); }
  };

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#0d948812' }}>
            <Users className="h-6 w-6" style={{ color: '#0d9488' }} />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('المعلمون', 'Teachers')}</h1>
            <p className="text-muted-foreground mt-1">{t('إدارة جميع المعلمين ومنصاتهم', 'Manage all teachers & their platforms')}</p>
          </div></div>
        <Button onClick={() => setShowForm(true)} className="gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('إضافة معلم', 'Add Teacher')}</Button>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        {[
          { label: t('إجمالي المعلمين', 'Total'), value: stats.total, icon: Users, color: 'bg-teal-50 text-teal-700' },
          { label: t('نشط', 'Active'), value: stats.active, icon: CheckCircle2, color: 'bg-emerald-50 text-emerald-600' },
          { label: t('موقوف', 'Suspended'), value: stats.inactive, icon: Ban, color: 'bg-rose-50 text-rose-500' },
          { label: t('بأشتراك فعال', 'With subscription'), value: stats.with_subscription, icon: Crown, color: 'bg-amber-50 text-amber-600' },
        ].map((s, i) => (
          <Card key={i} className="group border-0 shadow-sm card-hover overflow-hidden relative">
            <CardContent className="p-5 flex items-center gap-3">
              <span className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl ${s.color}`}><s.icon className="h-6 w-6" /></span>
              <div className="min-w-0"><div className="text-2xl font-black tracking-tight">{s.value}</div><div className="text-xs text-muted-foreground mt-1">{s.label}</div></div>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-3 items-center">
        <div className="relative max-w-md flex-1 min-w-[220px]">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
          <Input placeholder={t('بحث بالاسم أو البريد...', 'Search name or email...')} value={search} onChange={(e) => setSearch(e.target.value)} className="ps-9 h-10" />
        </div>
        <div className="flex gap-1.5">
          {[{ v: '', l: t('الكل', 'All') }, { v: 'active', l: t('نشط', 'Active') }, { v: 'inactive', l: t('موقوف', 'Suspended') }].map((f) => (
            <button key={f.v} onClick={() => setStatusFilter(f.v)}
              className={`px-4 py-2 rounded-full text-sm font-medium transition-colors ${statusFilter === f.v ? 'bg-teal-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}`}>
              {f.l}
            </button>
          ))}
        </div>
      </div>

      {loading ? <CardSkeleton count={6} /> :
        teachers.length === 0 ? (
          <EmptyState icon={Users} title={t('لا يوجد معلمون', 'No teachers')} description={t('ابدأ بإضافة أول معلم وتفعيل منصته', 'Start by adding your first teacher to activate their platform')} action={<Button onClick={() => setShowForm(true)} className="gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('إضافة معلم', 'Add Teacher')}</Button>} />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {teachers.map((teach) => (
              <Card key={teach.id} className={`border-0 shadow-sm card-hover ${!teach.is_active ? 'opacity-60' : ''}`}><CardContent className="p-5">
                <div className="flex items-start justify-between mb-3">
                  <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-teal-600 to-teal-500 text-white font-bold text-lg">{(teach.name || '?')[0]}</div>
                  {teach.is_active
                    ? <Badge className="bg-emerald-100 text-emerald-700 border-0">{t('نشط', 'Active')}</Badge>
                    : <Badge className="bg-rose-100 text-rose-600 border-0">{t('موقوف', 'Suspended')}</Badge>}
                </div>
                <h3 className="font-semibold">{teach.name}</h3>
                <p className="text-sm text-gray-500 mt-1 flex items-center gap-1 truncate"><Mail className="h-3 w-3 shrink-0" /> {teach.email}</p>
                {teach.tenant && (
                  <a href={platformUrl(teach.tenant.slug)} target="_blank" rel="noopener noreferrer"
                    className="text-xs text-teal-700 mt-1 font-mono flex items-center gap-1 hover:underline" dir="ltr"
                    title={t('فتح منصة المعلم في تبويب جديد', 'Open teacher platform in a new tab')}>
                    <ExternalLink className="h-3 w-3 shrink-0" />{window.location.origin}/t/{teach.tenant.slug}
                  </a>
                )}
                <div className="mt-3">{subBadge(teach.subscription)}</div>
                <div className="flex items-center gap-1.5 mt-4 flex-wrap">
                  {teach.tenant && (
                    <Button size="sm" className="rounded-full gap-1" onClick={() => window.open(platformUrl(teach.tenant.slug), '_blank')}>
                      <ExternalLink className="h-3.5 w-3.5" />{t('المنصة', 'Platform')}
                    </Button>
                  )}
                  <Button size="sm" variant="outline" className="rounded-full gap-1" onClick={() => openDetail(teach)}><Eye className="h-3.5 w-3.5" />{t('تفاصيل', 'Details')}</Button>
                  <Button size="sm" variant="outline" className={`rounded-full gap-1 ${teach.is_active ? 'text-amber-600' : 'text-emerald-600'}`} onClick={() => toggleStatus(teach)} disabled={busyId === teach.id}>
                    {busyId === teach.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : (teach.is_active ? <Ban className="h-3.5 w-3.5" /> : <CheckCircle2 className="h-3.5 w-3.5" />)}
                    {teach.is_active ? t('إيقاف', 'Suspend') : t('تفعيل', 'Activate')}
                  </Button>
                  <Button size="sm" variant="ghost" className="rounded-full gap-1 text-red-500" onClick={() => remove(teach)} disabled={busyId === teach.id}><Trash2 className="h-3.5 w-3.5" />{t('حذف', 'Delete')}</Button>
                </div>
              </CardContent></Card>
            ))}
          </div>
        )}

      {/* Add teacher dialog */}
      <Dialog open={showForm} onOpenChange={(o) => { setShowForm(o); setGeneratedPassword(''); }}>
        <DialogContent><DialogHeader><DialogTitle>{t('إضافة معلم', 'Add Teacher')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2"><Label>{t('الاسم', 'Name')}</Label><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="h-11" /></div>
            <div className="space-y-2"><Label>{t('البريد الإلكتروني', 'Email')}</Label><Input type="email" dir="ltr" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="h-11" /></div>
            <div className="space-y-2">
              <Label>{t('كلمة المرور (اختياري — تُولّد تلقائيًا)', 'Password (optional — auto-generated)')}</Label>
              <Input dir="ltr" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} className="h-11" placeholder="••••••••" />
            </div>
            <div className="space-y-2">
              <Label>{t('الخطة (اختياري — التجربة الافتراضية)', 'Plan (optional — default trial)')}</Label>
              <Select value={form.plan_id} onChange={(e) => setForm({ ...form, plan_id: e.target.value })} className="h-11">
                <option value="">{t('الخطة الأقل سعرًا (تجربة 7 أيام)', 'Cheapest plan (7-day trial)')}</option>
                {plans.map((p) => <option key={p.id} value={p.id}>{p.name} — {p.monthly_price} {p.currency}</option>)}
              </Select>
            </div>
            {generatedPassword && (
              <div className="p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-start gap-2">
                <KeyRound className="h-4 w-4 shrink-0 mt-0.5" />
                <div>{t('كلمة المرور المولّدة', 'Generated password')}: <b dir="ltr" className="font-mono">{generatedPassword}</b><br />
                  <span className="text-xs">{t('شاركها مع المعلم — غير قابلة للاسترجاع', 'Share it with the teacher — not recoverable')}</span></div>
              </div>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowForm(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={create} disabled={busy}>{busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />} {t('إنشاء', 'Create')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Teacher details dialog */}
      <Dialog open={!!detail} onOpenChange={(o) => !o && setDetail(null)}>
        <DialogContent className="max-w-5xl max-h-[85vh] flex flex-col">
          {detail && (
            <>
              <DialogHeader className="shrink-0">
                <DialogTitle className="flex items-center gap-3">
                  <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-600 text-white font-bold">{(detail.teacher?.name || '?')[0]}</span>
                  <span className="flex flex-col min-w-0">
                    <span className="truncate">{detail.teacher?.name}</span>
                    <span className="text-xs font-normal text-gray-500" dir="ltr">{detail.teacher?.email}</span>
                  </span>
                  {detail.tenant && <span className="ms-auto flex flex-col items-end min-w-0">
                    <span className="text-xs text-gray-500 truncate">{detail.tenant.name}</span>
                    <a href={platformUrl(detail.tenant.slug)} target="_blank" rel="noopener noreferrer"
                      className="font-mono text-teal-700 text-[11px] hover:underline flex items-center gap-1" dir="ltr">
                      <ExternalLink className="h-3 w-3" />/t/{detail.tenant.slug}
                    </a>
                  </span>}
                </DialogTitle>
              </DialogHeader>

              <div className="flex flex-wrap items-center gap-2 shrink-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <Badge className={detail.teacher?.is_active ? 'bg-emerald-100 text-emerald-700 border-0' : 'bg-rose-100 text-rose-600 border-0'}>
                    {detail.teacher?.is_active ? t('نشط', 'Active') : t('موقوف', 'Suspended')}
                  </Badge>
                  {detail.tenant && (detailSubscriptions()[0] ? subBadge(detailSubscriptions()[0]) : subBadge(null))}
                  {detail.tenant?.created_at && (
                    <span className="text-[11px] text-gray-400">{t('انضم', 'Joined')}: {new Date(detail.tenant.created_at).toLocaleDateString()}</span>
                  )}
                </div>
                <div className="ms-auto flex gap-1 p-1 bg-muted/50 rounded-full overflow-x-auto">
                  {([
                    ['overview', t('نظرة عامة', 'Overview')],
                    ['courses', `${t('الدورات', 'Courses')} (${detail.stats?.courses ?? 0})`],
                    ['students', `${t('الطلاب', 'Students')} (${detail.stats?.students ?? 0})`],
                    ['sales', t('المبيعات', 'Sales')],
                  ] as const).map(([key, label]) => (
                    <button key={key} onClick={() => setDetailTab(key)} className={`px-4 py-1.5 rounded-full text-xs font-medium transition-all shrink-0 ${detailTab === key ? 'bg-teal-600 text-white shadow' : 'text-gray-500 hover:text-gray-800'}`}>
                      {label}
                    </button>
                  ))}
                </div>
              </div>

              <div className="flex-1 overflow-y-auto pr-1 space-y-4">
                {detailTab === 'overview' && (
                  <>
                    {detail.tenant && (
                      <div className="p-4 rounded-xl border bg-gray-50/60">
                        <div className="flex items-center gap-2 mb-2">
                          <ExternalLink className="h-4 w-4 text-teal-600" />
                          <div className="text-sm font-semibold">{t('روابط المنصة', 'Platform links')}</div>
                        </div>
                        <div className="grid sm:grid-cols-2 gap-2">
                          <a href={platformUrl(detail.tenant.slug)} target="_blank" rel="noopener noreferrer"
                            className="group flex items-center gap-2 p-2.5 rounded-xl bg-white border text-sm hover:border-teal-400 transition-colors">
                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-teal-700"><ExternalLink className="h-4 w-4" /></span>
                            <span className="min-w-0 flex-1">
                              <span className="block text-xs font-semibold">{t('منصة المعلم', 'Teacher platform')}</span>
                              <span className="block text-[11px] text-gray-400 font-mono truncate" dir="ltr">{platformUrl(detail.tenant.slug)}</span>
                            </span>
                            <Button size="icon" variant="ghost" className="h-7 w-7 shrink-0 rounded-full"
                              onClick={(e) => { e.preventDefault(); e.stopPropagation(); copyText(platformUrl(detail.tenant.slug), t('تم نسخ رابط المنصة', 'Platform link copied')); }}>
                              <Copy className="h-3.5 w-3.5" />
                            </Button>
                          </a>
                          <a href={teacherLoginUrl()} target="_blank" rel="noopener noreferrer"
                            className="group flex items-center gap-2 p-2.5 rounded-xl bg-white border text-sm hover:border-teal-400 transition-colors">
                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600"><KeyRound className="h-4 w-4" /></span>
                            <span className="min-w-0 flex-1">
                              <span className="block text-xs font-semibold">{t('لوحة المعلم', 'Teacher dashboard')}</span>
                              <span className="block text-[11px] text-gray-400 font-mono truncate" dir="ltr">{teacherLoginUrl()}</span>
                            </span>
                            <Button size="icon" variant="ghost" className="h-7 w-7 shrink-0 rounded-full"
                              onClick={(e) => { e.preventDefault(); e.stopPropagation(); copyText(teacherLoginUrl(), t('تم نسخ رابط اللوحة', 'Dashboard link copied')); }}>
                              <Copy className="h-3.5 w-3.5" />
                            </Button>
                          </a>
                        </div>
                      </div>
                    )}
                    <div className="p-4 rounded-xl border bg-gradient-to-br from-teal-50/60 to-white">
                      <div className="flex items-center gap-2 mb-3">
                        <Crown className="h-4 w-4 text-amber-500" />
                        <div className="text-sm font-semibold">{t('اشتراك المعلم', 'Teacher subscription')}</div>
                        <div className="ms-auto">{detailSubscriptions()[0] ? subBadge(detailSubscriptions()[0]) : subBadge(null)}</div>
                      </div>
                      {detailSubscriptions().length === 0 ? (
                        <p className="text-sm text-gray-400">{t('لا يوجد اشتراك لهذا المعلم', 'No subscription for this teacher')}</p>
                      ) : (
                        <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                          {detailSubscriptions().map((s: any, i: number) => (
                            <div key={s.id || i} className="p-3 rounded-xl bg-white border text-sm">
                              <div className="text-xs text-gray-400 mb-1">{t('الخطة', 'Plan')}</div>
                              <div className="font-bold flex items-center gap-1.5">
                                <Crown className="h-3.5 w-3.5 text-amber-500" />
                                {s.plan_name || s.plan?.name || t('—', '—')}
                                {s.plan?.monthly_price ? <span className="text-xs font-normal text-gray-500" dir="ltr">({s.plan.monthly_price} {s.plan.currency}/م)</span> : null}
                              </div>
                              <div className="text-[11px] text-gray-400 mt-1">
                                {t('الدورة', 'Billing')}: {s.billing_cycle === 'yearly' ? t('سنوي', 'Yearly') : t('شهري', 'Monthly')} · {t('تجديد تلقائي', 'Auto-renew')}: {s.auto_renew ? t('نعم', 'Yes') : t('لا', 'No')}
                              </div>
                              <div className="grid grid-cols-2 gap-2 mt-2 text-[11px]">
                                <div>
                                  <div className="text-gray-400">{t('البداية', 'Start')}</div>
                                  <b className="font-medium">{s.starts_at ? new Date(s.starts_at).toLocaleDateString() : '—'}</b>
                                </div>
                                <div>
                                  <div className="text-gray-400">{t('الانتهاء', 'End')}</div>
                                  <b className="font-medium">{s.ends_at ? new Date(s.ends_at).toLocaleDateString() : '—'}</b>
                                </div>
                                <div>
                                  <div className="text-gray-400">{t('نهاية التجربة', 'Trial end')}</div>
                                  <b className="font-medium">{s.trial_ends_at ? new Date(s.trial_ends_at).toLocaleDateString() : '—'}</b>
                                </div>
                              </div>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                      {[
                        { icon: BookOpen, label: t('الدورات', 'Courses'), value: detail.stats?.courses ?? 0, color: 'text-teal-600' },
                        { icon: GraduationCap, label: t('الطلاب', 'Students'), value: detail.stats?.students ?? 0, color: 'text-emerald-500' },
                        { icon: Layers, label: t('التسجيلات', 'Enrollments'), value: detail.stats?.enrollments ?? 0, color: 'text-violet-500' },
                        { icon: Wallet, label: t('إجمالي المبيعات', 'Total revenue'), value: `${detail.stats?.revenue ?? 0} ${detail.payments?.[0]?.currency || ''}`, color: 'text-amber-500' },
                        { icon: TrendingUp, label: t('مبيعات آخر 30 يوم', 'Revenue (30d)'), value: `${detail.stats?.revenue_30d ?? 0} ${detail.payments?.[0]?.currency || ''}`, color: 'text-emerald-500' },
                        { icon: ClipboardList, label: t('الاختبارات', 'Exams'), value: detail.stats?.exams ?? 0, color: 'text-rose-500' },
                        { icon: Star, label: t('متوسط الدرجات', 'Avg score'), value: `${detail.stats?.avg_score ?? 0}%`, color: 'text-sky-500' },
                        { icon: Award, label: t('نسبة النجاح', 'Pass rate'), value: `${detail.stats?.pass_rate ?? 0}%`, color: 'text-teal-600' },
                        { icon: Percent, label: t('إتمام الكورسات', 'Completion'), value: `${detail.stats?.completion_rate ?? 0}%`, color: 'text-green-600' },
                        { icon: Ticket, label: t('بطاقات مفعلة', 'Cards redeemed'), value: `${detail.stats?.cards_redeemed ?? 0} / ${detail.stats?.cards ?? 0}`, color: 'text-amber-600' },
                        { icon: FileText, label: t('الواجبات', 'Assignments'), value: detail.stats?.assignments ?? 0, color: 'text-orange-500' },
                        { icon: CreditCard, label: t('مدفوعات', 'Payments'), value: detail.stats?.payments_count ?? 0, color: 'text-violet-500' },
                      ].map((s, i) => (
                        <div key={i} className="p-3 rounded-xl bg-gray-50 text-sm">
                          <div className="flex items-center gap-1.5 text-gray-500 text-xs"><s.icon className="h-3.5 w-3.5" />{s.label}</div>
                          <div className={`font-bold text-base mt-1 ${s.color}`} dir="ltr">{s.value}</div>
                        </div>
                      ))}
                    </div>
                    <div className="grid sm:grid-cols-2 gap-3">
                      <div className="p-4 rounded-xl bg-gray-50 text-sm space-y-2">
                        <div className="font-semibold flex items-center gap-1.5"><Wallet className="h-4 w-4 text-amber-500" />{t('ملخص المبيعات', 'Sales summary')}</div>
                        <div className="flex justify-between"><span className="text-gray-500">{t('مدفوع', 'Paid')}</span><b className="text-emerald-600" dir="ltr">{detail.stats?.payments_paid ?? 0}</b></div>
                        <div className="flex justify-between"><span className="text-gray-500">{t('قيد الانتظار', 'Pending')}</span><b className="text-amber-600" dir="ltr">{detail.stats?.payments_pending ?? 0}</b></div>
                        <div className="flex justify-between"><span className="text-gray-500">{t('بطاقات متاحة', 'Cards available')}</span><b dir="ltr">{detail.stats?.cards_available ?? 0}</b></div>
                      </div>
                      <div className="p-4 rounded-xl bg-gray-50 text-sm space-y-2">
                        <div className="font-semibold flex items-center gap-1.5"><Timer className="h-4 w-4 text-sky-500" />{t('أداء الطلاب', 'Student performance')}</div>
                        <div className="flex justify-between"><span className="text-gray-500">{t('محاولات اختبار', 'Exam attempts')}</span><b dir="ltr">{detail.stats?.attempts ?? 0}</b></div>
                        <div className="flex justify-between"><span className="text-gray-500">{t('متوسط الدرجات', 'Avg score')}</span><b dir="ltr">{detail.stats?.avg_score ?? 0}%</b></div>
                        <div className="flex justify-between"><span className="text-gray-500">{t('نسبة النجاح', 'Pass rate')}</span><b dir="ltr">{detail.stats?.pass_rate ?? 0}%</b></div>
                      </div>
                    </div>
                  </>
                )}

                {detailTab === 'courses' && (
                  <div className="space-y-2">
                    {detail.courses?.length === 0 && <div className="text-center text-gray-400 py-10">{t('لا توجد دورات', 'No courses')}</div>}
                    {(detail.courses || []).map((c: any) => (
                      <div key={c.id} className="flex flex-wrap items-center gap-3 p-3 rounded-xl bg-gray-50 text-sm">
                        <div className="flex-1 min-w-[160px]">
                          <div className="font-medium flex items-center gap-2">{c.title}
                            <Badge className={c.is_published ? 'bg-emerald-100 text-emerald-700 border-0' : 'bg-gray-200 text-gray-500 border-0'}>
                              {c.is_published ? t('منشور', 'Published') : t('مسودة', 'Draft')}
                            </Badge>
                          </div>
                          <div className="text-xs text-gray-400 mt-0.5">{new Date(c.created_at).toLocaleDateString()}</div>
                        </div>
                        <div className="flex items-center gap-4 text-xs text-gray-500">
                          <span className="flex items-center gap-1"><Layers className="h-3.5 w-3.5 text-violet-400" />{c.sections_count} {t('أقسام', 'sec.')}</span>
                          <span className="flex items-center gap-1"><Play className="h-3.5 w-3.5 text-teal-400" />{c.lessons_count} {t('دروس', 'les.')}</span>
                          <span className="flex items-center gap-1"><GraduationCap className="h-3.5 w-3.5 text-emerald-400" />{c.students_count} {t('طلاب', 'std.')}</span>
                        </div>
                        <div className="font-bold" dir="ltr">{c.price ? `${c.price} ${c.currency || ''}` : t('مدفوع', 'Paid')}</div>
                      </div>
                    ))}
                  </div>
                )}

                {detailTab === 'students' && (
                  <div className="space-y-2">
                    {detail.students?.length === 0 && <div className="text-center text-gray-400 py-10">{t('لا يوجد طلاب', 'No students')}</div>}
                    {(detail.students || []).map((s: any) => (
                      <div key={s.id} className="flex flex-wrap items-center gap-3 p-3 rounded-xl bg-gray-50 text-sm">
                        <span className="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold shrink-0">{(s.name || '?')[0]}</span>
                        <div className="flex-1 min-w-[160px]">
                          <div className="font-medium">{s.name}</div>
                          <div className="text-xs text-gray-400" dir="ltr">{s.email}</div>
                        </div>
                        <div className="flex items-center gap-3 text-xs text-gray-500">
                          <span className="flex items-center gap-1"><BookOpen className="h-3.5 w-3.5 text-teal-400" />{s.courses_count} {t('كورسات', 'courses')}</span>
                          <span className="flex items-center gap-1"><Award className="h-3.5 w-3.5 text-emerald-400" />{s.completed_count} {t('مكتمل', 'done')}</span>
                        </div>
                        <span className="text-[11px] text-gray-400">{s.joined_at ? new Date(s.joined_at).toLocaleDateString() : ''}</span>
                      </div>
                    ))}
                  </div>
                )}

                {detailTab === 'sales' && (
                  <div className="grid lg:grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <div className="text-sm font-semibold">{t('المبيعات حسب الكورس', 'Revenue by course')}</div>
                      {(detail.revenue_by_course || []).length === 0 && <div className="text-center text-gray-400 py-8 text-sm">{t('لا مبيعات بعد', 'No sales yet')}</div>}
                      {(detail.revenue_by_course || []).map((r: any) => (
                        <div key={r.course_id} className="flex items-center justify-between p-3 rounded-xl bg-gray-50 text-sm">
                          <span className="truncate pe-2">{r.title}</span>
                          <span className="text-xs text-gray-500 shrink-0">{r.sales} {t('بيع', 'sales')} · <b className="text-emerald-600" dir="ltr">{r.revenue}</b></span>
                        </div>
                      ))}
                    </div>
                    <div className="space-y-2">
                      <div className="text-sm font-semibold">{t('أحدث المدفوعات', 'Recent payments')}</div>
                      {(detail.payments || []).length === 0 && <div className="text-center text-gray-400 py-8 text-sm">{t('لا مدفوعات', 'No payments')}</div>}
                      {(detail.payments || []).map((p: any) => (
                        <div key={p.id} className="flex items-center justify-between p-3 rounded-xl bg-gray-50 text-sm">
                          <span className="flex items-center gap-2"><CreditCard className="h-4 w-4 text-violet-400" />{p.gateway || '—'}</span>
                          <span className="flex items-center gap-3">
                            <Badge className={p.status === 'paid' ? 'bg-emerald-100 text-emerald-700 border-0' : p.status === 'pending' ? 'bg-amber-100 text-amber-700 border-0' : 'bg-rose-100 text-rose-600 border-0'}>{p.status}</Badge>
                            <b dir="ltr">{p.amount} {p.currency}</b>
                          </span>
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
