'use client';

import { useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Loader2, CheckCircle2, ClipboardList, FileCheck2, User, Clock } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function TeacherExamsPage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [exams, setExams] = useState<any[]>([]);
  const [attempts, setAttempts] = useState<Record<string, any[]>>({});
  const [loading, setLoading] = useState(true);
  const [selected, setSelected] = useState<any>(null); // attempt being graded
  const [grades, setGrades] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const load = async () => {
    setLoading(true);
    try {
      const res = await fetch(`${API}/exams`, { headers: headers() });
      const d = await res.json();
      const list = d.exams || [];
      setExams(list);
      const map: Record<string, any[]> = {};
      await Promise.all(list.map(async (e: any) => {
        try {
          const r = await fetch(`${API}/exams/${e.id}/attempts`, { headers: headers() });
          const ad = await r.json();
          map[e.id] = ad.attempts || [];
        } catch { map[e.id] = []; }
      }));
      setAttempts(map);
    } catch { /* */ }
    setLoading(false);
  };

  useEffect(() => { load(); }, []);

  const needsReview = (examId: string) => (attempts[examId] || []).filter((a: any) => a.needs_review);

  const openGrading = (attempt: any) => {
    setSelected(attempt);
    setGrades({});
  };

  const saveGrades = async () => {
    if (!selected) return;
    const answers = Object.entries(grades).map(([id, pts]) => ({ exam_answer_id: id, points_earned: Number(pts) }));
    if (!answers.length) { toast.error(t('أدخل درجات قبل الحفظ', 'Enter grades first')); return; }
    setSaving(true);
    try {
      const examId = selected.exam_id;
      const res = await fetch(`${API}/exams/${examId}/attempts/${selected.id}/grade`, {
        method: 'POST', headers: headers(), body: JSON.stringify({ answers }),
      });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      toast.success(t('تم حفظ التصحيح', 'Grades saved'));
      setSelected(null);
      load();
    } catch { toast.error('Failed'); }
    setSaving(false);
  };

  const allNeedingReview = exams.reduce((n, e) => n + needsReview(e.id).length, 0);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <FileCheck2 className="h-6 w-6 text-teal-600" />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('الامتحانات والتصحيح', 'Exams & Grading')}</h1>
            <p className="text-muted-foreground mt-1">{t('راجع إجابات الطلاب وقيّم الأسئلة المقالية', 'Review student answers and grade essay questions')}</p>
          </div>
        </div>
        {allNeedingReview > 0 && (
          <Badge className="bg-amber-100 text-amber-700 border-0 gap-1 rounded-full px-3.5 py-1.5">
            <span className="h-2 w-2 rounded-full bg-amber-500 animate-pulse" />
            {t('بانتظار التصحيح', 'Awaiting review')}: {allNeedingReview}
          </Badge>
        )}
      </div>

      {loading && <div className="flex justify-center py-16"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>}

      {!loading && exams.length === 0 && (
        <Card className="border-0 shadow-sm">
          <CardContent className="flex flex-col items-center gap-3 py-14">
            <div className="flex h-20 w-20 items-center justify-center rounded-3xl bg-gradient-to-br from-teal-50 to-emerald-50 border border-teal-100">
              <ClipboardList className="h-10 w-10 text-teal-300" />
            </div>
            <div className="text-center">
              <p className="font-bold">{t('لا توجد امتحانات بعد', 'No exams yet')}</p>
              <p className="text-sm text-muted-foreground mt-1">{t('أنشئ امتحاناً من تبويب الكورسات', 'Create an exam from the Courses tab')}</p>
            </div>
          </CardContent>
        </Card>
      )}

      <div className="space-y-4">
        {exams.map((exam: any) => {
          const list = attempts[exam.id] || [];
          const pending = list.filter((a: any) => a.needs_review);
          return (
            <Card key={exam.id} className="border-0 shadow-sm overflow-hidden transition-all hover:shadow-md">
              <CardContent className="p-5 space-y-3">
                <div className="flex items-center justify-between flex-wrap gap-2">
                  <div className="flex items-center gap-3">
                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500/10 to-emerald-500/10 border border-teal-100"><ClipboardList className="h-5 w-5 text-teal-600" /></div>
                    <div>
                      <p className="font-bold">{exam.title}</p>
                      <div className="flex flex-wrap items-center gap-2 mt-1">
                        <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600">{t('المحاولات', 'Attempts')}: {list.length}</span>
                        {pending.length > 0 && (
                          <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-semibold text-amber-700">{t('بانتظار التصحيح', 'Pending')}: {pending.length}</span>
                        )}
                      </div>
                    </div>
                  </div>
                </div>

                {pending.length === 0 && <p className="text-sm text-muted-foreground">{t('لا توجد محاولات بانتظار التصحيح', 'No attempts awaiting review')}</p>}

                <div className="space-y-2">
                  {pending.map((a: any) => (
                    <div key={a.id} className="flex items-center justify-between flex-wrap gap-3 p-3.5 rounded-2xl bg-amber-50/60 border border-amber-100 transition-colors hover:bg-amber-50">
                      <div className="flex items-center gap-3 text-sm">
                        <span className="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-amber-400 to-orange-400 text-white font-bold text-xs shrink-0">
                          {a.student?.name?.[0] || '؟'}
                        </span>
                        <div>
                          <span className="font-semibold">{a.student?.name || a.user_id}</span>
                          <Badge className="bg-amber-100 text-amber-700 border-0 text-[10px] ms-2">{t('مقالي', 'Essay')}</Badge>
                        </div>
                      </div>
                      <div className="flex items-center gap-3 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1"><Clock className="h-3 w-3" />{a.submitted_at ? new Date(a.submitted_at).toLocaleString(locale === 'ar' ? 'ar' : 'en') : ''}</span>
                        <span>{t('النتيجة المؤقتة', 'Provisional')}: <b className="text-amber-700">{a.percentage ?? 0}%</b></span>
                        <Button size="sm" onClick={() => openGrading(a)} className="gap-1 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>{t('تصحيح', 'Grade')}</Button>
                      </div>
                    </div>
                  ))}
                </div>

                {!pending.length && list.length > 0 && (
                  <div className="space-y-1.5">
                    {list.filter((a: any) => !a.needs_review).slice(0, 5).map((a: any) => (
                      <div key={a.id} className="flex items-center justify-between text-sm p-2.5 rounded-xl bg-slate-50/60">
                        <span className="flex items-center gap-2"><User className="h-3.5 w-3.5 text-gray-300" />{a.student?.name || a.user_id}</span>
                        <span className="flex items-center gap-2 text-xs text-gray-500">
                          <b className={a.passed ? 'text-emerald-600' : 'text-slate-500'}>{a.percentage ?? 0}%</b> {a.passed ? <CheckCircle2 className="h-3.5 w-3.5 text-green-500" /> : null}
                        </span>
                      </div>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>
          );
        })}
      </div>

      {selected && (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-end sm:items-center justify-center p-0 sm:p-4" onClick={() => setSelected(null)}>
          <div className="w-full sm:max-w-lg bg-white rounded-t-2xl sm:rounded-2xl p-6 space-y-4 max-h-[85vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between">
              <h2 className="font-bold">{t('تصحيح المحاولة', 'Grade attempt')} #{selected.attempt_number}</h2>
              <button onClick={() => setSelected(null)} className="text-gray-400 hover:text-gray-600 text-lg" aria-label="close">✕</button>
            </div>

            <div className="space-y-3">
              {(selected.answers || []).map((ans: any) => {
                const given = (() => {
                  try { return JSON.parse(ans.given_answer); } catch { return ans.given_answer; }
                })();
                const text = typeof given === 'string' ? given : JSON.stringify(given);
                return (
                  <div key={ans.id} className="p-3 rounded-xl bg-gray-50 space-y-2">
                    <p className="text-sm font-medium">{ans.question?.question_text || t('سؤال', 'Question')}</p>
                    <p className="text-sm text-gray-600 bg-white rounded-lg p-2 border" dir="auto">{text || t('بدون إجابة', 'No answer')}</p>
                    <div className="flex items-center gap-2">
                      <span className="text-xs text-gray-400">{t('الدرجة', 'Score')} / {ans.points_max}:</span>
                      <Input
                        type="number" min={0} max={Number(ans.points_max)} step="0.5"
                        value={grades[ans.id] ?? ''}
                        onChange={(e) => setGrades({ ...grades, [ans.id]: e.target.value })}
                        className="h-9 w-24 text-center"
                        placeholder="0"
                      />
                    </div>
                  </div>
                );
              })}
            </div>

            <Button onClick={saveGrades} disabled={saving} className="w-full gap-2 rounded-full">
              {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}{t('حفظ التصحيح', 'Save grades')}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
