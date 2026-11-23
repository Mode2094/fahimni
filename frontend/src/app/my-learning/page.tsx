'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { CardSkeleton } from '@/components/ui/skeleton';
import {
  BookOpen, CreditCard, Loader2, GraduationCap, CheckCircle, CalendarDays, Video,
  Clock, ExternalLink, Heart, Ticket, ArrowLeft, X, Sparkles, PlayCircle,
  TrendingUp, Award, CalendarClock, Hourglass, ChevronDown, RefreshCw, ClipboardCheck,
} from 'lucide-react';
import { toast } from 'sonner';
import { enrollments as enrollmentsApi, dashboardApi, favoritesApi, cardsApi, tenants, type StudentDashboardOverview } from '@/services/endpoints';
import { getApiErrorMessage } from '@/services/api';
import { buildLearnAssignmentUrl } from '@/lib/course-preview';

export default function MyLearningPage() {
  const { locale } = useLocale();
  const { user } = useAuth();
  const [enrollments, setEnrollments] = useState<any[]>([]);
  const [lessons, setLessons] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [courseLoadError, setCourseLoadError] = useState(false);
  const [lessonLoadError, setLessonLoadError] = useState(false);
  const [tasksLoadError, setTasksLoadError] = useState(false);
  const [learningTasks, setLearningTasks] = useState<StudentDashboardOverview['learning_tasks']>({
    due_assignments: [],
    upcoming_exams: [],
  });
  const [code, setCode] = useState('');
  const [redeeming, setRedeeming] = useState(false);
  const [redeemed, setRedeemed] = useState(false);
  const [favorites, setFavorites] = useState<Set<string>>(new Set());
  const [registrationExpanded, setRegistrationExpanded] = useState<boolean | null>(null);
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const loadDashboard = async () => {
    setLoading(true);
    setCourseLoadError(false);
    setLessonLoadError(false);
    setTasksLoadError(false);

    const [enrollmentResult, lessonResult, favoriteResult, overviewResult] = await Promise.allSettled([
      enrollmentsApi.myCourses(),
      dashboardApi.studentLessons(),
      favoritesApi.list(),
      dashboardApi.studentOverview(),
    ]);

    if (enrollmentResult.status === 'fulfilled' && Array.isArray(enrollmentResult.value)) {
      setEnrollments(enrollmentResult.value.map((e: any) => {
        const rawProgress = e.progress_percent;
        const parsedProgress = rawProgress === null || rawProgress === undefined || rawProgress === ''
          ? Number.NaN
          : Number(rawProgress);
        return {
          id: e.course_id || e.id,
          title: e.course?.title || t('كورس', 'Course'),
          progress: Number.isFinite(parsedProgress) ? Math.max(0, Math.min(100, parsedProgress)) : null,
          status: typeof e.status === 'string' ? e.status : 'unknown',
        };
      }));
    } else {
      setCourseLoadError(true);
    }

    if (lessonResult.status === 'fulfilled' && Array.isArray(lessonResult.value)) {
      setLessons(lessonResult.value);
    } else {
      setLessonLoadError(true);
    }

    if (favoriteResult.status === 'fulfilled' && Array.isArray(favoriteResult.value)) {
      setFavorites(new Set(favoriteResult.value.map((favorite: any) => favorite.id)));
    }

    const summary = overviewResult.status === 'fulfilled' ? overviewResult.value?.learning_tasks : null;
    if (summary
      && Array.isArray(summary.due_assignments)
      && Array.isArray(summary.upcoming_exams)) {
      setLearningTasks(summary);
    } else {
      setTasksLoadError(true);
    }

    setLoading(false);
  };

  useEffect(() => { void loadDashboard(); }, []);

  const refresh = () => { void loadDashboard(); };

  const toggleFavorite = async (courseId: string) => {
    try {
      const d = await favoritesApi.toggle(courseId);
      setFavorites(prev => {
        const n = new Set(prev);
        d.favorited ? n.add(courseId) : n.delete(courseId);
        return n;
      });
    } catch { toast.error(t('تعذر تحديث المفضلة', 'Could not update favorite')); }
  };

  const redeem = async () => {
    if (!code) return;
    setRedeeming(true);
    try {
      if (!localStorage.getItem('tenant_id')) {
        const list = await tenants.list();
        if (list[0]?.id) localStorage.setItem('tenant_id', list[0].id);
      }
      const d = await cardsApi.redeem(code);
      setRedeemed(true);
      toast.success(t('تم التسجيل بنجاح', 'Enrolled!'));
      setCode('');
      refresh();
    } catch (err) {
      toast.error(getApiErrorMessage(err, t('فشل', 'Failed')));
      setRedeeming(false);
    }
    setRedeeming(false);
  };

  const completed = enrollments.filter((e) => e.status === 'completed').length;
  const active = enrollments.filter((e) => e.status === 'active').length;
  const knownProgress = enrollments
    .map((course) => course.progress)
    .filter((progress): progress is number => typeof progress === 'number' && Number.isFinite(progress));
  const avgProgress = knownProgress.length
    ? Math.round(knownProgress.reduce((sum, progress) => sum + progress, 0) / knownProgress.length)
    : null;
  const orderedEnrollments = [...enrollments].sort(
    (a, b) => Number(a.status === 'completed') - Number(b.status === 'completed'),
  );
  const showRegistrationForm = registrationExpanded ?? (!loading && !courseLoadError && enrollments.length === 0);
  const hour = new Date().getHours();
  const greeting = hour < 12 ? t('صباح الخير', 'Good morning') : hour < 18 ? t('مساء الخير', 'Good afternoon') : t('مساء الخير', 'Good evening');
  const formatTaskDate = (value: string | null | undefined) => {
    const date = value ? new Date(value) : null;
    return date && Number.isFinite(date.getTime())
      ? date.toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
      : '—';
  };
  const renderCourseCard = (course: any) => (
    <div key={course.id} className="relative">
      <Link href={`/course/${course.id}/learn`}>
        <Card className="border-0 shadow-sm overflow-hidden card-hover">
          <div className="flex items-center gap-4 p-5">
            <div className="relative flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-600 to-emerald-500 shadow-brand">
              <BookOpen className="h-6 w-6 text-white" aria-hidden="true" />
              <span className="absolute -bottom-1.5 -end-1.5 flex h-6 w-6 items-center justify-center rounded-full bg-white shadow-md">
                <PlayCircle className="h-4 w-4 text-teal-600" aria-hidden="true" />
              </span>
            </div>
            <div className="min-w-0 flex-1">
              <h3 className="font-bold truncate">{course.title}</h3>
              <div className="mt-2.5 flex items-center gap-3">
                {course.progress !== null && (
                  <div
                    className="h-2.5 flex-1 max-w-xs overflow-hidden rounded-full bg-gray-100"
                    role="progressbar"
                    aria-label={t(`التقدم في ${course.title}`, `Progress in ${course.title}`)}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={course.progress}
                  >
                    <div className="h-full rounded-full bg-gradient-to-r from-teal-500 to-emerald-500 transition-all duration-700" style={{ width: `${course.progress}%` }} />
                  </div>
                )}
                <span className="shrink-0 text-sm font-black text-teal-700">{course.progress === null ? '—' : `${course.progress}%`}</span>
                {course.status === 'completed' ? (
                  <Badge className="shrink-0 rounded-full border-0 bg-emerald-100 text-emerald-700"><CheckCircle className="me-1 h-3 w-3" aria-hidden="true" />{t('مكتمل', 'Done')}</Badge>
                ) : course.status === 'active' ? (
                  <Badge className="shrink-0 rounded-full border-0 bg-teal-100 text-teal-700">{t('نشط', 'Active')}</Badge>
                ) : (
                  <Badge className="shrink-0 rounded-full border-0 bg-slate-100 text-slate-600">{t('الحالة غير متاحة', 'Status unavailable')}</Badge>
                )}
              </div>
              <p className="mt-1.5 text-[11px] text-gray-500">
                {course.progress === null
                  ? t('بيانات التقدم غير متاحة حالياً', 'Progress data is unavailable')
                  : course.progress === 0
                    ? t('ابدأ أول درس الآن', 'Start your first lesson now')
                    : t('تابع التعلم من هذا الكورس', 'Continue learning in this course')}
              </p>
            </div>
          </div>
        </Card>
      </Link>
      <button
        type="button"
        onClick={() => toggleFavorite(course.id)}
        aria-pressed={favorites.has(course.id)}
        className="absolute top-1/2 -translate-y-1/2 end-4 flex h-11 w-11 items-center justify-center rounded-full border bg-white shadow-sm transition hover:scale-105 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
        aria-label={favorites.has(course.id) ? t('إزالة من المفضلة', 'Remove from favorites') : t('إضافة للمفضلة', 'Add to favorites')}
      >
        <Heart className={`h-4 w-4 ${favorites.has(course.id) ? 'fill-rose-500 text-rose-500' : 'text-gray-500'}`} aria-hidden="true" />
      </button>
    </div>
  );

  return (
    <div className="space-y-6 max-w-5xl mx-auto px-4 sm:px-6">
      {/* ─── ترحيب ─────────────────────────────── */}
      <section className="relative overflow-hidden rounded-3xl p-6 sm:p-8" style={{ background: 'linear-gradient(135deg, #0f2e2b 0%, #115e54 55%, #0d9488 100%)' }}>
        <div className="absolute top-0 left-1/4 w-72 h-72 bg-teal-300/20 rounded-full blur-3xl" />
        <div className="absolute bottom-0 right-1/4 w-64 h-64 bg-emerald-300/15 rounded-full blur-3xl" />
        <div className="absolute inset-0 opacity-[0.07]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '26px 26px' }} />
        <div className="relative z-10 flex flex-wrap items-start justify-between gap-6">
          <div className="min-w-0 flex-1">
            <span className="inline-flex items-center gap-1.5 rounded-full glass px-3 py-1 text-xs font-medium text-white mb-3">
              <Sparkles className="h-3.5 w-3.5 text-teal-200" />
              {greeting}، {user?.name?.split(' ')[0] || t('طالبنا العزيز', 'Dear student')}
            </span>
            <h1 className="text-2xl sm:text-3xl font-bold text-white leading-snug">{t('رحلتك التعليمية', 'Your learning journey')}</h1>
            <p className="text-white/80 text-sm sm:text-base mt-1.5 max-w-lg">
              {t('تابع كورساتك، استكمل دروسك، وحقق أهدافك — التقدم يحفظ تلقائياً.', 'Track your courses, continue your lessons and reach your goals — progress is saved automatically.')}
            </p>
            <div className="flex flex-wrap gap-2.5 mt-5">
              <a href="/exams"><Button className="gap-2 rounded-full bg-white text-teal-800 hover:bg-teal-50 shadow-lg"><Award className="h-4 w-4" /> {t('الاختبارات', 'Exams')}</Button></a>
              <a href="/leaderboard"><Button variant="ghost" className="gap-2 rounded-full text-white border border-white/25 glass hover:bg-white/10"><TrendingUp className="h-4 w-4" /> {t('لوحة الأوائل', 'Leaderboard')}</Button></a>
            </div>
          </div>
          <div className="grid grid-cols-3 gap-2.5 min-w-[240px]">
            {[
              { icon: BookOpen, label: t('نشط', 'Active'), value: loading || courseLoadError ? '—' : active },
              { icon: CheckCircle, label: t('مكتمل', 'Done'), value: loading || courseLoadError ? '—' : completed },
              { icon: TrendingUp, label: t('متوسط التقدم', 'Avg'), value: loading || courseLoadError || avgProgress === null ? '—' : `${avgProgress}%` },
            ].map((s, i) => (
              <div key={i} className="glass rounded-2xl px-4 py-3 text-center">
                <s.icon className="mx-auto h-5 w-5 text-teal-200 mb-1.5" />
                <div className="text-xl font-black text-white">{s.value}</div>
                <div className="text-[10px] text-white/75 mt-0.5">{s.label}</div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ─── التسجيل برمز — أساسي للطالب الجديد، اختياري للبقية ─── */}
      <section className="overflow-hidden rounded-3xl border border-teal-100 bg-[#0b1a26] text-white shadow-sm">
        <button
          type="button"
          onClick={() => setRegistrationExpanded(!showRegistrationForm)}
          aria-expanded={showRegistrationForm}
          aria-controls="student-enrollment-form"
          className="flex min-h-14 w-full items-center gap-3 p-4 text-start transition hover:bg-white/5"
        >
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 text-amber-300">
            <CreditCard className="h-5 w-5" aria-hidden="true" />
          </span>
          <span className="min-w-0 flex-1">
            <span className="block font-semibold">
              {enrollments.length > 0 ? t('إدخال بطاقة تسجيل', 'Enter another registration card') : t('سجل في كورس ببطاقة تسجيل', 'Enroll with a registration card')}
            </span>
            <span className="block text-xs text-white/55">
              {enrollments.length > 0
                ? t('يمكنك إضافة كورس آخر برمز معلمك.', 'Join another course with a teacher code.')
                : t('أدخل رمز البطاقة الذي حصلت عليه من معلمك.', 'Enter the registration code from your teacher.')}
            </span>
          </span>
          <ChevronDown className={`h-5 w-5 shrink-0 text-white/65 transition-transform ${showRegistrationForm ? 'rotate-180' : ''}`} aria-hidden="true" />
        </button>
        <div id="student-enrollment-form" hidden={!showRegistrationForm} className="border-t border-white/10 p-4 sm:p-5">
          <div className="flex flex-col gap-2.5 sm:flex-row">
            <Input
              value={code}
              onChange={(e) => setCode(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && redeem()}
              placeholder={t('XXXX-XXXX-XXXX', 'XXXX-XXXX-XXXX')}
              aria-label={t('رمز البطاقة', 'Card code')}
              className="h-12 flex-1 rounded-xl border-white/15 bg-white/10 text-center font-mono tracking-[0.2em] text-white placeholder:text-white/35"
              dir="ltr"
              autoComplete="off"
            />
            <Button onClick={redeem} disabled={redeeming || !code.trim()} className="h-12 min-h-11 shrink-0 rounded-xl px-7 text-white shadow-lg" style={{ background: 'linear-gradient(135deg, #d97706, #f59e0b)' }}>
              {redeeming ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : <Ticket className="h-4 w-4" aria-hidden="true" />}
              <span className="ms-1.5">{redeeming ? t('جارٍ التسجيل…', 'Enrolling…') : t('تسجيل', 'Redeem')}</span>
            </Button>
          </div>
        </div>
      </section>
      {redeemed && (
        <Card className="border border-emerald-400/25 bg-emerald-500/10 shadow-none" role="status">
          <CardContent className="flex items-center justify-between gap-3 p-3 text-sm text-emerald-800">
            <span className="flex items-center gap-2"><CheckCircle className="h-5 w-5 shrink-0" aria-hidden="true" />{t('تم التسجيل بنجاح — افتح كورسك الآن', 'Enrolled — open your course now')}</span>
            <button type="button" onClick={() => setRedeemed(false)} aria-label={t('إغلاق رسالة التسجيل', 'Dismiss enrollment message')} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg hover:bg-emerald-500/10">
              <X className="h-4 w-4" aria-hidden="true" />
            </button>
          </CardContent>
        </Card>
      )}

      {/* ─── واجبات واختبارات قريبة من بيانات الطالب ─── */}
      <section className="space-y-3" aria-labelledby="student-learning-tasks-title" data-testid="student-learning-tasks">
        <div className="flex items-center justify-between gap-3">
          <h2 id="student-learning-tasks-title" className="flex items-center gap-2 font-bold text-lg">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10">
              <ClipboardCheck className="h-4.5 w-4.5 text-amber-700" aria-hidden="true" />
            </span>
            {t('مهامك القادمة', 'Upcoming work')}
          </h2>
          <Link href="/exams" className="inline-flex min-h-11 items-center gap-1 rounded-lg px-2 text-sm font-semibold text-teal-700 hover:bg-teal-50">
            {t('الاختبارات', 'Exams')}<ArrowLeft className="h-4 w-4 rtl:rotate-0 ltr:rotate-180" aria-hidden="true" />
          </Link>
        </div>

        {loading ? (
          <CardSkeleton />
        ) : tasksLoadError ? (
          <Card className="border border-amber-200 bg-amber-50/70 shadow-none" role="alert">
            <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
              <p className="text-sm text-amber-900">{t('تعذر تحميل واجباتك واختباراتك؛ لم نعرض مهاماً تقديرية.', 'Could not load your assignments and exams; no estimated tasks are shown.')}</p>
              <Button variant="outline" onClick={loadDashboard} disabled={loading} className="min-h-11 gap-2" aria-label={t('إعادة تحميل المهام', 'Retry tasks')}>
                <RefreshCw className="h-4 w-4" aria-hidden="true" />{t('إعادة المحاولة', 'Retry')}
              </Button>
            </CardContent>
          </Card>
        ) : learningTasks.due_assignments.length === 0 && learningTasks.upcoming_exams.length === 0 ? (
          <Card className="border-0 shadow-sm">
            <CardContent className="flex items-center gap-3 p-4 text-sm text-muted-foreground" role="status">
              <CheckCircle className="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true" />
              {t('لا توجد واجبات أو اختبارات مستحقة خلال الأيام القادمة.', 'No assignments or exams are due in the coming days.')}
            </CardContent>
          </Card>
        ) : (
          <div className="grid gap-3 sm:grid-cols-2">
            {learningTasks.due_assignments.map((assignment) => (
              <Link
                key={`assignment-${assignment.id}`}
                href={buildLearnAssignmentUrl(assignment.course_id, assignment.id)}
                className="flex min-h-[92px] items-center gap-3 rounded-2xl border border-amber-100 bg-white p-4 shadow-sm transition hover:border-amber-200 hover:bg-amber-50/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
              >
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-500/10 text-amber-700">
                  <ClipboardCheck className="h-5 w-5" aria-hidden="true" />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-bold">{assignment.title}</span>
                  <span className="block truncate text-xs text-muted-foreground">{assignment.course_title || t('كورس', 'Course')}</span>
                  <span className={`mt-1 block text-xs font-semibold ${assignment.is_overdue ? 'text-rose-600' : 'text-amber-700'}`}>
                    {assignment.is_overdue
                      ? t('متأخر — يقبل التسليم المتأخر', 'Overdue — late submissions accepted')
                      : `${t('موعد التسليم', 'Due')}: ${formatTaskDate(assignment.due_at)}`}
                  </span>
                </span>
                <ArrowLeft className="h-4 w-4 shrink-0 text-teal-700 rtl:rotate-0 ltr:rotate-180" aria-hidden="true" />
              </Link>
            ))}
            {learningTasks.upcoming_exams.map((exam) => (
              <Link
                key={`exam-${exam.id}`}
                href={`/exam/${encodeURIComponent(exam.id)}`}
                className="flex min-h-[92px] items-center gap-3 rounded-2xl border border-violet-100 bg-white p-4 shadow-sm transition hover:border-violet-200 hover:bg-violet-50/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
              >
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-violet-500/10 text-violet-700">
                  <Award className="h-5 w-5" aria-hidden="true" />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-bold">{exam.title}</span>
                  <span className="block truncate text-xs text-muted-foreground">{exam.course_title || t('كورس', 'Course')}</span>
                  <span className="mt-1 block text-xs text-violet-700">
                    {t('ينتهي', 'Closes')}: {formatTaskDate(exam.ends_at)}
                  </span>
                </span>
                <ArrowLeft className="h-4 w-4 shrink-0 text-teal-700 rtl:rotate-0 ltr:rotate-180" aria-hidden="true" />
              </Link>
            ))}
          </div>
        )}
      </section>

      {/* ─── الدروس المجدولة ────────────────────── */}
      {(lessons.length > 0 || lessonLoadError) && (
        <section>
          <h2 className="font-bold text-lg mb-3 flex items-center gap-2">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-500/10"><CalendarDays className="h-4.5 w-4.5 text-violet-600" /></span>
            {t('دروسي المجدولة', 'My scheduled lessons')}
          </h2>
          {lessonLoadError ? (
            <Card className="border border-amber-200 bg-amber-50/70 shadow-none" role="alert">
              <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
                <p className="text-sm text-amber-900">{t('تعذر تحميل مواعيد دروسك.', 'Could not load your scheduled lessons.')}</p>
                <Button variant="outline" onClick={loadDashboard} disabled={loading} className="min-h-11 gap-2" aria-label={t('إعادة تحميل المواعيد', 'Retry lessons')}>
                  <RefreshCw className="h-4 w-4" aria-hidden="true" />{t('إعادة المحاولة', 'Retry')}
                </Button>
              </CardContent>
            </Card>
          ) : (
            <div className="space-y-3">
              {lessons.map((l) => {
              const d = new Date(l.instance_date + 'T00:00:00');
              return (
                <Card key={l.instance_key} className="border-0 shadow-sm p-5 transition-all hover:shadow-md">
                  <div className="flex flex-wrap items-start gap-4">
                    <div className="flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500 to-purple-600 text-white shadow-lg shadow-violet-500/25">
                      <span className="text-lg font-black leading-none">{d.getDate()}</span>
                      <span className="text-[10px] opacity-80 mt-0.5">{d.toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US', { month: 'short' })}</span>
                    </div>
                    <div className="flex-1 min-w-[200px]">
                      <h3 className="font-bold">{l.title}</h3>
                      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1 text-sm text-gray-500">
                        <span className="flex items-center gap-1"><Clock className="h-3.5 w-3.5 text-violet-500" />{d.toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US', { weekday: 'long', day: 'numeric', month: 'long' })}{l.start_time ? ` · ${l.start_time.slice(0, 5)}` : ''}</span>
                      </div>
                      <div className="flex flex-wrap gap-1.5 mt-2">
                        {l.is_recurring && <span className="inline-flex items-center gap-1 rounded-full bg-violet-50 px-2.5 py-0.5 text-[10px] font-semibold text-violet-600"><Hourglass className="h-3 w-3" />{t('تكرر أسبوعي', 'Weekly')}</span>}
                        {l.is_today && <span className="inline-flex items-center gap-1 rounded-full bg-teal-50 px-2.5 py-0.5 text-[10px] font-semibold text-teal-700"><CalendarClock className="h-3 w-3" />{t('اليوم', 'Today')}</span>}
                      </div>
                    </div>
                    {l.meeting_url ? (
                      <a href={l.meeting_url} target="_blank" rel="noreferrer">
                        <Button className="gap-1.5 rounded-full text-white shadow-brand min-h-[44px] px-4" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
                          <Video className="h-3.5 w-3.5" />{t('الانضمام للدرس', 'Join lesson')}<ExternalLink className="h-3 w-3" />
                        </Button>
                      </a>
                    ) : l.course_id ? (
                      // Students never receive the raw meeting link here; the
                      // course lesson page releases it once enrollment is
                      // verified, so link them into the course instead.
                      <Link href={`/course/${l.course_id}/learn`}>
                        <Button variant="outline" className="gap-1.5 rounded-full min-h-[44px] px-4">
                          <BookOpen className="h-3.5 w-3.5" />{t('فتح الدرس في الكورس', 'Open in course')}
                        </Button>
                      </Link>
                    ) : null}
                  </div>
                </Card>
              );
              })}
            </div>
          )}
        </section>
      )}

      {/* ─── الكورسات ───────────────────────────── */}
      <section>
        <h2 className="font-bold text-lg mb-3 flex items-center gap-2">
          <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-500/10"><BookOpen className="h-4.5 w-4.5 text-teal-600" /></span>
          {t('كورساتي', 'My courses')}
        </h2>

        {loading ? (
          <CardSkeleton />
        ) : courseLoadError ? (
          <Card className="border border-amber-200 bg-amber-50/70 shadow-none" role="alert">
            <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
              <p className="text-sm text-amber-900">{t('تعذر تحميل كورساتك الآن', 'Could not load your courses right now')}</p>
              <Button variant="outline" onClick={loadDashboard} disabled={loading} className="min-h-11 gap-2" aria-label={t('إعادة تحميل الكورسات', 'Retry courses')}>
                <RefreshCw className="h-4 w-4" aria-hidden="true" />{t('إعادة المحاولة', 'Retry')}
              </Button>
            </CardContent>
          </Card>
        ) : enrollments.length === 0 ? (
          <Card className="border-0 shadow-sm overflow-hidden">
            <CardContent className="flex flex-col items-center gap-4 py-14 text-center">
              <div className="flex h-20 w-20 items-center justify-center rounded-3xl bg-gradient-to-br from-teal-50 to-emerald-50 border border-teal-100">
                <GraduationCap className="h-10 w-10 text-teal-300" />
              </div>
              <div>
                <p className="text-xl font-bold">{t('لم تسجل في أي كورس بعد', 'No enrollments yet')}</p>
                <p className="text-gray-500 text-sm mt-2 max-w-sm">
                  {t('احصل على بطاقة تسجيل من معلمك وأدخل رمزها أعلاه ليظهر كورسك هنا فوراً', 'Get an enrollment card from your teacher and enter its code above to start learning')}
                </p>
              </div>
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-3">
            {orderedEnrollments.filter((course) => course.status !== 'completed').map(renderCourseCard)}
            {orderedEnrollments.filter((course) => course.status === 'completed').length > 0 && (
              <details className="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                <summary className="min-h-11 cursor-pointer list-none font-semibold text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600">
                  <span className="inline-flex items-center gap-2">
                    <CheckCircle className="h-4 w-4 text-emerald-600" aria-hidden="true" />
                    {t('الكورسات المكتملة', 'Completed courses')}
                    <Badge className="border-0 bg-slate-100 text-slate-600">
                      {orderedEnrollments.filter((course) => course.status === 'completed').length}
                    </Badge>
                  </span>
                </summary>
                <div className="mt-3 space-y-3">
                  {orderedEnrollments.filter((course) => course.status === 'completed').map(renderCourseCard)}
                </div>
              </details>
            )}
          </div>
        )}
      </section>
    </div>
  );
}
