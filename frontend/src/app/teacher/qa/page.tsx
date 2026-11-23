'use client';

import { useCallback, useEffect, useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { CardSkeleton } from '@/components/ui/skeleton';
import { useLocale } from '@/store/locale';
import { MessageCircleQuestion, Loader2, Send, CheckCircle2, Filter } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

type Question = {
  id: string;
  body: string;
  answer?: string | null;
  answered_at?: string | null;
  created_at?: string;
  student?: { name?: string; avatar_url?: string } | null;
  answered_by?: string | null;
  lesson?: { id: string; title?: string } | null;
};

const headers = () => ({
  'Content-Type': 'application/json',
  Accept: 'application/json',
  'X-Tenant-Id': typeof window !== 'undefined' ? localStorage.getItem('tenant_id') || '' : '',
});

export default function TeacherQaPage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [questions, setQuestions] = useState<Question[]>([]);
  const [unanswered, setUnanswered] = useState(0);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState<'all' | 'unanswered' | 'answered'>('unanswered');
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [busyId, setBusyId] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const q = status === 'all' ? '' : `?status=${status}`;
      const res = await fetch(`${API}/lesson-questions${q}`, { headers: headers() });
      if (res.ok) {
        const d = await res.json();
        setQuestions(d.questions || []);
        setUnanswered(d.unanswered_count ?? 0);
      }
    } catch {
      toast.error(t('تعذّر تحميل الأسئلة', 'Could not load questions'));
    }
    setLoading(false);
  }, [status, locale]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => { void load(); }, [load]);

  const answer = async (id: string) => {
    const body = (drafts[id] || '').trim();
    if (!body) return;
    setBusyId(id);
    try {
      const res = await fetch(`${API}/lesson-questions/${id}/answer`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ answer: body }),
      });
      if (!res.ok) { toast.error(t('تعذّر إرسال الرد', 'Could not send the answer')); return; }
      toast.success(t('تم إرسال الرد', 'Answer sent'));
      setDrafts((d) => ({ ...d, [id]: '' }));
      await load();
    } catch {
      toast.error(t('تعذّر إرسال الرد', 'Could not send the answer'));
    } finally { setBusyId(''); }
  };

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-teal-50 text-teal-600">
            <MessageCircleQuestion className="h-6 w-6" />
          </span>
          <div>
            <h1 className="text-xl font-bold">{t('أسئلة الطلاب', 'Student Questions')}</h1>
            <p className="text-sm text-gray-500">
              {unanswered > 0
                ? t(`${unanswered} سؤال بانتظار الرد`, `${unanswered} awaiting an answer`)
                : t('لا أسئلة معلّقة', 'No pending questions')}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Filter className="h-4 w-4 text-gray-400" />
          <select
            aria-label={t('التصفية', 'Filter')}
            className="rounded-full border bg-white px-3 py-1.5 text-sm"
            value={status}
            onChange={(e) => setStatus(e.target.value as any)}
          >
            <option value="unanswered">{t('بانتظار الرد', 'Unanswered')}</option>
            <option value="answered">{t('تم الرد', 'Answered')}</option>
            <option value="all">{t('الكل', 'All')}</option>
          </select>
        </div>
      </div>

      {loading ? (
        <CardSkeleton count={3} />
      ) : questions.length === 0 ? (
        <EmptyState
          icon={MessageCircleQuestion}
          title={t('لا أسئلة', 'No questions')}
          description={t('ستظهر هنا أسئلة الطلاب داخل الدروس.', 'Questions students ask inside lessons appear here.')}
        />
      ) : (
        <div className="space-y-3">
          {questions.map((q) => (
            <Card key={q.id} data-testid="qa-row">
              <CardContent className="space-y-3 p-4">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="font-medium">{q.body}</p>
                    <p className="mt-0.5 text-xs text-gray-400">
                      {q.student?.name || t('طالب', 'Student')}
                      {q.lesson?.title ? ` · ${q.lesson.title}` : ''}
                    </p>
                  </div>
                  {q.answered_at && (
                    <span className="flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">
                      <CheckCircle2 className="h-3.5 w-3.5" /> {t('تم الرد', 'Answered')}
                    </span>
                  )}
                </div>

                {q.answer ? (
                  <div className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">
                    <p className="whitespace-pre-wrap">{q.answer}</p>
                  </div>
                ) : (
                  <div className="space-y-2">
                    <textarea
                      value={drafts[q.id] || ''}
                      onChange={(e) => setDrafts((d) => ({ ...d, [q.id]: e.target.value }))}
                      rows={2}
                      aria-label={t('الرد', 'Answer')}
                      placeholder={t('اكتب الرد…', 'Write your answer…')}
                      className="w-full rounded-xl border p-3 text-sm"
                    />
                    <Button size="sm" onClick={() => answer(q.id)} disabled={busyId === q.id} className="gap-2 rounded-full" style={{ background: '#0d9488' }}>
                      {busyId === q.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Send className="h-3.5 w-3.5" />}
                      {t('إرسال الرد', 'Send answer')}
                    </Button>
                  </div>
                )}
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
