'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { CardSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import {
  Activity, Users, UserX, TrendingDown, CalendarClock, AlertTriangle,
  Search, Mail, ExternalLink, ChevronRight, ChevronLeft, Info, ShieldCheck,
  Clock, CheckCircle2,
} from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

type Reason = { code: string; days?: number; percent?: number; count?: number };
type Student = {
  id: string;
  name: string;
  email: string | null;
  avatar_url: string | null;
  last_activity_at: string | null;
  last_activity_source: string | null;
  days_inactive: number | null;
  data_available: boolean;
  status: string;
  at_risk: boolean;
  reasons: Reason[];
  progress_percent: number;
  courses_count: number;
  courses_completed: number;
  lessons_completed: number;
  overdue_assignments: number;
  overdue_exams: number;
};
type Meta = {
  thresholds: { inactive_days: number; low_progress_percent: number; min_enrolled_days: number };
  counts: Record<string, number>;
  signals: Record<string, string>;
  courses: { id: string; title: string }[];
  groups: { id: string; name: string; course_id: string }[];
  data_quality: { note: string; sources: Record<string, string> };
  pagination: { current_page: number; per_page: number; total: number; last_page: number };
};

const STATUS_META: Record<string, { ar: string; en: string; className: string }> = {
  overdue: { ar: 'متأخر عن الاستحقاق', en: 'Overdue', className: 'bg-rose-100 text-rose-700' },
  inactive: { ar: 'غير نشط', en: 'Inactive', className: 'bg-amber-100 text-amber-700' },
  low_progress: { ar: 'تقدّم منخفض', en: 'Low progress', className: 'bg-orange-100 text-orange-700' },
  no_activity_data: { ar: 'لا توجد بيانات', en: 'No data', className: 'bg-slate-200 text-slate-600' },
  new: { ar: 'جديد', en: 'New', className: 'bg-sky-100 text-sky-700' },
  active: { ar: 'نشط', en: 'Active', className: 'bg-emerald-100 text-emerald-700' },
};

const SOURCE_LABEL: Record<string, { ar: string; en: string }> = {
  login: { ar: 'تسجيل دخول', en: 'login' },
  lesson_progress: { ar: 'درس/فيديو', en: 'lesson/video' },
  exam_attempt: { ar: 'اختبار', en: 'exam' },
  assignment_submission: { ar: 'واجب', en: 'assignment' },
  device: { ar: 'جهاز', en: 'device' },
};

const EMPTY_META: Meta = {
  thresholds: { inactive_days: 7, low_progress_percent: 30, min_enrolled_days: 3 },
  counts: {}, signals: {}, courses: [], groups: [], data_quality: { note: '', sources: {} },
  pagination: { current_page: 1, per_page: 25, total: 0, last_page: 1 },
};

export default function TeacherStudentFollowUpPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = useCallback((ar: string, en: string) => (locale === 'ar' ? ar : en), [locale]);

  const [rows, setRows] = useState<Student[]>([]);
  const [meta, setMeta] = useState<Meta>(EMPTY_META);
  const [loading, setLoading] = useState(true);
  const [showSources, setShowSources] = useState(false);

  const [filters, setFilters] = useState({
    q: '',
    course_id: '',
    group_id: '',
    status: 'at_risk',
    inactive_days: '7',
    progress_below: '30',
    sort: 'risk',
    page: 1,
    per_page: 25,
  });

  const headers = useCallback(() => {
    const tid = typeof window !== 'undefined' ? localStorage.getItem('tenant_id') : '';
    return { 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  }, []);

  const load = useCallback((next = filters) => {
    setLoading(true);
    const params = new URLSearchParams();
    if (next.q) params.set('q', next.q);
    if (next.course_id) params.set('course_id', next.course_id);
    if (next.group_id) params.set('group_id', next.group_id);
    if (next.status) params.set('status', next.status);
    params.set('inactive_days', next.inactive_days);
    params.set('progress_below', next.progress_below);
    params.set('sort', next.sort);
    params.set('page', String(next.page));
    params.set('per_page', String(next.per_page));

    fetch(`${API}/teacher/follow-up?${params.toString()}`, { headers: headers() })
      .then((r) => r.json())
      .then((d) => {
        setRows(d.data || []);
        setMeta({ ...EMPTY_META, ...(d.meta || {}) });
      })
      .catch(() => setRows([]))
      .finally(() => setLoading(false));
  }, [headers]);

  // البحث بعد توقّف الكتابة (debounce) حتى لا نُثقل الخادم.
  const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);
  const apply = useCallback((patch: Partial<typeof filters>) => {
    setFilters((prev) => {
      const next = { ...prev, ...patch, page: patch.page ?? 1 };
      if (debounce.current) clearTimeout(debounce.current);
      debounce.current = setTimeout(() => load(next), 280);
      return next;
    });
  }, [load]);

  useEffect(() => {
    if (check()) load(filters);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const counts = meta.counts || {};
  const statCards = useMemo(() => ([
    { key: 'total', label: t('إجمالي الطلاب', 'Students'), value: counts.total ?? 0, icon: Users, color: '#0d9488', status: 'all', days: undefined as string | undefined },
    { key: 'at_risk', label: t('بحاجة متابعة', 'Need follow-up'), value: counts.at_risk ?? 0, icon: AlertTriangle, color: '#e11d48', status: 'at_risk', days: undefined },
    { key: 'inactive_7', label: t('غير نشط ٧ أيام', 'Inactive 7d'), value: counts.inactive_7 ?? 0, icon: Clock, color: '#d97706', status: 'inactive', days: '7' },
    { key: 'inactive_14', label: t('غير نشط ١٤ يوم', 'Inactive 14d'), value: counts.inactive_14 ?? 0, icon: UserX, color: '#b45309', status: 'inactive', days: '14' },
    { key: 'low_progress', label: t('تقدّم منخفض', 'Low progress'), value: counts.low_progress ?? 0, icon: TrendingDown, color: '#ea580c', status: 'low_progress', days: undefined },
    { key: 'overdue', label: t('متأخرون', 'Overdue'), value: counts.overdue ?? 0, icon: CalendarClock, color: '#be123c', status: 'overdue', days: undefined },
  ]), [counts, t]);

  const reasonLabel = (r: Reason): string => {
    switch (r.code) {
      case 'inactive':
        return t(`متغيّب منذ ${r.days} يوم`, `Inactive for ${r.days} days`);
      case 'no_activity_record':
        return t('لا يوجد سجل نشاط', 'No activity record');
      case 'low_progress':
        return t(`تقدّم منخفض (${r.percent}%)`, `Low progress (${r.percent}%)`);
      case 'overdue_assignments':
        return t(`${r.count} واجب فات موعده`, `${r.count} overdue assignment(s)`);
      case 'overdue_exams':
        return t(`${r.count} اختبار فات موعده`, `${r.count} missed exam(s)`);
      case 'never_signed_in':
        return t('لم يسجّل الدخول بعد', 'Never signed in');
      default:
        return r.code;
    }
  };

  const reasonTone = (code: string): string => {
    if (code === 'overdue_assignments' || code === 'overdue_exams') return 'bg-rose-50 text-rose-700 border-rose-100';
    if (code === 'inactive') return 'bg-amber-50 text-amber-700 border-amber-100';
    if (code === 'low_progress') return 'bg-orange-50 text-orange-700 border-orange-100';
    return 'bg-slate-50 text-slate-600 border-slate-200';
  };

  const inactivityText = (s: Student): string => {
    if (!s.data_available) return t('لا توجد بيانات نشاط', 'No activity data');
    if (s.days_inactive === 0) return t('اليوم', 'Today');
    const src = s.last_activity_source ? SOURCE_LABEL[s.last_activity_source] : null;
    const via = src ? t(` · ${src.ar}`, ` · ${src.en}`) : '';
    return t(`منذ ${s.days_inactive} يوم${via}`, `${s.days_inactive}d ago${via}`);
  };

  const json = meta.data_quality?.sources || {};

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      {/* العنوان */}
      <div className="flex flex-wrap items-center gap-3">
        <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
          <Activity className="h-6 w-6 text-teal-600" />
        </span>
        <div>
          <h1 className="text-2xl font-bold sm:text-3xl">{t('متابعة الطلاب', 'Student Follow-up')}</h1>
          <p className="mt-1 text-muted-foreground">
            {t('من يحتاج انتباهاً — حسب النشاط والتقدّم والاستحقاقات', 'Who needs attention — by activity, progress & deadlines')}
          </p>
        </div>
        <button
          type="button"
          onClick={() => setShowSources((v) => !v)}
          className="ms-auto inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs text-muted-foreground hover:text-foreground"
        >
          <Info className="h-3.5 w-3.5" />
          {t('مصادر البيانات', 'Data sources')}
        </button>
      </div>

      {showSources && (
        <Card className="border-slate-200 shadow-sm">
          <CardContent className="space-y-3 p-5 text-sm">
            <p className="flex items-start gap-2 text-muted-foreground">
              <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-teal-600" />
              {meta.data_quality?.note}
            </p>
            <div className="grid gap-2 sm:grid-cols-2">
              {Object.entries(json).map(([key, source]) => (
                <div key={key} className="flex items-center justify-between gap-2 rounded-lg bg-muted/40 px-3 py-2">
                  <span className="text-xs text-muted-foreground">{key}</span>
                  <code className="text-[11px] text-foreground/70">{source}</code>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      {/* بطاقات الإحصاء */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {statCards.map((s) => {
          const active = filters.status === s.status && (s.days === undefined || filters.inactive_days === s.days);
          return (
            <Card
              key={s.key}
              role="button"
              tabIndex={0}
              onClick={() => apply({ status: s.status, ...(s.days ? { inactive_days: s.days } : {}) })}
              onKeyDown={(e) => { if (e.key === 'Enter') apply({ status: s.status, ...(s.days ? { inactive_days: s.days } : {}) }); }}
              className={`cursor-pointer border-0 shadow-sm transition hover:shadow-md ${active ? 'ring-2 ring-teal-500/60' : ''}`}
            >
              <CardContent className="p-4">
                <div className="mb-3 flex h-9 w-9 items-center justify-center rounded-xl" style={{ backgroundColor: `${s.color}1a` }}>
                  <s.icon className="h-4 w-4" style={{ color: s.color }} />
                </div>
                <div className="text-2xl font-black tracking-tight">{s.value}</div>
                <div className="mt-0.5 text-[11px] leading-tight text-muted-foreground">{s.label}</div>
              </CardContent>
            </Card>
          );
        })}
      </div>

      {/* الفلاتر */}
      <div className="grid gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <div className="relative space-y-2 lg:col-span-2">
          <Label htmlFor="fu-q">{t('بحث', 'Search')}</Label>
          <div className="relative">
            <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <Input
              id="fu-q"
              value={filters.q}
              onChange={(e) => apply({ q: e.target.value })}
              className="h-10 ps-9"
              placeholder={t('ابحث بالاسم أو الإيميل...', 'Search by name or email...')}
            />
          </div>
        </div>
        <div className="space-y-2">
          <Label htmlFor="fu-course">{t('الكورس', 'Course')}</Label>
          <select
            id="fu-course"
            value={filters.course_id}
            onChange={(e) => apply({ course_id: e.target.value, group_id: '' })}
            className="h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm"
          >
            <option value="">{t('كل الكورسات', 'All courses')}</option>
            {meta.courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
          </select>
        </div>
        <div className="space-y-2">
          <Label htmlFor="fu-group">{t('المجموعة', 'Group')}</Label>
          <select
            id="fu-group"
            value={filters.group_id}
            onChange={(e) => apply({ group_id: e.target.value })}
            className="h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm"
          >
            <option value="">{t('كل المجموعات', 'All groups')}</option>
            {meta.groups
              .filter((g) => !filters.course_id || g.course_id === filters.course_id)
              .map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
          </select>
        </div>
        <div className="space-y-2">
          <Label htmlFor="fu-status">{t('الحالة', 'Status')}</Label>
          <select
            id="fu-status"
            value={filters.status}
            onChange={(e) => apply({ status: e.target.value })}
            className="h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm"
          >
            <option value="at_risk">{t('بحاجة متابعة', 'Need follow-up')}</option>
            <option value="all">{t('الكل', 'All')}</option>
            <option value="inactive">{t('غير نشط', 'Inactive')}</option>
            <option value="low_progress">{t('تقدّم منخفض', 'Low progress')}</option>
            <option value="overdue">{t('متأخر عن الاستحقاق', 'Overdue')}</option>
            <option value="no_activity_data">{t('لا توجد بيانات', 'No data')}</option>
            <option value="new">{t('جديد', 'New')}</option>
            <option value="active">{t('نشط', 'Active')}</option>
          </select>
        </div>
        <div className="space-y-2">
          <Label htmlFor="fu-days">{t('عتبة الغياب', 'Inactivity threshold')}</Label>
          <select
            id="fu-days"
            value={filters.inactive_days}
            onChange={(e) => apply({ inactive_days: e.target.value })}
            className="h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm"
          >
            <option value="7">{t('٧ أيام', '7 days')}</option>
            <option value="14">{t('١٤ يوم', '14 days')}</option>
            <option value="30">{t('٣٠ يوم', '30 days')}</option>
          </select>
        </div>
        <div className="space-y-2">
          <Label htmlFor="fu-progress">{t('عتبة التقدّم', 'Progress threshold')}</Label>
          <select
            id="fu-progress"
            value={filters.progress_below}
            onChange={(e) => apply({ progress_below: e.target.value })}
            className="h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm"
          >
            <option value="10">{t('أقل من ١٠٪', 'below 10%')}</option>
            <option value="25">{t('أقل من ٢٥٪', 'below 25%')}</option>
            <option value="30">{t('أقل من ٣٠٪', 'below 30%')}</option>
            <option value="50">{t('أقل من ٥٠٪', 'below 50%')}</option>
          </select>
        </div>
        <div className="space-y-2">
          <Label htmlFor="fu-sort">{t('الترتيب', 'Sort')}</Label>
          <select
            id="fu-sort"
            value={filters.sort}
            onChange={(e) => apply({ sort: e.target.value })}
            className="h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm"
          >
            <option value="risk">{t('الأهم متابعةً', 'Priority')}</option>
            <option value="inactive">{t('الأقدم نشاطاً', 'Least recent')}</option>
            <option value="progress">{t('الأقل تقدّماً', 'Lowest progress')}</option>
            <option value="name">{t('الاسم', 'Name')}</option>
          </select>
        </div>
      </div>

      {/* القائمة */}
      {loading ? (
        <CardSkeleton count={4} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={CheckCircle2}
          title={t('لا يوجد من يحتاج متابعة الآن', 'Nobody needs follow-up right now')}
          description={t('لا توجد بيانات نشاط كافية أو لا تنطبق الشروط الحالية على أي طالب.', 'Either activity data is insufficient or no student matches the current filters.')}
        />
      ) : (
        <div className="space-y-3">
          {rows.map((s) => {
            const status = STATUS_META[s.status] ?? STATUS_META.active;
            return (
              <Card key={s.id} className="border-0 shadow-sm transition hover:shadow-md">
                <CardContent className="flex flex-col gap-4 p-4 lg:flex-row lg:items-center">
                  {/* الهوية */}
                  <div className="flex min-w-0 flex-1 items-center gap-3">
                    {s.avatar_url ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={s.avatar_url} alt="" className="h-11 w-11 rounded-full object-cover" />
                    ) : (
                      <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-teal-500 to-emerald-400 font-bold text-white">
                        {s.name?.[0]?.toUpperCase()}
                      </div>
                    )}
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <p className="truncate font-bold">{s.name}</p>
                        <span data-testid="status-badge" className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${status.className}`}>
                          {locale === 'ar' ? status.ar : status.en}
                        </span>
                      </div>
                      <p className="truncate text-xs text-gray-500">{s.email || '—'}</p>
                      <p className="mt-0.5 flex items-center gap-1 text-[11px] text-gray-400">
                        <Clock className="h-3 w-3" />
                        {inactivityText(s)}
                      </p>
                    </div>
                  </div>

                  {/* التقدّم */}
                  <div className="w-full lg:w-52">
                    <div className="mb-1 flex items-center justify-between text-xs">
                      <span className="text-muted-foreground">{t('التقدّم', 'Progress')}</span>
                      <span className="font-semibold">{s.progress_percent}%</span>
                    </div>
                    <Progress
                      value={s.progress_percent}
                      barClassName={s.progress_percent < 30 ? 'bg-orange-500' : 'bg-teal-500'}
                    />
                    <p className="mt-1 text-[11px] text-gray-400">
                      {t(`${s.courses_count} كورس · ${s.lessons_completed} درس مكتمل`, `${s.courses_count} course(s) · ${s.lessons_completed} lesson(s) done`)}
                    </p>
                  </div>

                  {/* الأسباب */}
                  <div className="flex flex-wrap gap-1.5 lg:w-72">
                    {s.reasons.map((r, i) => (
                      <span key={i} className={`rounded-full border px-2 py-0.5 text-[11px] ${reasonTone(r.code)}`}>
                        {reasonLabel(r)}
                      </span>
                    ))}
                  </div>

                  {/* الإجراءات */}
                  <div className="flex shrink-0 items-center gap-2">
                    <Link
                      href={`/students?student=${s.id}`}
                      className="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold text-teal-700 hover:bg-teal-50"
                    >
                      <ExternalLink className="h-3.5 w-3.5" />
                      {t('الملف', 'Profile')}
                    </Link>
                    {s.email && (
                      <a
                        href={`mailto:${s.email}`}
                        className="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50"
                        title={t('مراسلة الطالب', 'Email the student')}
                      >
                        <Mail className="h-3.5 w-3.5" />
                      </a>
                    )}
                  </div>
                </CardContent>
              </Card>
            );
          })}
        </div>
      )}

      {/* الترقيم */}
      {!loading && meta.pagination.total > meta.pagination.per_page && (
        <div className="flex items-center justify-center gap-3 pt-2">
          <Button
            variant="outline"
            size="sm"
            disabled={filters.page <= 1}
            onClick={() => apply({ page: filters.page - 1 })}
            className="gap-1 rounded-full"
          >
            <ChevronRight className="h-4 w-4" />
            {t('السابق', 'Previous')}
          </Button>
          <span className="text-sm text-muted-foreground">
            {t(`صفحة ${filters.page} من ${meta.pagination.last_page}`, `Page ${filters.page} of ${meta.pagination.last_page}`)}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={filters.page >= meta.pagination.last_page}
            onClick={() => apply({ page: filters.page + 1 })}
            className="gap-1 rounded-full"
          >
            {t('التالي', 'Next')}
            <ChevronLeft className="h-4 w-4" />
          </Button>
        </div>
      )}
    </div>
  );
}
