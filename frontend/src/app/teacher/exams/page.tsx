'use client';

import { useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Loader2, CheckCircle2, ClipboardList, FileCheck2, Clock, X, Sparkles, HelpCircle, ExternalLink, Plus } from 'lucide-react';
import { toast } from 'sonner';
import { InteractiveHtmlQuestion } from '@/components/exam/interactive-html-question';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const LEVEL_LABEL: Record<string, [string, string]> = {
  advanced: ['متقدّم', 'Advanced'],
  proficient: ['متمكّن', 'Proficient'],
  developing: ['نامٍ', 'Developing'],
  needs_support: ['يحتاج دعم', 'Needs support'],
};

/** Whole seconds → h:mm:ss / m:ss. */
function fmtDuration(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds || 0));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  const mm = String(m).padStart(2, '0');
  const ss = String(sec).padStart(2, '0');
  return h > 0 ? `${h}:${mm}:${ss}` : `${m}:${ss}`;
}

/** Map a stored MC/true-false answer (option id or ids) to readable text. */
function optionTexts(given: any, options: any[]): string[] {
  const ids = Array.isArray(given) ? given : [given];
  return ids
    .map((id) => options?.find((o: any) => o.id === id)?.option_text
      ?? (typeof id === 'string' && ['true', 'false'].includes(id.toLowerCase())
        ? (id.toLowerCase() === 'true' ? '✔' : '✘')
        : id))
    .filter((x) => x !== undefined && x !== null && x !== '')
    .map((x) => String(x));
}

/**
 * Renders a single student answer for the teacher review list, by question
 * type — including the structured HTML/interactive questions (shown in the
 * isolated sandbox, read-only) and file links.
 */
function AnswerBody({ ans, t }: { ans: any; t: (ar: string, en: string) => string }) {
  const q = ans.question || {};
  const type = q.question_type;
  const given = ans.given_answer;
  const opts = q.options || [];

  if (type === 'multiple_choice' || type === 'true_false') {
    const list = optionTexts(given, opts);
    return <div className="flex flex-wrap gap-1.5">{list.length ? list.map((txt, i) => (
      <span key={i} className="rounded-lg border bg-white px-2.5 py-1 text-sm">{txt}</span>
    )) : <span className="text-sm text-gray-400">{t('بدون إجابة', 'No answer')}</span>}</div>;
  }

  if (type === 'drag_drop' || type === 'matching') {
    const pairs = Array.isArray(given) ? given : [];
    return <div className="space-y-1">{pairs.length ? pairs.map((p: any, i: number) => (
      <div key={i} className="flex items-center gap-2 text-sm"><span className="rounded bg-white border px-2 py-0.5">{p?.left ?? '—'}</span><span className="text-gray-400">↔</span><span className="rounded bg-white border px-2 py-0.5">{p?.right ?? '—'}</span></div>
    )) : <span className="text-sm text-gray-400">{t('بدون إجابة', 'No answer')}</span>}</div>;
  }

  if (type === 'ordering' || type === 'fill_blank') {
    const items = Array.isArray(given) ? given : [];
    return <div className="flex flex-wrap gap-1.5">{items.length ? items.map((it: any, i: number) => (
      <span key={i} className="rounded-lg border bg-white px-2.5 py-1 text-sm">{String(it)}</span>
    )) : <span className="text-sm text-gray-400">{t('بدون إجابة', 'No answer')}</span>}</div>;
  }

  if (type === 'file_upload') {
    const url = typeof given === 'string' ? given : '';
    return url ? (
      <a href={url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 text-sm text-teal-700 underline">
        <ExternalLink className="h-3.5 w-3.5" />{t('فتح الملف المرفوع', 'Open uploaded file')}
      </a>
    ) : <span className="text-sm text-gray-400">{t('بدون إجابة', 'No answer')}</span>;
  }

  if (type === 'interactive_html' || type === 'custom_html') {
    const interactive = q.metadata?.interactive || {};
    return (
      <div className="space-y-2">
        {interactive.html ? (
          <InteractiveHtmlQuestion
            questionId={`review-${ans.id}`}
            html={interactive.html}
            css={interactive.css || ''}
            js={interactive.js || ''}
            height={Number(interactive.height) || 360}
            autoCapture={false}
            value={typeof given === 'string' ? given : JSON.stringify(given ?? '')}
            onChange={() => {}}
            locked
          />
        ) : null}
        <div className="text-xs text-gray-500">
          {t('القيمة التي أرسلها الطالب', 'Value the student submitted')}:{' '}
          <code className="rounded bg-gray-100 px-1.5 py-0.5 font-mono" dir="ltr">
            {given === undefined || given === null || given === '' ? t('— لا شيء —', '— none —') : (typeof given === 'string' ? given : JSON.stringify(given))}
          </code>
        </div>
      </div>
    );
  }

  // writing + anything else → plain text
  const text = typeof given === 'string' ? given : (given == null ? '' : JSON.stringify(given));
  return <p className="whitespace-pre-wrap text-sm text-gray-700" dir="auto">{text || t('بدون إجابة', 'No answer')}</p>;
}

export default function TeacherExamsPage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [exams, setExams] = useState<any[]>([]);
  const [attempts, setAttempts] = useState<Record<string, any[]>>({});
  const [loading, setLoading] = useState(true);
  const [selected, setSelected] = useState<any>(null); // attempt being reviewed
  const [loadingAttempt, setLoadingAttempt] = useState(false);
  const [grades, setGrades] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const headers = () => ({
    'Content-Type': 'application/json',
    'X-Tenant-Id': localStorage.getItem('tenant_id') || '',
    Accept: 'application/json',
  });

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

  const openReview = async (attempt: any) => {
    setSelected(attempt);
    setGrades({});
    setLoadingAttempt(true);
    try {
      // Fetch the authoritative full attempt so the reviewer always sees the
      // complete answers (even if the list payload was trimmed).
      const res = await fetch(`${API}/exam-attempts/${attempt.id}`, { headers: headers() });
      if (res.ok) {
        const d = await res.json();
        if (d.attempt) setSelected(d.attempt);
      }
    } catch { /* keep the list copy */ }
    setLoadingAttempt(false);
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
      toast.success(t('تم حفظ التصحيح وإشعار الطالب', 'Grades saved and the student was notified'));
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
            <p className="text-muted-foreground mt-1">{t('كل محاولات طلابك في مكان واحد: راجع الإجابات وصحّح الأسئلة التي تحتاج مراجعة يدوية.', 'All of your students’ attempts in one place: review answers and grade the ones that need manual review.')}</p>
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
              <p className="text-sm text-muted-foreground mt-1">{t('أنشئ امتحاناً من صفحة الكورس، ثم تابع محاولات طلابك هنا.', 'Create an exam from a course page, then follow your students’ attempts here.')}</p>
            </div>
            <a href="/courses">
              <Button className="gap-2 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
                <Plus className="h-4 w-4" /> {t('أنشئ امتحاناً', 'Create an exam')}
              </Button>
            </a>
          </CardContent>
        </Card>
      )}

      <div className="space-y-4">
        {exams.map((exam: any) => {
          const list = attempts[exam.id] || [];
          const pending = list.filter((a: any) => a.needs_review);
          return (
            <Card key={exam.id} className="border-0 shadow-sm overflow-hidden">
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

                {list.length === 0 ? (
                  <p className="text-sm text-muted-foreground">{t('لا توجد محاولات بعد', 'No attempts yet')}</p>
                ) : (
                  <div className="space-y-2">
                    {list.map((a: any) => {
                      const manual = a.manual_review_count ?? (a.answers || []).filter((an: any) => an.is_correct === null).length;
                      return (
                        <div key={a.id} className={`flex items-center justify-between flex-wrap gap-3 p-3.5 rounded-2xl border transition-colors ${a.needs_review ? 'bg-amber-50/60 border-amber-100 hover:bg-amber-50' : 'bg-slate-50/60 border-slate-100 hover:bg-slate-50'}`}>
                          <div className="flex items-center gap-3 min-w-0">
                            <span className="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-teal-400 to-emerald-500 text-white font-bold text-xs shrink-0">
                              {a.student?.name?.[0] || '؟'}
                            </span>
                            <div className="min-w-0">
                              <div className="flex flex-wrap items-center gap-1.5">
                                <span className="font-semibold truncate">{a.student?.name || a.user_id}</span>
                                <Badge className="border-0 bg-slate-100 text-slate-600 text-[10px]">{t('محاولة', 'Attempt')} #{a.attempt_number}</Badge>
                                <Badge className={`border-0 text-[10px] ${a.status === 'submitted' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'}`}>{a.status === 'submitted' ? t('مُسلّمة', 'Submitted') : t('جارية', 'In progress')}</Badge>
                                {a.needs_review && <Badge className="border-0 bg-amber-100 text-amber-700 text-[10px]">{t('بانتظار التصحيح', 'Pending review')}</Badge>}
                              </div>
                              <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-muted-foreground mt-0.5">
                                <span className="flex items-center gap-1"><Clock className="h-3 w-3" />{a.submitted_at ? new Date(a.submitted_at).toLocaleString(locale === 'ar' ? 'ar' : 'en') : t('لم تُسلّم', 'not submitted')}</span>
                                <span>{t('المدة الفعلية', 'Time spent')}: {fmtDuration(a.time_spent_seconds)}</span>
                                {manual > 0 && <span className="text-amber-700 font-semibold">{manual} {t('سؤال يحتاج مراجعة يدوية', 'need manual review')}</span>}
                              </div>
                            </div>
                          </div>
                          <div className="flex items-center gap-3">
                            <span className="text-sm">{t('الدرجة', 'Score')}: <b className={a.passed ? 'text-emerald-600' : a.needs_review ? 'text-amber-700' : 'text-slate-600'}>{a.percentage ?? 0}%</b>{a.needs_review && <span className="text-[10px] text-amber-600"> ({t('مؤقتة', 'provisional')})</span>}</span>
                            <Button size="sm" onClick={() => openReview(a)} className="gap-1 rounded-full text-white" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>{t('مراجعة', 'Review')}</Button>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </CardContent>
            </Card>
          );
        })}
      </div>

      {selected && (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-end sm:items-center justify-center p-0 sm:p-4" onClick={() => setSelected(null)}>
          <div className="w-full sm:max-w-2xl bg-white rounded-t-2xl sm:rounded-2xl p-6 space-y-4 max-h-[88vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between">
              <div>
                <h2 className="font-bold">{t('مراجعة المحاولة', 'Review attempt')} #{selected.attempt_number}</h2>
                <p className="text-xs text-muted-foreground">{selected.student?.name || selected.user_id} · {selected.course?.title || selected.exam?.title || ''}{selected.section?.title ? ` · ${selected.section.title}` : ''} · {t('المدة', 'Time')} {fmtDuration(selected.time_spent_seconds)}</p>
              </div>
              <button onClick={() => setSelected(null)} className="text-gray-400 hover:text-gray-600" aria-label={t('إغلاق', 'Close')}><X className="h-5 w-5" /></button>
            </div>

            <div className="flex flex-wrap items-center gap-2 text-sm">
              <Badge className="border-0 bg-slate-100 text-slate-600">{t('مؤقتة', 'Provisional')}: {selected.percentage ?? 0}%</Badge>
              <Badge className={selected.passed ? 'border-0 bg-emerald-50 text-emerald-600' : 'border-0 bg-red-50 text-red-600'}>{selected.passed ? t('ناجح', 'Passed') : t('غير ناجح', 'Not passed')}</Badge>
              {selected.metadata?.auto_submitted && <Badge className="border-0 bg-amber-50 text-amber-700">{t('تسليم تلقائي', 'Auto-submitted')}</Badge>}
              {selected.metadata?.anti_cheat_violations > 0 && <Badge className="border-0 bg-red-50 text-red-600">{t('مخالفات', 'Violations')}: {selected.metadata.anti_cheat_violations}</Badge>}
            </div>

            {(selected.skills || []).length > 0 && (
              <div className="space-y-2 rounded-xl border border-teal-100 bg-teal-50/40 p-3">
                <p className="flex items-center gap-1.5 text-xs font-bold text-teal-700"><Sparkles className="h-3.5 w-3.5" />{t('الأداء حسب المهارة (من النتائج الفعلية)', 'Performance by skill (from real results)')}</p>
                <div className="space-y-1.5">
                  {selected.skills.map((s: any) => {
                    const label = LEVEL_LABEL[s.level] || [s.level, s.level];
                    const color = s.level === 'advanced' ? 'bg-emerald-500' : s.level === 'proficient' ? 'bg-teal-500' : s.level === 'developing' ? 'bg-amber-500' : 'bg-red-400';
                    return (
                      <div key={s.name} className="space-y-1">
                        <div className="flex items-center justify-between text-xs">
                          <span className="font-medium">{s.name}</span>
                          <span className="text-gray-500">{s.accuracy}% · {locale === 'ar' ? label[0] : label[1]}</span>
                        </div>
                        <div className="h-1.5 w-full rounded-full bg-white border"><div className={`h-full rounded-full ${color}`} style={{ width: `${Math.min(100, Math.max(0, s.accuracy))}%` }} /></div>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}

            {loadingAttempt ? (
              <div className="flex justify-center py-10"><Loader2 className="h-6 w-6 animate-spin text-teal-600" /></div>
            ) : (
              <div className="space-y-3">
                {(selected.answers || []).map((ans: any) => {
                  const manual = ans.is_correct === null || ans.is_correct === undefined;
                  return (
                    <div key={ans.id} className="p-3 rounded-xl bg-gray-50 space-y-2">
                      <div className="flex items-start justify-between gap-2">
                        <p className="text-sm font-medium">{ans.question?.question_text || t('سؤال', 'Question')}</p>
                        {ans.is_correct === true ? <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-500" /> : ans.is_correct === false ? <X className="h-4 w-4 shrink-0 text-red-500" /> : <HelpCircle className="h-4 w-4 shrink-0 text-amber-500" />}
                      </div>
                      <AnswerBody ans={ans} t={t} />
                      <div className="flex items-center gap-2">
                        <span className="text-xs text-gray-400">{t('الدرجة', 'Score')} / {ans.points_max}:</span>
                        <Input
                          type="number" min={0} max={Number(ans.points_max)} step="0.5"
                          value={grades[ans.id] ?? (manual ? '' : String(ans.points_earned ?? ''))}
                          onChange={(e) => setGrades({ ...grades, [ans.id]: e.target.value })}
                          className="h-9 w-24 text-center"
                          placeholder="0"
                          aria-label={t('درجة السؤال', 'Question score')}
                        />
                        {!manual && <span className="text-[11px] text-emerald-600">{t('مُصحّح تلقائياً', 'Auto-graded')}</span>}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}

            <Button onClick={saveGrades} disabled={saving} className="w-full gap-2 rounded-full">
              {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}{t('حفظ التصحيح', 'Save grades')}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
