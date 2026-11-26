'use client';

import { useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { CheckCircle, XCircle, ClipboardList, Award, TrendingUp, Target, Sparkles, AlertCircle, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function ResultsPage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [attempts, setAttempts] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  const load = () => {
    setLoading(true);
    setError(false);
    // /student/exam-attempts is tenant-protected: without X-Tenant-Id the API
    // answers 400 and this page would silently look empty.
    const tenantId = localStorage.getItem('tenant_id') || '';
    fetch(`${API}/student/exam-attempts`, {
      headers: { Accept: 'application/json', 'X-Tenant-Id': tenantId },
      credentials: 'same-origin',
    })
      .then((r) => { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
      .then((d) => setAttempts(d.attempts || []))
      .catch(() => setError(true))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  // Only finished, graded assessments feed the pass/average/best tiles. An
  // in-progress attempt and an ungraded practice exercise are never a grade,
  // and must never drag an average down or read as a failure.
  const graded = attempts.filter((a) => !a.is_practice && a.status === 'submitted' && !a.needs_review);
  const passed = graded.filter((a) => a.passed).length;
  const avg = graded.length ? Math.round(graded.reduce((a, x) => a + Number(x.percentage || 0), 0) / graded.length) : 0;
  const best = graded.length ? Math.max(...graded.map((a) => Number(a.percentage || 0))) : 0;

  return (
    <div className="space-y-6 max-w-5xl mx-auto">
      {/* ─── ترحيب ─────────────────────────────── */}
      <section className="relative overflow-hidden rounded-3xl p-6 sm:p-8" style={{ background: 'linear-gradient(135deg, #0f2e2b 0%, #115e54 55%, #0d9488 100%)' }}>
        <div className="absolute top-0 left-1/4 w-72 h-72 bg-teal-300/20 rounded-full blur-3xl" />
        <div className="absolute bottom-0 right-1/4 w-64 h-64 bg-emerald-300/15 rounded-full blur-3xl" />
        <div className="absolute inset-0 opacity-[0.07]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '26px 26px' }} />
        <div className="relative z-10 flex flex-wrap items-start justify-between gap-6">
          <div className="min-w-0 flex-1">
            <span className="inline-flex items-center gap-1.5 rounded-full glass px-3 py-1 text-xs font-medium text-white mb-3">
              <Sparkles className="h-3.5 w-3.5 text-teal-200" />
              {t('سجل أدائك', 'Your performance record')}
            </span>
            <h1 className="text-2xl sm:text-3xl font-bold text-white leading-snug">{t('النتائج', 'Results')}</h1>
            <p className="text-white/65 text-sm sm:text-base mt-1.5 max-w-lg">
              {t('كل محاولة خطوة للأمام — راقب تقدمك وحسّن مستواك', 'Every attempt is a step forward — track your progress and improve')}
            </p>
          </div>
          <div className="grid grid-cols-3 gap-2.5 w-full sm:w-auto sm:min-w-[240px]">
            {[
              { icon: Award, label: t('ناجح', 'Passed'), value: passed },
              { icon: TrendingUp, label: t('المتوسط', 'Average'), value: `${avg}%` },
              { icon: Target, label: t('الأعلى', 'Best'), value: `${best}%` },
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

      {loading ? <TableSkeleton rows={5} cols={3} /> :
        error ? (
          <Card className="border-0 shadow-sm p-8 text-center">
            <AlertCircle className="mx-auto h-9 w-9 text-red-500" />
            <p className="mt-3 font-semibold">{t('تعذر تحميل النتائج', 'Could not load your results')}</p>
            <p className="text-sm text-gray-500 mt-1">{t('تحقق من اتصالك ثم حاول مرة أخرى', 'Check your connection and try again')}</p>
            <Button onClick={load} variant="outline" className="mt-4 gap-2">
              <RefreshCw className="h-4 w-4" />
              {t('إعادة المحاولة', 'Retry')}
            </Button>
          </Card>
        ) :
        attempts.length === 0 ? (
          <EmptyState icon={ClipboardList} title={t('لا توجد نتائج', 'No results')} description={t('عندما تكمل اختباراً ستظهر نتيجتك هنا', 'Your exam results will appear here once you take an exam')} />
        ) : (
          <div className="space-y-3">
            {attempts.map((r) => {
              const pct = Number(r.percentage) || 0;
              const isPractice = !!r.is_practice;
              const inProgress = r.status === 'in_progress';
              const pendingReview = !isPractice && r.status === 'submitted' && !!r.needs_review;
              const isGradedResult = !isPractice && r.status === 'submitted' && !r.needs_review;
              const ok = isGradedResult && !!r.passed;

              // One authoritative status → one label. A practice exercise is
              // never "Failed", an in-progress attempt is never a 0%, and a
              // graded attempt awaiting the teacher is neither pass nor fail.
              const view = isPractice
                ? (inProgress
                  ? { label: t('قيد التمرين', 'In progress'), icon: RefreshCw, color: 'text-amber-600', bg: 'bg-amber-500/10', accent: 'linear-gradient(180deg, #d97706, #f59e0b)' }
                  : { label: t('مكتمل', 'Completed'), icon: CheckCircle, color: 'text-teal-600', bg: 'bg-teal-500/10', accent: 'linear-gradient(180deg, #0d9488, #14b8a6)' })
                : inProgress
                  ? { label: t('قيد الاختبار', 'In progress'), icon: RefreshCw, color: 'text-amber-600', bg: 'bg-amber-500/10', accent: 'linear-gradient(180deg, #d97706, #f59e0b)' }
                  : pendingReview
                    ? { label: t('بانتظار مراجعة المعلم', 'Awaiting teacher review'), icon: AlertCircle, color: 'text-amber-600', bg: 'bg-amber-500/10', accent: 'linear-gradient(180deg, #d97706, #f59e0b)' }
                    : ok
                      ? { label: t('ناجح', 'Passed'), icon: CheckCircle, color: 'text-emerald-600', bg: 'bg-emerald-500/10', accent: 'linear-gradient(180deg, #059669, #10b981)' }
                      : { label: t('راسب', 'Failed'), icon: XCircle, color: 'text-red-500', bg: 'bg-red-500/10', accent: 'linear-gradient(180deg, #ef4444, #f97316)' };

              return (
                <Card key={r.id} className="border-0 shadow-sm p-5 overflow-hidden relative transition-all hover:shadow-md">
                  <div className="absolute inset-y-0 start-0 w-1.5" style={{ background: view.accent }} />
                  <div className="flex flex-wrap items-center gap-4 ps-2">
                    <div className={`flex h-13 w-13 shrink-0 items-center justify-center rounded-2xl ${view.bg}`}>
                      <view.icon className={`h-6 w-6 ${view.color}`} />
                    </div>
                    <div className="flex-1 min-w-[180px]">
                      <div className="flex items-center gap-2 flex-wrap">
                        <h3 className="font-bold truncate">{r.exam_title || t('اختبار', 'Exam')}</h3>
                        {isPractice && (
                          <Badge className="bg-teal-50 text-teal-700 border-0 rounded-full">{t('تمرين مهارة', 'Skill practice')}</Badge>
                        )}
                        <Badge className={`border-0 rounded-full ${view.bg} ${view.color}`}>
                          <view.icon className="h-3 w-3 me-1" />{view.label}
                        </Badge>
                      </div>
                      {isGradedResult || pendingReview ? (
                        <div className="flex items-center gap-3 mt-2">
                          <div className="h-2 w-40 max-w-full rounded-full bg-gray-100 overflow-hidden">
                            <div className={`h-full rounded-full transition-all duration-700 ${ok ? 'bg-gradient-to-r from-emerald-500 to-teal-500' : 'bg-gradient-to-r from-amber-400 to-orange-400'}`} style={{ width: `${Math.min(100, pct)}%` }} />
                          </div>
                          <span className="text-xs text-gray-500">{r.score} / {r.total_points} {t('نقطة', 'pts')}</span>
                        </div>
                      ) : (
                        <p className="text-xs text-gray-500 mt-1.5">
                          {isPractice
                            ? t('لا يُحتسب هذا التمرين في معدلك ولا في ترتيب الطلاب.', 'This practice does not affect your average or the leaderboard.')
                            : t('لم تُسجَّل نتيجة بعد — أكمل الاختبار لعرض النتيجة.', 'No result yet — finish the exam to see it.')}
                        </p>
                      )}
                    </div>
                    <div className="text-start shrink-0">
                      {isGradedResult || pendingReview ? (
                        <div className={`text-3xl font-black ${ok ? 'text-emerald-600' : pendingReview ? 'text-amber-600' : 'text-red-500'}`}>{pct}%</div>
                      ) : (
                        <div className={`text-sm font-bold ${view.color}`}>{view.label}</div>
                      )}
                      <div className="text-[10px] text-gray-400 mt-0.5">
                        {(r.completed_at || r.submitted_at)
                          ? new Date(r.completed_at || r.submitted_at).toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US', { day: 'numeric', month: 'short', year: 'numeric' })
                          : ''}
                      </div>
                    </div>
                  </div>
                </Card>
              );
            })}
          </div>
        )}
    </div>
  );
}
