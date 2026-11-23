'use client';

import { useCallback, useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { notesApi } from '@/services/endpoints';
import { getApiErrorMessage } from '@/services/api';
import { formatClock } from '@/lib/watch-position';
import { canJumpToNote, positionProblemLabel, type LessonNote } from '@/lib/lesson-notes';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { toast } from 'sonner';
import {
  Bookmark, NotebookPen, Trash2, Pencil, Play, X, Check,
  Loader2, AlertTriangle, Clock, Lock,
} from 'lucide-react';

type Props = {
  lessonId: string;
  /** الفيديو الحالي للدرس (إن وُجد) — يُربط بالمرجع الزمني. */
  videoId?: string | null;
  /** هل يمكن قراءة الزمن/القفز؟ (المشغّل المحمي جاهز) */
  canUsePlayer: boolean;
  getCurrentTime: () => number | null;
  getDuration: () => number | null;
  onSeek: (seconds: number) => void;
  /** وضع المعاينة للمعلم: لا كتابة. */
  disabled?: boolean;
};

/**
 * لوحة ملاحظات الطالب داخل الدرس: كتابة ملاحظة، «احفظ هذه اللحظة» (علامة
 * مرجعية زمنية)، قائمة الملاحظات مع القفز إلى اللحظة المحفوظة، والتعديل/الحذف.
 *
 * الملاحظات خاصة بالطالب وحده — لا تُعرض لطالب آخر ولا للمعلم، والخادم يفرض
 * ذلك. الواجهة لا تعرض أي محتوى فيديو، والعلامة مجرد مرجع زمني.
 */
export default function LessonNotesPanel({
  lessonId, videoId = null, canUsePlayer, getCurrentTime, getDuration, onSeek, disabled = false,
}: Props) {
  const { locale } = useLocale();
  const t = useCallback((ar: string, en: string) => (locale === 'ar' ? ar : en), [locale]);

  const [notes, setNotes] = useState<LessonNote[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const [body, setBody] = useState('');
  const [attachTime, setAttachTime] = useState(false);

  const [editingId, setEditingId] = useState<string | null>(null);
  const [editBody, setEditBody] = useState('');
  const [busyId, setBusyId] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    notesApi.list({ lesson_id: lessonId, per_page: 100 })
      .then((d) => setNotes(d.data || []))
      .catch(() => setNotes([]))
      .finally(() => setLoading(false));
  }, [lessonId]);

  // المعاينة للمعلم (disabled): لا نكتب ولا نجلب أي بيانات — اللوحة غير معروضة أصلاً.
  useEffect(() => { if (!disabled) load(); }, [load, disabled]);

  const saveMoment = async () => {
    if (!canUsePlayer) return;
    const position = getCurrentTime();
    if (position === null) {
      toast.error(t('انتظر حتى يبدأ الفيديو ثم أعد المحاولة', 'Wait until the video starts, then try again'));
      return;
    }
    setSaving(true);
    try {
      const note = await notesApi.create({
        lesson_id: lessonId,
        video_id: videoId,
        type: 'bookmark',
        position_seconds: Math.floor(position),
        duration_seconds: getDuration() !== null ? Math.floor(getDuration() as number) : null,
      });
      setNotes((prev) => [note, ...prev]);
      toast.success(t(`حُفظت اللحظة عند ${formatClock(Math.floor(position))}`, `Moment saved at ${formatClock(Math.floor(position))}`));
    } catch (e) {
      toast.error(getApiErrorMessage(e, t('تعذّر حفظ العلامة', 'Could not save the bookmark')));
    } finally {
      setSaving(false);
    }
  };

  const addNote = async () => {
    const text = body.trim();
    const position = attachTime && canUsePlayer ? getCurrentTime() : null;
    if (!text && position === null) return;
    setSaving(true);
    try {
      const created = await notesApi.create({
        lesson_id: lessonId,
        video_id: position !== null ? videoId : null,
        type: 'note',
        body: text || null,
        position_seconds: position !== null ? Math.floor(position) : null,
        duration_seconds: position !== null && getDuration() !== null ? Math.floor(getDuration() as number) : null,
      });
      setNotes((prev) => [created, ...prev]);
      setBody('');
      setAttachTime(false);
      toast.success(t('حُفظت الملاحظة', 'Note saved'));
    } catch (e) {
      toast.error(getApiErrorMessage(e, t('تعذّر حفظ الملاحظة', 'Could not save the note')));
    } finally {
      setSaving(false);
    }
  };

  const saveEdit = async (note: LessonNote) => {
    setBusyId(note.id);
    try {
      const updated = await notesApi.update(note.id, { body: editBody.trim() || null });
      setNotes((prev) => prev.map((n) => (n.id === note.id ? updated : n)));
      setEditingId(null);
      toast.success(t('حُدّثت الملاحظة', 'Note updated'));
    } catch (e) {
      toast.error(getApiErrorMessage(e, t('تعذّر التحديث', 'Could not update')));
    } finally {
      setBusyId(null);
    }
  };

  const remove = async (note: LessonNote) => {
    if (!window.confirm(t('حذف هذه الملاحظة؟', 'Delete this note?'))) return;
    setBusyId(note.id);
    try {
      await notesApi.remove(note.id);
      setNotes((prev) => prev.filter((n) => n.id !== note.id));
      toast.success(t('حُذفت الملاحظة', 'Note deleted'));
    } catch (e) {
      toast.error(getApiErrorMessage(e, t('تعذّر الحذف', 'Could not delete')));
    } finally {
      setBusyId(null);
    }
  };

  if (disabled) return null;

  const composerHasContent = body.trim().length > 0 || (attachTime && canUsePlayer);

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 space-y-4" aria-label={t('ملاحظاتي', 'My notes')}>
      <header className="flex items-center justify-between gap-3">
        <h3 className="flex items-center gap-2 font-bold">
          <NotebookPen className="h-4.5 w-4.5 text-teal-600" />
          {t('ملاحظاتي وعلاماتي', 'My notes & bookmarks')}
        </h3>
        <span className="flex items-center gap-1 text-[11px] text-gray-400">
          <Lock className="h-3 w-3" />
          {t('خاصة بك وحدك', 'Private to you')}
        </span>
      </header>

      {/* المحرّر */}
      <div className="space-y-2">
        <Textarea
          value={body}
          onChange={(e) => setBody(e.target.value)}
          maxLength={2000}
          rows={3}
          placeholder={t('اكتب ملاحظة خاصة أثناء الدرس...', 'Write a private note while learning...')}
          className="resize-y"
        />
        <div className="flex flex-wrap items-center gap-2">
          <Button
            size="sm"
            onClick={addNote}
            disabled={saving || !composerHasContent}
            className="gap-1.5 rounded-full"
            style={{ background: '#0d9488' }}
          >
            {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}
            {t('حفظ الملاحظة', 'Save note')}
          </Button>

          {canUsePlayer && (
            <>
              <Button
                size="sm"
                variant="outline"
                onClick={saveMoment}
                disabled={saving}
                className="gap-1.5 rounded-full border-teal-200 text-teal-700 hover:bg-teal-50"
                title={t('احفظ اللحظة الحالية كعلامة مرجعية', 'Save the current moment as a bookmark')}
              >
                <Bookmark className="h-4 w-4" />
                {t('احفظ هذه اللحظة', 'Save this moment')}
              </Button>
              <label className="flex cursor-pointer items-center gap-1.5 text-xs text-gray-500">
                <input
                  type="checkbox"
                  checked={attachTime}
                  onChange={(e) => setAttachTime(e.target.checked)}
                  className="h-3.5 w-3.5 rounded border-gray-300"
                />
                {t('ربط الملاحظة بالوقت الحالي', 'Attach to current time')}
              </label>
            </>
          )}
        </div>
      </div>

      {/* القائمة */}
      <div className="space-y-2">
        {loading ? (
          <div className="flex items-center justify-center py-6 text-gray-400">
            <Loader2 className="h-5 w-5 animate-spin" />
          </div>
        ) : notes.length === 0 ? (
          <p className="rounded-xl bg-muted/40 px-3 py-4 text-center text-sm text-gray-400">
            {t('لا توجد ملاحظات في هذا الدرس بعد.', 'No notes in this lesson yet.')}
          </p>
        ) : (
          notes.map((n) => {
            const problem = positionProblemLabel(n, locale);
            const editing = editingId === n.id;
            return (
              <article key={n.id} className="rounded-xl border border-slate-100 bg-slate-50/60 p-3">
                <div className="flex items-start gap-3">
                  {/* المرجع الزمني / النوع */}
                  <div className="shrink-0">
                    {canJumpToNote(n) ? (
                      <button
                        type="button"
                        onClick={() => onSeek(n.position_seconds as number)}
                        className="inline-flex items-center gap-1 rounded-full bg-teal-600 px-2.5 py-1 text-[11px] font-bold text-white transition hover:bg-teal-500"
                        title={t('اذهب إلى هذه اللحظة', 'Jump to this moment')}
                      >
                        <Play className="h-3 w-3" />
                        {formatClock(n.position_seconds as number)}
                      </button>
                    ) : n.type === 'bookmark' ? (
                      <span className="inline-flex items-center gap-1 rounded-full bg-slate-200 px-2.5 py-1 text-[11px] font-semibold text-slate-600">
                        <Bookmark className="h-3 w-3" />
                        {t('علامة', 'Bookmark')}
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-500">
                        <Clock className="h-3 w-3" />
                        {t('ملاحظة', 'Note')}
                      </span>
                    )}
                  </div>

                  <div className="min-w-0 flex-1">
                    {editing ? (
                      <div className="space-y-2">
                        <Textarea
                          value={editBody}
                          onChange={(e) => setEditBody(e.target.value)}
                          maxLength={2000}
                          rows={2}
                          className="resize-y bg-white text-sm"
                        />
                        <div className="flex gap-2">
                          <Button size="sm" onClick={() => saveEdit(n)} disabled={busyId === n.id} className="h-7 gap-1 rounded-full text-xs">
                            {busyId === n.id ? <Loader2 className="h-3 w-3 animate-spin" /> : <Check className="h-3 w-3" />}
                            {t('حفظ', 'Save')}
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => setEditingId(null)} className="h-7 gap-1 rounded-full text-xs">
                            <X className="h-3 w-3" />
                            {t('إلغاء', 'Cancel')}
                          </Button>
                        </div>
                      </div>
                    ) : (
                      <>
                        {n.body ? (
                          <p className="whitespace-pre-wrap break-words text-sm text-gray-800">{n.body}</p>
                        ) : (
                          <p className="text-sm italic text-gray-400">{t('علامة مرجعية بدون تعليق', 'Bookmark without a comment')}</p>
                        )}
                        {problem && (
                          <p className="mt-1 flex items-center gap-1 text-[11px] text-amber-600">
                            <AlertTriangle className="h-3 w-3" /> {problem}
                          </p>
                        )}
                        <p className="mt-1 text-[10px] text-gray-400">
                          {n.updated_at ? new Date(n.updated_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', { dateStyle: 'medium', timeStyle: 'short' }) : ''}
                        </p>
                      </>
                    )}
                  </div>

                  {!editing && (
                    <div className="flex shrink-0 items-center gap-1">
                      <button
                        type="button"
                        onClick={() => { setEditingId(n.id); setEditBody(n.body || ''); }}
                        className="rounded-full p-1.5 text-gray-400 transition hover:bg-white hover:text-teal-600"
                        title={t('تعديل', 'Edit')}
                        aria-label={t('تعديل الملاحظة', 'Edit note')}
                      >
                        <Pencil className="h-3.5 w-3.5" />
                      </button>
                      <button
                        type="button"
                        onClick={() => remove(n)}
                        disabled={busyId === n.id}
                        className="rounded-full p-1.5 text-gray-400 transition hover:bg-white hover:text-rose-600"
                        title={t('حذف', 'Delete')}
                        aria-label={t('حذف الملاحظة', 'Delete note')}
                      >
                        {busyId === n.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Trash2 className="h-3.5 w-3.5" />}
                      </button>
                    </div>
                  )}
                </div>
              </article>
            );
          })
        )}
      </div>
    </section>
  );
}
