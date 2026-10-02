'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from 'sonner';
import { Users, BookOpen, ClipboardList, FileQuestion, Plus, Crown, CalendarClock, Clock, Video, Film, Radio, Activity, Wallet, ChevronLeft, ChevronRight, Smartphone, Copy, Check, Construction } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function TeacherDashboard() {
  const { user, check } = useAuth();
  const { locale } = useLocale();
  const { settings, load: loadSettings } = useSettings();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [plan, setPlan] = useState<any>(null);
  const [usage, setUsage] = useState<any>(null);
  const [analytics, setAnalytics] = useState<any>(null);
  const [lessons, setLessons] = useState<any[]>([]);
  const [bookings, setBookings] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [studentApp, setStudentApp] = useState<any>(null);
  const [copied, setCopied] = useState(false);

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const loadAll = async () => {
    setLoading(true);
    const [planR, anaR, lesR, bkR, appR] = await Promise.all([
      fetch(`${API}/teacher/plan`, { headers: headers() }),
      fetch(`${API}/teacher/analytics?days=30`, { headers: headers() }),
      fetch(`${API}/teacher/scheduled-lessons`, { headers: headers() }).catch(() => null),
      fetch(`${API}/teacher/bookings`, { headers: headers() }).catch(() => null),
      fetch(`${API}/teacher/student-app`, { headers: headers() }).catch(() => null),
    ]);

    if (planR.ok) { const d = await planR.json(); setPlan(d.plan || null); setUsage(d.usage || null); }
    if (anaR.ok) { const d = await anaR.json(); setAnalytics(d.overview || null); }
    if (lesR?.ok) { const d = await lesR.json(); setLessons(d.lessons || d.scheduled_lessons || []); }
    if (bkR?.ok) { const d = await bkR.json(); setBookings(Array.isArray(d.bookings) ? d.bookings : d.bookings?.data || []); }
    if (appR?.ok) { const d = await appR.json(); setStudentApp(d.student_app || null); }

    setLoading(false);
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

  const hasData = (analytics?.total_attempts ?? 0) > 0 || (analytics?.watch_seconds ?? 0) > 0;

  if (loading) {
    return (
    <div className="space-y-6 max-w-6xl mx-auto px-4 sm:px-6">
        <Skeleton className="h-24 w-full" />
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4"><Skeleton className="h-24" /><Skeleton className="h-24" /><Skeleton className="h-24" /><Skeleton className="h-24" /></div>
        <Skeleton className="h-48 w-full" />
        <div className="grid lg:grid-cols-2 gap-4"><Skeleton className="h-40" /><Skeleton className="h-40" /></div>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      {/* ─── الترحيب + الخطة ─────────────────────────── */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl sm:text-3xl font-bold">{greeting}، {user?.name || t('معلم', 'Teacher')}</h1>
          <p className="text-gray-500 text-sm mt-1">{t('إليك ملخص منصتك اليوم', 'Here is your platform summary today')}</p>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          {plan && (
            <a href="/pricing" className="flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-4 py-2 hover:bg-amber-100/70 transition">
              <Crown className="h-4 w-4 text-amber-500" />
              <span className="text-sm font-bold text-amber-700">{plan.plan_name || plan.name}</span>
              <span className="flex items-center gap-1 text-xs text-emerald-600 font-semibold"><span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />{t('نشطة', 'Active')}</span>
              <ChevronLeft className="h-3.5 w-3.5 text-amber-500" />
            </a>
          )}
          <a href="/courses"><Button className="gap-2 rounded-full"><Plus className="h-4 w-4" />{t('إنشاء كورس', 'New course')}</Button></a>
          <a href="/courses"><Button variant="outline" className="gap-2 rounded-full">{t('إدارة الكورسات', 'Manage courses')}</Button></a>
        </div>
      </div>

      {/* ─── الإحصائيات الرئيسية (4 فقط) ────────────── */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[
          { label: t('الطلاب', 'Students'), value: usage?.students?.used ?? 0, href: '/students', icon: Users },
          { label: t('الكورسات', 'Courses'), value: usage?.courses?.used ?? 0, href: '/courses', icon: BookOpen },
          { label: t('الاختبارات', 'Exams'), value: usage?.exams?.used ?? 0, href: '/courses', icon: ClipboardList },
          { label: t('المحاولات', 'Attempts'), value: analytics?.total_attempts ?? 0, href: '/analytics', icon: FileQuestion },
        ].map((s, i) => (
          <a key={i} href={s.href}>
            <Card className="border-0 shadow-sm hover:shadow-md transition">
              <CardContent className="p-5 flex items-center gap-4">
                <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-500/10"><s.icon className="h-5 w-5 text-teal-600" /></div>
                <div>
                  <p className="text-2xl font-bold">{s.value}</p>
                  <p className="text-sm text-gray-500">{s.label}</p>
                </div>
              </CardContent>
            </Card>
          </a>
        ))}
      </div>

      {/* ─── تطبيق الطلاب (Student App) ─────────────── */}
      {!settings.student_app_hidden && studentApp && (
        <Card className="border-0 shadow-sm">
          <CardContent className="p-5 flex flex-wrap items-center gap-4">
            <span className={`flex h-12 w-12 items-center justify-center rounded-2xl ${studentApp.enabled ? 'bg-teal-500/10' : 'bg-amber-50'}`}>
              {studentApp.enabled ? <Smartphone className="h-6 w-6 text-teal-600" /> : <Construction className="h-6 w-6 text-amber-500" />}
            </span>
            <div className="flex-1 min-w-[200px]">
              <p className="font-bold flex items-center gap-2">
                {t('تطبيق الطلاب', 'Student App')}
                {studentApp.enabled
                  ? <Badge className="bg-green-100 text-green-700 border-0">{t('مفعّل', 'Active')}</Badge>
                  : <Badge className="bg-amber-50 text-amber-700 border-0">{t('قيد التطوير', 'Under development')}</Badge>}
              </p>
              {studentApp.enabled ? (
                <p className="text-sm text-gray-500 mt-0.5">
                  {t('كود المعلم:', 'Teacher Code:')} <b className="text-teal-700 font-mono tracking-wider" dir="ltr">{studentApp.teacher_code}</b>
                </p>
              ) : (
                <p className="text-sm text-gray-400 mt-0.5">{t('سنخبرك عند توفر التطبيق.', 'We will let you know when the app is available.')}</p>
              )}
            </div>
            <div className="flex items-center gap-2">
              {studentApp.enabled && (
                <Button size="sm" variant="outline" className="gap-1.5 rounded-full" onClick={async () => {
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
              <a href="/teacher/student-app">
                <Button size="sm" className="gap-1.5 rounded-full">{t('التفاصيل', 'Details')}<ChevronLeft className="h-3.5 w-3.5" /></Button>
              </a>
            </div>
          </CardContent>
        </Card>
      )}

      {/* ─── استخدام خطتك (مصدر واحد موحد) ──────────── */}
      <Card className="border-0 shadow-sm">
        <CardContent className="p-6">
          <h2 className="font-bold mb-1 flex items-center gap-2"><Activity className="h-4 w-4 text-teal-600" />{t('استخدام خطتك', 'Your plan usage')}</h2>
          <p className="text-xs text-gray-400 mb-5">{t('الاستخدام مقابل حدود خطتك الحالية', 'Usage vs your current plan limits')}</p>
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
            {upUsage.map((it) => {
              const e = usage?.[it.key];
              if (!e) return null;
              const used = e.unit === 'bytes' ? fmtNum('storage', e.used) : String(e.used);
              const limit = e.unlimited ? t('غير محدود', 'Unlimited') : e.unit === 'bytes' ? fmtNum('storage', e.limit ?? 0) : String(e.limit ?? 0);
              const pct = e.unlimited ? 0 : Math.min(100, e.pct ?? 0);
              const state = e.unlimited ? t('غير محدود', 'Unlimited') : pct >= 90 ? t('قارب على الحد', 'Near limit') : t('ضمن الحد', 'Within limit');
              return (
                <div key={it.key} className="rounded-2xl border border-slate-100 bg-slate-50/50 p-4">
                  <p className="text-xs text-gray-500 font-semibold mb-1">{it.label}</p>
                  <p className="text-base font-bold">{used} <span className="text-xs font-medium text-gray-400">/ {limit}</span></p>
                  <div className="mt-2 h-1.5 rounded-full bg-slate-200/70">
                    {!e.unlimited && <div className={`h-full rounded-full ${pct >= 90 ? 'bg-rose-500' : pct >= 70 ? 'bg-amber-400' : 'bg-teal-500'}`} style={{ width: `${pct}%` }} />}
                  </div>
                  <p className={`mt-1.5 text-xs ${pct >= 90 && !e.unlimited ? 'text-rose-600' : 'text-gray-400'}`}>{state}{!e.unlimited && pct > 0 ? ` · ${Math.round(pct)}%` : ''}</p>
                </div>
              );
            })}
          </div>

          {/* الوسائط: فيديوهات/بث/تسجيلات + التكلفة (بلا تكرار التخزين) */}
          <div className="mt-5 border-t border-slate-100 pt-5">
            <h3 className="text-sm font-bold mb-3 flex items-center gap-2"><Film className="h-4 w-4 text-teal-600" />{t('الوسائط', 'Media')}</h3>
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              {[
                { label: t('الفيديوهات', 'Videos'), value: `${usage?.videos?.used ?? 0} / ${usage?.videos?.unlimited ? '∞' : (usage?.videos?.limit ?? 0)}` },
                { label: t('جلسات البث', 'Live sessions'), value: `${usage?.live_sessions?.used ?? 0} / ${usage?.live_sessions?.unlimited ? '∞' : (usage?.live_sessions?.limit ?? 0)}` },
                { label: t('التسجيلات', 'Recordings'), value: `${usage?.recordings?.used ?? 0} / ${usage?.recordings?.unlimited ? '∞' : (usage?.recordings?.limit ?? 0)}` },
                { label: t('التكلفة التقديرية (الشهر)', 'Est. cost (month)'), value: `$${(Number((analytics as any)?.monthly_infra_cost ?? 0)).toFixed(2)}`, muted: true },
              ].map((m, i) => (
                <div key={i} className={`rounded-xl border border-slate-100 px-4 py-3 ${m.muted ? 'bg-teal-50/50' : ''}`}>
                  <p className="text-xs text-gray-500 font-semibold">{m.label}</p>
                  <p className="text-base font-bold mt-0.5">{m.value}</p>
                </div>
              ))}
            </div>
          </div>
        </CardContent>
      </Card>

      {/* ─── القادم: دروس + مواعيد ──────────────────── */}
      <div className="grid lg:grid-cols-2 gap-6">
        {/* الدروس القادمة */}
        <Card className="border-0 shadow-sm">
          <CardContent className="p-6">
            <h2 className="font-bold mb-4 flex items-center gap-2"><CalendarClock className="h-4 w-4 text-teal-600" />{t('الدروس القادمة', 'Upcoming lessons')}</h2>
            {lessons.length === 0 ? (
              <div className="text-center py-6 space-y-2">
                <p className="text-sm text-gray-400">{t('لا توجد دروس مجدولة', 'No scheduled lessons')}</p>
              </div>
            ) : (
              <div className="space-y-2">
                {lessons.slice(0, 3).map((l, i) => (
                  <div key={i} className="flex items-center gap-3 rounded-xl border border-slate-100 p-3">
                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-50"><Clock className="h-4 w-4 text-teal-600" /></span>
                    <div className="flex-1 min-w-0">
                      <p className="font-medium text-sm truncate">{l.title}</p>
                      <p className="text-xs text-gray-400">{l.scheduled_at ? new Date(l.scheduled_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) : ''}</p>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        {/* المواعيد القادمة */}
        <Card className="border-0 shadow-sm">
          <CardContent className="p-6">
            <h2 className="font-bold mb-4 flex items-center gap-2"><CalendarClock className="h-4 w-4 text-teal-600" />{t('المواعيد القادمة', 'Upcoming appointments')}</h2>
            {upcomingBookings.length === 0 ? (
              <div className="text-center py-6">
                <p className="text-sm text-gray-400">{t('لا توجد مواعيد قادمة', 'No upcoming appointments')}</p>
              </div>
            ) : (
              <div className="space-y-2">
                {upcomingBookings.map((b, i) => (
                  <div key={i} className="flex items-center gap-3 rounded-xl border border-slate-100 p-3">
                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-50"><Users className="h-4 w-4 text-violet-600" /></div>
                    <div className="flex-1 min-w-0">
                      <p className="font-medium text-sm truncate">{b.student?.name || b.title || t('طالب', 'Student')}</p>
                      <p className="text-xs text-gray-400">{b.date ? new Date(b.date + (b.time ? 'T' + b.time : '')).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) : ''}</p>
                    </div>
                    <Badge className={b.status === 'confirmed' ? 'bg-emerald-50 text-emerald-600 border-0' : b.status === 'pending' ? 'bg-amber-50 text-amber-600 border-0' : 'bg-gray-100 text-gray-500 border-0'}>
                      {b.status === 'confirmed' ? t('مؤكد', 'Confirmed') : b.status === 'pending' ? t('معلق', 'Pending') : b.status}
                    </Badge>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* ─── الأداء ──────────────────────────────────── */}
      <Card className="border-0 shadow-sm">
        <CardContent className="p-6">
          <h2 className="font-bold mb-4 flex items-center gap-2"><Activity className="h-4 w-4 text-teal-600" />{t('الأداء', 'Performance')}</h2>
          {!hasData ? (
            <p className="text-sm text-gray-400 text-center py-4">{t('لا توجد بيانات كافية بعد — أضف اختبارات وفيديوهات لطلابك', 'Not enough data yet — add exams and videos for your students')}</p>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              {perf.map((p, i) => (
                <div key={i} className="rounded-2xl border border-slate-100 bg-slate-50/50 p-4 text-center">
                  <p className="text-2xl font-bold">
                    {p.key === 'completion' ? `${analytics?.completion_rate ?? 0}%` : p.key === 'watch' ? `${Math.round((analytics?.watch_seconds ?? 0) / 3600)} ${t('ساعة', 'h')}` : `${analytics?.pass_rate ?? 0}%`}
                  </p>
                  <p className="text-sm text-gray-500 mt-1">{p.label}</p>
                </div>
              ))}
            </div>
          )}
          <div className="mt-4 text-end">
            <a href="/analytics" className="text-sm text-teal-700 font-semibold hover:underline inline-flex items-center gap-1 py-2 px-1 min-h-[44px]">{locale === 'ar' ? <ChevronLeft className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}{t('التفاصيل في صفحة التحليلات', 'Details in Analytics')}</a>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
