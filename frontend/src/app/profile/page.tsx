'use client';

import { useCallback, useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CardSkeleton } from '@/components/ui/skeleton';
import {
  Star, Trophy, Medal, Rocket, Play, BookOpen, GraduationCap, CheckCircle2,
  Lock, Coins, History, Heart, Activity as ActivityIcon, CalendarDays, ArrowLeft,
  BookMarked, Target, Award, TrendingUp,
} from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

/* ترجمة أسباب النقاط — codes من قاعدة البيانات إلى تسميات واضحة */
const POINT_LABELS: Record<string, [string, string]> = {
  daily_login: ['تسجيل دخول يومي', 'Daily login'],
  lesson_completed: ['إكمال درس', 'Lesson completed'],
  exam_passed: ['اجتياز اختبار', 'Exam passed'],
  course_completed: ['إكمال كورس', 'Course completed'],
  first_course: ['إنجاز: أول خطوة', 'Achievement: First Step'],
  first_lesson: ['إنجاز: بداية الرحلة', 'Achievement: Beginner'],
  lesson_10: ['إنجاز: عشر دروس', 'Achievement: Ten Lessons'],
  lesson_50: ['إنجاز: خمسون درساً', 'Achievement: Fifty Lessons'],
  first_course_done: ['إنجاز: خريج', 'Achievement: Graduate'],
  courses_3: ['إنجاز: منتج', 'Achievement: Multi-Tasker'],
  exam_pass: ['إنجاز: نجاح', 'Achievement: Exam Winner'],
  exam_5: ['إنجاز: بطل الاختبارات', 'Achievement: Exam Champion'],
  perfect_score: ['إنجاز: علامة كاملة', 'Achievement: Perfect Score'],
  points_500: ['إنجاز: جامع النقاط', 'Achievement: Point Collector'],
};


const ICONS: Record<string, any> = {
  rocket: Rocket, play: Play, book: BookOpen, 'book-open': BookOpen, graduation: GraduationCap,
  layers: BookMarked, check: CheckCircle2, trophy: Trophy, star: Star, coins: Coins,
};

const TYPE_META: Record<string, { color: string; bg: string }> = {
  login: { color: '#0d9488', bg: '#eff6ff' },
  lesson: { color: '#059669', bg: '#ecfdf5' },
  exam: { color: '#7c3aed', bg: '#f5f3ff' },
  course: { color: '#d97706', bg: '#fffbeb' },
  achievement: { color: '#e11d48', bg: '#fff1f2' },
  enrollment: { color: '#0891b2', bg: '#ecfeff' },
  point: { color: '#475569', bg: '#f8fafc' },
};

export default function ProfilePage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [tab, setTab] = useState<'overview' | 'achievements' | 'activity' | 'points' | 'favorites'>('overview');
  const [data, setData] = useState<any>(null);
  const [achievements, setAchievements] = useState<any[]>([]);
  const [activity, setActivity] = useState<any[]>([]);
  const [points, setPoints] = useState<any[]>([]);
  const [leaderboard, setLeaderboard] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  const headers = () => {
    const tid = localStorage.getItem('tenant_id');
    return { Accept: 'application/json', ...(tid ? { 'X-Tenant-Id': tid } : {}) };
  };

  const load = useCallback(() => {
    setLoading(true);
    Promise.all([
      fetch(`${API}/student/profile`, { headers: headers() }).then(r => r.json()),
      fetch(`${API}/student/achievements`, { headers: headers() }).then(r => r.json()),
      fetch(`${API}/student/activity`, { headers: headers() }).then(r => r.json()),
      fetch(`${API}/student/points`, { headers: headers() }).then(r => r.json()),
      fetch(`${API}/student/leaderboard`, { headers: headers() }).then(r => r.json()),
    ]).then(([p, a, act, pt, lb]) => {
      setData(p.profile);
      setAchievements(a.achievements || []);
      setActivity(act.activity || []);
      setPoints(pt.history || []);
      setLeaderboard(lb.leaderboard || []);
    }).catch(() => {}).finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const toggleFavorite = async (courseId: string) => {
    const res = await fetch(`${API}/student/favorites/${courseId}`, { method: 'POST', headers: headers() });
    if (res.ok) load();
  };

  if (loading) return <div className="px-4 py-8"><CardSkeleton count={4} /></div>;
  if (!data) return <div className="flex items-center justify-center h-screen text-gray-400">{t('غير موجود', 'Not found')}</div>;

  const s = data.stats;
  const myRank = data.stats.rank;
  const unlockedCount = achievements.filter(a => a.unlocked).length;

  const tabs = [
    { id: 'overview' as const, label: t('النظرة العامة', 'Overview'), icon: Target },
    { id: 'achievements' as const, label: `${t('الإنجازات', 'Achievements')} (${unlockedCount}/${achievements.length})`, icon: Trophy },
    { id: 'activity' as const, label: t('سجل النشاط', 'Activity'), icon: ActivityIcon },
    { id: 'points' as const, label: t('النقاط', 'Points'), icon: Coins },
    { id: 'favorites' as const, label: t('المفضلة', 'Favorites'), icon: Heart },
  ];

  return (
    <div className="space-y-6 max-w-5xl mx-auto">
      <a href="/my-learning" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-teal-700 transition-colors"><ArrowLeft className="h-4 w-4" /> {t('رجوع لتعليمي', 'Back to my learning')}</a>

      {/* ─── Header card ─────────────────────────── */}
      <Card className="border-0 shadow-sm overflow-hidden">
        <div className="h-24 bg-gradient-to-l from-teal-600 via-teal-500 to-emerald-600 relative">
          <div className="absolute inset-0 opacity-[0.08]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '22px 22px' }} />
        </div>
        <CardContent className="p-6 -mt-12">
          <div className="flex flex-wrap items-end gap-4">
            <div className="flex h-24 w-24 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-500 to-emerald-400 text-white font-bold text-3xl ring-4 ring-white shadow-lg">
              {data.avatar_url ? <img src={data.avatar_url} className="w-full h-full object-cover rounded-2xl" alt="" /> : data.name?.[0]?.toUpperCase()}
            </div>
            <div className="flex-1 pt-10">
              <h1 className="text-2xl font-bold">{data.name}</h1>
              <p className="text-gray-500 text-sm">{data.email}</p>
            </div>
            <div className="flex gap-2 pt-10">
              <Badge className="bg-amber-100 text-amber-700 border-0 gap-1 px-3 py-1.5 rounded-full"><Coins className="h-3.5 w-3.5" />{s.points} {t('نقطة', 'pts')}</Badge>
              {myRank && <Badge className="bg-teal-100 text-teal-700 border-0 gap-1 px-3 py-1.5 rounded-full"><Trophy className="h-3.5 w-3.5" />{t('الترتيب', 'Rank')} #{myRank}</Badge>}
            </div>
          </div>

          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-6">
            {[
              { label: t('كورسات', 'Courses'), value: `${s.courses_completed}/${s.courses_count}`, icon: GraduationCap, color: '#0d9488' },
              { label: t('متوسط التقدم', 'Avg progress'), value: `${s.avg_progress}%`, icon: TrendingUp, color: '#7c3aed' },
              { label: t('دروس مكتملة', 'Lessons'), value: s.lessons_completed, icon: BookOpen, color: '#059669' },
              { label: t('اختبارات ناجحة', 'Exams passed'), value: s.exams_passed, icon: Award, color: '#d97706' },
            ].map((c, i) => (
              <div key={i} className="p-4 rounded-2xl border transition-all hover:shadow-md hover:border-teal-200">
                <div className="flex h-9 w-9 items-center justify-center rounded-xl mb-2" style={{ backgroundColor: `${c.color}15` }}><c.icon className="h-4.5 w-4.5" style={{ color: c.color }} /></div>
                <div className="text-xl font-bold">{c.value}</div>
                <div className="text-xs text-gray-500">{c.label}</div>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* ─── Tabs ────────────────────────────────── */}
      <div className="flex flex-wrap gap-1.5 p-1.5 bg-slate-100/80 rounded-full w-fit">
        {tabs.map(tb => (
          <button key={tb.id} onClick={() => setTab(tb.id)} className={`flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-bold transition-all ${tab === tb.id ? 'bg-white text-teal-700 shadow-sm' : 'text-gray-500 hover:text-gray-800'}`}>
            <tb.icon className="h-4 w-4" />{tb.label}
          </button>
        ))}
      </div>

      {/* ─── Overview ────────────────────────────── */}
      {tab === 'overview' && (
        <div className="space-y-6">
          <div className="grid lg:grid-cols-2 gap-4">
            <Card className="border-0 shadow-sm"><CardContent className="p-5">
              <h3 className="font-semibold mb-3 flex items-center gap-2"><BookOpen className="h-4 w-4 text-teal-600" />{t('تقدمي في الكورسات', 'My course progress')}</h3>
              {(data.enrollments || []).length === 0 && <p className="text-sm text-gray-400">{t('لا توجد كورسات مسجلة', 'No enrolled courses')}</p>}
              <div className="space-y-3">
                {(data.enrollments || []).map((e: any) => (
                  <a key={e.course_id} href={`/course/${e.course_id}/learn`} className="block">
                    <div className="flex items-center justify-between text-sm mb-1">
                      <span className="font-medium">{e.course_title}</span>
                      <span className="text-gray-500">{e.progress_percent}%</span>
                    </div>
                    <div className="h-2 rounded-full bg-gray-100 overflow-hidden">
                      <div className="h-full bg-gradient-to-l from-teal-500 to-emerald-400 rounded-full" style={{ width: `${e.progress_percent}%` }} />
                    </div>
                  </a>
                ))}
              </div>
            </CardContent></Card>

            <Card className="border-0 shadow-sm"><CardContent className="p-5">
              <h3 className="font-semibold mb-3 flex items-center gap-2"><Trophy className="h-4 w-4 text-amber-500" />{t('ترتيبي بين الطلاب', 'My ranking')}</h3>
              {leaderboard.length === 0 ? <p className="text-sm text-gray-400">{t('لا يوجد ترتيب بعد', 'No ranking yet')}</p> : (
                <div className="space-y-2">
                  {leaderboard.slice(0, 5).map((l: any) => (
                    <div key={l.user_id} className={`flex items-center gap-3 p-2.5 rounded-xl ${l.user_id === data.id ? 'bg-teal-50 border border-teal-200' : 'border'}`}>
                      <span className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold shrink-0 ${l.rank === 1 ? 'bg-amber-100 text-amber-700' : l.rank === 2 ? 'bg-gray-200 text-gray-600' : l.rank === 3 ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-500'}`}>{l.rank}</span>
                      <div className="flex-1 flex items-center gap-2 min-w-0">
                        {l.user_id === data.id && <Star className="h-3.5 w-3.5 text-amber-500 shrink-0" />}
                        <span className="text-sm font-medium truncate">{l.name}{l.user_id === data.id && <span className="text-teal-700"> ({t('أنا', 'me')})</span>}</span>
                      </div>
                      <span className="text-sm font-bold text-amber-600 shrink-0">{l.points}</span>
                    </div>
                  ))}
                  <a href="/leaderboard"><Button variant="outline" size="sm" className="w-full rounded-full mt-1 text-xs">{t('عرض الترتيب الكامل', 'View full leaderboard')}</Button></a>
                </div>
              )}
            </CardContent></Card>
          </div>

          <Card className="border-0 shadow-sm"><CardContent className="p-5">
            <h3 className="font-semibold mb-3 flex items-center gap-2"><Trophy className="h-4 w-4 text-violet-500" />{t('أحدث الإنجازات', 'Latest achievements')}</h3>
            <div className="grid grid-cols-3 sm:grid-cols-6 gap-3">
              {achievements.slice(0, 6).map(a => {
                const Icon = ICONS[a.icon] || Trophy;
                return (
                  <div key={a.id} className={`flex flex-col items-center gap-2 p-3 rounded-2xl border text-center ${a.unlocked ? 'bg-amber-50 border-amber-200' : 'opacity-45 grayscale'}`}>
                    <div className={`flex h-11 w-11 items-center justify-center rounded-full ${a.unlocked ? 'bg-amber-100 text-amber-600' : 'bg-gray-100 text-gray-400'}`}>{a.unlocked ? <Icon className="h-5 w-5" /> : <Lock className="h-4 w-4" />}</div>
                    <div className="text-[11px] font-medium leading-tight">{locale === 'ar' ? (a.title_ar || a.title) : a.title}</div>
                  </div>
                );
              })}
            </div>
          </CardContent></Card>
        </div>
      )}

      {/* ─── Achievements ────────────────────────── */}
      {tab === 'achievements' && (
        <div className="grid sm:grid-cols-2 gap-3">
          {achievements.map(a => {
            const Icon = ICONS[a.icon] || Trophy;
            return (
              <Card key={a.id} className={`border ${a.unlocked ? 'shadow-sm' : 'opacity-60'}`}>
                <CardContent className="p-4 flex gap-3">
                  <div className={`flex h-12 w-12 items-center justify-center rounded-2xl shrink-0 ${a.unlocked ? 'bg-amber-100 text-amber-600' : 'bg-gray-100 text-gray-400'}`}>
                    {a.unlocked ? <Icon className="h-6 w-6" /> : <Lock className="h-5 w-5" />}
                  </div>
                  <div className="flex-1">
                    <div className="flex items-center justify-between gap-2">
                      <h4 className="font-semibold text-sm">{locale === 'ar' ? (a.title_ar || a.title) : a.title}</h4>
                      {a.points > 0 && <span className="flex items-center gap-0.5 text-[11px] font-bold text-amber-600 shrink-0"><Coins className="h-3 w-3" />{a.points}</span>}
                    </div>
                    <p className="text-xs text-gray-500 mt-0.5">{locale === 'ar' ? (a.description_ar || a.description) : a.description}</p>
                    {a.unlocked && a.unlocked_at && <p className="text-[11px] text-gray-400 mt-1">{t('تم الحصول عليه', 'Unlocked')}: {new Date(a.unlocked_at).toLocaleDateString()}</p>}
                  </div>
                </CardContent>
              </Card>
            );
          })}
        </div>
      )}

      {/* ─── Activity ────────────────────────────── */}
      {tab === 'activity' && (
        <Card className="border-0 shadow-sm"><CardContent className="p-5">
          {activity.length === 0 && <p className="text-sm text-gray-400 text-center py-8">{t('لا يوجد نشاط بعد', 'No activity yet')}</p>}
          <div className="relative space-y-0">
            {activity.map((a: any, i: number) => {
              const meta = TYPE_META[a.type] || TYPE_META.point;
              return (
                <div key={a.id} className="flex gap-3 pb-4 relative">
                  {i < activity.length - 1 && <div className="absolute start-[15px] top-8 bottom-0 w-px bg-gray-100" />}
                  <div className="flex h-8 w-8 items-center justify-center rounded-full shrink-0 z-10" style={{ backgroundColor: meta.bg }}>
                    <ActivityIcon className="h-4 w-4" style={{ color: meta.color }} />
                  </div>
                  <div className="flex-1 pt-1">
                    <p className="text-sm">{a.description}</p>
                    <p className="text-[11px] text-gray-400 flex items-center gap-1 mt-0.5"><CalendarDays className="h-3 w-3" />{new Date(a.created_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US')}</p>
                  </div>
                </div>
              );
            })}
          </div>
        </CardContent></Card>
      )}

      {/* ─── Points ──────────────────────────────── */}
      {tab === 'points' && (
        <Card className="border-0 shadow-sm"><CardContent className="p-5">
          <div className="flex items-center gap-3 p-4 rounded-2xl bg-gradient-to-l from-amber-500 to-orange-400 text-white mb-4">
            <Coins className="h-8 w-8" />
            <div><div className="text-2xl font-bold">{s.points}</div><div className="text-sm text-white/80">{t('إجمالي النقاط', 'Total points')}</div></div>
          </div>
          {points.length === 0 && <p className="text-sm text-gray-400 text-center py-6">{t('لا نقاط بعد — أكمل الدروس والاختبارات لتكسب النقاط!', 'No points yet — complete lessons and exams to earn points!')}</p>}
          <div className="space-y-2">
            {points.map((p: any) => {
              const reason = String(p.reason || '');
              const key = reason.split(':').pop() || reason;
              const labels = POINT_LABELS[key] || POINT_LABELS[reason] || [reason, reason];
              return (
                <div key={p.id} className="flex items-center gap-3 p-3 rounded-xl border">
                  <div className={`flex h-9 w-9 items-center justify-center rounded-xl shrink-0 ${p.points > 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-500'}`}>
                    <History className="h-4 w-4" />
                  </div>
                  <div className="flex-1">
                    <p className="text-sm font-medium">{t(labels[0], labels[1])}</p>
                    <p className="text-[11px] text-gray-400">{new Date(p.created_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US')}</p>
                  </div>
                  <span className={`text-sm font-bold shrink-0 ${p.points > 0 ? 'text-emerald-600' : 'text-red-500'}`}>{p.points > 0 ? '+' : ''}{p.points}</span>
                </div>
              );
            })}
          </div>
        </CardContent></Card>
      )}

      {/* ─── Favorites ───────────────────────────── */}
      {tab === 'favorites' && (
        <div className="space-y-3">
          {(data.favorites || []).length === 0 && (
            <Card className="border-0 shadow-sm"><CardContent className="flex flex-col items-center gap-4 py-12 text-gray-400">
              <Heart className="h-12 w-12 opacity-40" />
              <p className="text-lg">{t('لا توجد كورسات مفضلة بعد', 'No favorite courses yet')}</p>
              <p className="text-sm">{t('اضغط على علامة القلب في صفحة تعليمي لإضافتها هنا', 'Tap the heart on a course in My Learning to add it here')}</p>
            </CardContent></Card>
          )}
          {(data.favorites || []).map((f: any) => (
            <Card key={f.course_id} className="border-0 shadow-sm">
              <CardContent className="p-4 flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-50 shrink-0"><Heart className="h-5 w-5 text-rose-500" /></div>
                <div className="flex-1 min-w-0">
                  <p className="font-medium truncate">{f.title}</p>
                  <div className="flex items-center gap-2 mt-1.5">
                    <div className="h-1.5 flex-1 max-w-[160px] rounded-full bg-gray-100 overflow-hidden"><div className="h-full bg-rose-500 rounded-full" style={{ width: `${f.progress_percent}%` }} /></div>
                    <span className="text-[11px] text-gray-500">{f.progress_percent}%</span>
                  </div>
                </div>
                <a href={`/course/${f.course_id}/learn`}><Button size="sm" variant="outline" className="rounded-full gap-1"><BookOpen className="h-3.5 w-3.5" />{t('متابعة', 'Continue')}</Button></a>
                <Button size="sm" variant="ghost" className="rounded-full text-rose-500" onClick={() => toggleFavorite(f.course_id)}><Heart className="h-4 w-4 fill-rose-500" /></Button>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
