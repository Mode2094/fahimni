'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import AdminSubscriptionMetrics from '@/components/admin/subscription-metrics';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { SectionCard, useIsDesktop } from '@/components/dashboard/dashboard-kit';
import { cn } from '@/lib/utils';
import {
  Users, GraduationCap, BookOpen, UserPlus,
  Crown, Ticket, FileText, BellRing, LayoutGrid, ShieldCheck, Activity, RefreshCw, AlertTriangle,
} from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

/**
 * Super-Admin dashboard — mobile-first redesign.
 *
 * One platform-wide snapshot serves the dashboard, teachers and subscriptions
 * pages. The headline metrics stay compact on mobile; payment and record-level
 * details are expandable and never mix currencies.
 */
export default function AdminDashboardPage() {
  const { check, user } = useAuth();
  const { locale } = useLocale();
  const { load: loadSettings } = useSettings();
  const isDesktop = useIsDesktop();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [stats, setStats] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  const loadStats = async () => {
    setLoading(true);
    setError(false);
    try {
      const res = await fetch(`${API}/dashboard`, {
        // Prevent the global authenticated-fetch helper from appending a
        // stale tenant_id from localStorage to this platform-wide request.
        headers: { 'X-Tenant-Id': '', Accept: 'application/json' },
        cache: 'no-store',
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

      <AdminSubscriptionMetrics metrics={stats?.subscription_metrics} />

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
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
          {[
            { label: t('مستخدمو المنصة', 'Platform users'), value: stats?.total_users ?? 0, icon: UserPlus },
            { label: t('الطلاب', 'Students'), value: stats?.total_students ?? 0, icon: GraduationCap },
            { label: t('الكورسات', 'Courses'), value: stats?.total_courses ?? 0, icon: BookOpen },
          ].map((item) => (
            <div key={item.label} className="flex min-h-16 items-center gap-3 rounded-xl border border-border bg-background p-3">
              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <item.icon className="h-4 w-4" />
              </span>
              <p className="min-w-0 flex-1 text-xs font-semibold text-muted-foreground">{item.label}</p>
              <p className="shrink-0 text-xl font-bold tabular-nums">{item.value}</p>
            </div>
          ))}
        </div>
      </SectionCard>
    </div>
  );
}
