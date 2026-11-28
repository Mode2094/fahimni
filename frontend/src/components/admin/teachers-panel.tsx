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
import { Alert } from '@/components/ui/alert';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { confirmDialog } from '@/components/ui/confirm-dialog';
import AdminSubscriptionMetrics from '@/components/admin/subscription-metrics';
import ManualSubscriptionModal from '@/components/admin/manual-subscription-modal';
import TeacherDomainModal from '@/components/admin/teacher-domain-modal';
import {
  Users, Plus, Search, Trash2, Loader2, Mail, Crown, Ban, CheckCircle2,
  BookOpen, GraduationCap, CreditCard, Ticket, Eye, KeyRound,
  Wallet, TrendingUp, ClipboardList, FileText, Percent, Layers, Timer, Award, Star, Play,
  ExternalLink, Copy, Phone, MessageCircle, CalendarPlus, History, AlertCircle,
  ShieldCheck, Globe, LogIn,
} from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminTeachersPanel() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [teachers, setTeachers] = useState<any[]>([]);
  const [loadError, setLoadError] = useState(false);
  const [subscriptionMetrics, setSubscriptionMetrics] = useState<any>(null);
  const [plans, setPlans] = useState<any[]>([]);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('');
  const [subscriptionStateFilter, setSubscriptionStateFilter] = useState('');
  const [filtersReady, setFiltersReady] = useState(false);
  const [showForm, setShowForm] = useState(false);
  const [detail, setDetail] = useState<any>(null);
  const [detailTab, setDetailTab] = useState<'overview' | 'courses' | 'students' | 'sales'>('overview');
  const [form, setForm] = useState({ name: '', email: '', phone: '', password: '', plan_id: '' });
  const [generatedPassword, setGeneratedPassword] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [extendOpen, setExtendOpen] = useState(false);
  const [extendMode, setExtendMode] = useState<'trial' | 'access'>('access');
  const [extendDays, setExtendDays] = useState('7');
  const [extendRequestId, setExtendRequestId] = useState('');
  const [extending, setExtending] = useState(false);
  const [showHistory, setShowHistory] = useState(false);
  const [manualOpen, setManualOpen] = useState(false);
  const [domainOpen, setDomainOpen] = useState(false);
  const [supportBusy, setSupportBusy] = useState(false);

  const headers = () => ({ 'X-Tenant-Id': '', Accept: 'application/json' });

  const load = useCallback(() => {
    const q = new URLSearchParams();
    if (search) q.set('search', search);
    if (statusFilter === 'active' || statusFilter === 'inactive') q.set('is_active', statusFilter === 'active' ? '1' : '0');
    if (subscriptionStateFilter) q.set('subscription_state', subscriptionStateFilter);
    fetch(`${API}/admin/teachers?${q}`, { headers: headers(), cache: 'no-store' })
      .then(r => { if (!r.ok) throw new Error(); return r.json(); })
      .then(d => {
        setTeachers(d.teachers || []);
        setSubscriptionMetrics(d.subscription_metrics || null);
        setLoadError(false);
      })
      .catch(() => setLoadError(true))
      .finally(() => setLoading(false));
  }, [search, statusFilter, subscriptionStateFilter]);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const state = params.get('subscription_state') || '';
    const allowedStates = ['valid_trial', 'paid', 'admin', 'expired', 'unverified', 'effective'];

    if (allowedStates.includes(state)) setSubscriptionStateFilter(state);
    const isActive = params.get('is_active');
    if (isActive === '1') setStatusFilter('active');
    if (isActive === '0') setStatusFilter('inactive');
    setFiltersReady(true);
  }, []);

  useEffect(() => {
    if (!filtersReady || !check()) return;
    const id = setTimeout(load, 250);
    return () => clearTimeout(id);
  }, [filtersReady, search, statusFilter, subscriptionStateFilter, load]);

  useEffect(() => {
    if (!filtersReady || !check()) return;
    fetch(`${API}/admin/plans`, { headers: headers(), cache: 'no-store' })
      .then(r => r.json()).then(d => setPlans(d.plans || [])).catch(() => {});
  }, [filtersReady, check()]);

  const create = async () => {
    if (!form.name || !form.email) { toast.error(t('أكمل الاسم والبريد', 'Fill name & email')); return; }
    if (!form.phone.trim()) { toast.error(t('رقم الهاتف مطلوب', 'Phone number is required')); return; }
    setBusy(true);
    try {
      const res = await fetch(`${API}/admin/teachers`, {
        method: 'POST', headers: { 'Content-Type': 'application/json', ...headers() },
        body: JSON.stringify({ name: form.name, email: form.email, phone: form.phone, password: form.password || undefined, plan_id: form.plan_id || undefined }),
      });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      setGeneratedPassword(d.generated_password || '');
      toast.success(t('تم إنشاء المعلم', 'Teacher created'));
      setShowForm(false); setForm({ name: '', email: '', phone: '', password: '', plan_id: '' });
      load();
    } catch { toast.error(t('تعذّر إتمام العملية، حاول مرة أخرى', 'Could not complete the action, please try again')); } finally { setBusy(false); }
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
    } catch { toast.error(t('تعذّر إتمام العملية، حاول مرة أخرى', 'Could not complete the action, please try again')); } finally { setBusyId(null); }
  };

  const remove = async (teach: any) => {
    if (!(await confirmDialog({ description: t(`حذف المعلم «${teach.name}»؟ سيتم إيقاف منصته وحسابه.`, `Delete "${teach.name}"? Their platform & account will be suspended.`) }))) return;
    setBusyId(teach.id);
    try {
      const res = await fetch(`${API}/admin/teachers/${teach.id}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(t('تم الحذف', 'Deleted'));
      load();
    } catch { toast.error(t('تعذّر إتمام العملية، حاول مرة أخرى', 'Could not complete the action, please try again')); } finally { setBusyId(null); }
  };

  const openDetail = async (teach: any) => {
    try {
      setDetailTab('overview');
      const d = await fetch(`${API}/admin/teachers/${teach.id}`, { headers: headers() }).then(r => r.json());

      // Manual activation/renewal history (independent of the payment archive).
      let manualHistory: any[] = [];
      try {
        const h = await fetch(`${API}/admin/teachers/${teach.id}/subscription/history`, { headers: headers() }).then(r => r.json());
        manualHistory = h.operations || [];
      } catch { /* history is best-effort */ }

      setDetail({
        ...d,
        manual_history: manualHistory,
        teacher: {
          ...d.teacher,
          subscription_summary: teach.subscription_summary ?? d.teacher?.subscription_summary,
        },
      });
    } catch { toast.error(t('تعذّر إتمام العملية، حاول مرة أخرى', 'Could not complete the action, please try again')); }
  };

  const subBadge = (s: any, summary?: any) => {
    if (summary?.state && summary.state !== 'none') {
      const state = summary.state;
      const styles: Record<string, string> = {
        paid: 'bg-emerald-100 text-emerald-700 border-0',
        valid_trial: 'bg-sky-100 text-sky-700 border-0',
        admin: 'bg-teal-100 text-teal-800 border-0',
        expired: 'bg-rose-100 text-rose-600 border-0',
        pending: 'bg-amber-100 text-amber-700 border-0',
        unverified: 'bg-orange-100 text-orange-700 border-0',
      };
      const labels: Record<string, string> = {
        paid: t('مشترك مدفوع', 'Paid subscriber'),
        valid_trial: t('تجربة مجانية', 'Free trial'),
        admin: t('وصول إداري', 'Administrative access'),
        expired: t('منتهي', 'Expired'),
        pending: t('قيد الدفع', 'Pending payment'),
        unverified: t('وصول غير مثبت الدفع', 'Unverified access'),
      };
      const endDate = state === 'valid_trial'
        ? summary.trial_ends_at
        : state === 'admin'
          ? summary.ends_at
          : null;
      const formattedEnd = endDate
        ? new Date(endDate).toLocaleDateString(locale === 'ar' ? 'ar' : 'en')
        : null;

      return (
        <div className="flex flex-col items-start gap-1">
          <Badge className={styles[state] || 'bg-gray-100 text-gray-500 border-0'}>
            {summary.plan_name ? `${summary.plan_name} · ` : ''}{labels[state] || state}
          </Badge>
          {formattedEnd && (
            <span className="text-[11px] text-muted-foreground" data-testid={state === 'valid_trial' ? 'teacher-trial-end' : 'teacher-admin-end'}>
              {state === 'valid_trial' ? t('تنتهي التجربة', 'Trial ends') : t('ينتهي الوصول', 'Access ends')}: {formattedEnd}
            </span>
          )}
        </div>
      );
    }

    if (!s) return <Badge className="bg-gray-100 text-gray-500 border-0">{t('بدون خطة', 'No plan')}</Badge>;
    const rawStatus = s.status === 'canceled' || s.status === 'cancelled' ? 'canceled' : s.status;
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
    if (rawStatus === 'trialing' && (!s.trial_ends_at || new Date(s.trial_ends_at).getTime() <= Date.now())) {
      return <Badge className={map.expired}>{planName} · {t('منتهي', 'Expired')}</Badge>;
    }

    return <Badge className={map[rawStatus] || ''}>{planName} · {label[rawStatus] || rawStatus}</Badge>;
  };

  const detailSubscriptions = (): any[] => {
    const list = detail?.tenant?.subscriptions || [];
    return list.length ? list : (detail?.teacher?.subscription ? [detail.teacher.subscription] : []);
  };

  const platformUrl = (slug?: string) => (slug ? `${window.location.origin}/t/${slug}` : '');
  const teacherLoginUrl = () => `${window.location.origin}/teacher/login`;

  const hasPhone = (value?: string | null) => !!(value && String(value).trim());
  const whatsappUrl = (value: string) => `https://wa.me/${String(value).replace(/\D/g, '')}`;

  const copyText = async (text: string, msg: string) => {
    try {
      await navigator.clipboard.writeText(text);
      toast.success(msg);
    } catch { toast.error(t('تعذر النسخ', 'Cannot copy')); }
  };

  // ── Subscription extension (Super Admin only) ──────────────────────────
  const isAdminExtended = (s: any) => !!s?.admin_ends_at && new Date(s.admin_ends_at).getTime() > Date.now();
  const fmtDate = (v?: string | null) => (v ? new Date(v).toLocaleDateString() : '—');

  const accessSourceLabel = (src?: string) => {
    if (src === 'freemius') return t('اشتراك مدفوع (Freemius)', 'Paid (Freemius)');
    if (src === 'legacy') return t('اشتراك محلي', 'Local subscription');
    if (src === 'admin') return t('تمديد إداري', 'Admin extension');
    return t('لا يوجد وصول', 'No access');
  };

  const paymentStatusLabel = (status?: string) => {
    const map: Record<string, string> = {
      active: t('نشط', 'Active'), trialing: t('تجربة', 'Trial'), expired: t('منتهي', 'Expired'),
      canceled: t('ملغى', 'Canceled'), pending: t('قيد الدفع', 'Pending'),
    };
    return status ? (map[status] || status) : t('لا يوجد', 'None');
  };

  const manualOperationLabel = (type?: string) => {
    const map: Record<string, [string, string]> = {
      activate: ['تفعيل مدفوع', 'Paid activation'],
      renew: ['تجديد', 'Renewal'],
      upgrade: ['ترقية', 'Upgrade'],
      downgrade: ['تخفيض', 'Downgrade'],
      plan_change: ['تغيير الخطة', 'Plan change'],
      extend: ['تمديد', 'Extension'],
      free_grant: ['منح مجاني', 'Free grant'],
    };
    const entry = type ? map[type] : undefined;
    return entry ? t(entry[0], entry[1]) : (type || '—');
  };

  const manualPaymentMethodLabel = (method?: string | null) => {
    const map: Record<string, [string, string]> = {
      cliq: ['كليك', 'CliQ'], bank_transfer: ['حوالة بنكية', 'Bank transfer'],
      cash: ['نقدًا', 'Cash'], other: ['أخرى', 'Other'],
    };
    const entry = method ? map[method] : undefined;
    return entry ? t(entry[0], entry[1]) : (method || '—');
  };

  const newRequestId = () => (globalThis.crypto?.randomUUID?.() ?? `req_${Date.now()}_${Math.random().toString(36).slice(2)}`);

  // The row the admin acts on: the newest one that exists (access is resolved
  // across ALL rows server-side, so any row is a valid target). If the loaded
  // list is empty for any reason but the server still reports a subscription
  // id, fall back to it so the extend dialog can never dead-end with a
  // misleading "no subscription to extend".
  const accessFallbackSub = (): any | null => {
    const sid = detail?.access?.subscription_id;
    if (!sid) return null;
    return {
      id: sid,
      trial_ends_at: detail?.access?.trial_ends_at ?? null,
      ends_at: detail?.access?.access_ends_at ?? null,
      admin_ends_at: detail?.access?.admin_ends_at ?? null,
    };
  };

  const extendTargetSub = () => detailSubscriptions().find((s: any) => s?.id) || accessFallbackSub();

  // The row that owns the FREE TRIAL (`trial_ends_at`). The trial endpoint
  // must receive that specific row — not a provider-owned mirror — or it
  // correctly refuses to touch a paid subscription.
  const trialTargetSub = () => detailSubscriptions().find((s: any) => s?.trial_ends_at) || extendTargetSub();
  const extendTarget = () => (extendMode === 'trial' ? trialTargetSub() : extendTargetSub());

  // Date the extension is anchored on: the trial end (trial mode) or the
  // current effective access end (admin-grant mode). Live preview only.
  const extendCurrentEnd = (): string | null => {
    if (extendMode === 'trial') return detail?.access?.trial_ends_at || trialTargetSub()?.trial_ends_at || null;
    return detail?.access?.access_ends_at || extendTargetSub()?.admin_ends_at || extendTargetSub()?.ends_at || null;
  };

  const previewEnd = (): string | null => {
    const days = Number(extendDays);
    if (!Number.isFinite(days) || days < 1) return null;
    const cur = extendCurrentEnd();
    // Active window → add to its end; lapsed → start from now. Mirrors the
    // server rule so the shown date equals the saved date.
    const baseMs = cur ? Math.max(new Date(cur).getTime(), Date.now()) : Date.now();
    return new Date(baseMs + days * 86400000).toISOString();
  };

  const fmtDateTime = (v?: string | null) => (v ? new Date(v).toLocaleString() : '—');

  const openExtend = (mode: 'trial' | 'access') => {
    const target = mode === 'trial' ? trialTargetSub() : extendTargetSub();
    if (!target) { toast.error(t('لا يوجد اشتراك لتمديده', 'No subscription to extend')); return; }
    setExtendMode(mode);
    setExtendDays(mode === 'trial' ? '7' : '30');
    setExtendRequestId(newRequestId());
    setExtendOpen(true);
  };

  /**
   * Enter the teacher's real dashboard in a NEW TAB as a Super Admin support
   * session: mint a one-time token, then open the entry route which redeems it
   * and lands on the teacher panel with the support banner on top.
   */
  const enterSupport = async () => {
    const teacher = detail?.teacher;
    if (!teacher) return;

    setSupportBusy(true);
    try {
      const res = await fetch(`${API}/admin/support/${teacher.id}/begin`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', ...headers() },
        body: JSON.stringify({ reason: 'admin_support_panel' }),
      });
      const d = await res.json().catch(() => ({}));
      if (!res.ok) {
        toast.error(d.message || t('تعذّر بدء جلسة الدعم', 'Could not start the support session'));
        return;
      }
      const tab = window.open(d.enter_path, '_blank', 'noopener');
      if (!tab) {
        toast.error(t('يرجى السماح بالنوافذ المنبثقة لفتح لوحة المعلم.', 'Please allow pop-ups to open the teacher panel.'));
        return;
      }
      toast.success(t('تم فتح لوحة المعلم في تبويب جديد.', 'The teacher panel was opened in a new tab.'));
    } catch {
      toast.error(t('تعذّر بدء جلسة الدعم', 'Could not start the support session'));
    } finally {
      setSupportBusy(false);
    }
  };

  const extend = async () => {
    const days = Number(extendDays);
    if (!Number.isInteger(days) || days < 1) { toast.error(t('أدخل عددًا صحيحًا موجبًا من الأيام', 'Enter a valid positive whole number of days')); return; }
    const sub = extendTarget();
    if (!sub) { toast.error(t('لا يوجد اشتراك لتمديده', 'No subscription to extend')); return; }

    const isTrial = extendMode === 'trial';

    if (!(await confirmDialog({
      description: isTrial
        ? t(
            `تمديد التجربة المجانية لهذا المعلم ${days} يومًا دون أي عملية دفع ودون تغيير خطته؟`,
            `Extend this teacher's free trial by ${days} days with no charge and no plan change?`,
          )
        : t(
            `تمديد وصول هذا المعلم ${days} يومًا دون أي عملية دفع، وتفعيل حسابه فورًا؟`,
            `Extend this teacher's access by ${days} days with no charge and activate the account immediately?`,
          ),
    }))) return;

    setExtending(true);
    try {
      const path = isTrial ? 'extend-trial' : 'extend';
      const res = await fetch(`${API}/admin/subscriptions/${sub.id}/${path}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', ...headers() },
        body: JSON.stringify({ days, request_id: extendRequestId || undefined }),
      });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || t('تعذر تمديد الاشتراك', 'Could not extend the subscription')); return; }
      const raw = isTrial ? (d.trial_ends_at || d.access?.trial_ends_at) : d.access_ends_at;
      const end = raw ? new Date(raw).toLocaleDateString() : '';
      toast.success(d.already_applied
        ? t('تم تطبيق هذا التمديد مسبقًا', 'This extension was already applied')
        : isTrial
          ? t(`تم تمديد التجربة المجانية حتى ${end}`, `Free trial extended until ${end}`)
          : t(`تم تمديد وصول المعلم حتى ${end} وفُعّل حسابه فورًا`, `Teacher access extended until ${end} and the account is active now`));
      setExtendOpen(false);
      if (detail?.teacher?.id) await openDetail({ id: detail.teacher.id });
    } catch { toast.error(t('تعذر الاتصال', 'Cannot connect')); }
    finally { setExtending(false); }
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

      <AdminSubscriptionMetrics metrics={subscriptionMetrics} />

      {/* Filters */}
      <div className="flex flex-wrap gap-3 items-center">
        <div className="relative max-w-md flex-1 min-w-[220px]">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
          <Input placeholder={t('بحث بالاسم أو البريد أو الهاتف...', 'Search name, email or phone...')} value={search} onChange={(e) => setSearch(e.target.value)} className="ps-9 h-10" />
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

      {loadError && (
        <Alert variant="error" icon={AlertCircle}>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <span>{t('تعذر تحميل قائمة المعلمين', 'Could not load the teachers list')}</span>
            <Button size="sm" variant="outline" onClick={load}>{t('إعادة المحاولة', 'Retry')}</Button>
          </div>
        </Alert>
      )}

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
                <p title={teach.email} className="text-sm text-gray-500 mt-1 flex items-center gap-1 truncate"><Mail className="h-3 w-3 shrink-0" /> {teach.email}</p>
                <div className="text-sm mt-1 flex items-center gap-1">
                  <Phone className="h-3 w-3 shrink-0 text-gray-400" />
                  {hasPhone(teach.phone) ? (
                    <span className="flex items-center gap-1 min-w-0">
                      <span dir="ltr" className="truncate">{teach.phone}</span>
                      <button title={t('نسخ الرقم', 'Copy number')} className="text-gray-400 hover:text-teal-600 shrink-0"
                        onClick={() => copyText(teach.phone, t('تم نسخ رقم الهاتف', 'Phone copied'))}>
                        <Copy className="h-3 w-3" />
                      </button>
                      <a title="WhatsApp" className="text-emerald-600 hover:text-emerald-700 shrink-0" href={whatsappUrl(teach.phone)} target="_blank" rel="noopener noreferrer">
                        <MessageCircle className="h-3.5 w-3.5" />
                      </a>
                    </span>
                  ) : (
                    <span className="text-gray-400">{t('غير متوفر', 'Not provided')}</span>
                  )}
                </div>
                {teach.tenant && (
                  <a href={platformUrl(teach.tenant.slug)} target="_blank" rel="noopener noreferrer"
                    className="text-xs text-teal-700 mt-1 font-mono flex items-center gap-1 hover:underline" dir="ltr"
                    title={t('فتح منصة المعلم في تبويب جديد', 'Open teacher platform in a new tab')}>
                    <ExternalLink className="h-3 w-3 shrink-0" />{window.location.origin}/t/{teach.tenant.slug}
                  </a>
                )}
                <div className="mt-3">{subBadge(teach.subscription, teach.subscription_summary)}</div>
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
            <div className="space-y-2"><Label>{t('رقم الهاتف', 'Phone number')}</Label><Input type="tel" inputMode="tel" dir="ltr" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className="h-11" placeholder="+962791234567" /></div>
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

      {/* Extend (trial or admin access) dialog */}
      <Dialog open={extendOpen} onOpenChange={(o) => !extending && setExtendOpen(o)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {extendMode === 'trial' ? t('تمديد التجربة المجانية', 'Extend free trial') : t('تمديد وصول المعلم', 'Extend teacher access')}
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-3">
            <div className="space-y-2">
              <Label>{t('خيارات سريعة', 'Quick options')}</Label>
              <div className="flex flex-wrap gap-1.5">
                {[1, 2, 7, 14, 30].map((d) => (
                  <button key={d} type="button"
                    onClick={() => setExtendDays(String(d))}
                    className={`px-3 py-1.5 rounded-full text-xs font-medium border transition-colors ${Number(extendDays) === d ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 hover:border-teal-400'}`}>
                    {d === 1 ? t('يوم', '1 day') : d === 2 ? t('يومان', '2 days') : t(`${d} يومًا`, `${d} days`)}
                  </button>
                ))}
              </div>
            </div>
            <div className="space-y-2">
              <Label>{t('أو أدخل عدد الأيام', 'Or enter a number of days')}</Label>
              <Input type="number" min={1} step={1} value={extendDays} onChange={(e) => setExtendDays(e.target.value)} className="h-11" data-testid="extend-days" />
            </div>
            <div className="p-3 rounded-xl bg-gray-50 border text-sm space-y-1.5" data-testid="extend-preview">
              <div className="flex justify-between gap-2">
                <span className="text-gray-500">{extendMode === 'trial' ? t('نهاية التجربة الحالية', 'Current trial end') : t('نهاية الوصول الحالية', 'Current access end')}</span>
                <b dir="ltr" className="text-gray-700">{fmtDateTime(extendCurrentEnd())}</b>
              </div>
              <div className="flex justify-between gap-2">
                <span className="text-gray-500">{extendMode === 'trial' ? t('نهاية التجربة الجديدة', 'New trial end') : t('نهاية الوصول الجديدة', 'New access end')}</span>
                <b dir="ltr" className="text-teal-700">{fmtDateTime(previewEnd())}</b>
              </div>
            </div>
            <p className="text-xs text-gray-500">
              {extendMode === 'trial'
                ? t(
                    'يمدّد هذا التجربة المجانية لهذا المعلم فقط: تُضاف الأيام إلى نهاية التجربة الحالية، وإن كانت منتهية فتبدأ من الآن وتُعاد التهيئة. لا تحويل إلى اشتراك مدفوع، ولا فاتورة، ولا تغيير في الخطة أو حدودها أو بيانات المعلم وطلابه.',
                    'This extends the free trial for this teacher only: days are added to the current trial end, or start from now if it lapsed. No paid conversion, no invoice, and no change to the plan, its limits, or the teacher/students data.',
                  )
                : t(
                    'يمنح هذا التمديد وصولًا فوريًا لاستخدام المنصة حتى التاريخ الجديد، دون أي عملية دفع لدى Freemius ودون تغيير حالة الفوترة. تبقى المدة المدفوعة المتبقية كما هي ويُضاف التمديد بعدها.',
                    'This extension grants immediate platform access until the new date — with no Freemius charge and no change to the billing state. Any remaining paid period is preserved and the extension is added after it.',
                  )}
            </p>
          </div>
          <DialogFooter>
            <Button variant="outline" disabled={extending} onClick={() => setExtendOpen(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button disabled={extending || !Number.isInteger(Number(extendDays)) || Number(extendDays) < 1} className="gap-2" data-testid="extend-confirm" onClick={extend}>
              {extending ? <Loader2 className="h-4 w-4 animate-spin" /> : <CalendarPlus className="h-4 w-4" />}
              {extendMode === 'trial' ? t('تمديد التجربة', 'Extend trial') : t('تمديد', 'Extend')}
            </Button>
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

              <div className="flex-1 overflow-y-auto pe-1 space-y-4">
                {detailTab === 'overview' && (
                  <>
                    {/* Contact & account data — name, phone and email are the
                        mandatory teacher identifiers; both values are copyable
                        and the phone opens a WhatsApp chat. Missing values are
                        shown explicitly instead of being hidden. */}
                    <div className="p-4 rounded-xl border bg-white">
                      <div className="flex items-center gap-2 mb-3">
                        <Phone className="h-4 w-4 text-teal-600" />
                        <div className="text-sm font-semibold">{t('بيانات التواصل', 'Contact details')}</div>
                      </div>
                      <div className="grid sm:grid-cols-3 gap-3 text-sm">
                        <div className="p-3 rounded-xl bg-gray-50 min-w-0">
                          <div className="text-[11px] text-gray-400">{t('الاسم الكامل', 'Full name')}</div>
                          <div className="font-medium mt-0.5 truncate">{detail.teacher?.name || '—'}</div>
                        </div>
                        <div className="p-3 rounded-xl bg-gray-50 min-w-0">
                          <div className="text-[11px] text-gray-400">{t('رقم الهاتف', 'Phone number')}</div>
                          {hasPhone(detail.teacher?.phone) ? (
                            <div className="flex items-center gap-1.5 mt-0.5 min-w-0">
                              <span dir="ltr" className="font-medium truncate">{detail.teacher.phone}</span>
                              <button title={t('نسخ الرقم', 'Copy number')} className="text-gray-400 hover:text-teal-600 shrink-0"
                                onClick={() => copyText(detail.teacher.phone, t('تم نسخ رقم الهاتف', 'Phone copied'))}>
                                <Copy className="h-3.5 w-3.5" />
                              </button>
                              <a title="WhatsApp" className="text-emerald-600 hover:text-emerald-700 shrink-0" href={whatsappUrl(detail.teacher.phone)} target="_blank" rel="noopener noreferrer">
                                <MessageCircle className="h-4 w-4" />
                              </a>
                            </div>
                          ) : (
                            <div className="font-medium mt-0.5 text-gray-400">{t('غير متوفر', 'Not provided')}</div>
                          )}
                        </div>
                        <div className="p-3 rounded-xl bg-gray-50 min-w-0">
                          <div className="text-[11px] text-gray-400">{t('البريد الإلكتروني', 'Email')}</div>
                          <div className="flex items-center gap-1.5 mt-0.5 min-w-0">
                            <span dir="ltr" className="font-medium truncate">{detail.teacher?.email}</span>
                            <button title={t('نسخ البريد', 'Copy email')} className="text-gray-400 hover:text-teal-600 shrink-0"
                              onClick={() => copyText(detail.teacher.email, t('تم نسخ البريد الإلكتروني', 'Email copied'))}>
                              <Copy className="h-3.5 w-3.5" />
                            </button>
                          </div>
                        </div>
                      </div>
                      <div className="flex flex-wrap items-center gap-x-5 gap-y-2 mt-3 text-[11px] text-gray-500">
                        <span className="flex items-center gap-1.5">
                          {t('حالة الحساب', 'Account status')}:
                          <Badge className={detail.teacher?.is_active ? 'bg-emerald-100 text-emerald-700 border-0' : 'bg-rose-100 text-rose-600 border-0'}>
                            {detail.teacher?.is_active ? t('نشط', 'Active') : t('موقوف', 'Suspended')}
                          </Badge>
                        </span>
                        <span>{t('تاريخ التسجيل', 'Registered')}: <b className="font-medium">{detail.teacher?.created_at ? new Date(detail.teacher.created_at).toLocaleDateString() : '—'}</b></span>
                        <span>{t('آخر نشاط', 'Last activity')}: <b className="font-medium">{detail.teacher?.last_login_at ? new Date(detail.teacher.last_login_at).toLocaleString() : t('غير متوفر', 'Not provided')}</b></span>
                      </div>
                    </div>
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
                    {detail.tenant && (
                      <div className="p-4 rounded-xl border bg-sky-50/40">
                        <div className="flex items-center gap-2 mb-3">
                          <ShieldCheck className="h-4 w-4 text-sky-600" />
                          <div className="text-sm font-semibold">{t('إدارة منصة المعلم والدعم', 'Manage the teacher platform & support')}</div>
                        </div>
                        <div className="grid sm:grid-cols-2 gap-2">
                          <button type="button" onClick={enterSupport} disabled={supportBusy} data-testid="enter-support"
                            className="group flex items-center gap-2 p-3 rounded-xl bg-white border text-sm hover:border-sky-400 transition-colors text-start disabled:opacity-60">
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-700">
                              {supportBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <LogIn className="h-4 w-4" />}
                            </span>
                            <span className="min-w-0 flex-1">
                              <span className="block text-xs font-semibold">{t('الدخول إلى لوحة المعلم', 'Enter the teacher dashboard')}</span>
                              <span className="block text-[11px] text-gray-400">{t('فتح لوحة المعلم في تبويب جديد بوضع الدعم الإداري', 'Open the teacher dashboard in a new tab in support mode')}</span>
                            </span>
                          </button>
                          <button type="button" onClick={() => setDomainOpen(true)} data-testid="open-domain"
                            className="group flex items-center gap-2 p-3 rounded-xl bg-white border text-sm hover:border-teal-400 transition-colors text-start">
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-teal-700"><Globe className="h-4 w-4" /></span>
                            <span className="min-w-0 flex-1">
                              <span className="block text-xs font-semibold">{t('الدومين المخصص', 'Custom domain')}</span>
                              <span className="block text-[11px] text-gray-400">{t('ربط دومين المعلم والتحقق منه وإدارة SSL', 'Connect, verify and manage the teacher’s domain & SSL')}</span>
                            </span>
                          </button>
                        </div>
                        <p className="text-[11px] text-gray-500 mt-2 flex items-center gap-1.5">
                          <AlertCircle className="h-3 w-3 shrink-0" />
                          {t('يُسجَّل كل تعديل بهوية السوبر أدمن. العمليات المالية والأمنية الحساسة تبقى محظورة داخل وضع الدعم وتُنفَّذ من لوحة الإدارة.', 'Every change is logged under the Super Admin. Money/security-sensitive actions stay blocked in support mode and are performed from the admin panel.')}
                        </p>
                      </div>
                    )}
                    <div className="p-4 rounded-xl border bg-gradient-to-br from-teal-50/60 to-white space-y-3">
                      <div className="flex items-center gap-2 flex-wrap">
                        <Crown className="h-4 w-4 text-amber-500" />
                        <div className="text-sm font-semibold">{t('اشتراك المعلم والوصول', 'Teacher subscription & access')}</div>
                        <div className="ms-auto flex items-center gap-2">
                          {detailSubscriptions()[0] ? subBadge(detailSubscriptions()[0]) : subBadge(null)}
                          {detail.tenant && (
                            <Button size="sm" className="gap-1.5 rounded-full bg-amber-500 hover:bg-amber-600 text-white" data-testid="manual-subscription"
                              onClick={() => setManualOpen(true)}>
                              <Wallet className="h-3.5 w-3.5" /> {t('تفعيل / إدارة الاشتراك يدويًا', 'Activate / manage manually')}
                            </Button>
                          )}
                          {detail.tenant && (
                            <Button size="sm" variant="outline" className="gap-1.5 rounded-full" data-testid="extend-access"
                              data-mode={detail.access?.can_extend_trial ? 'trial' : 'access'}
                              onClick={() => openExtend(detail.access?.can_extend_trial ? 'trial' : 'access')}>
                              <CalendarPlus className="h-3.5 w-3.5" /> {detail.access?.can_extend_trial ? t('تمديد التجربة', 'Extend trial') : t('تمديد الوصول', 'Extend access')}
                            </Button>
                          )}
                        </div>
                      </div>

                      {/* Real access state — deliberately separated from payment. */}
                      {detail.tenant && (
                        <div className={`grid grid-cols-2 gap-3 ${detail.access?.trial_ends_at ? 'sm:grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-3'}`}>
                          <div className="p-3 rounded-xl bg-white border text-sm">
                            <div className="text-xs text-gray-400">{t('حالة الوصول', 'Access')}</div>
                            <div className="font-bold mt-0.5 flex items-center gap-1" data-testid="access-status">
                              {detail.access?.access_active
                                ? <><CheckCircle2 className="h-4 w-4 text-emerald-500" /> {t('مفعّل', 'Active')}</>
                                : <><Ban className="h-4 w-4 text-rose-500" /> {t('غير مفعّل', 'Inactive')}</>}
                            </div>
                            <div className="text-[11px] text-gray-400 mt-1">{t('المصدر', 'Source')}: {accessSourceLabel(detail.access?.access_source)}</div>
                          </div>
                          <div className="p-3 rounded-xl bg-white border text-sm">
                            <div className="text-xs text-gray-400">{t('انتهاء الوصول', 'Access ends')}</div>
                            <div className="font-bold mt-0.5" dir="ltr">{fmtDate(detail.access?.access_ends_at)}</div>
                            <div className="text-[11px] text-gray-400 mt-1">{t('الأيام المتبقية', 'Days left')}: {detail.access?.days_left ?? '—'}</div>
                          </div>
                          {detail.access?.trial_ends_at && (
                            <div className="p-3 rounded-xl bg-white border text-sm" data-testid="trial-info">
                              <div className="text-xs text-gray-400">{t('نهاية التجربة المجانية', 'Free trial ends')}</div>
                              <div className="font-bold mt-0.5" dir="ltr">{fmtDate(detail.access.trial_ends_at)}</div>
                              <div className="text-[11px] mt-1">
                                {detail.access?.trial_active
                                  ? <span className="text-gray-400">{t('الأيام المتبقية', 'Days left')}: {detail.access?.trial_days_left ?? '—'}</span>
                                  : <span className="text-rose-500 font-medium">{t('انتهت التجربة — التمديد يبدأ من الآن', 'Trial ended — extension starts now')}</span>}
                              </div>
                            </div>
                          )}
                          <div className="p-3 rounded-xl bg-white border text-sm">
                            <div className="text-xs text-gray-400">{t('حالة الدفع', 'Payment')}</div>
                            <div className="font-bold mt-0.5">{paymentStatusLabel(detailSubscriptions()[0]?.status)}</div>
                            <div className="text-[11px] text-gray-400 mt-1">{t('حالة الفوترة الفعلية لدى المزوّد', 'Real billing state with the provider')}</div>
                          </div>
                        </div>
                      )}

                      {/* Administrative grant — clearly labelled and auditable. */}
                      {detail.access?.admin_extension && (
                        <div className="flex items-start gap-2 p-3 rounded-xl bg-teal-50 border border-teal-100 text-sm" data-testid="admin-access-banner">
                          <CalendarPlus className="h-4 w-4 text-teal-600 mt-0.5 shrink-0" />
                          <div>
                            <div className="font-semibold text-teal-800">{t('وصول ممنوح إداريًا (بغض النظر عن حالة الدفع)', 'Access granted administratively (independent of payment)')}</div>
                            <div className="text-[11px] text-teal-700 mt-0.5">
                              {t('حتى', 'Until')} <b dir="ltr">{fmtDate(detail.access?.admin_ends_at)}</b>
                              {detail.access?.admin_grant_days ? <> · {t('المدة', 'Duration')}: {detail.access.admin_grant_days} {t('يوم', 'days')}</> : null}
                              {detail.access?.admin_granted_by ? <> · {t('بواسطة', 'By')}: {detail.access.admin_granted_by}</> : null}
                              {detail.access?.admin_granted_at ? <> · {fmtDate(detail.access.admin_granted_at)}</> : null}
                            </div>
                          </div>
                        </div>
                      )}

                      {detailSubscriptions().length > 0 ? (
                        <div>
                          <button type="button" onClick={() => setShowHistory(v => !v)}
                            className="flex items-center gap-1.5 text-xs text-gray-500 hover:text-teal-600">
                            <History className="h-3.5 w-3.5" />
                            {showHistory
                              ? t('إخفاء سجل الاشتراكات', 'Hide subscription history')
                              : t(`عرض سجل الاشتراكات (${detailSubscriptions().length})`, `Show subscription history (${detailSubscriptions().length})`)}
                          </button>
                          {showHistory && (
                            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-3">
                              {detailSubscriptions().map((s: any, i: number) => (
                                <div key={s.id || i} className={`p-3 rounded-xl border text-sm ${isAdminExtended(s) ? 'bg-teal-50/50 border-teal-200' : 'bg-white'}`}>
                                  <div className="text-xs text-gray-400 mb-1">{t('الخطة', 'Plan')}</div>
                                  <div className="font-bold flex items-center gap-1.5">
                                    <Crown className="h-3.5 w-3.5 text-amber-500" />
                                    {s.plan_name || s.plan?.name || t('—', '—')}
                                    {s.plan?.monthly_price ? <span className="text-xs font-normal text-gray-500" dir="ltr">({s.plan.monthly_price} {s.plan.currency}/م)</span> : null}
                                  </div>
                                  <div className="text-[11px] text-gray-400 mt-1">
                                    {t('الدورة', 'Billing')}: {s.billing_cycle === 'yearly' ? t('سنوي', 'Yearly') : t('شهري', 'Monthly')} · {t('تجديد تلقائي', 'Auto-renew')}: {s.auto_renew ? t('نعم', 'Yes') : t('لا', 'No')}
                                  </div>
                                  <div className="text-[11px] text-gray-400 mt-1">{t('حالة الدفع', 'Payment')}: {paymentStatusLabel(s.status)}</div>
                                  <div className="grid grid-cols-2 gap-2 mt-2 text-[11px]">
                                    <div>
                                      <div className="text-gray-400">{t('البداية', 'Start')}</div>
                                      <b className="font-medium">{fmtDate(s.starts_at)}</b>
                                    </div>
                                    <div>
                                      <div className="text-gray-400">{t('الانتهاء', 'End')}</div>
                                      <b className="font-medium">{fmtDate(s.ends_at)}</b>
                                    </div>
                                    <div>
                                      <div className="text-gray-400">{t('نهاية التجربة', 'Trial end')}</div>
                                      <b className="font-medium">{fmtDate(s.trial_ends_at)}</b>
                                    </div>
                                  </div>
                                  {isAdminExtended(s) && (
                                    <div className="text-[11px] text-teal-700 mt-1 font-medium">
                                      {t('وصول إداري حتى', 'Admin access until')} {fmtDate(s.admin_ends_at)}
                                    </div>
                                  )}
                                </div>
                              ))}
                            </div>
                          )}
                        </div>
                      ) : (
                        <p className="text-sm text-gray-400">{t('لا يوجد اشتراك لهذا المعلم', 'No subscription for this teacher')}</p>
                      )}

                      {/* Manual activation / renewal history (audit trail). */}
                      {(detail.manual_history?.length ?? 0) > 0 && (
                        <div data-testid="manual-history">
                          <div className="text-xs font-semibold text-gray-500 mb-2 flex items-center gap-1.5">
                            <History className="h-3.5 w-3.5" /> {t('سجل العمليات اليدوية', 'Manual operations history')}
                          </div>
                          <div className="space-y-2">
                            {detail.manual_history.map((op: any) => (
                              <div key={op.id} className="p-3 rounded-xl bg-white border text-sm">
                                <div className="flex items-center flex-wrap gap-2">
                                  <Badge className="bg-amber-100 text-amber-800 border-0">{manualOperationLabel(op.operation_type)}</Badge>
                                  <b>{op.new_plan?.name || op.new_plan_id || '—'}</b>
                                  {op.is_free_grant
                                    ? <span className="text-[11px] text-gray-400">{t('بدون تحصيل', 'No charge')}</span>
                                    : <span className="text-[11px] text-emerald-700" dir="ltr">{op.amount} {op.currency} · {manualPaymentMethodLabel(op.payment_method)}</span>}
                                  <span className="ms-auto text-[11px] text-gray-400">{fmtDateTime(op.created_at)}</span>
                                </div>
                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-2 text-[11px]">
                                  <div><div className="text-gray-400">{t('الخطة السابقة', 'Previous plan')}</div><b className="font-medium">{op.previous_plan?.name || '—'}</b></div>
                                  <div><div className="text-gray-400">{t('البداية', 'Start')}</div><b className="font-medium">{fmtDate(op.starts_at)}</b></div>
                                  <div><div className="text-gray-400">{t('الانتهاء', 'End')}</div><b className="font-medium">{fmtDate(op.ends_at)}</b></div>
                                  <div><div className="text-gray-400">{t('بواسطة', 'By')}</div><b className="font-medium">{op.performed_by_name || '—'}</b></div>
                                </div>
                                {(op.payment_reference || op.reason) && (
                                  <div className="text-[11px] text-gray-500 mt-1.5 flex flex-wrap gap-x-3 gap-y-1">
                                    {op.payment_reference && <span>{t('المرجع', 'Reference')}: <b className="font-mono" dir="ltr">{op.payment_reference}</b></span>}
                                    {op.reason && <span>{t('ملاحظات', 'Notes')}: {op.reason}</span>}
                                  </div>
                                )}
                              </div>
                            ))}
                          </div>
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

      {/* Manual subscription activation / management (Super Admin). */}
      {detail?.teacher && (
        <ManualSubscriptionModal
          open={manualOpen}
          onOpenChange={setManualOpen}
          teacher={{ id: detail.teacher.id, name: detail.teacher.name, email: detail.teacher.email }}
          current={{
            plan_name: detailSubscriptions()[0]?.plan_name || detailSubscriptions()[0]?.plan?.name || null,
            status: detailSubscriptions()[0]?.status || null,
            source: detail.access?.access_source || null,
            ends_at: detail.access?.access_ends_at || null,
            trial_ends_at: detail.access?.trial_ends_at || null,
          }}
          plans={plans}
          onDone={() => openDetail({ id: detail.teacher.id })}
        />
      )}

      {/* Super-Admin custom-domain management (per teacher). */}
      {detail?.teacher && (
        <TeacherDomainModal
          open={domainOpen}
          onOpenChange={setDomainOpen}
          teacher={{ id: detail.teacher.id, name: detail.teacher.name }}
          onDone={() => openDetail({ id: detail.teacher.id })}
        />
      )}
    </div>
  );
}
