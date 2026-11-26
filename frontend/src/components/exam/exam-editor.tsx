'use client';

import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { EmptyState } from '@/components/ui/empty-state';
import { confirmDialog } from '@/components/ui/confirm-dialog';
import { QuestionBuilder, type QuestionDraft } from '@/components/exam/question-builder';
import { buildQuestionPayload, draftFromQuestion } from '@/lib/exam-question-payload';
import { fetchHtmlEditorPrefs, saveHtmlEditorPrefs } from '@/lib/html-editor-prefs';
import { useLocale } from '@/store/locale';
import { toast } from 'sonner';
import {
  Plus, Pencil, Trash2, ArrowUp, ArrowDown, Loader2, ClipboardList,
  CheckCircle2, XCircle, Search, HelpCircle, ArrowRight, ArrowLeft, Eye, Copy, Dices,
} from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

/** The ten question types, with a label + emoji for the list/filter UI. */
export const QUESTION_TYPE_META: Record<string, { ar: string; en: string; icon: string }> = {
  multiple_choice: { ar: 'اختيار متعدد', en: 'Multiple choice', icon: '☑️' },
  true_false: { ar: 'صح / خطأ', en: 'True / False', icon: '✅' },
  drag_drop: { ar: 'سحب وإفلات', en: 'Drag & drop', icon: '🧲' },
  matching: { ar: 'مطابقة', en: 'Matching', icon: '🔗' },
  ordering: { ar: 'ترتيب', en: 'Ordering', icon: '🔢' },
  fill_blank: { ar: 'املأ الفراغ', en: 'Fill the blank', icon: '✍️' },
  writing: { ar: 'إجابة كتابية', en: 'Written answer', icon: '📝' },
  file_upload: { ar: 'رفع ملف', en: 'File upload', icon: '📎' },
  interactive_html: { ar: 'سؤال تفاعلي', en: 'Interactive (HTML)', icon: '🧪' },
  custom_html: { ar: 'سؤال HTML', en: 'HTML question', icon: '🌐' },
};

const EMPTY_QUESTION: QuestionDraft = {
  question_text: '', question_type: 'multiple_choice', points: '1', exam_id: '', section_id: '',
  options: [{ option_text: '', is_correct: true }, { option_text: '', is_correct: false }],
  image_url: '', pairs: [], items: [], answers: [''], keywords: '', skills: '',
  interactive_html: '', interactive_css: '', interactive_js: '', interactive_height: '360',
  interactive_auto_capture: true, grading_mode: 'manual', grading_rule: 'single_choice', grading_key: '', grading_tolerance: '',
};

export type ExamEditorProps = {
  exam: any;
  sections: { id: string; title: string }[];
  /** All questions of the current course, each with its `exams[]` links. */
  questions: any[];
  onClose: () => void;
  /** Ask the parent to refetch the course (exams + questions). */
  onChanged: () => void;
};

/**
 * The single place a teacher manages one exam: its settings and its questions,
 * together, inside the course content area (never a full-screen overlay, so the
 * main sidebar stays visible and usable). Questions are authored with the one
 * shared QuestionBuilder (all ten types, including the interactive question and
 * the separate HTML question, both sandboxed),
 * and saved questions from the course can be attached with a click. Removing a
 * question only detaches it from the exam — the question and any historical
 * results are never deleted.
 */
export function ExamEditor({ exam: initialExam, sections, questions, onClose, onChanged }: ExamEditorProps) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [exam, setExam] = useState<any>(initialExam);
  const [innerTab, setInnerTab] = useState<'questions' | 'settings'>('questions');
  const [pickerOpen, setPickerOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [bankOpen, setBankOpen] = useState(false);
  const [bankCount, setBankCount] = useState('5');
  const [bankCategory, setBankCategory] = useState('');
  const [bankDifficulty, setBankDifficulty] = useState('');
  const [bankType, setBankType] = useState('');
  const [bankDrawing, setBankDrawing] = useState(false);
  const [savingSettings, setSavingSettings] = useState(false);
  const [ordering, setOrdering] = useState(false);
  const [busyId, setBusyId] = useState('');

  // The question currently being authored/edited (`null` = list view).
  const [draft, setDraft] = useState<QuestionDraft | null>(null);
  const [draftTarget, setDraftTarget] = useState<any>(null);
  const [savingDraft, setSavingDraft] = useState(false);

  useEffect(() => { setExam(initialExam); }, [initialExam?.id]);

  const headers = () => ({
    'Content-Type': 'application/json',
    'X-Tenant-Id': localStorage.getItem('tenant_id') || '',
    Accept: 'application/json',
  });

  /** Questions attached to THIS exam, in the teacher's saved order. */
  const examQuestions = useMemo(() => {
    return questions
      .map((q) => {
        const link = (q.exams || []).find((e: any) => e.id === exam.id);
        return link ? { q, sort: Number(link.sort_order ?? 0) } : null;
      })
      .filter((r): r is { q: any; sort: number } => r !== null)
      .sort((a, b) => a.sort - b.sort || String(a.q.created_at).localeCompare(String(b.q.created_at)))
      .map((r) => r.q);
  }, [questions, exam.id]);

  const usedIds = useMemo(() => new Set(examQuestions.map((q) => q.id)), [examQuestions]);

  const pickerResults = useMemo(() => {
    const needle = search.trim().toLowerCase();
    return questions.filter((q) => {
      if (typeFilter && q.question_type !== typeFilter) return false;
      if (needle && !String(q.question_text || '').toLowerCase().includes(needle)) return false;
      return true;
    });
  }, [questions, search, typeFilter]);

  const typeMeta = (type: string) => QUESTION_TYPE_META[type] || { ar: type, en: type, icon: '❓' };

  // ── settings ────────────────────────────────────────────────
  const saveSettings = async () => {
    setSavingSettings(true);
    try {
      const res = await fetch(`${API}/exams/${exam.id}`, {
        method: 'PUT',
        headers: headers(),
        body: JSON.stringify({
          title: exam.title,
          description: exam.description,
          assessment_mode: exam.assessment_mode === 'practice' ? 'practice' : 'graded',
          duration_minutes: Number(exam.duration_minutes) || 0,
          pass_mark_percent: Number(exam.pass_mark_percent) || 0,
          max_attempts: Number(exam.max_attempts) || 0,
          random_questions_count: Number(exam.random_questions_count) > 0 ? Number(exam.random_questions_count) : null,
          shuffle_questions: !!exam.shuffle_questions,
          shuffle_options: !!exam.shuffle_options,
          is_published: !!exam.is_published,
        }),
      });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || t('تعذّر الحفظ', 'Save failed')); return; }
      const d = await res.json().catch(() => null);
      if (d?.exam) setExam(d.exam);
      toast.success(t('تم حفظ الإعدادات', 'Settings saved'));
      onChanged();
    } catch { toast.error(t('تعذّر الحفظ', 'Save failed')); }
    setSavingSettings(false);
  };

  const togglePublish = async () => {
    try {
      const res = await fetch(`${API}/exams/${exam.id}`, {
        method: 'PUT',
        headers: headers(),
        body: JSON.stringify({ is_published: !exam.is_published }),
      });
      if (!res.ok) throw new Error();
      const next = !exam.is_published;
      setExam((e: any) => ({ ...e, is_published: next }));
      toast.success(next ? t('تم النشر', 'Published') : t('تم الإلغاء', 'Unpublished'));
      onChanged();
    } catch { toast.error(t('تعذّر تغيير حالة النشر', 'Could not change publish state')); }
  };

  // ── attach / detach / reorder ───────────────────────────────
  const attach = async (questionId: string) => {
    setBusyId(questionId);
    try {
      const res = await fetch(`${API}/exams/${exam.id}/add-questions`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ questions: [{ question_id: questionId }] }),
      });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || t('تعذّرت الإضافة', 'Could not add')); return; }
      toast.success(t('أُضيف السؤال إلى الاختبار', 'Question added to exam'));
      onChanged();
    } catch { toast.error(t('تعذّرت الإضافة', 'Could not add')); }
    setBusyId('');
  };

  const detach = async (questionId: string) => {
    if (!(await confirmDialog({ description: t('إزالة هذا السؤال من الاختبار؟ سيبقى محفوظاً في بنك أسئلة الكورس ولن تُفقد نتائجه.', 'Remove this question from the exam? It stays saved in the course bank and its results are kept.') }))) return;
    setBusyId(questionId);
    try {
      const res = await fetch(`${API}/exams/${exam.id}/questions/${questionId}`, { method: 'DELETE', headers: headers() });
      if (res.status === 409) {
        toast.error(t('لا يمكن إزالة السؤال: توجد إجابات مُصحّحة عليه في هذا الاختبار. أبقِه للحفاظ على نتائج الطلاب.', 'Cannot remove: this question already has graded answers in this exam. Keep it to preserve student results.'));
        return;
      }
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || t('تعذّرت الإزالة', 'Could not remove')); return; }
      toast.success(t('تمت الإزالة من الاختبار', 'Removed from exam'));
      onChanged();
    } catch { toast.error(t('تعذّرت الإزالة', 'Could not remove')); }
    setBusyId('');
  };

  const move = async (index: number, dir: -1 | 1) => {
    const ids = examQuestions.map((q) => q.id);
    const j = index + dir;
    if (j < 0 || j >= ids.length) return;
    [ids[index], ids[j]] = [ids[j], ids[index]];
    setOrdering(true);
    try {
      const res = await fetch(`${API}/exams/${exam.id}/questions/reorder`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ question_ids: ids }),
      });
      if (!res.ok) throw new Error();
      onChanged();
    } catch { toast.error(t('تعذّر ترتيب الأسئلة', 'Could not reorder')); }
    setOrdering(false);
  };

  /** Draw N random questions from the teacher's bank straight into this exam. */
  const drawFromBank = async () => {
    const count = Number(bankCount);
    if (!count || count < 1) { toast.error(t('أدخل عدداً صحيحاً أكبر من صفر', 'Enter a count greater than zero')); return; }
    setBankDrawing(true);
    try {
      const res = await fetch(`${API}/exams/${exam.id}/draw-questions`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({
          count,
          course_id: initialExam.course_id || undefined,
          category: bankCategory || undefined,
          difficulty: bankDifficulty || undefined,
          question_type: bankType || undefined,
        }),
      });
      const d = await res.json().catch(() => null);
      if (!res.ok) { toast.error(d?.message || t('تعذّر السحب من البنك', 'Could not draw from the bank')); return; }
      const added = d?.added_count ?? 0;
      if (added === 0) {
        toast.info(t('لا توجد أسئلة مطابقة متاحة في البنك (قد تكون كلها مضافة بالفعل)', 'No matching questions available (they may all be added already)'));
      } else {
        toast.success(`${t('أُضيف', 'Added')} ${added} ${t('سؤالاً من البنك', 'question(s) from the bank')}`);
      }
      setBankOpen(false);
      onChanged();
    } catch { toast.error(t('تعذّر السحب من البنك', 'Could not draw from the bank')); }
    setBankDrawing(false);
  };

  const deleteFromBank = async (q: any) => {
    if ((q.exams || []).length) return;
    if (!(await confirmDialog({ description: t('حذف هذا السؤال نهائياً من البنك؟', 'Permanently delete this question from the bank?') }))) return;
    setBusyId(q.id);
    try {
      const res = await fetch(`${API}/questions/${q.id}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم الحذف', 'Deleted'));
      onChanged();
    } catch { toast.error(t('تعذّر الحذف', 'Delete failed')); }
    setBusyId('');
  };

  // ── question authoring ──────────────────────────────────────
  const openAdd = async () => {
    setDraftTarget(null);
    setPickerOpen(false);
    // Seed a NEW question with this teacher's own last HTML-editor settings
    // (per teacher, never per device). Editing an existing question ignores
    // these and uses the question's own saved values.
    const prefs = await fetchHtmlEditorPrefs();
    setDraft({
      ...EMPTY_QUESTION,
      exam_id: exam.id,
      section_id: exam.section_id || '',
      ...(prefs ? {
        interactive_height: prefs.height,
        grading_mode: prefs.grading_mode,
        grading_rule: prefs.grading_rule,
        interactive_auto_capture: prefs.auto_capture,
      } : {}),
    });
  };

  const openEdit = (q: any) => {
    setDraftTarget(q);
    setDraft(draftFromQuestion(q, exam.id));
  };

  /**
   * "Copy question": open the builder pre-filled with a copy of `q` so the
   * teacher reviews/edits before saving. Nothing is sent to the server here, so
   * a double click can never create duplicates, and no attempts/answers/results
   * are ever copied (those live on the attempt, not the question). Plan limits
   * are enforced by the normal create request on save.
   */
  const copyQuestion = (q: any) => {
    setDraftTarget(null);
    setPickerOpen(false);
    setDraft({
      ...draftFromQuestion(q, exam.id),
      question_text: `${q.question_text} (${t('نسخة', 'copy')})`,
      exam_id: exam.id,
    });
  };

  const closeDraft = () => { setDraft(null); setDraftTarget(null); };

  const saveQuestion = async () => {
    if (!draft) return;
    if (!draft.question_text.trim()) { toast.error(t('أدخل نص السؤال', 'Enter question text')); return; }
    setSavingDraft(true);
    try {
      const payload = buildQuestionPayload(draft, {
        courseId: initialExam.course_id,
        examId: draftTarget ? null : exam.id,
      });

      const res = await fetch(`${API}/questions${draftTarget ? `/${draftTarget.id}` : ''}`, {
        method: draftTarget ? 'PUT' : 'POST',
        headers: headers(),
        body: JSON.stringify(payload),
      });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || t('تعذّر حفظ السؤال', 'Could not save question')); return; }
      // Remember this teacher's HTML-editor setup for their next new question
      // (never for editing an existing one, which keeps its own values).
      if (draft.question_type === 'interactive_html' || draft.question_type === 'custom_html') {
        void saveHtmlEditorPrefs({
          height: draft.interactive_height,
          grading_mode: draft.grading_mode,
          grading_rule: draft.grading_rule,
          auto_capture: draft.interactive_auto_capture,
        });
      }
      toast.success(draftTarget ? t('تم تحديث السؤال', 'Question updated') : t('تمت إضافة السؤال', 'Question added'));
      closeDraft();
      onChanged();
    } catch { toast.error(t('تعذّر حفظ السؤال', 'Could not save question')); }
    setSavingDraft(false);
  };

  return (
    <div className="space-y-4" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
      {/* Header — stays inside the course content, so the main sidebar remains
          visible and usable while the teacher edits this exam. */}
      <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
        <Button variant="outline" className="gap-2 rounded-full" onClick={onClose}>
          {locale === 'ar' ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />}
          {t('رجوع إلى الكورس', 'Back to course')}
        </Button>
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-100">
          <ClipboardList className="h-5 w-5 text-violet-600" />
        </span>
        <div className="min-w-0 flex-1">
          <h1 className="truncate text-lg font-bold">{exam.title || t('محرر الاختبار', 'Exam editor')}</h1>
          <p className="text-xs text-muted-foreground">
            {examQuestions.length} {t('سؤال', 'questions')} · {t('المدة', 'Duration')} {exam.duration_minutes} {t('دقيقة', 'min')}
          </p>
        </div>
        <Badge className={exam.is_published ? 'border-0 bg-green-50 text-green-600' : 'border-0 bg-gray-100 text-gray-500'}>
          {exam.is_published ? t('منشور', 'Published') : t('مسودة', 'Draft')}
        </Badge>
        <Button
          variant="outline"
          className="gap-2 rounded-full"
          onClick={() => window.open(`/exam/${exam.id}?preview=1`, '_blank', 'noopener')}
          title={t('افتح المعاينة في تبويب جديد', 'Open preview in a new tab')}
        >
          <Eye className="h-4 w-4" />
          {t('معاينة الاختبار كطالب', 'Preview as student')}
        </Button>
      </div>

      {/* Tab bar */}
      <div className="flex gap-1.5">
        <button onClick={() => setInnerTab('questions')} className={`rounded-full px-5 py-2 text-sm font-bold transition ${innerTab === 'questions' ? 'bg-violet-600 text-white' : 'text-gray-500 hover:bg-slate-100'}`}>
          {t('الأسئلة', 'Questions')} ({examQuestions.length})
        </button>
        <button onClick={() => setInnerTab('settings')} className={`rounded-full px-5 py-2 text-sm font-bold transition ${innerTab === 'settings' ? 'bg-violet-600 text-white' : 'text-gray-500 hover:bg-slate-100'}`}>
          {t('الإعدادات', 'Settings')}
        </button>
      </div>

      <div className="mx-auto max-w-5xl">
        {/* ── Question authoring view ── */}
          {innerTab === 'questions' && draft ? (
            <div className="space-y-4">
              <Button variant="ghost" className="gap-2 rounded-full" onClick={closeDraft}>
                {locale === 'ar' ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />}
                {t('رجوع إلى أسئلة الاختبار', 'Back to exam questions')}
              </Button>
              <h2 className="text-xl font-bold">{draftTarget ? t('تعديل السؤال', 'Edit question') : t('سؤال جديد', 'New question')}</h2>
              <QuestionBuilder
                value={draft}
                onChange={setDraft}
                sections={sections}
                exams={[{ id: exam.id, title: exam.title }]}
                lockExam
              />
              <div className="flex flex-wrap gap-2 border-t pt-4">
                <Button onClick={saveQuestion} disabled={savingDraft} className="gap-2 rounded-full bg-violet-600 text-white hover:bg-violet-700">
                  {savingDraft ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
                  {t('حفظ السؤال', 'Save question')}
                </Button>
                <Button variant="outline" className="rounded-full" onClick={closeDraft}>{t('إلغاء', 'Cancel')}</Button>
              </div>
            </div>
          ) : innerTab === 'questions' ? (
            <div className="space-y-4">
              {/* Actions */}
              <div className="flex flex-wrap gap-2">
                <Button onClick={openAdd} className="gap-2 rounded-full bg-violet-600 text-white hover:bg-violet-700">
                  <Plus className="h-4 w-4" />{t('سؤال جديد', 'New question')}
                </Button>
                <Button variant="outline" className="gap-2 rounded-full" onClick={() => { setPickerOpen((v) => !v); setTypeFilter(''); setSearch(''); }}>
                  <HelpCircle className="h-4 w-4" />{t('اختيار سؤال محفوظ', 'Pick a saved question')}
                </Button>
                <Button variant="outline" className="gap-2 rounded-full" onClick={() => { setBankOpen((v) => !v); setPickerOpen(false); }}>
                  <Dices className="h-4 w-4" />{t('سحب عشوائي من البنك', 'Draw from bank')}
                </Button>
                <Button variant="outline" className="gap-2 rounded-full" onClick={togglePublish}>
                  {exam.is_published ? <XCircle className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />}
                  {exam.is_published ? t('إلغاء النشر', 'Unpublish') : t('نشر', 'Publish')}
                </Button>
              </div>

              {/* Saved-question picker */}
              {pickerOpen && (
                <div className="space-y-3 rounded-2xl border border-teal-100 bg-teal-50/40 p-4">
                  <div className="flex flex-col gap-2 sm:flex-row">
                    <div className="relative flex-1">
                      <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                      <Input value={search} onChange={(e) => setSearch(e.target.value)} className="h-10 bg-white ps-9" placeholder={t('بحث في أسئلة الكورس...', 'Search course questions...')} />
                    </div>
                    <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} className="h-10 rounded-lg border border-gray-200 bg-white px-3 text-sm">
                      <option value="">{t('كل الأنواع', 'All types')}</option>
                      {Object.entries(QUESTION_TYPE_META).map(([id, m]) => (
                        <option key={id} value={id}>{m.icon} {locale === 'ar' ? m.ar : m.en}</option>
                      ))}
                    </select>
                  </div>
                  {pickerResults.length === 0 ? (
                    <p className="py-4 text-center text-sm text-gray-400">{t('لا أسئلة مطابقة في هذا الكورس بعد', 'No matching questions in this course yet')}</p>
                  ) : (
                    <div className="max-h-80 space-y-2 overflow-y-auto">
                      {pickerResults.map((q) => {
                        const meta = typeMeta(q.question_type);
                        const used = usedIds.has(q.id);
                        const inBank = (q.exams || []).length === 0;
                        return (
                          <div key={q.id} className="flex items-center gap-3 rounded-xl border border-slate-100 bg-white p-3">
                            <span className="text-lg">{meta.icon}</span>
                            <div className="min-w-0 flex-1">
                              <p className="truncate text-sm font-medium">{q.question_text}</p>
                              <p className="text-[11px] text-gray-400">
                                {locale === 'ar' ? meta.ar : meta.en} · {q.points} {t('نقطة', 'pts')} · {t('مستخدم في', 'used in')} {(q.exams || []).length} {t('اختبار', 'exam(s)')}
                              </p>
                            </div>
                            {used ? (
                              <Badge className="border-0 bg-green-50 text-green-600">{t('مضاف', 'Added')}</Badge>
                            ) : (
                              <Button size="sm" className="gap-1 rounded-full bg-teal-600 text-white hover:bg-teal-700" disabled={busyId === q.id} onClick={() => attach(q.id)}>
                                {busyId === q.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Plus className="h-3.5 w-3.5" />}{t('إضافة', 'Add')}
                              </Button>
                            )}
                            <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => openEdit(q)} title={t('تعديل السؤال', 'Edit')} aria-label={t('تعديل السؤال', 'Edit')}><Pencil className="h-3.5 w-3.5" /></Button>
                            <Button size="sm" variant="ghost" className="rounded-full text-xs text-slate-600" onClick={() => copyQuestion(q)} title={t('نسخ سؤال', 'Copy question')} aria-label={t('نسخ سؤال', 'Copy question')}><Copy className="h-3.5 w-3.5" /></Button>
                            {inBank && <Button size="sm" variant="ghost" className="rounded-full text-red-500" onClick={() => deleteFromBank(q)} aria-label={t('حذف نهائي', 'Delete')} title={t('حذف نهائي', 'Delete')}><Trash2 className="h-3.5 w-3.5" /></Button>}
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              )}

              {/* Random draw from the shared bank */}
              {bankOpen && (
                <div className="space-y-3 rounded-2xl border border-amber-100 bg-amber-50/40 p-4">
                  <p className="text-sm font-medium">{t('اسحب عدداً من أسئلة البنك عشوائياً إلى هذا الاختبار', 'Randomly pull a number of bank questions into this exam')}</p>
                  <div className="grid gap-2 sm:grid-cols-4">
                    <div className="space-y-1">
                      <Label>{t('العدد', 'Count')}</Label>
                      <Input type="number" min={1} dir="ltr" value={bankCount} onChange={(e) => setBankCount(e.target.value)} className="h-10 bg-white" />
                    </div>
                    <div className="space-y-1">
                      <Label>{t('التصنيف', 'Category')}</Label>
                      <Input value={bankCategory} onChange={(e) => setBankCategory(e.target.value)} className="h-10 bg-white" placeholder={t('أي تصنيف', 'Any category')} />
                    </div>
                    <div className="space-y-1">
                      <Label>{t('الصعوبة', 'Difficulty')}</Label>
                      <select value={bankDifficulty} onChange={(e) => setBankDifficulty(e.target.value)} className="h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm">
                        <option value="">{t('كل المستويات', 'All levels')}</option>
                        <option value="easy">{t('سهل', 'Easy')}</option>
                        <option value="medium">{t('متوسط', 'Medium')}</option>
                        <option value="hard">{t('صعب', 'Hard')}</option>
                      </select>
                    </div>
                    <div className="space-y-1">
                      <Label>{t('النوع', 'Type')}</Label>
                      <select value={bankType} onChange={(e) => setBankType(e.target.value)} className="h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm">
                        <option value="">{t('كل الأنواع', 'All types')}</option>
                        {Object.entries(QUESTION_TYPE_META).map(([id, m]) => (
                          <option key={id} value={id}>{m.icon} {locale === 'ar' ? m.ar : m.en}</option>
                        ))}
                      </select>
                    </div>
                  </div>
                  <p className="text-[11px] text-muted-foreground">{t('يُسحب من بنك أسئلة الكورس الحالي، ويُستثنى أي سؤال مضاف بالفعل إلى الاختبار فلا تتكرر الأسئلة.', 'Drawn from this course\u2019s bank; questions already in the exam are excluded so nothing is duplicated.')}</p>
                  <Button onClick={drawFromBank} disabled={bankDrawing} className="gap-2 rounded-full bg-amber-600 text-white hover:bg-amber-700">
                    {bankDrawing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Dices className="h-4 w-4" />}{t('اسحب وأضف', 'Draw and add')}
                  </Button>
                </div>
              )}

              {/* Attached questions */}
              {examQuestions.length === 0 ? (
                <EmptyState icon={ClipboardList} title={t('لا أسئلة بعد', 'No questions yet')} description={t('أضف سؤالاً جديداً أو اختر سؤالاً محفوظاً من الكورس', 'Add a new question or pick a saved one from the course')} />
              ) : (
                <div className="space-y-2">
                  {examQuestions.map((q, i) => {
                    const meta = typeMeta(q.question_type);
                    return (
                      <div key={q.id} className="rounded-2xl border border-slate-100 bg-white p-4">
                        <div className="flex items-start gap-3">
                          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-sm font-bold text-violet-700">{i + 1}</div>
                          <div className="min-w-0 flex-1">
                            <p className="font-semibold">{q.question_text}</p>
                            <div className="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-gray-400">
                              <span>{meta.icon} {locale === 'ar' ? meta.ar : meta.en}</span>
                              <span>· {q.points} {t('نقطة', 'pts')}</span>
                              {q.options?.filter((o: any) => o.is_correct).map((o: any, oi: number) => (
                                <span key={oi} className="font-medium text-green-600">✓ {o.option_text}</span>
                              ))}
                            </div>
                          </div>
                          <div className="flex shrink-0 items-center gap-1">
                            <Button size="sm" variant="ghost" className="h-8 w-8" disabled={i === 0 || ordering} onClick={() => move(i, -1)} aria-label={t('أعلى', 'Up')}><ArrowUp className="h-3.5 w-3.5" /></Button>
                            <Button size="sm" variant="ghost" className="h-8 w-8" disabled={i === examQuestions.length - 1 || ordering} onClick={() => move(i, 1)} aria-label={t('أسفل', 'Down')}><ArrowDown className="h-3.5 w-3.5" /></Button>
                            <Button size="sm" variant="ghost" className="h-8 w-8 text-violet-700" onClick={() => openEdit(q)} aria-label={t('تعديل', 'Edit')} title={t('تعديل', 'Edit')}><Pencil className="h-3.5 w-3.5" /></Button>
                            <Button size="sm" variant="ghost" className="h-8 w-8 text-slate-600" onClick={() => copyQuestion(q)} aria-label={t('نسخ سؤال', 'Copy question')} title={t('نسخ سؤال', 'Copy question')}><Copy className="h-3.5 w-3.5" /></Button>
                            <Button size="sm" variant="ghost" className="h-8 w-8 text-red-500" disabled={busyId === q.id} onClick={() => detach(q.id)} aria-label={t('إزالة من الاختبار', 'Remove from exam')} title={t('إزالة من الاختبار', 'Remove from exam')}><Trash2 className="h-3.5 w-3.5" /></Button>
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          ) : (
            // ── Settings ──
            <div className="space-y-5">
              <div className="space-y-2">
                <Label>{t('العنوان', 'Title')} *</Label>
                <Input value={exam.title} onChange={(e) => setExam({ ...exam, title: e.target.value })} className="h-11" />
              </div>
              {/* Practice vs graded: one switch decides whether this is a scored
                  examination or an ungraded skill exercise. It is snapshotted
                  onto every attempt when it starts, so changing it never
                  reclassifies a live attempt. */}
              <div className="space-y-2 rounded-xl border p-3">
                <Switch
                  checked={exam.assessment_mode === 'practice'}
                  onCheckedChange={(v) => setExam({ ...exam, assessment_mode: v ? 'practice' : 'graded' })}
                  label={t('وضع التمرين (بدون درجات أو وقت)', 'Practice mode (no score or timer)')}
                />
                <p className="text-[11px] text-muted-foreground">
                  {exam.assessment_mode === 'practice'
                    ? t('تمرين مهارة غير مُقيَّم: لا وقت، ولا درجات، ولا مكافحة غش. يُسجَّل الإتمام فقط، ولا يُحتسب في المعدلات أو ترتيب الطلاب.', 'An ungraded skill practice: no timer, no score, no anti-cheat. Only completion is recorded, and it never affects averages or the leaderboard.')
                    : t('اختبار رسمي مُقيَّم: وقت، ودرجة نجاح، ومحاولات، ومكافحة غش. يظهر في المعدلات وترتيب الطلاب.', 'A formal graded exam: timer, pass mark, attempts, and anti-cheat. It counts toward averages and the leaderboard.')}
                </p>
              </div>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div className="space-y-2"><Label>{t('المدة (دقائق)', 'Duration (min)')}</Label><Input type="number" disabled={exam.assessment_mode === 'practice'} value={exam.duration_minutes} onChange={(e) => setExam({ ...exam, duration_minutes: e.target.value })} className="h-11" /></div>
                <div className="space-y-2"><Label>{t('درجة النجاح %', 'Pass mark %')}</Label><Input type="number" disabled={exam.assessment_mode === 'practice'} value={exam.pass_mark_percent} onChange={(e) => setExam({ ...exam, pass_mark_percent: e.target.value })} className="h-11" /></div>
                <div className="space-y-2"><Label>{t('عدد المحاولات', 'Max attempts')}</Label><Input type="number" disabled={exam.assessment_mode === 'practice'} value={exam.max_attempts} onChange={(e) => setExam({ ...exam, max_attempts: e.target.value })} className="h-11" />
                  <p className="text-[11px] text-muted-foreground">{t('0 = بلا حد. تُحتسب المحاولة عند التسليم فقط؛ إعادة التحميل أو انقطاع الاتصال تستأنف نفس المحاولة.', '0 = unlimited. An attempt is counted only on submit; a reload or a dropped connection resumes the same attempt.')}</p>
                </div>
              </div>
              <div className="space-y-2">
                <Label>{t('الوصف', 'Description')}</Label>
                <Textarea value={exam.description || ''} onChange={(e) => setExam({ ...exam, description: e.target.value })} className="min-h-[90px]" />
              </div>
              <div className="space-y-3">
                <div className="flex items-center gap-2 rounded-xl border p-3">
                  <Switch checked={!!exam.shuffle_questions} onCheckedChange={(v) => setExam({ ...exam, shuffle_questions: v })} label={t('خلط ترتيب الأسئلة للطالب', 'Shuffle questions for the student')} />
                </div>
                <div className="flex items-center gap-2 rounded-xl border p-3">
                  <Switch checked={!!exam.shuffle_options} onCheckedChange={(v) => setExam({ ...exam, shuffle_options: v })} label={t('خلط ترتيب الخيارات داخل كل سؤال', 'Shuffle answer options inside each question')} />
                </div>
                <div className="space-y-2 rounded-xl border p-3">
                  <Label>{t('عدد أسئلة عشوائي من أسئلة الاختبار في كل محاولة', 'Random number of the exam questions per attempt')}</Label>
                  <Input
                    type="number"
                    min={0}
                    dir="ltr"
                    className="h-11 max-w-[160px]"
                    placeholder="0"
                    value={exam.random_questions_count ?? ''}
                    onChange={(e) => setExam({ ...exam, random_questions_count: e.target.value })}
                  />
                  <p className="text-[11px] text-muted-foreground">
                    {t('0 = اعرض كل الأسئلة. أدخل رقماً أقل من عدد أسئلة الاختبار ليُسحب هذا العدد عشوائياً لكل طالب ويُثبَّت لبقية محاولته. للاختيار من البنك استخدم «سحب عشوائي من البنك» في تبويب الأسئلة.', '0 = show all. Enter a number lower than the exam\u2019s question count to draw that many at random per student, frozen for their attempt. To pick from the bank instead, use \u201cDraw from bank\u201d on the Questions tab.')}
                  </p>
                </div>
              </div>
              <div className="flex flex-wrap gap-2 border-t pt-4">
                <Button onClick={saveSettings} disabled={savingSettings} className="gap-2 rounded-full bg-violet-600 text-white hover:bg-violet-700">
                  {savingSettings ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
                  {t('حفظ الإعدادات', 'Save settings')}
                </Button>
                <Button variant="outline" className="gap-2 rounded-full" onClick={togglePublish}>
                  {exam.is_published ? <XCircle className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />}
                  {exam.is_published ? t('إلغاء النشر', 'Unpublish') : t('نشر', 'Publish')}
                </Button>
              </div>
            </div>
          )}
      </div>
    </div>
  );
}
