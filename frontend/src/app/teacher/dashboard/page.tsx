'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { SectionCard, StatTile, useIsDesktop } from '@/components/dashboard/dashboard-kit';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';
import { Users, BookOpen, ClipboardList, FileQuestion, Plus, Crown, CalendarClock, Clock, Video, Film, Radio, Copy, Check, Construction, Ticket, CalendarDays, Sparkles, Globe, ExternalLink, LayoutGrid, Activity, Smartphone, ChevronLeft, ChevronRight, HardDrive, AlertTriangle } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const ONBOARDING_META: Record<string, { ar: string; en: string; icon: any; href: string }> = {
  course: { ar: 'أنشئ كورسك الأول', en: 'Create your first course', icon: BookOpen, href: '/courses' },
  lesson: { ar: 'أضف درساً داخل الكورس', en: 'Add a lesson to the course', icon: BookOpen, href: '/courses' },
  video: { ar: 'ارفع فيديو تعليمياً', en: 'Upload a teaching video', icon: Video, href: '/courses' },
  card: { ar: 'أنشئ بطاقة تسجيل لطلابك', en: 'Create a registration card', icon: Ticket, href: '/teacher/cards' },
  assignment: { ar: 'أضف واجباً لطلابك', en: 'Add an assignment', icon: ClipboardList, href: '/courses' },
  live: { ar: 'ابدأ بثاً مباشراً', en: 'Hold a live session', icon: Radio, href: '/teacher/live' },
  schedule: { ar: 'جدوِل درساً بموعد ثابت', en: 'Schedule a lesson', icon: CalendarDays, href: '/teacher/schedule' },
};

export default function TeacherDashboard() {
  const { user, check } = useAuth();
  const { locale } = useLocale();
  const { settings, load: loadSettings } = useSettings();
  const isDesktop = useIsDesktop();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const Arrow = locale === 'ar' ? ChevronLeft : ChevronRight;

  const [plan, setPlan] = useState<any>(null);
  const [usage, setUsage] = useState<any>(null);
  const [analytics, setAnalytics] = useState<any>(null);
  const [lessons, setLessons] = useState<any[]>([]);
  const [bookings, setBookings] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [studentApp, setStudentApp] = useState<any>(null);
  const [onboarding, setOnboarding] = useState<any>(null);
  const [platform, setPlatform] = useState<{ url: string; slug: string; customDomain: string | null; verified: boolean } | null>(null);
  const [copied, setCopied] = useState(false);
  const [linkCopied, setLinkCopied] = useState(false);

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  /** The teacher's own platform address: the verified custom domain when set,
   *  otherwise the free /t/{slug} page. Falls back to the tenant slug when the
   *  domain endpoint is not available to this role. */
  const buildPlatform = (d: any) => {
    const origin = typeof window !== 'undefined' ? window.location.origin : '';
    const url = (typeof d?.page_url === 'string' && d.page_url) || (d?.slug ? `${origin}/t/${d.slug}` : '');
    if (!url) return null;
    return { url, slug: d?.slug || '', customDomain: d?.domain || null, verified: !!d?.verified };
  };

  const loadAll = async () => {
    setLoading(true);
    try {
      // Each request degrades on its own: one failing endpoint must never blank
      // the dashboard or leave it stuck on skeletons forever.
      const [planR, anaR, lesR, bkR, appR, onbR, domR] = await Promise.all([
        fetch(`${API}/teacher/plan`, { headers: headers() }).catch(() => null),
        fetch(`${API}/teacher/analytics?days=30`, { headers: headers() }).catch(() => null),
        fetch(`${API}/teacher/scheduled-lessons`, { headers: headers() }).catch(() => null),
        fetch(`${API}/teacher/bookings`, { headers: headers() }).catch(() => null),
        fetch(`${API}/teacher/student-app`, { headers: headers() }).catch(() => null),
        fetch(`${API}/teacher/onboarding`, { headers: headers() }).catch(() => null),
        fetch(`${API}/teacher/domain`, { headers: headers() }).catch(() => null),
      ]);

      if (planR?.ok) { const d = await planR.json(); setPlan(d.plan || null); setUsage(d.usage || null); }
      if (anaR?.ok) { const d = await anaR.json(); setAnalytics(d.overview || null); }
      if (lesR?.ok) { const d = await lesR.json(); setLessons(d.lessons || d.scheduled_lessons || []); }
      if (bkR?.ok) { const d = await bkR.json(); setBookings(Array.isArray(d.bookings) ? d.bookings : d.bookings?.data || []); }
      if (appR?.ok) { const d = await appR.json(); setStudentApp(d.student_app || null); }
      if (onbR?.ok) { const d = await onbR.json(); setOnboarding(d || null); }

      let platformInfo = domR?.ok ? buildPlatform(await domR.json()) : null;
      if (!platformInfo) {
        // The domain endpoint needs settings.manage; a plain teacher still gets
        // their slug from the tenant list so the link card is never empty.
        const tR = await fetch(`${API}/tenants`, { headers: { Accept: 'application/json' } }).catch(() => null);
        if (tR?.ok) {
          const d = await tR.json();
          const list = Array.isArray(d?.tenants) ? d.tenants : [];
          const tid = localStorage.getItem('tenant_id');
          const tenant = list.find((x: any) => x.id === tid) || list[0];
          platformInfo = buildPlatform({ slug: tenant?.slug });
        }
      }
      setPlatform(platformInfo);
    } catch {
      // Malformed payloads etc. — render whatever we have instead of locking up.
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (check()) {
      loadSettings();
      loadAll();
    }
  }, [check()]);

  const fmtBytes = (bytes: number) => {
    if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
    if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    if (bytes >= 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${bytes} B`;
  };

  const fmtNum = (key: string, v: number) => (key === 'storage' ? fmtBytes(v) : String(v));

  const copyPlatformLink = async () => {
    if (!platform?.url) return;
    try {
      await navigator.clipboard.writeText(platform.url);
      setLinkCopied(true);
      setTimeout(() => setLinkCopied(false), 2000);
      toast.success(t('تم نسخ رابط منصتك', 'Platform link copied'));
    } catch { toast.error(t('تعذر النسخ', 'Copy failed')); }
  };

  // الوقت الحالي للترحيب
  const nowH = new Date().getHours();
  const greeting = nowH < 12 ? t('صباح الخير', 'Good morning') : nowH < 17 ? t('مساء الخير', 'Good afternoon') : t('مساء الخير', 'Good evening');

  const upUsage: { key: string; label: string }[] = [
    { key: 'courses', label: t('الكورسات', 'Courses') },
    { key: 'students', label: t('الطلاب', 'Students') },
    { key: 'storage', label: t('التخزين', 'Storage') },
    { key: 'videos', label: t('الفيديوهات', 'Videos') },
    { key: 'exams', label: t('الاختبارات', 'Exams') },
    { key: 'questions', label: t('الأسئلة', 'Questions') },
    { key: 'teachers', label: t('المعلمون', 'Teachers') },
  ];

  const upcomingBookings = (bookings || [])
    .filter((b) => b.status !== 'cancelled')
    .filter((b) => b.date && new Date(b.date + (b.time ? 'T' + b.time : '')) >= new Date(Date.now() - 3600e3))
    .slice(0, 3);

  const perf = [
    { label: t('معدل الإكمال', 'Completion rate'), key: 'completion' },
    { label: t('ساعات التعلم', 'Learning hours'), key: 'watch' },
    { label: t('نسبة النجاح', 'Pass rate'), key: 'pass' },
  ];

  const stats = [
    { label: t('الطلاب', 'Students'), value: usage?.students?.used ?? 0, href: '/students', icon: Users, tone: 'bg-teal-500/10 text-teal-600' },
    { label: t('الكورسات', 'Courses'), value: usage?.courses?.used ?? 0, href: '/courses', icon: BookOpen, tone: 'bg-sky-500/10 text-sky-600' },
    { label: t('الاختبارات', 'Exams'), value: usage?.exams?.used ?? 0, href: '/courses', icon: ClipboardList, tone: 'bg-violet-500/10 text-violet-600' },
    { label: t('المحاولات', 'Attempts'), value: analytics?.total_attempts ?? 0, href: '/analytics', icon: FileQuestion, tone: 'bg-amber-500/10 text-amber-600' },
  ];

  const hasData = (analytics?.total_attempts ?? 0) > 0 || (analytics?.watch_seconds ?? 0) > 0;

  if (loading) {
    return (
      <div className="mx-auto max-w-6xl space-y-4 sm:space-y-6">
        <Skeleton className="h-36 w-full rounded-3xl sm:h-32" />
        <div className="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
          <Skeleton className="h-28" /><Skeleton className="h-28" /><Skeleton className="h-28" /><Skeleton className="h-28" />
        </div>
        <Skeleton className="h-32 w-full" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-6xl space-y-4 sm:space-y-6">
      {/* ─── الترحيب + الخطة + الإجراءات السريعة ──────── */}
      <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-teal-800 to-teal-700 p-4 text-white shadow-brand sm:p-6">
        <div className="pointer-events-none absolute -end-12 -top-14 h-44 w-44 rounded-full bg-white/10 blur-3xl" aria-hidden="true" />
        <div className="relative flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between lg:gap-6">
          <div className="min-w-0">
            <h1 className="text-xl font-extrabold leading-snug tracking-tight sm:text-2xl lg:text-3xl">
              {greeting}، <span className="break-words">{user?.name || t('معلم', 'Teacher')}</span>
            </h1>
            <p className="mt-1 text-xs text-teal-50/90 sm:text-sm">{t('إليك ملخص منصتك اليوم', 'Here is your platform summary today')}</p>
          </div>

          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-3">
            {plan && (
              <a
                href="/pricing"
                className="inline-flex max-w-full items-center gap-2 self-start rounded-full bg-white/15 px-3.5 py-2 text-xs font-bold ring-1 ring-white/25 backdrop-blur transition hover:bg-white/25 lg:self-auto"
              >
                <Crown className="h-3.5 w-3.5 shrink-0 text-amber-300" />
                <span className="truncate">{plan.plan_name || plan.name}</span>
                <span className="inline-flex shrink-0 items-center gap-1 font-semibold text-teal-50/90">
                  <span className="h-1.5 w-1.5 rounded-full bg-emerald-300" />
                  {t('نشطة', 'Active')}
                </span>
              </a>
            )}

            <div className="grid grid-cols-2 gap-2.5 lg:flex lg:gap-2.5" data-testid="quick-actions">
              <a href="/courses" className="rounded-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70 lg:rounded-full">
                <Button className="h-auto min-h-11 w-full gap-2 rounded-2xl bg-white px-3 py-2.5 font-bold text-teal-700 shadow-sm hover:bg-teal-50 lg:h-9 lg:min-h-0 lg:w-auto lg:rounded-full lg:px-4 lg:py-0">
                  <Plus className="h-4 w-4 shrink-0" />
                  {t('إنشاء كورس', 'New course')}
                </Button>
              </a>
              <a href="/courses" className="rounded-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70 lg:rounded-full">
                <Button variant="outline" className="h-auto min-h-11 w-full gap-2 rounded-2xl border-white/30 bg-white/10 px-3 py-2.5 font-bold text-white hover:bg-white/20 hover:text-white lg:h-9 lg:min-h-0 lg:w-auto lg:rounded-full lg:px-4 lg:py-0">
                  <LayoutGrid className="h-4 w-4 shrink-0" />
                  {t('إدارة الكورسات', 'Manage courses')}
                </Button>
              </a>
            </div>
          </div>
        </div>
      </section>

      {/* ─── الإحصائيات الرئيسية (2×2 على الهاتف) ────── */}
      <div className="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4" data-testid="stats-grid">
        {stats.map((s, i) => (
          <StatTile key={i} label={s.label} value={s.value} href={s.href} icon={s.icon} tone={s.tone} />
        ))}
      </div>

      {/* ─── رابط المنصة (بطاقة مضغوطة) ─────────────── */}
      <Card className="border-0 shadow-sm">
        <CardContent className="p-4 sm:p-5">
          <div className="flex items-start gap-3">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-teal-500/10">
              <Globe className="h-5 w-5 text-teal-600" />
            </span>
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-bold">{t('رابط منصتك', 'Your platform link')}</h2>
                {platform?.url && <Badge className="border-0 bg-green-100 text-green-700">{t('جاهزة للمشاركة', 'Ready to share')}</Badge>}
              </div>
              {platform?.url ? (
                <div className="mt-2.5 flex items-center gap-1.5 rounded-xl border border-teal-100 bg-teal-50/60 p-1 ps-3">
                  <span className="min-w-0 flex-1 truncate font-mono text-xs text-teal-800" dir="ltr">
                    {platform.url.replace(/^https?:\/\//, '')}
                  </span>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8 shrink-0"
                    onClick={copyPlatformLink}
                    aria-label={t('نسخ رابط المنصة', 'Copy platform link')}
                    title={t('نسخ رابط المنصة', 'Copy platform link')}
                  >
                    {linkCopied ? <Check className="h-4 w-4 text-emerald-600" /> : <Copy className="h-4 w-4" />}
                  </Button>
                </div>
              ) : (
                <p className="mt-2 text-xs text-amber-600">{t('لم يُحدَّد رابط منصتك بعد — حدّده من إعدادات المنصة.', 'Your platform link is not set yet — set it from platform settings.')}</p>
              )}
            </div>
          </div>
          <div className="mt-3 grid grid-cols-2 gap-2.5">
            {platform?.url && (
              <a href={platform.url} target="_blank" rel="noopener noreferrer">
                <Button variant="outline" className="h-10 w-full gap-2 rounded-xl">
                  <ExternalLink className="h-4 w-4" />
                  {t('معاينة', 'Preview')}
                </Button>
              </a>
            )}
            <a href="/teacher/settings" className={cn(!platform?.url && 'col-span-2')}>
              <Button className="h-10 w-full gap-2 rounded-xl">
                {t('إعدادات المنصة', 'Platform settings')}
                <Arrow className="h-4 w-4" />
              </Button>
            </a>
          </div>
        </CardContent>
      </Card>

      {/* ─── تطبيق الطلاب (Student App) ─────────────── */}
      {!settings.student_app_hidden && studentApp && (
        <Card className="border-0 shadow-sm">
          <CardContent className="flex flex-wrap items-center gap-3 p-4 sm:p-5">
            <span className={cn('flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl', studentApp.enabled ? 'bg-teal-500/10' : 'bg-amber-50')}>
              {studentApp.enabled ? <Smartphone className="h-5 w-5 text-teal-600" /> : <Construction className="h-5 w-5 text-amber-500" />}
            </span>
            <div className="min-w-[160px] flex-1">
              <p className="flex flex-wrap items-center gap-2 font-bold">
                {t('تطبيق الطلاب', 'Student App')}
                {studentApp.enabled
                  ? <Badge className="border-0 bg-green-100 text-green-700">{t('مفعّل', 'Active')}</Badge>
                  : <Badge className="border-0 bg-amber-50 text-amber-700">{t('قيد التطوير', 'Under development')}</Badge>}
              </p>
              {studentApp.enabled ? (
                <p className="mt-0.5 text-sm text-muted-foreground">
                  {t('كود المعلم:', 'Teacher Code:')} <b className="font-mono tracking-wider text-teal-700" dir="ltr">{studentApp.teacher_code}</b>
                </p>
              ) : (
                <p className="mt-0.5 text-sm text-muted-foreground">{t('سنخبرك عند توفر التطبيق.', 'We will let you know when the app is available.')}</p>
              )}
            </div>
            <div className="flex w-full items-center gap-2 sm:w-auto">
              {studentApp.enabled && (
                <Button size="sm" variant="outline" className="flex-1 gap-1.5 rounded-full sm:flex-none" onClick={async () => {
                  try {
                    await navigator.clipboard.writeText(studentApp.teacher_code || '');
                    setCopied(true);
                    setTimeout(() => setCopied(false), 2000);
                    toast.success(t('تم نسخ كود المعلم', 'Teacher code copied'));
                  } catch { toast.error(t('تعذر النسخ', 'Copy failed')); }
                }}>
                  {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
                  {copied ? t('تم النسخ', 'Copied') : t('نسخ الكود', 'Copy code')}
                </Button>
              )}
              <a href="/teacher/student-app" className="flex-1 sm:flex-none">
                <Button size="sm" className="w-full gap-1.5 rounded-full sm:w-auto">{t('التفاصيل', 'Details')}<Arrow className="h-3.5 w-3.5" /></Button>
              </a>
            </div>
          </CardContent>
        </Card>
      )}

      {/* ─── خطوات البدء (تظهر فقط حتى يكتمل الإعداد) ─── */}
      {onboarding && !onboarding.all_done && Array.isArray(onboarding.steps) && onboarding.steps.length > 0 && (
        <SectionCard
          icon={Sparkles}
          title={t('خطواتك الأولى', 'Getting started')}
          description={t('أكمل هذه الخطوات لتجهيز منصتك لطلابك.', 'Complete these steps to get your platform ready for students.')}
          badge={<span className="shrink-0 rounded-full bg-teal-50 px-2.5 py-0.5 text-xs font-semibold text-teal-700">{onboarding.completed} / {onboarding.total}</span>}
          defaultOpen
          forceOpen={isDesktop}
        >
          <div className="mb-4 h-1.5 overflow-hidden rounded-full bg-slate-100">
            <div className="h-full rounded-full bg-teal-500 transition-all" style={{ width: `${onboarding.total ? Math.round((onboarding.completed / onboarding.total) * 100) : 0}%` }} />
          </div>
          <div className="grid gap-2 sm:grid-cols-2">
            {onboarding.steps.map((s: any) => {
              const meta = ONBOARDING_META[s.key];
              if (!meta) return null;
              const Icon = meta.icon;
              return (
                <a
                  key={s.key}
                  href={meta.href}
                  className={cn('flex items-center gap-3 rounded-xl border p-3 transition', s.done ? 'border-slate-100 bg-slate-50/50' : 'border-teal-100 bg-white hover:bg-teal-50/40')}
                  aria-label={s.done ? `${t(meta.ar, meta.en)} — ${t('تم', 'Done')}` : t(meta.ar, meta.en)}
                >
                  <span className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', s.done ? 'bg-emerald-50' : 'bg-teal-500/10')}>
                    {s.done ? <Check className="h-4 w-4 text-emerald-600" /> : <Icon className="h-4 w-4 text-teal-600" />}
                  </span>
                  <span className={cn('flex-1 text-sm font-medium', s.done ? 'text-gray-400 line-through' : 'text-gray-700')}>{t(meta.ar, meta.en)}</span>
                  {!s.done && <Arrow className="h-4 w-4 shrink-0 text-teal-500" />}
                </a>
              );
            })}
          </div>
        </SectionCard>
      )}

      {/* ─── استخدام خطتك (مصدر واحد موحد) ──────────── */}
      <SectionCard
        icon={Activity}
        title={t('استخدام خطتك', 'Your plan usage')}
        description={t('الاستخدام مقابل حدود خطتك الحالية', 'Usage vs your current plan limits')}
        forceOpen={isDesktop}
      >
        {/* تنبيه الاقتراب من امتلاء مساحة التخزين — مع المساحة المتبقية */}
        {usage?.storage && !usage.storage.unlimited && (usage.storage.pct ?? 0) >= 80 && (
          <div className={cn('mb-4 flex items-center gap-2.5 rounded-2xl border px-4 py-3 text-sm', (usage.storage.pct ?? 0) >= 90 ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-amber-200 bg-amber-50 text-amber-800')}>
            <AlertTriangle className="h-4 w-4 shrink-0" />
            <span className="font-semibold">
              {(usage.storage.pct ?? 0) >= 90
                ? t('اقتربت جداً من حد التخزين', 'You are very close to your storage limit')
                : t('اقتربت من حد التخزين', 'You are approaching your storage limit')}
              {' — '}{t('المساحة المتبقية', 'Remaining storage')}: {fmtNum('storage', usage.storage.remaining ?? 0)}
            </span>
          </div>
        )}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
          {upUsage.map((it) => {
            const e = usage?.[it.key];
            if (!e) return null;
            const used = e.unit === 'bytes' ? fmtNum('storage', e.used) : String(e.used);
            const limit = e.unlimited ? t('غير محدود', 'Unlimited') : e.unit === 'bytes' ? fmtNum('storage', e.limit ?? 0) : String(e.limit ?? 0);
            const pct = e.unlimited ? 0 : Math.min(100, e.pct ?? 0);
            const state = e.unlimited ? t('غير محدود', 'Unlimited') : pct >= 90 ? t('قارب على الحد', 'Near limit') : t('ضمن الحد', 'Within limit');
            return (
              <div key={it.key} className="rounded-2xl border border-slate-100 bg-slate-50/50 p-4">
                <p className="mb-1 text-xs font-semibold text-gray-500">{it.label}</p>
                <p className="text-base font-bold">{used} <span className="text-xs font-medium text-gray-400">/ {limit}</span></p>
                <div className="mt-2 h-1.5 rounded-full bg-slate-200/70">
                  {!e.unlimited && <div className={cn('h-full rounded-full', pct >= 90 ? 'bg-rose-500' : pct >= 70 ? 'bg-amber-400' : 'bg-teal-500')} style={{ width: `${pct}%` }} />}
                </div>
                <p className={cn('mt-1.5 text-xs', pct >= 90 && !e.unlimited ? 'text-rose-600' : 'text-gray-400')}>{state}{!e.unlimited && pct > 0 ? ` · ${Math.round(pct)}%` : ''}</p>
                {it.key === 'storage' && !e.unlimited && (
                  <p className="mt-1 flex items-center gap-1 text-[11px] font-medium text-gray-500">
                    <HardDrive className="h-3 w-3 text-gray-400" />{t('المتبقي', 'Remaining')}: {fmtNum('storage', e.remaining ?? 0)}
                  </p>
                )}
              </div>
            );
          })}
        </div>

        {/* الوسائط والملفات: فيديوهات/بث + ملفات/PDF (بلا تكرار التخزين) */}
        <div className="mt-5 border-t border-slate-100 pt-5">
          <h3 className="mb-3 flex items-center gap-2 text-sm font-bold"><Film className="h-4 w-4 text-teal-600" />{t('الوسائط والملفات', 'Media & files')}</h3>
          <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            {[
              { label: t('الفيديوهات', 'Videos'), value: `${usage?.videos?.used ?? 0} / ${usage?.videos?.unlimited ? '∞' : (usage?.videos?.limit ?? 0)}` },
              { label: t('جلسات البث', 'Live sessions'), value: `${usage?.live_sessions?.used ?? 0} / ${usage?.live_sessions?.unlimited ? '∞' : (usage?.live_sessions?.limit ?? 0)}` },
              { label: t('الملفات', 'Files'), value: `${usage?.files?.used ?? 0} / ${usage?.files?.unlimited ? '∞' : (usage?.files?.limit ?? 0)}` },
              { label: t('ملفات PDF', 'PDFs'), value: `${usage?.pdfs?.used ?? 0} / ${usage?.pdfs?.unlimited ? '∞' : (usage?.pdfs?.limit ?? 0)}` },
            ].map((m, i) => (
              <div key={i} className="rounded-xl border border-slate-100 px-4 py-3">
                <p className="text-xs font-semibold text-gray-500">{m.label}</p>
                <p className="mt-0.5 text-base font-bold">{m.value}</p>
              </div>
            ))}
          </div>
        </div>
      </SectionCard>

      {/* ─── القادم: دروس + مواعيد ──────────────────── */}
      <div className="grid gap-4 lg:grid-cols-2 lg:gap-6">
        <SectionCard icon={CalendarClock} title={t('الدروس القادمة', 'Upcoming lessons')} forceOpen={isDesktop}>
          {lessons.length === 0 ? (
            <div className="space-y-2 py-4 text-center">
              <p className="text-sm text-gray-400">{t('لا توجد دروس مجدولة', 'No scheduled lessons')}</p>
            </div>
          ) : (
            <div className="space-y-2">
              {lessons.slice(0, 3).map((l, i) => (
                <div key={i} className="flex items-center gap-3 rounded-xl border border-slate-100 p-3">
                  <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-teal-50"><Clock className="h-4 w-4 text-teal-600" /></span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{l.title}</p>
                    <p className="truncate text-xs text-gray-400">{l.scheduled_at ? new Date(l.scheduled_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) : ''}</p>
                  </div>
                </div>
              ))}
            </div>
          )}
        </SectionCard>

        <SectionCard icon={CalendarClock} title={t('المواعيد القادمة', 'Upcoming appointments')} forceOpen={isDesktop}>
          {upcomingBookings.length === 0 ? (
            <div className="py-4 text-center">
              <p className="text-sm text-gray-400">{t('لا توجد مواعيد قادمة', 'No upcoming appointments')}</p>
            </div>
          ) : (
            <div className="space-y-2">
              {upcomingBookings.map((b, i) => (
                <div key={i} className="flex items-center gap-3 rounded-xl border border-slate-100 p-3">
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-violet-50"><Users className="h-4 w-4 text-violet-600" /></div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{b.student?.name || b.title || t('طالب', 'Student')}</p>
                    <p className="truncate text-xs text-gray-400">{b.date ? new Date(b.date + (b.time ? 'T' + b.time : '')).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) : ''}</p>
                  </div>
                  <Badge className={cn('shrink-0 border-0', b.status === 'confirmed' ? 'bg-emerald-50 text-emerald-600' : b.status === 'pending' ? 'bg-amber-50 text-amber-600' : 'bg-gray-100 text-gray-500')}>
                    {b.status === 'confirmed' ? t('مؤكد', 'Confirmed') : b.status === 'pending' ? t('معلق', 'Pending') : b.status}
                  </Badge>
                </div>
              ))}
            </div>
          )}
        </SectionCard>
      </div>

      {/* ─── الأداء ──────────────────────────────────── */}
      <SectionCard icon={Activity} title={t('الأداء', 'Performance')} forceOpen={isDesktop}>
        {!hasData ? (
          <p className="py-4 text-center text-sm text-gray-400">{t('لا توجد بيانات كافية بعد — أضف اختبارات وفيديوهات لطلابك', 'Not enough data yet — add exams and videos for your students')}</p>
        ) : (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 sm:gap-4">
            {perf.map((p, i) => (
              <div key={i} className="rounded-2xl border border-slate-100 bg-slate-50/50 p-4 text-center">
                <p className="text-2xl font-bold tabular-nums">
                  {p.key === 'completion' ? `${analytics?.completion_rate ?? 0}%` : p.key === 'watch' ? `${Math.round((analytics?.watch_seconds ?? 0) / 3600)} ${t('ساعة', 'h')}` : `${analytics?.pass_rate ?? 0}%`}
                </p>
                <p className="mt-1 text-sm text-gray-500">{p.label}</p>
              </div>
            ))}
          </div>
        )}
        <div className="mt-3 text-end">
          <a href="/analytics" className="inline-flex min-h-[44px] items-center gap-1 py-2 text-sm font-semibold text-teal-700 hover:underline">
            <Arrow className="h-4 w-4" />
            {t('التفاصيل في صفحة التحليلات', 'Details in Analytics')}
          </a>
        </div>
      </SectionCard>
    </div>
  );
}
