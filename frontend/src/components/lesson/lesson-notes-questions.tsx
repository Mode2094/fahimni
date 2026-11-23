'use client';

import { useCallback, useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import {
  NotebookPen, MessageCircleQuestion, Trash2, Loader2, Send, Check, Clock, Share2,
} from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

type Note = { id: string; body: string; timestamp_seconds?: number | null; is_shared?: boolean; created_at?: string };
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

function fmt(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds));
  const m = Math.floor(s / 60);
  const r = s % 60;
  return `${m}:${String(r).padStart(2, '0')}`;
}

/**
 * Lesson notes (optionally pinned to a video moment) + a small Q&A thread.
 * Rendered only for a really-enrolled student; the API re-checks ownership on
 * every write, so this component is a convenience, never the security boundary.
 */
export default function LessonNotesQuestions({
  lessonId,
  getTimestamp,
}: {
  lessonId: string;
  getTimestamp?: () => number;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [tab, setTab] = useState<'notes' | 'qa'>('notes');
  const [notes, setNotes] = useState<Note[]>([]);
  const [questions, setQuestions] = useState<Question[]>([]);
  const [loading, setLoading] = useState(true);
  const [noteBody, setNoteBody] = useState('');
  const [noteShared, setNoteShared] = useState(false);
  const [pinTime, setPinTime] = useState(false);
  const [busy, setBusy] = useState(false);
  const [qBody, setQBody] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [n, q] = await Promise.all([
        fetch(`${API}/lesson-notes?lesson_id=${lessonId}`, { headers: headers() })
          .then((r) => (r.ok ? r.json() : { notes: [] })),
        fetch(`${API}/lessons/${lessonId}/questions`, { headers: headers() })
          .then((r) => (r.ok ? r.json() : { questions: [] })),
      ]);
      setNotes(n.notes || []);
      setQuestions(q.questions || []);
    } catch {
      /* non-fatal */
    }
    setLoading(false);
  }, [lessonId]);

  useEffect(() => { void load(); }, [load]);

  const addNote = async () => {
    const body = noteBody.trim();
    if (!body || busy) return;
    setBusy(true);
    try {
      const res = await fetch(`${API}/lessons/${lessonId}/notes`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({
          body,
          is_shared: noteShared,
          timestamp_seconds: pinTime && getTimestamp ? Math.floor(getTimestamp()) : null,
        }),
      });
      if (!res.ok) { toast.error(t('تعذّر حفظ الملاحظة', 'Could not save the note')); return; }
      setNoteBody('');
      setNoteShared(false);
      setPinTime(false);
      await load();
    } catch {
      toast.error(t('تعذّر حفظ الملاحظة', 'Could not save the note'));
    } finally { setBusy(false); }
  };

  const deleteNote = async (id: string) => {
    setBusy(true);
    try {
      const res = await fetch(`${API}/lesson-notes/${id}`, { method: 'DELETE', headers: headers() });
      if (res.ok) setNotes((ns) => ns.filter((n) => n.id !== id));
    } finally { setBusy(false); }
  };

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
    <section className="rounded-2xl border bg-white" aria-label={t('ملاحظات وأسئلة الدرس', 'Lesson notes & questions')}>
      <div className="flex border-b">
        <button
          onClick={() => setTab('notes')}
          className={`flex items-center gap-2 px-4 py-3 text-sm ${tab === 'notes' ? 'border-b-2 border-teal-600 font-medium text-teal-700' : 'text-gray-500'}`}
        >
          <NotebookPen className="h-4 w-4" /> {t('ملاحظاتي', 'My notes')}
        </button>
        <button
          onClick={() => setTab('qa')}
          className={`flex items-center gap-2 px-4 py-3 text-sm ${tab === 'qa' ? 'border-b-2 border-teal-600 font-medium text-teal-700' : 'text-gray-500'}`}
        >
          <MessageCircleQuestion className="h-4 w-4" /> {t('اسأل المعلم', 'Ask the teacher')}
        </button>
      </div>

      {tab === 'notes' ? (
        <div className="space-y-3 p-4">
          <textarea
            value={noteBody}
            onChange={(e) => setNoteBody(e.target.value)}
            rows={2}
            aria-label={t('نص الملاحظة', 'Note text')}
            placeholder={t('اكتب ملاحظة…', 'Write a note…')}
            className="w-full rounded-xl border p-3 text-sm"
          />
          <div className="flex flex-wrap items-center gap-3 text-xs text-gray-600">
            {getTimestamp && (
              <label className="flex items-center gap-1.5">
                <input type="checkbox" checked={pinTime} onChange={(e) => setPinTime(e.target.checked)} />
                <Clock className="h-3.5 w-3.5" /> {t('ربط بلحظة الفيديو', 'Pin to video time')}
              </label>
            )}
            <label className="flex items-center gap-1.5">
              <input type="checkbox" checked={noteShared} onChange={(e) => setNoteShared(e.target.checked)} />
              <Share2 className="h-3.5 w-3.5" /> {t('مشاركة مع المعلم', 'Share with teacher')}
            </label>
            <Button size="sm" onClick={addNote} disabled={busy} className="ms-auto gap-2 rounded-full" style={{ background: '#0d9488' }}>
              {busy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Send className="h-3.5 w-3.5" />} {t('حفظ', 'Save')}
            </Button>
          </div>

          {loading ? (
            <div className="py-2"><Loader2 className="h-4 w-4 animate-spin text-gray-400" /></div>
          ) : notes.length === 0 ? (
            <p className="py-2 text-sm text-gray-400">{t('لا ملاحظات بعد.', 'No notes yet.')}</p>
          ) : (
            <ul className="space-y-2">
              {notes.map((n) => (
                <li key={n.id} data-testid="lesson-note" className="flex items-start gap-2 rounded-xl bg-gray-50 p-3 text-sm">
                  <div className="flex-1">
                    {n.timestamp_seconds != null && (
                      <span className="me-2 rounded bg-teal-100 px-1.5 py-0.5 text-xs text-teal-700" dir="ltr">{fmt(n.timestamp_seconds)}</span>
                    )}
                    {n.body}
                    {n.is_shared && <span className="ms-2 text-xs text-gray-400">· {t('مشتركة', 'shared')}</span>}
                  </div>
                  <button onClick={() => deleteNote(n.id)} aria-label={t('حذف', 'Delete')} className="text-gray-400 hover:text-red-500">
                    <Trash2 className="h-4 w-4" />
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      ) : (
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
      )}
    </section>
  );
}
