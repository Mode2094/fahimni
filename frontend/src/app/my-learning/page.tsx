'use client';

import { useEffect, useState } from 'react';
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
  TrendingUp, Award, CalendarClock, Hourglass,
} from 'lucide-react';
import { toast } from 'sonner';
import { enrollments as enrollmentsApi, dashboardApi, favoritesApi, cardsApi, tenants } from '@/services/endpoints';
import { getApiErrorMessage } from '@/services/api';

export default function MyLearningPage() {
  const { locale } = useLocale();
  const { user } = useAuth();
  const [enrollments, setEnrollments] = useState<any[]>([]);
  const [lessons, setLessons] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [code, setCode] = useState('');
  const [redeeming, setRedeeming] = useState(false);
  const [redeemed, setRedeemed] = useState(false);
  const [favorites, setFavorites] = useState<Set<string>>(new Set());
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  useEffect(() => {
    Promise.all([
      enrollmentsApi.myCourses().catch(() => [] as any[]),
      dashboardApi.studentLessons().catch(() => [] as any[]),
      favoritesApi.list().catch(() => [] as any[]),
    ]).then(([enrolled, lessonList, favList]) => {
      setEnrollments(enrolled.map((e: any) => ({ id: e.course_id || e.id, title: e.course?.title || 'Course', progress: Number(e.progress_percent) || 0, status: e.status || 'active' })));
      setLessons(lessonList || []);
      setFavorites(new Set((favList || []).map((f: any) => f.id)));
    }).finally(() => setLoading(false));
  }, []);

  const refresh = () => {
    Promise.all([
      enrollmentsApi.myCourses().catch(() => [] as any[]),
      dashboardApi.studentLessons().catch(() => [] as any[]),
    ]).then(([enrolled, lessonList]) => {
      setEnrollments(enrolled.map((e: any) => ({ id: e.course_id || e.id, title: e.course?.title || 'Course', progress: Number(e.progress_percent) || 0, status: e.status || 'active' })));
      setLessons(lessonList || []);
    });
  };

  const toggleFavorite = async (courseId: string) => {
    try {
      const d = await favoritesApi.toggle(courseId);
      setFavorites(prev => {
        const n = new Set(prev);
        d.favorited ? n.add(courseId) : n.delete(courseId);
        return n;
      });
    } catch { toast.error(t('فشل', 'Failed')); }
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
  const avgProgress = enrollments.length ? Math.round(enrollments.reduce((a, c) => a + c.progress, 0) / enrollments.length) : 0;
  const hour = new Date().getHours();
  const greeting = hour < 12 ? t('صباح الخير', 'Good morning') : hour < 18 ? t('مساء الخير', 'Good afternoon') : t('مساء الخير', 'Good evening');

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
            <p className="text-white/65 text-sm sm:text-base mt-1.5 max-w-lg">
              {t('تابع كورساتك، استكمل دروسك، وحقق أهدافك — التقدم يحفظ تلقائياً.', 'Track your courses, continue your lessons and reach your goals — progress is saved automatically.')}
            </p>
            <div className="flex flex-wrap gap-2.5 mt-5">
              <a href="/exams"><Button className="gap-2 rounded-full bg-white text-teal-800 hover:bg-teal-50 shadow-lg"><Award className="h-4 w-4" /> {t('الاختبارات', 'Exams')}</Button></a>
              <a href="/leaderboard"><Button variant="ghost" className="gap-2 rounded-full text-white border border-white/25 glass hover:bg-white/10"><TrendingUp className="h-4 w-4" /> {t('لوحة الأوائل', 'Leaderboard')}</Button></a>
            </div>
          </div>
          <div className="grid grid-cols-3 gap-2.5 min-w-[240px]">
            {[
              { icon: BookOpen, label: t('نشط', 'Active'), value: active },
              { icon: CheckCircle, label: t('مكتمل', 'Done'), value: completed },
              { icon: TrendingUp, label: t('متوسط التقدم', 'Avg'), value: `${avgProgress}%` },
            ].map((s, i) => (
              <div key={i} className="glass rounded-2xl px-4 py-3 text-center">
                <s.icon className="mx-auto h-5 w-5 text-teal-200 mb-1.5" />
                <div className="text-xl font-black text-white">{s.value}</div>
                <div className="text-[10px] text-white/60 mt-0.5">{s.label}</div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ─── تفعيل بطاقة ────────────────────────── */}
      <section className="relative overflow-hidden rounded-3xl p-[2px]" style={{ background: 'linear-gradient(135deg, #0d9488, #d97706, #0d9488)' }}>
        <div className="rounded-[calc(1.5rem-2px)] bg-[#0b1a26] p-6 sm:p-7">
          <div className="flex flex-wrap items-start justify-between gap-6">
            <div className="flex items-start gap-4 flex-1 min-w-[260px]">
              <div className="flex h-13 w-13 shrink-0 items-center justify-center rounded-2xl bg-white/10 border border-white/10">
                <CreditCard className="h-6 w-6 text-amber-300" />
              </div>
              <div>
                <h2 className="font-bold text-white text-lg">{t('سجل في كورس ببطاقة تسجيل', 'Enroll with a registration card')}</h2>
                <p className="text-white/55 text-sm mt-1">{t('أدخل رمز البطاقة الذي حصلت عليه من معلمك — تسجيل فوري بدون أي تعبئة', 'Enter the card code from your teacher — instant enrollment, no forms')}</p>
              </div>
            </div>
            {redeemed ? (
              <div className="w-full sm:w-auto pop-in flex items-center justify-between gap-3 rounded-2xl bg-emerald-500/15 border border-emerald-400/25 px-4 py-3 text-emerald-300 text-sm">
                <span className="flex items-center gap-2"><CheckCircle className="h-5 w-5 shrink-0" />{t('تم التسجيل بنجاح — افتح كورسك الآن', 'Enrolled — open your course now')}</span>
                <button onClick={() => setRedeemed(false)} className="text-white/50 hover:text-white transition-colors"><X className="h-4 w-4" /></button>
              </div>
            ) : (
              <div className="flex flex-col sm:flex-row gap-2.5 w-full sm:w-auto sm:min-w-[380px]">
                <Input
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && redeem()}
                  placeholder={t('XXXX-XXXX-XXXX', 'XXXX-XXXX-XXXX')}
                  className="h-12 flex-1 bg-white/8 border-white/15 text-white placeholder:text-white/30 font-mono text-center tracking-[0.2em] rounded-xl"
                  dir="ltr"
                />
                <Button onClick={redeem} disabled={redeeming || !code} className="h-12 px-7 rounded-xl text-white shrink-0 shadow-lg" style={{ background: 'linear-gradient(135deg, #d97706, #f59e0b)' }}>
                  {redeeming ? <Loader2 className="h-4 w-4 animate-spin" /> : <Ticket className="h-4 w-4" />}
                  <span className="ms-1.5">{t('تسجيل', 'Redeem')}</span>
                </Button>
              </div>
            )}
          </div>
        </div>
      </section>

      {/* ─── الدروس المجدولة ────────────────────── */}
      {lessons.length > 0 && (
        <section>
          <h2 className="font-bold text-lg mb-3 flex items-center gap-2">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-500/10"><CalendarDays className="h-4.5 w-4.5 text-violet-600" /></span>
            {t('دروسي المجدولة', 'My scheduled lessons')}
          </h2>
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
                    {l.meeting_url && (
                      <a href={l.meeting_url} target="_blank" rel="noreferrer">
                        <Button className="gap-1.5 rounded-full text-white shadow-brand min-h-[44px] px-4" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
                          <Video className="h-3.5 w-3.5" />{t('الانضمام للدرس', 'Join lesson')}<ExternalLink className="h-3 w-3" />
                        </Button>
                      </a>
                    )}
                  </div>
                </Card>
              );
            })}
          </div>
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
        ) : enrollments.length === 0 ? (
          <Card className="border-0 shadow-sm overflow-hidden">
            <CardContent className="flex flex-col items-center gap-4 py-14 text-center">
              <div className="flex h-20 w-20 items-center justify-center rounded-3xl bg-gradient-to-br from-teal-50 to-emerald-50 border border-teal-100">
                <GraduationCap className="h-10 w-10 text-teal-300" />
              </div>
              <div>
                <p className="text-xl font-bold">{t('لم تسجل في أي كورس بعد', 'No enrollments yet')}</p>
                <p className="text-gray-400 text-sm mt-2 max-w-sm">
                  {t('احصل على بطاقة تسجيل من معلمك وأدخل رمزها أعلاه ليظهر كورسك هنا فوراً', 'Get an enrollment card from your teacher and enter its code above to start learning')}
                </p>
              </div>
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-3">
            {enrollments.map((c) => (
              <div key={c.id} className="relative">
                <a href={`/course/${c.id}/learn`}>
                  <Card className="border-0 shadow-sm overflow-hidden card-hover">
                    <div className="flex items-center gap-4 p-5">
                      <div className="relative flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-600 to-emerald-500 shadow-brand">
                        <BookOpen className="h-6 w-6 text-white" />
                        <span className="absolute -bottom-1.5 -end-1.5 flex h-6 w-6 items-center justify-center rounded-full bg-white shadow-md">
                          <PlayCircle className="h-4 w-4 text-teal-600" />
                        </span>
                      </div>
                      <div className="flex-1 min-w-0">
                        <h3 className="font-bold truncate">{c.title}</h3>
                        <div className="flex items-center gap-3 mt-2.5">
                          <div className="h-2.5 flex-1 max-w-xs rounded-full bg-gray-100 overflow-hidden">
                            <div className="h-full rounded-full bg-gradient-to-r from-teal-500 to-emerald-500 transition-all duration-700" style={{ width: `${c.progress}%` }} />
                          </div>
                          <span className="text-sm font-black text-teal-700 shrink-0">{c.progress}%</span>
                          {c.status === 'completed' ? (
                            <Badge className="bg-emerald-100 text-emerald-700 border-0 rounded-full shrink-0"><CheckCircle className="h-3 w-3 me-1" />{t('مكتمل', 'Done')}</Badge>
                          ) : (
                            <Badge className="bg-teal-100 text-teal-700 border-0 rounded-full shrink-0">{t('نشط', 'Active')}</Badge>
                          )}
                        </div>
                        <p className="text-[11px] text-gray-400 mt-1.5">{c.progress === 0 ? t('ابدأ أول درس الآن', 'Start your first lesson now') : t('تابع من حيث توقفت', 'Continue where you left off')}</p>
                      </div>
                    </div>
                  </Card>
                </a>
                <button
                  onClick={() => toggleFavorite(c.id)}
                  className="absolute top-1/2 -translate-y-1/2 end-4 flex h-11 w-11 items-center justify-center rounded-full bg-white border shadow-sm hover:scale-110 transition"
                  title={t('مفضلة', 'Favorite')}
                >
                  <Heart className={`h-4 w-4 ${favorites.has(c.id) ? 'fill-rose-500 text-rose-500' : 'text-gray-400'}`} />
                </button>
              </div>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
