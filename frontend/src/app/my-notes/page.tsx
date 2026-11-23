'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { notesApi } from '@/services/endpoints';
import { getApiErrorMessage } from '@/services/api';
import { formatClock } from '@/lib/watch-position';
import { buildLearnUrl } from '@/lib/course-preview';
import {
  groupNotes, positionProblemLabel, type LessonNote,
} from '@/lib/lesson-notes';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { CardSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { toast } from 'sonner';
import {
  NotebookPen, Search, Bookmark, Clock, Trash2, Pencil, X, Check,
  Loader2, AlertTriangle, Play, ExternalLink, Lock,
} from 'lucide-react';

type SummaryCourse = {
  course_id: string;
  course_title: string;
  notes_count: number;
  lessons: { lesson_id: string; lesson_title: string; notes_count: number }[];
};

export default function MyNotesPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = useCallback((ar: string, en: string) => (locale === 'ar' ? ar : en), [locale]);

  const [notes, setNotes] = useState<LessonNote[]>([]);
  const [summary, setSummary] = useState<SummaryCourse[]>([]);
  const [loading, setLoading] = useState(true);
  const [filters, setFilters] = useState({ q: '', course_id: '', lesson_id: '', type: '' as '' | 'note' | 'bookmark' });

  const [editingId, setEditingId] = useState<string | null>(null);
  const [editBody, setEditBody] = useState('');
  const [busyId, setBusyId] = useState<string | null>(null);

  const load = useCallback((f = filters) => {
    setLoading(true);
    notesApi.list({
      q: f.q || undefined,
      course_id: f.course_id || undefined,
      lesson_id: f.lesson_id || undefined,
      type: f.type || undefined,
      per_page: 100,
    })
      .then((d) => setNotes(d.data || []))
      .catch(() => setNotes([]))
      .finally(() => setLoading(false));
  }, [filters]);

  useEffect(() => {
    if (!check()) return;
    notesApi.summary().then(setSummary).catch(() => setSummary([]));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // بحث بعد توقّف الكتابة (debounce).
  useEffect(() => {
    const id = setTimeout(() => load(filters), 280);
    return () => clearTimeout(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters.q, filters.course_id, filters.lesson_id, filters.type]);

  const groups = useMemo(() => groupNotes(notes), [notes]);
  const lessonsForCourse = useMemo(
    () => summary.find((c) => c.course_id === filters.course_id)?.lessons || [],
    [summary, filters.course_id],
  );
  const totalNotes = summary.reduce((sum, c) => sum + c.notes_count, 0);

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
      notesApi.summary().then(setSummary).catch(() => {});
      toast.success(t('حُذفت الملاحظة', 'Note deleted'));
    } catch (e) {
      toast.error(getApiErrorMessage(e, t('تعذّر الحذف', 'Could not delete')));
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <div className="flex flex-wrap items-center gap-3">
        <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
          <NotebookPen className="h-6 w-6 text-teal-600" />
        </span>
        <div className="min-w-0">
          <h1 className="text-2xl font-bold sm:text-3xl">{t('ملاحظاتي وعلاماتي', 'My Notes & Bookmarks')}</h1>
          <p className="mt-1 text-muted-foreground">
            {t('كل ما دوّنته أثناء الدراسة — منظّم حسب الكورس والدرس', 'Everything you noted while learning — grouped by course and lesson')}
          </p>
        </div>
        <span className="ms-auto inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1.5 text-[11px] text-gray-500">
          <Lock className="h-3.5 w-3.5" />
          {t('خاصة بك وحدك — لا يراها غيرك', 'Private to you — nobody else sees them')}
        </span>
      </div>

      {/* الفلاتر */}
      <div className="grid gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <div className="space-y-2 lg:col-span-2">
          <Label htmlFor="notes-q">{t('بحث في الملاحظات', 'Search notes')}</Label>
          <div className="relative">
            <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <Input
              id="notes-q"
              value={filters.q}
              onChange={(e) => setFilters((f) => ({ ...f, q: e.target.value }))}
              className="h-10 ps-9"
              placeholder={t('ابحث في نص ملاحظاتك...', 'Search your notes...')}
            />
          </div>
        </div>
        <div className="space-y-2">
          <Label htmlFor="notes-course">{t('الكورس', 'Course')}</Label>
          <select
            id="notes-course"
            value={filters.course_id}
            onChange={(e) => setFilters((f) => ({ ...f, course_id: e.target.value, lesson_id: '' }))}
            className="h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm"
          >
            <option value="">{t('كل الكورسات', 'All courses')}</option>
            {summary.map((c) => (
              <option key={c.course_id} value={c.course_id}>{c.course_title} ({c.notes_count})</option>
            ))}
          </select>
        </div>
        <div className="space-y-2">
          <Label htmlFor="notes-type">{t('النوع', 'Type')}</Label>
          <select
            id="notes-type"
            value={filters.type}
            onChange={(e) => setFilters((f) => ({ ...f, type: e.target.value as any }))}
            className="h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm"
          >
            <option value="">{t('الكل', 'All')}</option>
            <option value="note">{t('ملاحظات', 'Notes')}</option>
            <option value="bookmark">{t('علامات مرجعية', 'Bookmarks')}</option>
          </select>
        </div>
        {filters.course_id && lessonsForCourse.length > 0 && (
          <div className="space-y-2 lg:col-span-2">
            <Label htmlFor="notes-lesson">{t('الدرس', 'Lesson')}</Label>
            <select
              id="notes-lesson"
              value={filters.lesson_id}
              onChange={(e) => setFilters((f) => ({ ...f, lesson_id: e.target.value }))}
              className="h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm"
            >
              <option value="">{t('كل الدروس', 'All lessons')}</option>
              {lessonsForCourse.map((l) => (
                <option key={l.lesson_id} value={l.lesson_id}>{l.lesson_title} ({l.notes_count})</option>
              ))}
            </select>
          </div>
        )}
      </div>

      {/* القائمة */}
      {loading ? (
        <CardSkeleton count={3} />
      ) : notes.length === 0 ? (
        <EmptyState
          icon={NotebookPen}
          title={totalNotes === 0 ? t('لا توجد ملاحظات بعد', 'No notes yet') : t('لا نتائج مطابقة', 'No matching notes')}
          description={totalNotes === 0
            ? t('افتح أي درس واكتب ملاحظة أو «احفظ هذه اللحظة» لتظهر هنا.', 'Open any lesson and write a note, or “save this moment”, to see it here.')
            : t('جرّب تغيير البحث أو الفلاتر.', 'Try changing your search or filters.')}
        />
      ) : (
        <div className="space-y-6">
          {groups.map((course) => (
            <Card key={course.course_id} className="border-0 shadow-sm">
              <CardContent className="p-4 sm:p-5 space-y-4">
                <div className="flex items-center gap-2 border-b pb-2">
                  <h2 className="font-bold">{course.course_title}</h2>
                  <span className="rounded-full bg-teal-50 px-2 py-0.5 text-[11px] font-semibold text-teal-700">
                    {t(`${course.notes_count} ملاحظة`, `${course.notes_count} note(s)`)}
                  </span>
                </div>

                {course.lessons.map((lesson) => (
                  <div key={lesson.lesson_id} className="space-y-2">
                    <div className="flex items-center justify-between gap-2">
                      <h3 className="text-sm font-semibold text-gray-600">{lesson.lesson_title}</h3>
                      <Link
                        href={buildLearnUrl(course.course_id, { lessonId: lesson.lesson_id })}
                        className="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] font-semibold text-teal-700 hover:bg-teal-50"
                      >
                        <ExternalLink className="h-3 w-3" />
                        {t('افتح الدرس', 'Open lesson')}
                      </Link>
                    </div>

                    {lesson.notes.map((n) => {
                      const problem = positionProblemLabel(n, locale);
                      const editing = editingId === n.id;
                      return (
                        <article key={n.id} className="rounded-xl border border-slate-100 bg-slate-50/60 p-3">
                          <div className="flex items-start gap-3">
                            <div className="shrink-0">
                              {n.has_position && n.position_seconds !== null ? (
                                <Link
                                  href={buildLearnUrl(course.course_id, { lessonId: lesson.lesson_id })}
                                  className="inline-flex items-center gap-1 rounded-full bg-teal-600 px-2.5 py-1 text-[11px] font-bold text-white transition hover:bg-teal-500"
                                  title={t('افتح الدرس عند هذه اللحظة', 'Open the lesson at this moment')}
                                >
                                  <Play className="h-3 w-3" />
                                  {formatClock(n.position_seconds)}
                                </Link>
                              ) : n.type === 'bookmark' ? (
                                <span className="inline-flex items-center gap-1 rounded-full bg-slate-200 px-2.5 py-1 text-[11px] font-semibold text-slate-600">
                                  <Bookmark className="h-3 w-3" />{t('علامة', 'Bookmark')}
                                </span>
                              ) : (
                                <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-500">
                                  <Clock className="h-3 w-3" />{t('ملاحظة', 'Note')}
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
                                      <X className="h-3 w-3" />{t('إلغاء', 'Cancel')}
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
                    })}
                  </div>
                ))}
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
