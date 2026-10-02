'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CardSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { ClipboardList, Play, Clock, Target, FileQuestion, CheckCircle2, Sparkles } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function ExamsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [exams, setExams] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!check()) return;
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    fetch(`${API}/exams`, { headers: { 'X-Tenant-Id': tid || '', Accept: 'application/json' } })
      .then(r => r.json()).then(d => setExams(d.exams || [])).catch(() => {})
      .finally(() => setLoading(false));
  }, [check()]);

  const published = exams.filter((e) => e.is_published);
  const totalQuestions = exams.reduce((a, e) => a + (e.questions_count ?? 0), 0);

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      {/* ─── ترحيب ─────────────────────────────── */}
      <section className="relative overflow-hidden rounded-3xl p-6 sm:p-8" style={{ background: 'linear-gradient(135deg, #312e81 0%, #4c1d95 55%, #6d28d9 100%)' }}>
        <div className="absolute top-0 left-1/4 w-72 h-72 bg-violet-300/20 rounded-full blur-3xl" />
        <div className="absolute bottom-0 right-1/4 w-64 h-64 bg-fuchsia-300/15 rounded-full blur-3xl" />
        <div className="absolute inset-0 opacity-[0.07]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '26px 26px' }} />
        <div className="relative z-10 flex flex-wrap items-start justify-between gap-6">
          <div className="min-w-0 flex-1">
            <span className="inline-flex items-center gap-1.5 rounded-full glass px-3 py-1 text-xs font-medium text-white mb-3">
              <Sparkles className="h-3.5 w-3.5 text-violet-200" />
              {t('قاعة الاختبارات', 'Exam hall')}
            </span>
            <h1 className="text-2xl sm:text-3xl font-bold text-white leading-snug">{t('الاختبارات', 'Exams')}</h1>
            <p className="text-white/65 text-sm sm:text-base mt-1.5 max-w-lg">
              {t('اختبر معلوماتك وقِس تقدمك — النتائج تظهر فور التسليم.', 'Test your knowledge and measure your progress — results appear instantly after submission.')}
            </p>
          </div>
          <div className="grid grid-cols-3 gap-2.5 min-w-[240px]">
            {[
              { icon: ClipboardList, label: t('الاختبارات', 'Exams'), value: published.length },
              { icon: Target, label: t('حد النجاح', 'Pass mark'), value: exams[0] ? `${exams[0].pass_mark_percent}%` : '—' },
              { icon: FileQuestion, label: t('أسئلة', 'Questions'), value: totalQuestions },
            ].map((s, i) => (
              <div key={i} className="glass rounded-2xl px-4 py-3 text-center">
                <s.icon className="mx-auto h-5 w-5 text-violet-200 mb-1.5" />
                <div className="text-xl font-black text-white">{s.value}</div>
                <div className="text-[10px] text-white/60 mt-0.5">{s.label}</div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {loading ? <CardSkeleton /> :
        published.length === 0 ? (
          <EmptyState icon={ClipboardList} title={t('لا توجد اختبارات', 'No exams')} description={t('ستظهر اختباراتك هنا عند إضافتها من معلمك', 'Exams assigned by your teacher will appear here')} />
        ) : (
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {published.map((e) => (
              <Card key={e.id} className="border-0 shadow-sm overflow-hidden card-hover flex flex-col">
                <div className="h-24 relative flex items-center justify-center" style={{ background: 'linear-gradient(135deg, #4c1d9518, #7c3aed12)' }}>
                  <div className="absolute inset-0 opacity-[0.06]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #7c3aed 1px, transparent 0)', backgroundSize: '18px 18px' }} />
                  <ClipboardList className="h-10 w-10 opacity-50" style={{ color: '#7c3aed' }} />
                  {e.questions_count > 0 && (
                    <span className="absolute top-2.5 start-2.5 inline-flex items-center gap-1 rounded-full bg-white/90 backdrop-blur px-2.5 py-1 text-[10px] font-black text-violet-700 shadow-sm">
                      <FileQuestion className="h-3 w-3" />{e.questions_count} {t('سؤال', 'Q')}
                    </span>
                  )}
                </div>
                <CardContent className="p-5 flex-1 flex flex-col">
                  <h3 className="font-bold line-clamp-1">{e.title}</h3>
                  <p className="text-xs text-muted-foreground mt-1.5">{e.description || t('اختبر معلوماتك في هذا الاختبار', 'Test your knowledge in this exam')}</p>
                  <div className="flex flex-wrap items-center gap-2 mt-3">
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-violet-50 px-2.5 py-1 text-[11px] font-semibold text-violet-700"><Clock className="h-3 w-3" />{e.duration_minutes} {t('دقيقة', 'min')}</span>
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700"><Target className="h-3 w-3" />{t('نجاح', 'Pass')}: {e.pass_mark_percent}%</span>
                    {e.shuffle_questions && <Badge className="bg-teal-50 text-teal-700 border-0 text-[10px] rounded-full">{t('أسئلة عشوائية', 'Shuffled')}</Badge>}
                  </div>
                  <div className="mt-4 pt-4 border-t flex-1 flex items-end">
                    <a href={`/exam/${e.id}`} className="w-full">
                      <Button className="w-full rounded-full gap-1.5 text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #7c3aed, #8b5cf6)' }}>
                        <Play className="h-4 w-4" /> {t('ابدأ الاختبار', 'Start exam')}
                      </Button>
                    </a>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
    </div>
  );
}
