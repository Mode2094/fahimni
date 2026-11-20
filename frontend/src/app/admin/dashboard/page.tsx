'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { SectionCard, StatTile, useIsDesktop } from '@/components/dashboard/dashboard-kit';
import { cn } from '@/lib/utils';
import {
  Users, GraduationCap, BookOpen, CreditCard, UserPlus, Wallet, TrendingUp,
  Crown, Ticket, FileText, BellRing, LayoutGrid, ShieldCheck, Activity, RefreshCw, AlertTriangle,
} from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

/**
 * Super-Admin dashboard — mobile-first redesign.
 *
 * One responsive layout serves every width: the phone opens on a compact
 * header, a single revenue card and a 2×2 statistics grid, while desktop keeps
 * the same components laid out 4-up with roomier spacing. Revenue is shown
 * exactly once, secondary stats live in their own section, and every tile
 * links to a real admin page.
 */
export default function AdminDashboardPage() {
  const { check, user } = useAuth();
  const { locale } = useLocale();
  const { formatPrice, load: loadSettings } = useSettings();
  const isDesktop = useIsDesktop();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [stats, setStats] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  const loadStats = async () => {
    setLoading(true);
    setError(false);
    try {
      const tid = localStorage.getItem('tenant_id');
      const res = await fetch(`${API}/dashboard`, {
        headers: { 'X-Tenant-Id': tid || '', Accept: 'application/json' },
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      setStats(await res.json());
    } catch {
      // Surface a clear, retryable error instead of zeroes that look real.
      setError(true);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!check()) return;
    loadSettings();
    loadStats();
  }, [check()]);

  const firstName = (user?.name || '').trim().split(/\s+/)[0] || '';

  // 2×2 on phones · 4-up from lg — the four headline platform statistics.
  const primaryStats = [
    { label: t('المعلمون', 'Teachers'), value: stats?.total_tenants ?? 0, href: '/admin/teachers', icon: Users, tone: 'bg-teal-500/10 text-teal-600' },
    { label: t('الطلاب', 'Students'), value: stats?.total_students ?? 0, href: '/admin/students', icon: GraduationCap, tone: 'bg-violet-500/10 text-violet-600' },
    { label: t('الكورسات', 'Courses'), value: stats?.total_courses ?? 0, href: '/admin/teachers', icon: BookOpen, tone: 'bg-emerald-500/10 text-emerald-600' },
    { label: t('الاشتراكات النشطة', 'Active subscriptions'), value: stats?.active_subscriptions ?? 0, href: '/admin/subscriptions', icon: CreditCard, tone: 'bg-sky-500/10 text-sky-600' },
  ];

  // Every destination below is a real, existing admin page.
  const quickActions = [
    { label: t('إدارة المعلمين', 'Manage teachers'), href: '/admin/teachers', icon: Users, tone: 'bg-teal-500/10 text-teal-600' },
    { label: t('الخطط والأسعار', 'Plans & pricing'), href: '/admin/plans', icon: Crown, tone: 'bg-amber-500/10 text-amber-600' },
    { label: t('الدعم الفني', 'Support'), href: '/admin/tickets', icon: Ticket, tone: 'bg-violet-500/10 text-violet-600' },
    { label: t('المبيعات والفواتير', 'Sales & invoices'), href: '/admin/payments', icon: FileText, tone: 'bg-emerald-500/10 text-emerald-600' },
    { label: t('إشعارات المعلمين', 'Teacher notifications'), href: '/admin/teacher-notifications', icon: BellRing, tone: 'bg-sky-500/10 text-sky-600' },
  ];

  if (loading) {
    return (
      <div className="mx-auto max-w-6xl space-y-4 sm:space-y-6" data-testid="admin-dashboard-loading">
        <Skeleton className="h-28 w-full rounded-3xl" />
        <Skeleton className="h-20 w-full rounded-2xl" />
        <div className="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
          <Skeleton className="h-28" /><Skeleton className="h-28" /><Skeleton className="h-28" /><Skeleton className="h-28" />
        </div>
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="mx-auto max-w-6xl" data-testid="admin-dashboard-error">
        <Card className="border-0 shadow-sm">
          <CardContent className="flex flex-col items-center gap-3 p-8 text-center">
            <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-500/10">
              <AlertTriangle className="h-7 w-7 text-amber-500" />
            </span>
            <div>
              <p className="font-bold">{t('تعذّر تحميل الإحصائيات', 'Could not load the statistics')}</p>
              <p className="mt-1 text-sm text-muted-foreground">{t('تحقّق من اتصالك ثم أعد المحاولة.', 'Check your connection and try again.')}</p>
            </div>
            <button
              onClick={loadStats}
              data-testid="dashboard-retry"
              className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-primary-foreground transition-colors hover:bg-primary/90"
            >
              <RefreshCw className="h-4 w-4" />
              {t('إعادة المحاولة', 'Retry')}
            </button>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-6xl space-y-4 sm:space-y-6" data-testid="admin-dashboard">
      {/* ─── رأس مختصر ─────────────────────────────── */}
      <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-teal-800 to-teal-700 p-4 text-white shadow-brand sm:p-5">
        <div className="pointer-events-none absolute -end-10 -top-12 h-40 w-40 rounded-full bg-white/10 blur-3xl" aria-hidden="true" />
        <div className="relative min-w-0">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-0.5 text-[11px] font-bold ring-1 ring-white/20">
            <ShieldCheck className="h-3 w-3 text-teal-100" />
            {t('مركز إدارة المنصة', 'Platform control center')}
          </span>
          <h1 className="mt-2 text-xl font-extrabold leading-snug tracking-tight sm:text-2xl lg:text-3xl">
            {t('لوحة الإدارة', 'Admin Dashboard')}{firstName ? <span className="font-semibold text-teal-50/80"> · {firstName}</span> : null}
          </h1>
          <p className="mt-1 text-xs text-teal-50/85 sm:text-sm">{t('ملخص المنصة لحظياً', 'Live platform summary')}</p>
        </div>
      </section>

      {/* ─── الإيرادات (بطاقة واحدة، بلا تكرار) ─────── */}
      <Card className="border-0 shadow-sm" data-testid="revenue-card">
        <CardContent className="flex items-center gap-4 p-4 sm:p-5">
          <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-teal-500/10">
            <Wallet className="h-6 w-6 text-teal-600" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="text-xs font-semibold text-muted-foreground">{t('إجمالي إيرادات المنصة', 'Platform total revenue')}</p>
            <p className="mt-1 break-words text-2xl font-black leading-none tabular-nums sm:text-3xl" data-testid="revenue-value">
              {formatPrice(stats?.total_revenue ?? 0)}
            </p>
            <p className="mt-1.5 flex items-center gap-1.5 text-[11px] text-muted-foreground">
              <TrendingUp className="h-3.5 w-3.5 text-emerald-500" />
              {t('جميع الإيرادات المكتملة', 'All completed revenue')}
            </p>
          </div>
        </CardContent>
      </Card>

      {/* ─── الإحصائيات الرئيسية (2×2 على الهاتف) ────── */}
      <div className="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4" data-testid="stats-grid">
        {primaryStats.map((s, i) => (
          <StatTile key={i} testId={`stat-${i}`} label={s.label} value={s.value} href={s.href} icon={s.icon} tone={s.tone} />
        ))}
      </div>

      {/* ─── إجراءات سريعة ──────────────────────────── */}
      <SectionCard
        icon={LayoutGrid}
        title={t('إجراءات سريعة', 'Quick actions')}
        description={t('أكثر مهام الإدارة استخداماً', 'The most-used admin tasks')}
        forceOpen
      >
        <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-5" data-testid="quick-actions">
          {quickActions.map((q) => (
            <a
              key={q.href}
              href={q.href}
              className="group flex min-h-[76px] flex-col justify-between gap-2 rounded-2xl border border-border bg-card p-3 transition-all duration-200 hover:-translate-y-0.5 hover:border-teal-200 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <span className={cn('flex h-9 w-9 items-center justify-center rounded-xl', q.tone)}>
                <q.icon className="h-[18px] w-[18px]" />
              </span>
              <span className="text-xs font-bold leading-tight sm:text-sm">{q.label}</span>
            </a>
          ))}
        </div>
      </SectionCard>

      {/* ─── بقية الإحصائيات في قسم مناسب ────────────── */}
      <SectionCard
        icon={Activity}
        title={t('نظرة عامة على المنصة', 'Platform overview')}
        description={t('مؤشرات إضافية', 'Additional metrics')}
        forceOpen={isDesktop}
        testId="platform-overview"
      >
        <div className="flex items-center gap-3 rounded-2xl border border-border bg-background p-4">
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-orange-500/10 text-orange-600">
            <UserPlus className="h-5 w-5" />
          </span>
          <p className="min-w-0 flex-1 text-sm font-semibold text-muted-foreground">{t('مستخدمو المنصة', 'Platform users')}</p>
          <p className="shrink-0 text-2xl font-bold tabular-nums">{stats?.total_users ?? 0}</p>
        </div>
      </SectionCard>
    </div>
  );
}
