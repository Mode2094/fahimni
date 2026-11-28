'use client';

import { useCallback, useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { confirmDialog } from '@/components/ui/confirm-dialog';
import { MessageCircleQuestion, Trash2, Loader2, Send, Check } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

type Question = {
  id: string; body: string; answer?: string | null; answered_at?: string | null;
  created_at?: string; mine?: boolean; student?: { name?: string } | null; answered_by?: string | null;
};

function headers(): Record<string, string> {
  const tid = typeof window !== 'undefined' ? localStorage.getItem('tenant_id') : null;
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...(tid ? { 'X-Tenant-Id': tid } : {}),
  };
}

/**
 * Per-lesson Q&A thread: a student asks on the lesson and sees the teacher's
 * answer. Rendered only for a really-enrolled student; the API re-checks
 * ownership on every write, so this component is a convenience, never the
 * security boundary.
 */
export default function LessonQuestions({ lessonId }: { lessonId: string }) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [questions, setQuestions] = useState<Question[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [qBody, setQBody] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const q = await fetch(`${API}/lessons/${lessonId}/questions`, { headers: headers() })
        .then((r) => (r.ok ? r.json() : { questions: [] }));
      setQuestions(q.questions || []);
    } catch {
      /* non-fatal */
    }
    setLoading(false);
  }, [lessonId]);

  useEffect(() => { void load(); }, [load]);

  const askQuestion = async () => {
    const body = qBody.trim();
    if (!body || busy) return;
    setBusy(true);
    try {
      const res = await fetch(`${API}/lessons/${lessonId}/questions`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ body, is_public: true }),
      });
      if (!res.ok) { toast.error(t('تعذّر إرسال السؤال', 'Could not send the question')); return; }
      setQBody('');
      await load();
    } catch {
      toast.error(t('تعذّر إرسال السؤال', 'Could not send the question'));
    } finally { setBusy(false); }
  };

  const deleteQuestion = async (id: string) => {
    const ok = await confirmDialog({
      danger: true,
      description: t('حذف هذا السؤال؟ لا يمكن التراجع.', 'Delete this question? This cannot be undone.'),
      confirmLabel: t('حذف', 'Delete'),
    });
    if (!ok) return;
    setBusy(true);
    try {
      const res = await fetch(`${API}/lesson-questions/${id}`, { method: 'DELETE', headers: headers() });
      if (res.ok) setQuestions((qs) => qs.filter((q) => q.id !== id));
      else {
        const d = await res.json().catch(() => null);
        if (d?.message) toast.error(d.message);
      }
    } finally { setBusy(false); }
  };

  return (
    <section className="rounded-2xl border bg-white" aria-label={t('أسئلة الدرس', 'Lesson questions')}>
      <div className="flex items-center gap-2 border-b px-4 py-3 text-sm font-medium text-teal-700">
        <MessageCircleQuestion className="h-4 w-4" /> {t('اسأل المعلم', 'Ask the teacher')}
      </div>
      <div className="space-y-3 p-4">
        <textarea
          value={qBody}
          onChange={(e) => setQBody(e.target.value)}
          rows={2}
          aria-label={t('نص السؤال', 'Question text')}
          placeholder={t('اكتب سؤالك عن الدرس…', 'Ask your question about this lesson…')}
          className="w-full rounded-xl border p-3 text-sm"
        />
        <Button size="sm" onClick={askQuestion} disabled={busy} className="gap-2 rounded-full" style={{ background: '#0d9488' }}>
          {busy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Send className="h-3.5 w-3.5" />} {t('إرسال', 'Send')}
        </Button>

        {loading ? (
          <div className="py-2"><Loader2 className="h-4 w-4 animate-spin text-gray-400" /></div>
        ) : questions.length === 0 ? (
          <p className="py-2 text-sm text-gray-400">{t('لا أسئلة بعد.', 'No questions yet.')}</p>
        ) : (
          <ul className="space-y-3">
            {questions.map((q) => (
              <li key={q.id} data-testid="lesson-question" className="rounded-xl border p-3 text-sm">
                <div className="flex items-start justify-between gap-2">
                  <p className="font-medium">{q.body}</p>
                  {q.mine && q.answered_at == null && (
                    <button onClick={() => deleteQuestion(q.id)} aria-label={t('حذف', 'Delete')} className="text-gray-400 hover:text-red-500">
                      <Trash2 className="h-4 w-4" />
                    </button>
                  )}
                </div>
                {q.answer ? (
                  <div className="mt-2 rounded-lg bg-emerald-50 p-2 text-emerald-800">
                    <span className="flex items-center gap-1 text-xs font-medium"><Check className="h-3.5 w-3.5" /> {t('رد المعلم', 'Teacher')}{q.answered_by ? ` · ${q.answered_by}` : ''}</span>
                    <p className="mt-1 whitespace-pre-wrap">{q.answer}</p>
                  </div>
                ) : (
                  <p className="mt-1 text-xs text-amber-600">{t('بانتظار رد المعلم', 'Awaiting the teacher')}</p>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}
