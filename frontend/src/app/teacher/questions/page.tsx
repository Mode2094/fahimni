'use client';

import { useCallback, useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { QuestionBuilder, type QuestionDraft } from '@/components/exam/question-builder';
import { QUESTION_TYPE_META } from '@/components/exam/exam-editor';
import {
  buildQuestionPayload, bankMetaFromQuestion, draftFromQuestion, type BankMeta,
} from '@/lib/exam-question-payload';
import { fetchHtmlEditorPrefs } from '@/lib/html-editor-prefs';
import { toast } from 'sonner';
import {
  Search, Plus, Pencil, Copy, Trash2, Loader2, CheckCircle2, ClipboardList,
  ArrowRight, ArrowLeft, ListChecks, Filter, Puzzle,
} from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';
const PER_PAGE = 12;

const EMPTY_DRAFT: QuestionDraft = {
  question_text: '', question_type: 'multiple_choice', points: '1', exam_id: '', section_id: '',
  options: [{ option_text: '', is_correct: true }, { option_text: '', is_correct: false }],
  image_url: '', pairs: [], items: [], answers: [''], keywords: '', skills: '',
  interactive_html: '', interactive_css: '', interactive_js: '', interactive_height: '360',
  interactive_auto_capture: true, grading_mode: 'manual', grading_rule: 'single_choice', grading_key: '', grading_tolerance: '',
};

const EMPTY_META: BankMeta = { course_id: '', difficulty: 'medium', category: '', is_active: true, explanation: '' };

/**
 * بنك الأسئلة المركزي للمعلم: أنشئ السؤال مرة واحدة وأعد استخدامه في أي اختبار.
 *
 * الصفحة تعرض أسئلة المعلم (كل كورساته) مع بحث وتصفية وترقيم على الخادم،
 * وتتيح إنشاء سؤال جديد بأنواعه العشرة، وتعديله/نسخه/تعطيله، واختيار عدة
 * أسئلة دفعة واحدة وإضافتها إلى اختبار عبر نفس محرّك الاختبارات القائم (لا
 * نظام موازٍ). لا تُحذف إلا الأسئلة غير المرتبطة بأي اختبار للحفاظ على نتائج
 * الطلاب.
 */
export default function TeacherQuestionBankPage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [questions, setQuestions] = useState<any[]>([]);
  const [categories, setCategories] = useState<string[]>([]);
  const [pagination, setPagination] = useState<any>({ current_page: 1, last_page: 1, total: 0 });
  const [loading, setLoading] = useState(true);
  const [courses, setCourses] = useState<any[]>([]);
  const [exams, setExams] = useState<any[]>([]);

  // ── filters ────────────────────────────────────────────────
  const [q, setQ] = useState('');
  const [debouncedQ, setDebouncedQ] = useState('');
  const [courseId, setCourseId] = useState('');
  const [type, setType] = useState('');
  const [difficulty, setDifficulty] = useState('');
  const [category, setCategory] = useState('');
  const [activeOnly, setActiveOnly] = useState(false);
  const [page, setPage] = useState(1);
  const [tick, setTick] = useState(0);

  // ── selection / bulk ───────────────────────────────────────
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [busy, setBusy] = useState('');
  const [addExamId, setAddExamId] = useState('');
  const [adding, setAdding] = useState(false);

  // ── authoring ──────────────────────────────────────────────
  const [draft, setDraft] = useState<QuestionDraft | null>(null);
  const [draftTarget, setDraftTarget] = useState<any>(null);
  const [meta, setMeta] = useState<BankMeta>(EMPTY_META);
  const [sections, setSections] = useState<{ id: string; title: string }[]>([]);
  const [savingDraft, setSavingDraft] = useState(false);

  const headers = useCallback(() => ({
    'Content-Type': 'application/json',
    'X-Tenant-Id': localStorage.getItem('tenant_id') || '',
    Accept: 'application/json',
  }), []);

  const reload = () => setTick((n) => n + 1);
  const typeMeta = (ty: string) => QUESTION_TYPE_META[ty] || { ar: ty, en: ty, icon: '❓' };

  // Search is debounced so typing does not fire a request per keystroke.
  useEffect(() => {
    const id = setTimeout(() => { setDebouncedQ(q); setPage(1); }, 300);
    return () => clearTimeout(id);
  }, [q]);

  // Reference data (course filter + bulk "add to exam" picker).
  useEffect(() => {
    (async () => {
      try {
        const [cr, er] = await Promise.all([
          fetch(`${API}/courses?per_page=100`, { headers: headers() }),
          fetch(`${API}/exams?per_page=100`, { headers: headers() }),
        ]);
        if (cr.ok) setCourses(((await cr.json()).courses) || []);
        if (er.ok) setExams(((await er.json()).exams) || []);
      } catch { /* ignore */ }
    })();
  }, [headers]);

  // The bank listing (server-side search + filters + pagination).
  useEffect(() => {
    let cancelled = false;

    (async () => {
      setLoading(true);
      const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) });
      if (debouncedQ.trim()) params.set('q', debouncedQ.trim());
      if (courseId) params.set('course_id', courseId);
      if (type) params.set('question_type', type);
      if (difficulty) params.set('difficulty', difficulty);
      if (category) params.set('category', category);
      if (activeOnly) params.set('is_active', '1');

      try {
        const res = await fetch(`${API}/questions?${params.toString()}`, { headers: headers() });
        const d = await res.json().catch(() => null);
        if (!cancelled && res.ok && d) {
          setQuestions(d.questions || []);
          setCategories(d.categories || []);
          if (d.pagination) setPagination(d.pagination);
        }
      } catch { /* ignore */ }
      if (!cancelled) setLoading(false);
    })();

    return () => { cancelled = true; };
  }, [debouncedQ, courseId, type, difficulty, category, activeOnly, page, tick, headers]);

  // Sections of the course currently chosen in the authoring form.
  useEffect(() => {
    if (!meta.course_id) { setSections([]); return; }
    let cancelled = false;
    (async () => {
      try {
        const res = await fetch(`${API}/sections?course_id=${meta.course_id}`, { headers: headers() });
        if (res.ok && !cancelled) {
          setSections((((await res.json()).sections) || []).map((s: any) => ({ id: s.id, title: s.title })));
        }
      } catch { /* ignore */ }
    })();
    return () => { cancelled = true; };
  }, [meta.course_id, headers]);

  const resetFilters = () => {
    setQ(''); setCourseId(''); setType(''); setDifficulty(''); setCategory(''); setActiveOnly(false); setPage(1);
  };

  // ── selection helpers ──────────────────────────────────────
  const toggleSelected = (id: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  };
  const allSelected = questions.length > 0 && questions.every((qq) => selected.has(qq.id));
  const toggleAll = () => {
    setSelected(allSelected ? new Set() : new Set(questions.map((qq) => qq.id)));
  };
  const selectedQuestions = questions.filter((qq) => selected.has(qq.id));

  // ── row actions ────────────────────────────────────────────
  const usedInExams = (qq: any) => (qq.exams || []).length;

  const toggleActive = async (qq: any) => {
    setBusy(qq.id);
    try {
      const res = await fetch(`${API}/questions/${qq.id}`, {
        method: 'PUT',
        headers: headers(),
        body: JSON.stringify({ is_active: !qq.is_active }),
      });
      if (!res.ok) throw new Error();
      toast.success(!qq.is_active ? t('تم تفعيل السؤال', 'Question activated') : t('تم تعطيل السؤال', 'Question deactivated'));
      reload();
    } catch { toast.error(t('تعذّر تحديث الحالة', 'Could not update')); }
    setBusy('');
  };

  const removeQuestion = async (qq: any) => {
    if (usedInExams(qq)) {
      toast.error(t('لا يمكن الحذف: السؤال مرتبط باختبار. أزله من الاختبار أولاً.', 'Cannot delete: the question is used in an exam. Remove it from the exam first.'));
      return;
    }
    if (!window.confirm(t('حذف هذا السؤال نهائياً من البنك؟', 'Permanently delete this question from the bank?'))) return;
    setBusy(qq.id);
    try {
      const res = await fetch(`${API}/questions/${qq.id}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم الحذف', 'Deleted'));
      setSelected((prev) => { const n = new Set(prev); n.delete(qq.id); return n; });
      reload();
    } catch { toast.error(t('تعذّر الحذف', 'Delete failed')); }
    setBusy('');
  };

  // ── bulk actions ───────────────────────────────────────────
  const addSelectedToExam = async () => {
    if (!addExamId) { toast.error(t('اختر اختباراً', 'Pick an exam')); return; }
    if (selected.size === 0) return;
    setAdding(true);
    try {
      const res = await fetch(`${API}/exams/${addExamId}/add-questions`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ questions: [...selected].map((question_id) => ({ question_id })) }),
      });
      const d = await res.json().catch(() => null);
      if (!res.ok) { toast.error(d?.message || t('تعذّرت الإضافة', 'Could not add')); return; }
      toast.success(t('أُضيفت الأسئلة المحددة إلى الاختبار', 'Selected questions added to the exam'));
      setSelected(new Set());
      setAddExamId('');
      reload();
    } catch { toast.error(t('تعذّرت الإضافة', 'Could not add')); }
    setAdding(false);
  };

  const deleteSelectedUnused = async () => {
    const targets = selectedQuestions.filter((qq) => !usedInExams(qq));
    if (targets.length === 0) {
      toast.error(t('كل الأسئلة المحددة مرتبطة باختبارات؛ لا يمكن حذفها.', 'All selected questions are used in exams; they cannot be deleted.'));
      return;
    }
    if (!window.confirm(t(`حذف ${targets.length} سؤالاً غير مرتبط بأي اختبار؟`, `Delete ${targets.length} question(s) not linked to any exam?`))) return;
    setBusy('bulk');
    try {
      await Promise.all(targets.map((qq) => fetch(`${API}/questions/${qq.id}`, { method: 'DELETE', headers: headers() })));
      toast.success(t('تم حذف الأسئلة المحددة', 'Selected questions deleted'));
      setSelected(new Set());
      reload();
    } catch { toast.error(t('تعذّر الحذف', 'Delete failed')); }
    setBusy('');
  };

  // ── authoring ──────────────────────────────────────────────
  const openNew = async () => {
    setDraftTarget(null);
    setMeta({ ...EMPTY_META, course_id: courseId || '', category: category || '' });
    const prefs = await fetchHtmlEditorPrefs();
    setDraft({
      ...EMPTY_DRAFT,
      ...(prefs ? {
        interactive_height: prefs.height,
        grading_mode: prefs.grading_mode,
        grading_rule: prefs.grading_rule,
        interactive_auto_capture: prefs.auto_capture,
      } : {}),
    });
  };

  const openEdit = (qq: any) => {
    setDraftTarget(qq);
    setMeta(bankMetaFromQuestion(qq));
    setDraft(draftFromQuestion(qq));
  };

  const copyQuestion = (qq: any) => {
    setDraftTarget(null);
    setMeta({ ...bankMetaFromQuestion(qq), is_active: true });
    setDraft({ ...draftFromQuestion(qq), question_text: `${qq.question_text} (${t('نسخة', 'copy')})` });
  };

  const closeDraft = () => { setDraft(null); setDraftTarget(null); };

  const saveDraft = async () => {
    if (!draft) return;
    if (!draft.question_text.trim()) { toast.error(t('أدخل نص السؤال', 'Enter question text')); return; }
    setSavingDraft(true);
    try {
      const payload = buildQuestionPayload(draft, {
        courseId: meta.course_id || null,
        examId: draftTarget ? null : (draft.exam_id || null),
        meta,
      });
      const res = await fetch(`${API}/questions${draftTarget ? `/${draftTarget.id}` : ''}`, {
        method: draftTarget ? 'PUT' : 'POST',
        headers: headers(),
        body: JSON.stringify(payload),
      });
      const d = await res.json().catch(() => null);
      if (!res.ok) { toast.error(d?.message || t('تعذّر حفظ السؤال', 'Could not save question')); return; }
      toast.success(draftTarget ? t('تم تحديث السؤال', 'Question updated') : t('تمت إضافة السؤال إلى البنك', 'Question added to the bank'));
      closeDraft();
      reload();
    } catch { toast.error(t('تعذّر حفظ السؤال', 'Could not save question')); }
    setSavingDraft(false);
  };

  const courseName = (id: string) => courses.find((c) => c.id === id)?.title || '';

  // ── authoring view ─────────────────────────────────────────
  if (draft) {
    return (
      <div className="mx-auto max-w-4xl space-y-4" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
        <Button variant="ghost" className="gap-2 rounded-full" onClick={closeDraft}>
          {locale === 'ar' ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />}
          {t('رجوع إلى بنك الأسئلة', 'Back to the bank')}
        </Button>
        <h1 className="text-2xl font-bold">{draftTarget ? t('تعديل السؤال', 'Edit question') : t('سؤال جديد في البنك', 'New bank question')}</h1>

        <div className="grid gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm sm:grid-cols-4">
          <div className="space-y-2">
            <Label>{t('الكورس', 'Course')}</Label>
            <select value={meta.course_id} onChange={(e) => setMeta({ ...meta, course_id: e.target.value })} className="h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm">
              <option value="">{t('بدون كورس (بنك عام)', 'No course (general bank)')}</option>
              {courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
            </select>
          </div>
          <div className="space-y-2">
            <Label>{t('الصعوبة', 'Difficulty')}</Label>
            <select value={meta.difficulty} onChange={(e) => setMeta({ ...meta, difficulty: e.target.value })} className="h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm">
              <option value="easy">{t('سهل', 'Easy')}</option>
              <option value="medium">{t('متوسط', 'Medium')}</option>
              <option value="hard">{t('صعب', 'Hard')}</option>
            </select>
          </div>
          <div className="space-y-2">
            <Label>{t('التصنيف', 'Category')}</Label>
            <Input value={meta.category} onChange={(e) => setMeta({ ...meta, category: e.target.value })} className="h-10" placeholder={t('مثال: فيزياء', 'e.g. Physics')} />
          </div>
          <div className="flex items-end gap-2 pb-1">
            <Switch checked={meta.is_active} onCheckedChange={(v) => setMeta({ ...meta, is_active: v })} label={t('مُفعّل', 'Active')} />
          </div>
        </div>

        <QuestionBuilder
          value={draft}
          onChange={setDraft}
          sections={sections}
          exams={exams.map((e) => ({ id: e.id, title: e.title }))}
        />

        <div className="space-y-2 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
          <Label>{t('شرح الإجابة (اختياري — يظهر للمعلم)', 'Answer explanation (optional — teacher only)')}</Label>
          <Textarea value={meta.explanation} onChange={(e) => setMeta({ ...meta, explanation: e.target.value })} className="min-h-[80px]" />
        </div>

        <div className="flex flex-wrap gap-2 border-t pt-4">
          <Button onClick={saveDraft} disabled={savingDraft} className="gap-2 rounded-full bg-violet-600 text-white hover:bg-violet-700">
            {savingDraft ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
            {t('حفظ السؤال في البنك', 'Save to bank')}
          </Button>
          <Button variant="outline" className="rounded-full" onClick={closeDraft}>{t('إلغاء', 'Cancel')}</Button>
        </div>
      </div>
    );
  }

  // ── bank list view ─────────────────────────────────────────
  return (
    <div className="mx-auto max-w-6xl space-y-5" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-100">
            <Puzzle className="h-5 w-5 text-violet-600" />
          </span>
          <div>
            <h1 className="text-2xl font-bold">{t('بنك الأسئلة', 'Question Bank')}</h1>
            <p className="text-sm text-gray-500">{t('أنشئ السؤال مرة واحدة، وأعد استخدامه في أي اختبار.', 'Author once, reuse in any exam.')}</p>
          </div>
        </div>
        <Button onClick={openNew} className="gap-2 rounded-full bg-violet-600 text-white hover:bg-violet-700">
          <Plus className="h-4 w-4" />{t('سؤال جديد', 'New question')}
        </Button>
      </div>

      {/* Filters */}
      <div className="space-y-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
        <div className="flex flex-col gap-2 sm:flex-row">
          <div className="relative flex-1">
            <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <Input value={q} onChange={(e) => setQ(e.target.value)} className="h-10 ps-9" placeholder={t('ابحث في نص السؤال أو التصنيف...', 'Search question text or category...')} />
          </div>
          <select value={courseId} onChange={(e) => { setCourseId(e.target.value); setPage(1); }} className="h-10 rounded-lg border border-gray-200 bg-white px-3 text-sm">
            <option value="">{t('كل الكورسات', 'All courses')}</option>
            {courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
          </select>
          <select value={type} onChange={(e) => { setType(e.target.value); setPage(1); }} className="h-10 rounded-lg border border-gray-200 bg-white px-3 text-sm">
            <option value="">{t('كل الأنواع', 'All types')}</option>
            {Object.entries(QUESTION_TYPE_META).map(([id, m]) => (
              <option key={id} value={id}>{m.icon} {locale === 'ar' ? m.ar : m.en}</option>
            ))}
          </select>
          <select value={difficulty} onChange={(e) => { setDifficulty(e.target.value); setPage(1); }} className="h-10 rounded-lg border border-gray-200 bg-white px-3 text-sm">
            <option value="">{t('كل المستويات', 'All levels')}</option>
            <option value="easy">{t('سهل', 'Easy')}</option>
            <option value="medium">{t('متوسط', 'Medium')}</option>
            <option value="hard">{t('صعب', 'Hard')}</option>
          </select>
          <select value={category} onChange={(e) => { setCategory(e.target.value); setPage(1); }} className="h-10 rounded-lg border border-gray-200 bg-white px-3 text-sm">
            <option value="">{t('كل التصنيفات', 'All categories')}</option>
            {categories.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
        <div className="flex flex-wrap items-center gap-4">
          <Switch checked={activeOnly} onCheckedChange={(v) => { setActiveOnly(v); setPage(1); }} label={t('المفعّلة فقط', 'Active only')} />
          <button onClick={resetFilters} className="inline-flex items-center gap-1.5 text-xs text-gray-500 hover:text-gray-800">
            <Filter className="h-3.5 w-3.5" />{t('مسح الفلاتر', 'Clear filters')}
          </button>
          <span className="text-xs text-gray-400">{pagination.total ?? questions.length} {t('سؤال', 'questions')}</span>
        </div>
      </div>

      {/* Bulk bar */}
      {selected.size > 0 && (
        <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-teal-100 bg-teal-50/50 p-3">
          <span className="text-sm font-medium text-teal-800">{selected.size} {t('محدد', 'selected')}</span>
          <select value={addExamId} onChange={(e) => setAddExamId(e.target.value)} className="h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm">
            <option value="">{t('اختر اختباراً...', 'Choose an exam...')}</option>
            {exams.map((e) => <option key={e.id} value={e.id}>{e.title}</option>)}
          </select>
          <Button size="sm" onClick={addSelectedToExam} disabled={adding || !addExamId} className="gap-1.5 rounded-full bg-teal-600 text-white hover:bg-teal-700">
            {adding ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <ListChecks className="h-3.5 w-3.5" />}{t('أضف إلى الاختبار', 'Add to exam')}
          </Button>
          <Button size="sm" variant="outline" onClick={deleteSelectedUnused} disabled={busy === 'bulk'} className="gap-1.5 rounded-full text-red-600">
            <Trash2 className="h-3.5 w-3.5" />{t('حذف المحدد', 'Delete selected')}
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())} className="rounded-full">{t('إلغاء التحديد', 'Clear')}</Button>
        </div>
      )}

      {/* List */}
      {loading ? (
        <div className="space-y-2">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-20 w-full" />)}</div>
      ) : questions.length === 0 ? (
        <EmptyState
          icon={ClipboardList}
          title={t('لا أسئلة مطابقة', 'No matching questions')}
          description={t('ابدأ بإنشاء سؤال جديد في البنك أو عدّل الفلاتر.', 'Create a new bank question or adjust the filters.')}
          action={<Button onClick={openNew} className="gap-2 rounded-full"><Plus className="h-4 w-4" />{t('سؤال جديد', 'New question')}</Button>}
        />
      ) : (
        <div className="space-y-2">
          <label className="flex items-center gap-2 px-1 text-xs text-gray-500">
            <input type="checkbox" checked={allSelected} onChange={toggleAll} className="h-4 w-4 rounded" />
            {t('تحديد كل الصفحة', 'Select this page')}
          </label>
          {questions.map((qq) => {
            const m = typeMeta(qq.question_type);
            const used = usedInExams(qq);
            return (
              <div key={qq.id} className={`rounded-2xl border bg-white p-4 shadow-sm ${selected.has(qq.id) ? 'border-teal-300' : 'border-slate-100'}`}>
                <div className="flex items-start gap-3">
                  <input type="checkbox" checked={selected.has(qq.id)} onChange={() => toggleSelected(qq.id)} className="mt-1 h-4 w-4 rounded" />
                  <div className="min-w-0 flex-1">
                    <p className="font-semibold">{qq.question_text}</p>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-gray-400">
                      <span>{m.icon} {locale === 'ar' ? m.ar : m.en}</span>
                      <span>· {qq.points} {t('نقطة', 'pts')}</span>
                      {qq.difficulty && <span>· {qq.difficulty === 'easy' ? t('سهل', 'Easy') : qq.difficulty === 'hard' ? t('صعب', 'Hard') : t('متوسط', 'Medium')}</span>}
                      {qq.category && <span>· {qq.category}</span>}
                      {qq.course_id && courseName(qq.course_id) && <span>· {courseName(qq.course_id)}</span>}
                      <span>· {t('مستخدم في', 'used in')} {used} {t('اختبار', 'exam(s)')}</span>
                    </div>
                  </div>
                  <div className="flex shrink-0 items-center gap-1">
                    <Badge className={qq.is_active ? 'border-0 bg-green-50 text-green-600' : 'border-0 bg-gray-100 text-gray-500'}>
                      {qq.is_active ? t('مُفعّل', 'Active') : t('معطّل', 'Inactive')}
                    </Badge>
                    {busy === qq.id
                      ? <Loader2 className="mx-2 h-4 w-4 animate-spin text-gray-400" />
                      : <Switch checked={qq.is_active} onCheckedChange={() => toggleActive(qq)} label={qq.is_active ? t('تعطيل السؤال', 'Deactivate question') : t('تفعيل السؤال', 'Activate question')} />}
                    <Button size="sm" variant="ghost" className="h-8 w-8 text-violet-700" onClick={() => openEdit(qq)} title={t('تعديل', 'Edit')} aria-label={t('تعديل', 'Edit')}><Pencil className="h-3.5 w-3.5" /></Button>
                    <Button size="sm" variant="ghost" className="h-8 w-8 text-slate-600" onClick={() => copyQuestion(qq)} title={t('نسخ', 'Copy')} aria-label={t('نسخ', 'Copy')}><Copy className="h-3.5 w-3.5" /></Button>
                    <Button size="sm" variant="ghost" className="h-8 w-8 text-red-500" onClick={() => removeQuestion(qq)} disabled={busy === qq.id} title={used ? t('مرتبط باختبار — لا يمكن الحذف', 'Used in an exam — cannot delete') : t('حذف', 'Delete')} aria-label={t('حذف', 'Delete')}><Trash2 className="h-3.5 w-3.5" /></Button>
                  </div>
                </div>
              </div>
            );
          })}

          {/* Pagination */}
          {(pagination.last_page ?? 1) > 1 && (
            <div className="flex items-center justify-center gap-3 pt-3">
              <Button size="sm" variant="outline" className="rounded-full" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
                {locale === 'ar' ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />}{t('السابق', 'Previous')}
              </Button>
              <span className="text-sm text-gray-500">{t('صفحة', 'Page')} {pagination.current_page} / {pagination.last_page}</span>
              <Button size="sm" variant="outline" className="rounded-full" disabled={page >= (pagination.last_page ?? 1)} onClick={() => setPage((p) => p + 1)}>
                {t('التالي', 'Next')}{locale === 'ar' ? <ArrowLeft className="h-4 w-4" /> : <ArrowRight className="h-4 w-4" />}
              </Button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
