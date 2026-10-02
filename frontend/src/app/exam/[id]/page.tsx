'use client';

import { useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { use } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Loader2, AlertTriangle, Clock, CheckCircle, Trophy, XCircle, ArrowLeft, RotateCcw, ShieldAlert } from 'lucide-react';
import { QuestionRenderer, type AnswerValue } from '@/components/exam/question-renderer';
import { Latex } from '@/components/exam/latex';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function ExamPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { check } = useAuth();
  const { locale } = useLocale();
  const router = useRouter();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [exam, setExam] = useState<any>(null);
  const [questions, setQuestions] = useState<any[]>([]);
  const [attemptId, setAttemptId] = useState('');
  const [started, setStarted] = useState(false);
  const [current, setCurrent] = useState(0);
  const [answers, setAnswers] = useState<Record<string, AnswerValue>>({});
  const [result, setResult] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [deadline, setDeadline] = useState<number | null>(null);
  const [timeLeft, setTimeLeft] = useState<number | null>(null);
  const [violations, setViolations] = useState(0);
  const [antiCheat, setAntiCheat] = useState(false);
  const [warnShown, setWarnShown] = useState(false);
  const submitRef = useRef<(() => void) | null>(null);
  const submittedRef = useRef(false);
  const startedAtRef = useRef(0);

  const typeLabel = (tp: string) => {
    const map: Record<string, [string, string]> = {
      multiple_choice: ['اختيار متعدد', 'Multiple choice'],
      true_false: ['صح/خطأ', 'True/False'],
      drag_drop: ['سحب وإفلات', 'Drag & drop'],
      matching: ['مطابقة', 'Matching'],
      ordering: ['ترتيب', 'Ordering'],
      fill_blank: ['املأ الفراغ', 'Fill blank'],
      writing: ['إجابة كتابية', 'Written'],
      file_upload: ['رفع ملف', 'File upload'],
    };
    const l = map[tp] || [tp, tp];
    return locale === 'ar' ? l[0] : l[1];
  };

  useEffect(() => {
    if (!check()) { router.replace('/student/login'); return; }
    fetch(`${API}/exams/${id}`, { headers: { Accept: 'application/json' } }).then(r => r.json()).then(d => setExam(d.exam || null)).catch(() => {}).finally(() => setLoading(false));
  }, [id]);

  // Timer
  useEffect(() => {
    if (!deadline) return;
    const tick = () => {
      const left = deadline - Date.now();
      setTimeLeft(Math.max(0, left));
      if (left <= 0 && !submittedRef.current) {
        clearInterval(timer);
        toast.warning(t('انتهى الوقت — تم تسليم الإجابات تلقائياً', 'Time is up — answers submitted automatically'));
        submitRef.current?.();
      }
    };
    const timer = setInterval(tick, 1000);
    tick();
    return () => clearInterval(timer);
  }, [deadline]);

  // Anti-cheat
  useEffect(() => {
    if (!started || !antiCheat || submittedRef.current) return;
    const onHide = () => {
      if (document.hidden) {
        setViolations((v) => {
          const nv = v + 1;
          if (nv >= 3 && !submittedRef.current) {
            toast.error(t('تم اكتشاف مخالفات متكررة — تم تسليم الاختبار', 'Repeated violations detected — exam submitted'));
            submitRef.current?.();
          } else if (nv >= 1 && !warnShown) {
            setWarnShown(true);
            toast.warning(t('انتبه: مغادرة صفحة الاختبار تُسجل كمخالفة — 3 مخالفات = تسليم تلقائي', 'Warning: leaving the exam page counts as a violation — 3 = auto-submit'));
          }
          return nv;
        });
      }
    };
    const onBlur = () => {
      if (document.hasFocus() === false) {
        setViolations((v) => v + 1);
      }
    };
    const onCopy = (e: ClipboardEvent) => { e.preventDefault(); setViolations((v) => v + 1); };
    const onCtx = (e: Event) => e.preventDefault();
    document.addEventListener('visibilitychange', onHide);
    window.addEventListener('blur', onBlur);
    document.addEventListener('copy', onCopy);
    document.addEventListener('contextmenu', onCtx);
    return () => {
      document.removeEventListener('visibilitychange', onHide);
      window.removeEventListener('blur', onBlur);
      document.removeEventListener('copy', onCopy);
      document.removeEventListener('contextmenu', onCtx);
    };
  }, [started, antiCheat, warnShown]);

  const startExam = async () => {
    setLoading(true);
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    try {
      const res = await fetch(`${API}/exams/${id}/attempt`, { method: 'POST', headers: { 'X-Tenant-Id': tid || '', Accept: 'application/json' } });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      setAttemptId(d.attempt.id);
      setQuestions(d.questions || []);
      setAntiCheat(!!d.exam?.anti_cheat_enabled);
      setViolations(0);
      setWarnShown(false);
      submittedRef.current = false;
      setAnswers({});
      setCurrent(0);
      setResult(null);
      if (d.deadline) setDeadline(new Date(d.deadline).getTime());
      else setDeadline(null);
      startedAtRef.current = Date.now();
      setStarted(true);
      window.scrollTo(0, 0);
    } catch { toast.error('Failed'); }
    setLoading(false);
  };

  const submit = async () => {
    if (submittedRef.current) return;
    submittedRef.current = true;
    setSubmitting(true);
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    try {
      const payload = questions.map(q => {
        const a = answers[q.exam_question_id];
        const given = a === undefined ? '' : typeof a === 'string' ? a : JSON.stringify(a);
        return { exam_question_id: q.exam_question_id, question_id: q.question_id, given_answer: given, time_spent_seconds: 0 };
      }).filter(a => a.given_answer !== '');
      const res = await fetch(`${API}/exams/${id}/attempts/${attemptId}/submit`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' },
        body: JSON.stringify({
          answers: payload,
          anti_cheat_violations: violations,
          // Actual time spent since the attempt started — not the remaining time.
          time_spent_seconds: startedAtRef.current ? Math.round((Date.now() - startedAtRef.current) / 1000) : 0,
        }),
      });
      const d = await res.json();
      if (!res.ok) { submittedRef.current = false; toast.error(d.message || 'Failed'); return; }
      setResult(d.result);
      setStarted(false);
      setDeadline(null);
    } catch { submittedRef.current = false; toast.error('Failed'); }
    setSubmitting(false);
  };
  submitRef.current = submit;

  if (loading) return <div className="flex items-center justify-center h-screen"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>;

  if (!started) {
    return (
      <div className="max-w-2xl mx-auto space-y-8 py-8 px-4">
        <Card className="border-0 shadow-sm p-8 text-center space-y-6">
          <div className="flex h-20 w-20 items-center justify-center rounded-full bg-primary/10 mx-auto"><AlertTriangle className="h-10 w-10 text-primary" /></div>
          <h1 className="text-3xl font-bold">{exam?.title || 'Exam'}</h1>
          <div className="grid grid-cols-3 gap-3 text-sm">
            <div className="p-3 rounded-xl bg-gray-50"><p className="text-xs text-gray-400">{t('المدة', 'Duration')}</p><p className="font-bold text-lg">{exam?.duration_minutes || 0} {t('د', 'm')}</p></div>
            <div className="p-3 rounded-xl bg-gray-50"><p className="text-xs text-gray-400">{t('الأسئلة', 'Questions')}</p><p className="font-bold text-lg">{exam?.questions_count ?? questions.length}</p></div>
            <div className="p-3 rounded-xl bg-gray-50"><p className="text-xs text-gray-400">{t('النجاح', 'Pass')}</p><p className="font-bold text-lg">{exam?.pass_mark_percent || 0}%</p></div>
          </div>
          {result ? (
            <div className="space-y-3">
              <div className={`flex h-24 w-24 items-center justify-center rounded-full mx-auto ${result.passed ? 'bg-emerald-100' : 'bg-red-100'}`}>{result.passed ? <Trophy className="h-12 w-12 text-emerald-500" /> : <XCircle className="h-12 w-12 text-red-500" />}</div>
              <h1 className="text-3xl font-bold">{result.passed ? t('مبروك!', 'Passed!') : t('حاول مرة أخرى', 'Try again')}</h1>
              <div className="text-6xl font-bold">{result.percentage}%</div>
              <div className="text-sm text-gray-500">{result.score} / {result.total} · {t('النجاح', 'Pass')}: {result.pass_mark}%</div>
              {result.attempts_left !== undefined && result.attempts_left !== -1 && (
                <div className="text-sm text-gray-400">{t('المحاولات المتبقية', 'Attempts left')}: <b>{result.attempts_left}</b></div>
              )}
              <div className="flex justify-center gap-3 pt-2">
                {(result.attempts_left === undefined || result.attempts_left > 0) && (
                  <Button onClick={startExam} className="gap-2 rounded-full"><RotateCcw className="h-4 w-4" />{t('إعادة الاختبار', 'Retake exam')}</Button>
                )}
                <a href="/my-learning"><Button variant="outline">{t('العودة', 'Back')}</Button></a>
              </div>
            </div>
          ) : (
            <>
              <p className="text-gray-500">{t(`لديك ${exam?.duration_minutes || 30} دقيقة. نسبة النجاح ${exam?.pass_mark_percent || 70}%.`, 'You have X minutes.')}</p>
              {exam?.anti_cheat_enabled !== false && (
                <div className="flex items-start gap-2 p-3 rounded-xl bg-amber-50 text-amber-700 text-xs text-start">
                  <ShieldAlert className="h-4 w-4 shrink-0 mt-0.5" />
                  <span>{t('وضع منع الغش مفعل: مغادرة الصفحة تُسجل مخالفة، و3 مخالفات = تسليم تلقائي. النسخ والنقر الأيمن معطلان.', 'Anti-cheat is ON: leaving the page counts as a violation, 3 = auto-submit. Copy & right-click are disabled.')}</span>
                </div>
              )}
              <Button size="lg" onClick={startExam}>{t('ابدأ الاختبار', 'Start Exam')}</Button>
            </>
          )}
        </Card>
      </div>
    );
  }

  const q = questions[current];
  if (!q) return null;

  const answeredCount = questions.filter((x) => {
    const a = answers[x.exam_question_id];
    if (a === undefined) return false;
    if (typeof a === 'string') return a !== '';
    if (Array.isArray(a)) return a.length > 0;
    return true;
  }).length;

  const fmt = (ms: number) => {
    const s = Math.max(0, Math.floor(ms / 1000));
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return `${h > 0 ? h + ':' : ''}${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
  };

  return (
    <div className="max-w-3xl mx-auto space-y-6 py-8 px-4 select-none" style={{ userSelect: 'none' }}>
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <a href="/my-learning" className="flex items-center gap-1 text-sm text-gray-500"><ArrowLeft className="h-4 w-4" /> {t('خروج', 'Exit')}</a>
        <div className="flex items-center gap-2">
          {antiCheat && (
            <span className={`flex items-center gap-1 text-[11px] ${violations >= 3 ? 'text-red-600 font-bold' : violations > 0 ? 'text-amber-600' : 'text-gray-400'}`}>
              <ShieldAlert className="h-3.5 w-3.5" />{t('مخالفات', 'Violations')}: {violations}/3
            </span>
          )}
          {timeLeft !== null && (
            <span className={`flex items-center gap-1.5 px-3 py-1.5 rounded-full font-mono text-sm font-bold ${timeLeft < 60000 ? 'bg-red-50 text-red-600 animate-pulse' : 'bg-teal-50 text-teal-700'}`}>
              <Clock className="h-4 w-4" />{fmt(timeLeft)}
            </span>
          )}
        </div>
        <span className="text-sm text-gray-500">{t('سؤال', 'Q')} {current + 1}/{questions.length}</span>
      </div>

      {/* Progress */}
      <div className="flex gap-1">
        {questions.map((xq, i) => {
          const a = answers[xq.exam_question_id];
          const done = a !== undefined && (typeof a === 'string' ? a !== '' : Array.isArray(a) ? a.length > 0 : true);
          return (
            <button key={xq.exam_question_id} onClick={() => setCurrent(i)} className={`h-2 flex-1 rounded-full transition-all ${i === current ? 'bg-teal-600' : done ? 'bg-green-400' : 'bg-gray-200'}`} title={`${i + 1}`} />
          );
        })}
      </div>

      <Card className="border-0 shadow-sm p-8">
        <div className="flex items-center gap-1.5 mb-2">
          <Badge variant="outline" className="text-[10px]">{typeLabel(q.question_type)}</Badge>
          <Badge variant="outline" className="text-[10px]">{q.points} {t('نقطة', 'pts')}</Badge>
        </div>
        <h2 className="text-xl font-semibold mb-3"><Latex text={q.question_text} /></h2>
        {q.metadata?.image_url && <img src={q.metadata.image_url} className="rounded-xl border max-h-64 mb-4 object-contain" alt="" />}
        <QuestionRenderer q={q} value={answers[q.exam_question_id] ?? (q.question_type === 'drag_drop' || q.question_type === 'matching' ? [] : q.question_type === 'ordering' ? (q.metadata?.items || []).map((i: any) => typeof i === 'string' ? i : i.text) : q.question_type === 'fill_blank' ? [] : '')} onChange={(v) => setAnswers({ ...answers, [q.exam_question_id]: v })} />
      </Card>

      <div className="flex justify-between items-center gap-3 flex-wrap">
        <div className="text-xs text-gray-400">{answeredCount}/{questions.length} {t('مُجاب', 'answered')}</div>
        <div className="flex gap-2 flex-wrap">
          <Button variant="outline" disabled={current === 0} onClick={() => setCurrent(current - 1)}>{t('السابق', 'Prev')}</Button>
          {current < questions.length - 1 ? (
            <Button onClick={() => setCurrent(current + 1)}>{t('التالي', 'Next')}</Button>
          ) : (
            <Button onClick={submit} disabled={submitting} className="gap-2">{submitting && <Loader2 className="h-4 w-4 animate-spin" />}{t('تسليم الإجابات', 'Submit answers')}</Button>
          )}
        </div>
      </div>
    </div>
  );
}
