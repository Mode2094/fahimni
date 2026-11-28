'use client';

import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react';
import { use } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { confirmDialog } from '@/components/ui/confirm-dialog';
import {
  ArrowLeft, ArrowRight, Plus, Trash2, Video, FileText, Lock, Unlock, ChevronDown, ChevronUp, Loader2,
  Pencil, Layers, BookOpen, ClipboardList, ArrowUp, ArrowDown, Eye, EyeOff, ListChecks, CheckCircle2, XCircle, GripVertical,
  Upload, FileAudio, FileVideo, FileImage, FileArchive, File, ClipboardCheck, CalendarClock, Star, MessageSquare, Send,
  UploadCloud, Radio, ExternalLink, Link2, Copy, Info, Users, Film } from 'lucide-react';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';
import { ExamEditor } from '@/components/exam/exam-editor';
import VideoUploadQueue from '@/components/media/video-upload-queue';
import VideoLibraryPicker from '@/components/media/video-library-picker';
import CourseGroupsManager from '@/components/course/course-groups';
import { DuplicateDialog, type DuplicateKind } from '@/components/content/duplicate-dialog';
import { uploadVideoDirect } from '@/lib/tus-upload';
import { stableIdempotencyKey } from '@/lib/video-upload-queue';
import { moveLessonBy, moveLessonTo } from '@/lib/lesson-order';
import { buildLearnUrl } from '@/lib/course-preview';
import { useUnsavedChangesWarning } from '@/lib/use-unsaved-changes';
import { getApiErrorMessage } from '@/services/api';
import { plural, formatDuration } from '@/lib/ar-format';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const EMPTY_LESSON = { title: '', is_free_preview: false, kind: 'lesson', exercise_exam_id: '', exercise_position: 'after_video' };

export default function CourseDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [tab, setTab] = useState<'content' | 'exams' | 'assignments' | 'questions'>('content');
  const [course, setCourse] = useState<any>(null);
  const [sections, setSections] = useState<any[]>([]);
  const [exams, setExams] = useState<any[]>([]);
  const [questions, setQuestions] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [dragLesson, setDragLesson] = useState<string | null>(null);
  const [dragOverLesson, setDragOverLesson] = useState<string | null>(null);
  const [orderSaving, setOrderSaving] = useState(false);
  const [lesContents, setLesContents] = useState<any[]>([]);
  const [lesContentsLoading, setLesContentsLoading] = useState(false);
  const [contentType, setContentType] = useState<'video' | 'text' | ''>('');
  const [contentTarget, setContentTarget] = useState<any>(null);
  const [contentVideoUrl, setContentVideoUrl] = useState('');
  const [contentText, setContentText] = useState('');
  const [contentSaving, setContentSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [videoUploading, setVideoUploading] = useState(false);
  const [videoUploadProgress, setVideoUploadProgress] = useState(0);
  const [videoUploadError, setVideoUploadError] = useState('');
  const [videoUploadName, setVideoUploadName] = useState('');
  const [videoQueueOpen, setVideoQueueOpen] = useState(false);
  const [videoLibraryOpen, setVideoLibraryOpen] = useState(false);
  const [groupsOpen, setGroupsOpen] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const videoInputRef = useRef<HTMLInputElement>(null);
  const pointerDragRef = useRef<{ lessonId: string; sectionId: string } | null>(null);

  const [dup, setDup] = useState<{ kind: DuplicateKind; id: string; title: string } | null>(null);

  const [secOpen, setSecOpen] = useState(false);
  const [secTarget, setSecTarget] = useState<any>(null);
  const [secTitle, setSecTitle] = useState('');
  const [lesOpen, setLesOpen] = useState(false);
  const [lesTarget, setLesTarget] = useState<any>(null);
  const [lesSection, setLesSection] = useState('');
  const [les, setLes] = useState<any>({ ...EMPTY_LESSON });
  const [searchQ, setSearchQ] = useState('');
  const [searchResults, setSearchResults] = useState<{ lessons: any[]; sections: any[] }>({ lessons: [], sections: [] });
  const [searching, setSearching] = useState(false);
  const [flashId, setFlashId] = useState<string | null>(null);

  // ─── Assignments ───────────────────────────────
  const [assignments, setAssignments] = useState<any[]>([]);
  const [assignOpen, setAssignOpen] = useState(false);
  const [assignTarget, setAssignTarget] = useState<any>(null);
  const [assign, setAssign] = useState<any>({ title: '', description: '', section_id: '', points: '10', due_at: '', allow_late: false, is_published: false, file_url: '', file_name: '' });
  const [assignUploading, setAssignUploading] = useState(false);
  const assignFileInputRef = useRef<HTMLInputElement>(null);
  const [viewSubm, setViewSubm] = useState<string | null>(null);
  const [gradeVals, setGradeVals] = useState<Record<string, string>>({});
  const [gradeCmts, setGradeCmts] = useState<Record<string, string>>({});
  const [gradingId, setGradingId] = useState<string | null>(null);
  const [commentVals, setCommentVals] = useState<Record<string, string>>({});
  const [commentingId, setCommentingId] = useState<string | null>(null);

  // Any open editor dialog/panel means in-progress work that a reload would
  // drop — warn before the tab closes while the teacher is mid-edit.
  const editingInProgress = secOpen || lesOpen || assignOpen || contentType !== '' || videoQueueOpen;
  useUnsavedChangesWarning(editingInProgress);

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const load = async () => {
    setLoading(true);
    const [sr, er, qr, ar] = await Promise.all([
      fetch(`${API}/sections?course_id=${id}`, { headers: headers() }),
      fetch(`${API}/exams?course_id=${id}`, { headers: headers() }),
      fetch(`${API}/questions?course_id=${id}`, { headers: headers() }),
      fetch(`${API}/assignments?course_id=${id}`, { headers: headers() }),
    ]);
    if (sr.ok) { const secs = (await sr.json()).sections || []; setSections(secs); setExpanded(new Set(secs.map((s: any) => s.id))); }
    if (er.ok) setExams((await er.json()).exams || []);
    if (qr.ok) setQuestions((await qr.json()).questions || []);
    if (ar.ok) setAssignments((await ar.json()).assignments || []);
    const cr = await fetch(`${API}/courses/${id}`, { headers: headers() });
    if (cr.ok) setCourse((await cr.json()).course || null);
    setLoading(false);
  };
  useEffect(() => { if (id && check()) load(); }, [id, check()]);

  // ─── Course search ─────────────────────────────
  const searchCourse = async (q: string) => {
    setSearchQ(q);
    if (q.trim().length < 2) { setSearchResults({ lessons: [], sections: [] }); return; }
    setSearching(true);
    try {
      const res = await fetch(`${API}/courses/${id}/search?q=${encodeURIComponent(q.trim())}`, { headers: headers() });
      if (!res.ok) throw new Error();
      const d = await res.json();
      setSearchResults(d.results || { lessons: [], sections: [] });
    } catch { setSearchResults({ lessons: [], sections: [] }); }
    setSearching(false);
  };
  const goToLesson = (l: any) => {
    setSearchQ(''); setSearchResults({ lessons: [], sections: [] });
    const sec = sections.find((s) => s.id === l.section_id);
    if (sec) {
      setExpanded((prev) => { const n = new Set(prev); n.add(sec.id); return n; });
      setTimeout(() => {
        const el = document.getElementById(`lesson-${l.id}`);
        if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); setFlashId(l.id); setTimeout(() => setFlashId(null), 2500); }
      }, 150);
    }
  };

  // ─── Sections ──────────────────────────────────
  const openSecAdd = () => { setSecTarget(null); setSecTitle(''); setSecOpen(true); };
  const openSecEdit = (s: any) => { setSecTarget(s); setSecTitle(s.title); setSecOpen(true); };
  const saveSection = async () => {
    if (!secTitle.trim()) { toast.error(t('أدخل عنوان القسم', 'Enter section title')); return; }
    try {
      const body = { course_id: id, title: secTitle, sort_order: secTarget ? secTarget.sort_order : sections.length + 1 };
      const res = await fetch(`${API}/sections${secTarget ? `/${secTarget.id}` : ''}`, { method: secTarget ? 'PUT' : 'POST', headers: headers(), body: JSON.stringify(body) });
      if (!res.ok) throw new Error();
      toast.success(secTarget ? t('تم تحديث القسم', 'Section updated') : t('تم إضافة القسم', 'Section added'));
      setSecOpen(false); setSecTarget(null); setSecTitle(''); load();
    } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر حفظ القسم', 'Could not save the section'))); }
  };
  const removeSection = async (sid: string) => {
    if (!(await confirmDialog({ description: t('هل أنت متأكد من حذف هذا القسم؟ سيتم حذف جميع دروسه ومحتواها.', 'Delete this section? All its lessons and content will be removed.') }))) return;
    try { const res = await fetch(`${API}/sections/${sid}`, { method: 'DELETE', headers: headers() }); if (!res.ok) throw new Error(); toast.success(t('تم الحذف', 'Deleted')); load(); } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر حذف القسم', 'Could not delete the section'))); }
  };
  const moveSection = async (s: any, dir: number) => {
    const idx = sections.findIndex((x) => x.id === s.id);
    const other = sections[idx + dir];
    if (!other) return;
    try {
      await fetch(`${API}/sections/${s.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ sort_order: other.sort_order }) });
      await fetch(`${API}/sections/${other.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ sort_order: s.sort_order }) });
      load();
    } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر نقل القسم', 'Could not move the section'))); }
  };

  // ─── Lessons ───────────────────────────────────
  const openLesAdd = (sid: string) => { setLesTarget(null); setLesSection(sid); setLes({ ...EMPTY_LESSON }); setLesOpen(true); };
  const openLesEdit = (l: any) => {
    setLesTarget(l); setLesSection(l.section_id);
    setLes({
      title: l.title || '', is_free_preview: !!l.is_free_preview, kind: 'lesson',
      exercise_exam_id: l.metadata?.exercise_exam_id || '',
      exercise_position: l.metadata?.exercise_position || 'after_video',
    });
    setLesOpen(true);
    resetContentForm();
    loadLesContents(l.id);
  };
  const saveLesson = async () => {
    if (!les.title.trim()) { toast.error(t('أدخل عنوان الدرس', 'Enter lesson title')); return; }
    try {
      const body: any = { section_id: lesSection, title: les.title, is_free_preview: les.is_free_preview };
      // Only a video lesson can carry an attached exercise; the id is the SAME
      // exam engine used everywhere else, so no separate grading system exists.
      if (lesTarget && isVideoLesson(lesTarget)) {
        body.metadata = {
          exercise_exam_id: les.exercise_exam_id || null,
          exercise_position: les.exercise_position || 'after_video',
        };
      }
      const res = await fetch(`${API}/lessons${lesTarget ? `/${lesTarget.id}` : ''}`, { method: lesTarget ? 'PUT' : 'POST', headers: headers(), body: JSON.stringify(body) });
      if (!res.ok) throw new Error();
      toast.success(lesTarget ? t('تم تحديث الدرس', 'Lesson updated') : t('تم إضافة الدرس', 'Lesson added'));
      setLesOpen(false); setLesTarget(null); setLes({ ...EMPTY_LESSON }); load();
    } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر حفظ الدرس', 'Could not save the lesson'))); }
  };
  const removeLesson = async (lid: string) => {
    if (!(await confirmDialog({ description: t('هل أنت متأكد من حذف هذا الدرس؟', 'Delete this lesson?') }))) return;
    try { const res = await fetch(`${API}/lessons/${lid}`, { method: 'DELETE', headers: headers() }); if (!res.ok) throw new Error(); toast.success(t('تم الحذف', 'Deleted')); load(); } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر حذف الدرس', 'Could not delete the lesson'))); }
  };
  const moveLesson = async (l: any, dir: number) => {
    const sec = sections.find((s) => s.id === l.section_id);
    if (!sec || orderSaving) return;

    const expectedIds = (sec.lessons || []).map((lesson: any) => lesson.id);
    const ids = moveLessonBy(expectedIds, l.id, dir < 0 ? -1 : 1);
    if (ids) await saveLessonOrder(sec.id, ids, expectedIds);
  };

  const saveLessonOrder = async (sectionId: string, lessonIds: string[], expectedIds: string[]) => {
    const previous = sections;
    const current = sections.find((section) => section.id === sectionId)?.lessons || [];
    if (lessonIds.length !== current.length || lessonIds.every((id, index) => id === current[index]?.id)) return;

    const sortOrder = new Map(lessonIds.map((lessonId, index) => [lessonId, index + 1]));
    const optimistic = sections.map((section) => section.id === sectionId
      ? {
        ...section,
        lessons: lessonIds
          .map((lessonId) => section.lessons.find((lesson: any) => lesson.id === lessonId))
          .filter(Boolean)
          .map((lesson: any) => ({ ...lesson, sort_order: sortOrder.get(lesson.id) })),
      }
      : section);
    setSections(optimistic);
    setOrderSaving(true);

    try {
      const res = await fetch(`${API}/lessons/reorder`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ section_id: sectionId, lesson_ids: lessonIds, expected_lesson_ids: expectedIds }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => null);
        if (res.status === 409) {
          throw new Error(t('تغيّر ترتيب الدروس في تبويب آخر — جارٍ تحميل الترتيب الحالي.', 'Lesson order changed elsewhere — reloading the latest order.'));
        }
        throw new Error(data?.message || t('فشل حفظ الترتيب', 'Failed to save order'));
      }
      toast.success(t('تم حفظ ترتيب الدروس', 'Lesson order saved'));
    } catch (error: any) {
      setSections(previous);
      toast.error(error?.message || t('فشل حفظ الترتيب — أُعيد الترتيب السابق', 'Could not save order — the previous order was restored'));
      void load();
    } finally {
      setOrderSaving(false);
    }
  };

  const moveExam = async (e: any, dir: number) => {
    if (orderSaving) return;
    const expectedIds = exams.filter((x: any) => x.section_id === e.section_id).map((x: any) => x.id);
    const ids = moveLessonBy(expectedIds, e.id, dir < 0 ? -1 : 1);
    if (ids) await saveExamOrder(e.section_id, ids, expectedIds);
  };

  const saveExamOrder = async (sectionId: string, examIds: string[], expectedIds: string[]) => {
    const previous = exams;
    const current = exams.filter((x: any) => x.section_id === sectionId);
    if (examIds.length !== current.length || examIds.every((id, index) => id === current[index]?.id)) return;

    const sortOrder = new Map(examIds.map((examId, index) => [examId, index + 1]));
    const optimistic = exams
      .map((x: any) => (x.section_id === sectionId ? { ...x, sort_order: sortOrder.get(x.id) } : x))
      .sort((a: any, b: any) => (a.sort_order ?? 0) - (b.sort_order ?? 0));
    setExams(optimistic);
    setOrderSaving(true);

    try {
      const res = await fetch(`${API}/exams/reorder`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ course_id: id, section_id: sectionId, exam_ids: examIds, expected_exam_ids: expectedIds }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => null);
        if (res.status === 409) {
          throw new Error(t('تغيّر ترتيب الاختبارات في تبويب آخر — جارٍ تحميل الترتيب الحالي.', 'Exam order changed elsewhere — reloading the latest order.'));
        }
        throw new Error(data?.message || t('فشل حفظ الترتيب', 'Failed to save order'));
      }
      toast.success(t('تم حفظ ترتيب الاختبارات', 'Exam order saved'));
    } catch (error: any) {
      setExams(previous);
      toast.error(error?.message || t('فشل حفظ الترتيب — أُعيد الترتيب السابق', 'Could not save order — the previous order was restored'));
      void load();
    } finally {
      setOrderSaving(false);
    }
  };
  const finishPointerDrag = async (clientX: number, clientY: number) => {
    const active = pointerDragRef.current;
    if (!active) return;
    pointerDragRef.current = null;

    const target = document.elementFromPoint?.(clientX, clientY)?.closest<HTMLElement>('[data-lesson-row]');
    const targetId = target?.dataset.sectionId === active.sectionId ? target.dataset.lessonRow : null;
    setDragLesson(null);
    setDragOverLesson(null);

    const section = sections.find((item) => item.id === active.sectionId);
    const expectedIds = (section?.lessons || []).map((lesson: any) => lesson.id);
    const ids = section && targetId
      ? moveLessonTo(expectedIds, active.lessonId, targetId)
      : null;
    if (ids) await saveLessonOrder(active.sectionId, ids, expectedIds);
  };

  const onPointerDragStart = (event: ReactPointerEvent<HTMLButtonElement>, lesson: any) => {
    if (orderSaving || pointerDragRef.current || (event.pointerType === 'mouse' && event.button !== 0)) return;
    pointerDragRef.current = { lessonId: lesson.id, sectionId: lesson.section_id };
    setDragLesson(lesson.id);
    setDragOverLesson(lesson.id);
  };
  const onPointerDragMove = (event: ReactPointerEvent<HTMLDivElement>) => {
    const active = pointerDragRef.current;
    if (!active) return;

    const target = (event.target as HTMLElement).closest<HTMLElement>('[data-lesson-row]');
    if (target?.dataset.sectionId === active.sectionId) {
      setDragOverLesson(target.dataset.lessonRow || null);
    }
  };
  const onLessonPointerEnter = (event: ReactPointerEvent<HTMLDivElement>, lesson: any) => {
    const active = pointerDragRef.current;
    if (active && active.sectionId === lesson.section_id) {
      setDragOverLesson(lesson.id);
    }
  };
  const clearLessonDrag = () => {
    pointerDragRef.current = null;
    setDragLesson(null);
    setDragOverLesson(null);
  };

  // ─── Lesson content (video / PDF / audio / text / files) ─────
  const loadLesContents = async (lessonId: string) => {
    setLesContentsLoading(true);
    try {
      const res = await fetch(`${API}/lessons/${lessonId}/contents`, { headers: headers() });
      if (res.ok) setLesContents((await res.json()).contents || []);
    } catch { /* ignore */ } finally { setLesContentsLoading(false); }
  };
  const resetContentForm = () => { setContentType(''); setContentTarget(null); setContentVideoUrl(''); setContentText(''); };
  const openContentForm = (type: 'video' | 'text') => { resetContentForm(); setContentType(type); };
  const openContentEdit = (c: any) => {
    if (c.content_type === 'video') { setContentTarget(c); setContentType('video'); setContentVideoUrl(c.video?.provider_video_id || ''); }
    if (c.content_type === 'text') { setContentTarget(c); setContentType('text'); setContentText(c.text?.body || ''); }
  };
  const detectProvider = (url: string) => {
    if (/youtu\.?be/.test(url)) return 'youtube';
    if (/vimeo\.com/.test(url)) return 'vimeo';
    return 'url';
  };
  const normalizeVideoId = (url: string) => url;
  const saveContent = async () => {
    if (!contentType) return;
    if (contentType === 'video' && !contentVideoUrl.trim()) { toast.error(t('أدخل رابط الفيديو', 'Enter video URL')); return; }
    if (contentType === 'text' && !contentText.trim()) { toast.error(t('أدخل النص', 'Enter text content')); return; }
    setContentSaving(true);
    try {
      const content_data = contentType === 'video'
        ? { provider: detectProvider(contentVideoUrl.trim()), provider_video_id: normalizeVideoId(contentVideoUrl.trim()), title: les.title, description: '' }
        : { body: contentText, format: 'html', word_count: 0, reading_time_minutes: 0 };
      const body: any = { content_type: contentType, title: les.title, sort_order: lesContents.length + 1, content_data };
      if (!contentTarget) body.lesson_id = lesTarget.id;
      const res = await fetch(`${API}/lesson-contents${contentTarget ? `/${contentTarget.id}` : ''}`, { method: contentTarget ? 'PUT' : 'POST', headers: headers(), body: JSON.stringify(body) });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || t('تعذر حفظ المحتوى', 'Could not save the content')); return; }
      toast.success(contentTarget ? t('تم تحديث المحتوى', 'Content updated') : t('تمت إضافة المحتوى', 'Content added'));
      resetContentForm();
      loadLesContents(lesTarget.id);
    } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر حفظ المحتوى', 'Could not save the content'))); } finally { setContentSaving(false); }
  };
  const removeContent = async (cid: string) => {
    if (!(await confirmDialog({ description: t('هل تريد حذف هذا المحتوى؟', 'Delete this content?') }))) return;
    try {
      const res = await fetch(`${API}/lesson-contents/${cid}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم حذف المحتوى', 'Content removed'));
      loadLesContents(lesTarget.id);
    } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر حذف المحتوى', 'Could not delete the content'))); }
  };
  const [liveBusy, setLiveBusy] = useState(false);
  const [liveOpen, setLiveOpen] = useState(false);
  const [liveTitle, setLiveTitle] = useState('');
  const [liveMeetUrl, setLiveMeetUrl] = useState('');
  const [liveError, setLiveError] = useState('');

  const MEET_RE = /^https:\/\/meet\.google\.com\/[a-z0-9-]+(\/)?$/i;

  const openLiveForLesson = () => {
    if (!lesSection) { toast.error(t('اختر وحدة أولاً', 'Pick a unit first')); return; }
    setLiveTitle(les.title || lesTarget?.title || t('بث مباشر', 'Live'));
    setLiveMeetUrl('');
    setLiveError('');
    setLiveOpen(true);
  };

  const saveLiveSession = async () => {
    if (liveBusy) return;
    if (!MEET_RE.test(liveMeetUrl.trim())) {
      setLiveError(t('رابط Google Meet غير صحيح — يجب أن يبدأ بـ https://meet.google.com/', 'Invalid Google Meet link — must start with https://meet.google.com/'));
      return;
    }
    setLiveBusy(true);
    try {
      let lessonId = lesTarget?.id;
      const lessonTitle = liveTitle.trim() || les.title || t('بث مباشر', 'Live');

      // من "درس جديد": أنشئ الدرس أولاً (إن لم يكن محفوظاً بعد)
      if (!lessonId) {
        const lr = await fetch(`${API}/lessons`, {
          method: 'POST',
          headers: headers(),
          body: JSON.stringify({ section_id: lesSection, title: lessonTitle, is_free_preview: les.is_free_preview }),
        });
        const ld = await lr.json().catch(() => null);
        if (!lr.ok) { toast.error(ld?.message || t('تعذر إنشاء الدرس', 'Could not create the lesson')); return; }
        lessonId = ld.lesson?.id || ld.id;
        if (!lessonId) { toast.error(t('تعذر إنشاء الدرس', 'Could not create the lesson')); return; }
        toast.success(t('تم إنشاء الدرس', 'Lesson created'));
      }

      const res = await fetch(`${API}/teacher/live-sessions`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ title: lessonTitle, lesson_id: lessonId, provider: 'google_meet', meeting_url: liveMeetUrl.trim(), record_enabled: true }),
      });
      const d = await res.json().catch(() => null);
      if (!res.ok) { toast.error(d?.message || t('تعذر إنشاء حصة البث', 'Could not create the live session')); return; }
      toast.success(t('تم إنشاء حصة البث المباشر داخل الدرس', 'Live session created inside the lesson'));
      setLiveOpen(false);
      setLesOpen(false); setLesTarget(null); setLes({ ...EMPTY_LESSON });
      load();
    } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر إنشاء حصة البث', 'Could not create the live session'))); } finally { setLiveBusy(false); }
  };
  const onVideoSelected = async (e: any) => {
    const file = e.target.files?.[0];
    if (!file || videoUploading) return;
    // مفتاح حتمي مشتق من الملف نفسه: نفس الملف = نفس المفتاح → لا تكرار
    // ولا احتساب مساحة مرتين، وإن أعيد الاختيار يمكن استئناف الرفع.
    const currentVideo = lesContents.find((content: any) => content.content_type === 'video');
    const uploadKey = `${stableIdempotencyKey(file)}-${lesTarget.id}-${currentVideo?.id || 'new'}`;
    const tid = localStorage.getItem('tenant_id') || '';
    setVideoUploading(true);
    setVideoUploadProgress(0);
    setVideoUploadError('');
    setVideoUploadName(file.name);
    try {
      // 1) تذكرة رفع مباشر قابل للاستئناف (TUS) — تحقق الخطة + حجز المساحة
      //    + توقيع على السيرفر (مفتاح Bunny لا يخرج للمتصفح أبداً).
      const res = await fetch(`${API}/teacher/media/resumable-ticket`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({
          lesson_id: lesTarget.id,
          replace_content_id: currentVideo?.id || null,
          file_size: file.size,
          title: file.name.replace(/\.[^.]+$/, ''),
          original_filename: file.name,
          mime_type: file.type || 'video/mp4',
          idempotency_key: uploadKey,
        }),
      });
      const d = await res.json().catch(() => null);
      if (!res.ok) {
        setVideoUploadError(d?.message || t('فشل إنشاء تذكرة الرفع', 'Failed to create upload ticket'));
        setVideoUploading(false);
        return;
      }

      const upload = d?.upload || {};

      // 2) رفع الملف مباشرة إلى Bunny من المتصفح — الفيديو لا يمر عبر خادمنا.
      await new Promise<void>((resolve, reject) => {
        uploadVideoDirect(
          file,
          {
            endpoint: upload.endpoint,
            signature: upload.signature,
            expires: upload.expires,
            library_id: upload.library_id,
            video_guid: upload.video_guid,
          },
          {
            onProgress: (p) => setVideoUploadProgress(p),
            onSuccess: () => resolve(),
            onError: (m) => reject(new Error(m || 'upload failed')),
          },
          { resume: Boolean(upload.reused) },
        ).catch(reject);
      });

      // 3) ربط الأصل بالدرس. الفيديو يبقى «قيد المعالجة» حتى يؤكد المزوّد.
      const cr = await fetch(`${API}/teacher/media/resumable-complete`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ asset_id: upload.asset_id, lesson_id: lesTarget.id }),
      });
      const completion = await cr.json().catch(() => null);
      if (!cr.ok) {
        throw new Error(completion?.message || t('فشل إتمام الرفع', 'Failed to complete upload'));
      }

      setVideoUploadProgress(100);
      toast.success(currentVideo
        ? t('جُهِّز الفيديو الجديد للمعالجة؛ سيبقى الفيديو الحالي يعمل حتى يكتمل الاستبدال.', 'The new video is processing; the current video stays active until replacement is ready.')
        : t('تم رفع الفيديو بنجاح — قيد المعالجة الآن', 'Video uploaded — processing now'));
      resetContentForm();
      loadLesContents(lesTarget.id);
    } catch (err: any) {
      const raw = err?.message || '';
      const msg = raw.includes('HTTP 413')
        ? t('الملف أكبر من الحد المسموح', 'File exceeds the allowed limit')
        : raw.includes('HTTP ')
          ? t('فشل رفع الفيديو', 'Video upload failed') + ` (${raw})`
          : raw === 'network'
            ? t('فشل الاتصال بالخادم — تحقق من الشبكة', 'Network error — check your connection')
            : t('فشل رفع الفيديو', 'Video upload failed');
      setVideoUploadError(msg);
      toast.error(msg);
    } finally {
      setVideoUploading(false);
      e.target.value = '';
    }
  };

  const onFileSelected = async (e: any) => {    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('lesson_id', lesTarget.id);
      const res = await fetch(`${API}/upload`, { method: 'POST', headers: { 'X-Tenant-Id': localStorage.getItem('tenant_id') || '', Accept: 'application/json' }, body: fd });
      const d = await res.json().catch(() => null);
      if (!res.ok) { toast.error(d?.message || t('تعذر رفع الملف', 'Could not upload the file')); return; }
      toast.success(t('تم رفع الملف', 'File uploaded'));
      loadLesContents(lesTarget.id);
    } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر رفع الملف', 'Could not upload the file'))); } finally { setUploading(false); e.target.value = ''; }
  };
  const formatBytes = (b: number) => {
    if (!b) return '';
    if (b < 1024) return `${b} B`;
    if (b < 1048576) return `${(b / 1024).toFixed(1)} KB`;
    if (b < 1073741824) return `${(b / 1048576).toFixed(1)} MB`;
    return `${(b / 1073741824).toFixed(1)} GB`;
  };
  const contentIcon = (c: any) => {
    if (c.content_type === 'live') return Radio;
    if (c.content_type === 'video') return Video;
    if (c.content_type === 'text') return FileText;
    const ext = (c.file?.extension || '').toLowerCase();
    if (['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'].includes(ext)) return FileAudio;
    if (['mp4', 'webm', 'mov', 'mkv', 'avi'].includes(ext)) return FileVideo;
    if (ext === 'pdf') return FileText;
    if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico'].includes(ext)) return FileImage;
    if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) return FileArchive;
    return File;
  };
  const contentLabel = (c: any) => {
    if (c.content_type === 'live') return t('بث مباشر', 'Live');
    if (c.content_type === 'video') return t('فيديو', 'Video');
    if (c.content_type === 'text') return t('نص', 'Text');
    const ext = (c.file?.extension || '').toLowerCase();
    if (['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'].includes(ext)) return t('صوت', 'Audio');
    if (ext === 'pdf') return t('PDF', 'PDF');
    if (['mp4', 'webm', 'mov', 'mkv', 'avi'].includes(ext)) return t('فيديو', 'Video');
    return t('ملف', 'File');
  };
  const hasVideoContent = lesContents.some((content: any) => content.content_type === 'video');

  // ─── Exams ─────────────────────────────────────
  const openExamFromSection = async (sid: string) => {
    try {
      const res = await fetch(`${API}/exams`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ course_id: id, section_id: sid, title: t('اختبار الوحدة', 'Unit exam'), duration_minutes: 30, pass_mark_percent: 50, max_attempts: 3, shuffle_questions: true, is_published: false }),
      });
      const d = await res.json();
      if (!res.ok) { toast.error(d?.message || t('تعذر إنشاء الاختبار', 'Could not create the exam')); return; }
      toast.success(t('تم إنشاء الاختبار — أضف أسئلته', 'Exam created — add its questions'));
      load();
      setViewerExam(d.exam || d);
    } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر إنشاء الاختبار', 'Could not create the exam'))); }
  };
  const removeExam = async (eid: string) => {
    if (!(await confirmDialog({ description: t('هل أنت متأكد من حذف هذا الاختبار؟', 'Delete this exam?') }))) return;
    try { const res = await fetch(`${API}/exams/${eid}`, { method: 'DELETE', headers: headers() }); if (!res.ok) throw new Error(); toast.success(t('تم الحذف', 'Deleted')); load(); } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر حذف الاختبار', 'Could not delete the exam'))); }
  };

  // ─── Assignments ───────────────────────────────
  const EMPTY_ASSIGN = { title: '', description: '', section_id: '', points: '10', due_at: '', allow_late: false, is_published: false, file_url: '', file_name: '' };
  const openAssignAdd = () => { setAssignTarget(null); setAssign({ ...EMPTY_ASSIGN }); setAssignOpen(true); };
  const openAssignEdit = (a: any) => {
    setAssignTarget(a);
    setAssign({
      title: a.title || '', description: a.description || '', section_id: a.section_id || '',
      points: String(a.points ?? '10'), due_at: a.due_at ? a.due_at.slice(0, 16) : '',
      allow_late: !!a.allow_late, is_published: !!a.is_published,
      file_url: a.file_url || '', file_name: a.file_name || '',
    });
    setAssignOpen(true);
  };
  const uploadAssignFile = async (file: File) => {
    setAssignUploading(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      const res = await fetch(`${API}/assignment-upload`, { method: 'POST', headers: { 'X-Tenant-Id': localStorage.getItem('tenant_id') || '' }, body: fd });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || t('تعذر رفع ملف الواجب', 'Could not upload the assignment file')); return; }
      const d = await res.json();
      setAssign((a: any) => ({ ...a, file_url: d.url, file_name: d.name }));
    } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر رفع ملف الواجب', 'Could not upload the assignment file'))); }
    setAssignUploading(false);
  };
  const saveAssign = async () => {
    if (!assign.title.trim()) { toast.error(t('أدخل عنوان الواجب', 'Enter assignment title')); return; }
    try {
      const body = {
        course_id: id, title: assign.title, description: assign.description,
        section_id: assign.section_id || null, points: Number(assign.points) || 0,
        due_at: assign.due_at || null, allow_late: assign.allow_late, is_published: assign.is_published,
        file_url: assign.file_url || null, file_name: assign.file_name || null,
      };
      const res = await fetch(`${API}/assignments${assignTarget ? `/${assignTarget.id}` : ''}`, { method: assignTarget ? 'PUT' : 'POST', headers: headers(), body: JSON.stringify(body) });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || t('تعذر حفظ الواجب', 'Could not save the assignment')); return; }
      toast.success(assignTarget ? t('تم تحديث الواجب', 'Assignment updated') : t('تم إنشاء الواجب', 'Assignment created'));
      setAssignOpen(false); setAssignTarget(null); setAssign({ ...EMPTY_ASSIGN }); load();
    } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر حفظ الواجب', 'Could not save the assignment'))); }
  };
  const removeAssign = async (aid: string) => {
    if (!(await confirmDialog({ description: t('هل أنت متأكد من حذف هذا الواجب وجميع تسليماته؟', 'Delete this assignment and all its submissions?') }))) return;
    try { const res = await fetch(`${API}/assignments/${aid}`, { method: 'DELETE', headers: headers() }); if (!res.ok) throw new Error(); toast.success(t('تم الحذف', 'Deleted')); if (viewSubm === aid) setViewSubm(null); load(); } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر حذف الواجب', 'Could not delete the assignment'))); }
  };
  const toggleAssignPublish = async (a: any) => {
    try {
      const res = await fetch(`${API}/assignments/${a.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ is_published: !a.is_published }) });
      if (!res.ok) throw new Error();
      toast.success(a.is_published ? t('تم الإيقاف', 'Unpublished') : t('تم النشر', 'Published'));
      load();
    } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر تغيير حالة نشر الواجب', 'Could not change the assignment publish state'))); }
  };
  const gradeSubmission = async (a: any, s: any) => {
    const grade = gradeVals[s.id];
    if (grade === undefined || grade === '') { toast.error(t('أدخل الدرجة', 'Enter a grade')); return; }
    setGradingId(s.id);
    try {
      const res = await fetch(`${API}/assignments/${a.id}/submissions/${s.id}/grade`, { method: 'POST', headers: headers(), body: JSON.stringify({ grade: Number(grade), grade_comment: gradeCmts[s.id] || '' }) });
      if (!res.ok) throw new Error();
      toast.success(t('تم التصحيح', 'Graded'));
      setGradeVals((g) => ({ ...g, [s.id]: '' })); setGradeCmts((g) => ({ ...g, [s.id]: '' })); load();
    } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر حفظ الدرجة', 'Could not save the grade'))); }
    setGradingId(null);
  };
  const addComment = async (s: any) => {
    const text = commentVals[s.id];
    if (!text?.trim()) return;
    setCommentingId(s.id);
    try {
      const res = await fetch(`${API}/submissions/${s.id}/comments`, { method: 'POST', headers: headers(), body: JSON.stringify({ comment: text }) });
      if (!res.ok) throw new Error();
      setCommentVals((c) => ({ ...c, [s.id]: '' })); load();
    } catch (err) { toast.error(getApiErrorMessage(err, t('تعذر إضافة التعليق', 'Could not add the comment'))); }
    setCommentingId(null);
  };
  const fmtDue = (iso: string | null) => {
    if (!iso) return t('بدون موعد', 'No deadline');
    const d = new Date(iso);
    const now = Date.now();
    const diff = d.getTime() - now;
    const past = diff < 0;
    const s = d.toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    if (Math.abs(diff) < 86400000) return past ? t('سُلم اليوم', 'Due today') + ` (${s})` : t('يستحق اليوم', 'Due today') + ` (${s})`;
    return s + (past ? t(' — انتهى', ' — past') : '');
  };
  const toggle = (sid: string) => { const n = new Set(expanded); n.has(sid) ? n.delete(sid) : n.add(sid); setExpanded(n); };
  const secLessons = (s: any) => s.lessons || [];

  const tabs = [
    { id: 'content' as const, label: t('المحتوى', 'Content'), icon: Layers, count: sections.length + exams.length + questions.length },
    { id: 'assignments' as const, label: t('الواجبات', 'Assignments'), icon: ClipboardCheck, count: assignments.length },
  ];

  // ─── جلسات البث المرتبطة بالدروس (للبطاقة) ───
  const [liveSessions, setLiveSessions] = useState<Record<string, any>>({});
  const fetchLiveForLesson = async (lessonId: string) => {
    if (liveSessions[lessonId]) return;
    try {
      const r = await fetch(`${API}/teacher/live-sessions/by-lesson/${lessonId}`, { headers: headers() });
      const d = await r.json();
      if (d.session) setLiveSessions((m) => ({ ...m, [lessonId]: d.session }));
    } catch { /* */ }
  };
  const isLiveLesson = (l: any) => (l.contents || []).some((c: any) => c.content_type === 'live');
  const isVideoLesson = (l: any) => l.lesson_type === 'video' || (l.contents || []).some((c: any) => c.content_type === 'video');

  // ─── محرر الاختبار المفتوح (إعدادات + أسئلة كيان واحد) ───
  const [viewerExam, setViewerExam] = useState<any>(null);

  const sectionExams = (sid: string) => exams.filter((e) => e.section_id === sid);
  const sectionAssigns = (sid: string) => assignments.filter((a) => a.section_id === sid);
  // A question's exam links come back as `exams[]` (the exam_questions pivot),
  // not an `exam_id` column — reading `exams` is what actually shows the
  // questions attached to an exam.
  const examQuestions = (eid: string) => questions.filter((qq) => (qq.exams || []).some((e: any) => e.id === eid));

  const openExamViewer = (e: any) => setViewerExam(e);

  return (
    <div
      className="space-y-6 max-w-6xl mx-auto"
      onPointerMove={onPointerDragMove}
      onPointerUp={(event) => void finishPointerDrag(event.clientX, event.clientY)}
      onPointerCancel={clearLessonDrag}
    >
      {/* The whole course content is hidden while an exam is being edited, so
          the exam editor takes over the content area while the main sidebar
          stays visible and usable. */}
      <div className={viewerExam ? 'hidden' : 'space-y-6'}>
      {/* ─── رأس الكورس ─────────────────────────── */}
      <div className="flex items-start justify-between flex-wrap gap-4">
        <div>
          <a href="/teacher/courses" className="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800">{locale === 'ar' ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />}{t('رجوع للكورسات', 'Back to courses')}</a>
          <h1 className="text-2xl sm:text-3xl font-bold mt-2">{course?.title || t('إدارة الكورس', 'Course Builder')}</h1>
          {course?.description && <p className="text-gray-500 text-sm mt-1 max-w-xl">{course.description}</p>}
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <Button variant="outline" className="gap-2 rounded-full" onClick={() => setVideoQueueOpen(true)} title={t('رفع عدة ملفات فيديو مرة واحدة', 'Upload several video files at once')}>
            <UploadCloud className="h-4 w-4" />{t('رفع فيديوهات متعددة', 'Upload multiple videos')}
          </Button>
          <Button variant="outline" className="gap-2 rounded-full" onClick={() => setGroupsOpen(true)} title={t('مجموعات الطلاب داخل الكورس', 'In-course student groups')}>
            <Users className="h-4 w-4" />{t('مجموعات الطلاب', 'Student groups')}
          </Button>
          {course && (
            <Button variant="outline" className="gap-2 rounded-full" onClick={() => setDup({ kind: 'course', id, title: course.title || '' })} title={t('إنشاء نسخة مسودة من الكورس كاملاً', 'Create a draft copy of the whole course')}>
              <Copy className="h-4 w-4" />{t('إنشاء نسخة', 'Duplicate course')}
            </Button>
          )}
          <a href={buildLearnUrl(id, { preview: true })} target="_blank" rel="noreferrer"><Button variant="outline" className="gap-2 rounded-full" title={t('اعرض الكورس والدروس كما يراها الطالب (بما فيها المسودات)', 'View the course and lessons exactly as a student sees them (drafts included)')}><Eye className="h-4 w-4" />{t('معاينة كطالب', 'Preview as student')}</Button></a>
          {course && (
            <Button variant={course.is_published ? 'outline' : 'default'} className="gap-2 rounded-full" onClick={() => {
              fetch(`${API}/courses/${id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ is_published: !course.is_published }) })
                .then((r) => r.json()).then(() => { load(); toast.success(course.is_published ? t('أُلغي النشر', 'Unpublished') : t('نُشر الكورس', 'Published')); }).catch(() => toast.error(t('تعذر تغيير حالة نشر الكورس', 'Could not change the course publish state')));
            }}>
              <CheckCircle2 className="h-4 w-4" />{course.is_published ? t('إلغاء النشر', 'Unpublish') : t('نشر', 'Publish')}
            </Button>
          )}
        </div>
      </div>

      {/* ─── إحصاءات الكورس ─────────────────────── */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        {[
          { label: t('الوحدات', 'Units'), value: sections.length, icon: Layers },
          { label: t('الدروس', 'Lessons'), value: sections.reduce((a: number, s: any) => a + (s.lessons?.length || 0), 0), icon: BookOpen },
          { label: t('الاختبارات', 'Exams'), value: exams.length, icon: ClipboardList },
          { label: t('الواجبات', 'Assignments'), value: assignments.length, icon: ClipboardCheck },
        ].map((c, i) => (
          <div key={i} className="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-500/10"><c.icon className="h-5 w-5 text-teal-600" /></div>
            <div><p className="text-xl font-bold">{c.value}</p><p className="text-xs text-gray-500">{c.label}</p></div>
          </div>
        ))}
      </div>

      {/* ─── الوحدات (المستوى الرئيسي) ───────────── */}
      {loading ? <Skeleton className="h-40 w-full" /> : sections.length === 0 ? (
        <EmptyState icon={Layers} title={t('لا توجد وحدات بعد', 'No units yet')} description={t('أنشئ أول وحدة وابدأ بإضافة المحتوى', 'Create your first unit and start adding content')}
          action={<Button onClick={openSecAdd} className="gap-2 rounded-full"><Plus className="h-4 w-4" />{t('إضافة وحدة', 'Add unit')}</Button>} />
      ) : (
        <div className="space-y-6">
          {orderSaving && <p className="sr-only" role="status">{t('جار حفظ ترتيب الدروس', 'Saving lesson order')}</p>}
          {sections.map((s, si) => {
            const secLessons = s.lessons || [];
            const secEx = sectionExams(s.id);
            const secAs = sectionAssigns(s.id);
            const totalItems = secLessons.length + secEx.length + secAs.length;
            const published = secLessons.filter((l: any) => l.is_published !== false).length + secEx.filter((e: any) => e.is_published).length;

            return (
              <Card key={s.id} className="border-0 shadow-sm overflow-hidden">
                <CardContent className="p-0">
                  {/* رأس الوحدة */}
                  <div className="flex items-center justify-between gap-3 p-5 border-b border-slate-100 bg-slate-50/50">
                    <div className="flex items-center gap-3">
                      <button onClick={() => { const n = new Set(expanded); n.has(s.id) ? n.delete(s.id) : n.add(s.id); setExpanded(n); }} className="text-gray-500 hover:text-gray-800" aria-label={t('فتح/إغلاق', 'Toggle')}>
                        {expanded.has(s.id) ? <ChevronUp className="h-5 w-5" /> : <ChevronDown className="h-5 w-5" />}
                      </button>
                      <div>
                        <h2 className="font-bold text-lg">{s.title}</h2>
                        <p className="text-xs text-gray-400">{totalItems} {t('عناصر', 'items')} · {published} {t('منشورة', 'published')}</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-1.5">
                      <Button size="sm" variant="ghost" onClick={() => openSecEdit(s)} className="gap-1 rounded-full text-xs"><Pencil className="h-3.5 w-3.5" /></Button>
                      <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => moveSection(s, -1)} disabled={si === 0} aria-label={t('تحريك الوحدة لأعلى', 'Move unit up')} title={t('تحريك الوحدة لأعلى', 'Move unit up')}><ArrowUp className="h-3.5 w-3.5" /></Button>
                      <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => moveSection(s, 1)} disabled={si === sections.length - 1} aria-label={t('تحريك الوحدة لأسفل', 'Move unit down')} title={t('تحريك الوحدة لأسفل', 'Move unit down')}><ArrowDown className="h-3.5 w-3.5" /></Button>
                      <Button size="sm" variant="ghost" className="rounded-full text-xs text-red-500" onClick={() => removeSection(s.id)} aria-label={t('حذف الوحدة', 'Delete unit')} title={t('حذف الوحدة', 'Delete unit')}><Trash2 className="h-3.5 w-3.5" /></Button>
                    </div>
                  </div>

                  {/* محتوى الوحدة المدمج */}
                  {(!expanded.has(s.id) || expanded.size === 0) ? null : (
                  <div className="p-5 space-y-3">
                    {/* دروس (وبطاقة البث المباشر المميزة) */}
                    {secLessons.map((l: any, li: number) => {
                      const isLive = isLiveLesson(l);
                      const live = liveSessions[l.id];
                      if (isLive) { if (!live) fetchLiveForLesson(l.id); }
                      const hasContent = (l.contents || []).length > 0;

                      // ── بطاقة البث المباشر ──
                      if (isLive) {
                        return (
                          <div
                            key={l.id}
                            data-lesson-row={l.id}
                            data-section-id={l.section_id}
                            onPointerEnter={(e) => onLessonPointerEnter(e, l)}
                            className={cn(
                              'rounded-xl border p-4 space-y-3 transition-colors',
                              dragLesson === l.id && 'opacity-50',
                              dragOverLesson === l.id && dragLesson !== l.id ? 'border-teal-400 ring-1 ring-teal-200' : 'border-rose-200',
                              'bg-rose-50/50',
                            )}
                          >
                            <div className="flex items-center gap-3">
                              <button
                                type="button"
                                data-lesson-drag-handle={l.id}
                                onPointerDown={(e) => onPointerDragStart(e, l)}
                                onPointerCancel={clearLessonDrag}
                                disabled={orderSaving}
                                className="touch-none cursor-grab rounded p-1 text-rose-400 hover:bg-white/70 active:cursor-grabbing disabled:cursor-wait"
                                aria-label={t(`اسحب لإعادة ترتيب ${l.title}`, `Drag to reorder ${l.title}`)}
                                title={t('اسحب لإعادة ترتيب الدرس', 'Drag to reorder lesson')}
                              >
                                <GripVertical className="h-4 w-4 shrink-0" aria-hidden="true" />
                              </button>
                              <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-rose-100 shrink-0"><Radio className="h-4 w-4 text-rose-600" /></span>
                              <div className="flex-1 min-w-0">
                                <p className="font-semibold text-sm truncate">{l.title}</p>
                                <p className="text-[11px] text-gray-400 flex items-center gap-2">
                                  {live?.scheduled_at ? (
                                    <span className="flex items-center gap-1 text-rose-600 font-medium"><CalendarClock className="h-3 w-3" />{new Date(live.scheduled_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US')}</span>
                                  ) : <span>{t('لم يحدد موعد بعد', 'No schedule set yet')}</span>}
                                </p>
                              </div>
                              <a href={buildLearnUrl(id, { preview: true, lessonId: l.id })} target="_blank" rel="noreferrer" aria-label={t('معاينة الحصة كطالب', 'Preview session as student')} title={t('معاينة الحصة كطالب', 'Preview session as student')}>
                                <Button size="sm" variant="ghost" className="rounded-full text-xs text-rose-700"><Eye className="h-3.5 w-3.5" /></Button>
                              </a>
                              <a href="/teacher/live"><Button size="sm" className="gap-1 rounded-full bg-rose-600 hover:bg-rose-700 text-white"><Radio className="h-3.5 w-3.5" />{t('إدارة الحصة', 'Manage session')}</Button></a>
                              <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => moveLesson(l, -1)} disabled={orderSaving || li === 0} aria-label={t('تحريك الدرس لأعلى', 'Move lesson up')} title={t('تحريك الدرس لأعلى', 'Move lesson up')}><ArrowUp className="h-3.5 w-3.5" /></Button>
                              <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => moveLesson(l, 1)} disabled={orderSaving || li === secLessons.length - 1} aria-label={t('تحريك الدرس لأسفل', 'Move lesson down')} title={t('تحريك الدرس لأسفل', 'Move lesson down')}><ArrowDown className="h-3.5 w-3.5" /></Button>
                              <Button size="sm" variant="ghost" className="rounded-full text-xs text-red-500" onClick={() => removeLesson(l.id)} aria-label={t('حذف الدرس', 'Delete lesson')} title={t('حذف الدرس', 'Delete lesson')}><Trash2 className="h-3.5 w-3.5" /></Button>
                            </div>
                            <div className="flex items-center gap-2">
                              <Input type="datetime-local" className="h-9 w-auto text-xs" dir="ltr"
                                value={live?.scheduled_at ? live.scheduled_at.slice(0, 16) : ''}
                                onChange={async (e) => {
                                  const r = await fetch(`${API}/teacher/live-sessions/${live.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ scheduled_at: e.target.value }) });
                                  if (r.ok) { fetchLiveForLesson(l.id); toast.success(t('تم تحديد الموعد — سيظهر للطلاب', 'Scheduled — students will see it')); }
                                }} />
                              <span className="text-[11px] text-gray-400">{t('حدد موعد البث — يظهر عند الطالب', 'Set the live date — shown to students')}</span>
                            </div>
                          </div>
                        );
                      }

                      // ── بطاقة الدرس العادي ──
                      return (
                        <div
                          key={l.id}
                          data-lesson-row={l.id}
                          data-section-id={l.section_id}
                          onPointerEnter={(e) => onLessonPointerEnter(e, l)}
                          className={cn(
                            'flex items-center gap-3 rounded-xl border p-3 transition',
                            dragLesson === l.id && 'opacity-50',
                            dragOverLesson === l.id && dragLesson !== l.id
                              ? 'border-teal-400 bg-teal-50/60 ring-1 ring-teal-200'
                              : 'border-slate-100 hover:bg-slate-50/60',
                          )}
                        >
                          <button
                            type="button"
                            data-lesson-drag-handle={l.id}
                            onPointerDown={(e) => onPointerDragStart(e, l)}
                            onPointerCancel={clearLessonDrag}
                            disabled={orderSaving}
                            className="touch-none cursor-grab rounded p-1 text-gray-400 hover:bg-white active:cursor-grabbing disabled:cursor-wait"
                            aria-label={t(`اسحب لإعادة ترتيب ${l.title}`, `Drag to reorder ${l.title}`)}
                            title={t('اسحب لإعادة ترتيب الدرس', 'Drag to reorder lesson')}
                          >
                            <GripVertical className="h-4 w-4 shrink-0" aria-hidden="true" />
                          </button>
                          <span className={`flex h-9 w-9 items-center justify-center rounded-lg shrink-0 ${hasContent ? 'bg-teal-50' : 'bg-gray-100'}`}>
                            {hasContent ? <Video className="h-4 w-4 text-teal-600" /> : <FileText className="h-4 w-4 text-gray-400" />}
                          </span>
                          <div className="flex-1 min-w-0">
                            <p className="font-semibold text-sm truncate">{l.title}</p>
                            <p className="text-[11px] text-gray-400 flex items-center gap-2">
                              <span className="flex items-center gap-1">{l.is_free_preview ? <Unlock className="h-3 w-3 text-green-500" /> : <Lock className="h-3 w-3 text-gray-400" />}{l.is_free_preview ? t('معاينة', 'Preview') : t('مدفوع', 'Paid')}</span>
                              {hasContent ? <span className="text-teal-600 font-medium">· {t('محتوى', 'content')}</span> : <span className="text-gray-400">· {t('لا محتوى بعد — أضف الآن', 'no content yet — add now')}</span>}
                            </p>
                          </div>
                          <Button size="sm" className="gap-1 rounded-full text-xs bg-teal-600 hover:bg-teal-700 text-white" onClick={() => openLesEdit(l)}>
                            {hasContent ? <><Pencil className="h-3.5 w-3.5" />{t('إدارة المحتوى', 'Manage content')}</> : <><Plus className="h-3.5 w-3.5" />{t('إضافة محتوى', 'Add content')}</>}
                          </Button>
                          <a href={buildLearnUrl(id, { preview: true, lessonId: l.id })} target="_blank" rel="noreferrer" aria-label={t('معاينة الدرس كطالب', 'Preview lesson as student')} title={t('معاينة الدرس كطالب', 'Preview lesson as student')}>
                            <Button size="sm" variant="ghost" className="rounded-full text-xs text-teal-700"><Eye className="h-3.5 w-3.5" /></Button>
                          </a>
                          <Button size="sm" variant="ghost" className="rounded-full text-xs text-teal-700" onClick={() => setDup({ kind: 'lesson', id: l.id, title: l.title })} aria-label={t('إنشاء نسخة من الدرس', 'Duplicate lesson')} title={t('إنشاء نسخة من الدرس', 'Duplicate lesson')}><Copy className="h-3.5 w-3.5" /></Button>
                          <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => moveLesson(l, -1)} disabled={orderSaving || li === 0} aria-label={t('تحريك الدرس لأعلى', 'Move lesson up')} title={t('تحريك الدرس لأعلى', 'Move lesson up')}><ArrowUp className="h-3.5 w-3.5" /></Button>
                          <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => moveLesson(l, 1)} disabled={orderSaving || li === secLessons.length - 1} aria-label={t('تحريك الدرس لأسفل', 'Move lesson down')} title={t('تحريك الدرس لأسفل', 'Move lesson down')}><ArrowDown className="h-3.5 w-3.5" /></Button>
                          <Button size="sm" variant="ghost" className="rounded-full text-xs text-red-500" onClick={() => removeLesson(l.id)} aria-label={t('حذف الدرس', 'Delete lesson')} title={t('حذف الدرس', 'Delete lesson')}><Trash2 className="h-3.5 w-3.5" /></Button>
                        </div>
                      );
                    })}

                    {/* اختبارات الوحدة */}
                    <div className="space-y-3">
                    {secEx.map((e: any, ei: number) => (
                      <div key={e.id} className="flex items-center gap-3 rounded-xl border border-violet-100 bg-violet-50/40 p-3 hover:bg-violet-50 transition">
                        <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-100 shrink-0"><ClipboardList className="h-4 w-4 text-violet-600" /></span>
                        <div className="flex-1 min-w-0">
                          <p className="font-semibold text-sm truncate">{e.title}</p>
                          <p className="text-[11px] text-gray-400">
                            {plural.questions(examQuestions(e.id).length, locale)} · {formatDuration(e.duration_minutes, locale)} · {t('نجاح', 'pass')} {e.pass_mark_percent}%
                          </p>
                        </div>
                        <Badge className={e.is_published ? 'bg-green-50 text-green-600 border-0' : 'bg-gray-100 text-gray-500 border-0'}>{e.is_published ? t('منشور', 'Published') : t('مسودة', 'Draft')}</Badge>
                        <div className="flex items-center gap-1">
                          <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => moveExam(e, -1)} disabled={orderSaving || ei === 0} aria-label={t('تحريك الاختبار لأعلى', 'Move exam up')} title={t('تحريك الاختبار لأعلى', 'Move exam up')}><ArrowUp className="h-3.5 w-3.5" /></Button>
                          <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => moveExam(e, 1)} disabled={orderSaving || ei === secEx.length - 1} aria-label={t('تحريك الاختبار لأسفل', 'Move exam down')} title={t('تحريك الاختبار لأسفل', 'Move exam down')}><ArrowDown className="h-3.5 w-3.5" /></Button>
                          <Button size="sm" className="gap-1 rounded-full bg-violet-600 hover:bg-violet-700 text-white" onClick={() => openExamViewer(e)}><Pencil className="h-3.5 w-3.5" />{t('تحرير', 'Edit')}</Button>
                          <Button size="sm" variant="ghost" className="rounded-full text-xs text-violet-700" onClick={() => setDup({ kind: 'exam', id: e.id, title: e.title })} aria-label={t('إنشاء نسخة من الاختبار', 'Duplicate exam')} title={t('إنشاء نسخة من الاختبار', 'Duplicate exam')}><Copy className="h-3.5 w-3.5" /></Button>
                          <Button size="sm" variant="ghost" className="rounded-full text-xs text-red-500" onClick={() => removeExam(e.id)}><Trash2 className="h-3.5 w-3.5" /></Button>
                        </div>
                      </div>
                    ))}
                    </div>

                    {/* واجبات الوحدة */}
                    {secAs.map((a: any) => {
                      const subs: any[] = Array.isArray(a.submissions) ? a.submissions : [];
                      const open = viewSubm === a.id;
                      const statusLabel: Record<string, string> = {
                        submitted: t('تم التسليم', 'Submitted'),
                        late: t('متأخر', 'Late'),
                        graded: t('مصحح', 'Graded'),
                      };
                      return (
                        <div key={a.id} className="rounded-xl border border-amber-100 bg-amber-50/40 transition">
                          <div className="flex items-center gap-3 p-3 hover:bg-amber-50">
                            <GripVertical className="h-4 w-4 text-amber-300 shrink-0" />
                            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-100 shrink-0"><ClipboardCheck className="h-4 w-4 text-amber-600" /></span>
                            <div className="flex-1 min-w-0">
                              <p className="font-semibold text-sm truncate">{a.title}</p>
                              <p className="text-[11px] text-gray-400">{a.points} {t('نقطة', 'pts')}{a.due_at ? ` · ${t('ينتهي', 'due')} ${new Date(a.due_at).toLocaleDateString()}` : ''}</p>
                            </div>
                            <Badge className={a.is_published ? 'bg-green-50 text-green-600 border-0' : 'bg-gray-100 text-gray-500 border-0'}>{a.is_published ? t('منشور', 'Published') : t('مسودة', 'Draft')}</Badge>
                            <Button size="sm" variant="ghost" className="gap-1 rounded-full text-xs text-amber-700" aria-expanded={open} aria-controls={`assign-subs-${a.id}`} onClick={() => setViewSubm(open ? null : a.id)}>
                              <Users className="h-3.5 w-3.5" />{t('التسليمات', 'Submissions')}
                              <span className="ms-1 rounded-full bg-amber-200/70 px-1.5 text-[10px] font-semibold">{subs.length}</span>
                            </Button>
                            <Button size="sm" variant="ghost" className="rounded-full text-xs text-teal-700" onClick={() => openAssignEdit(a)}><Pencil className="h-3.5 w-3.5" />{t('تحرير', 'Edit')}</Button>
                            <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => toggleAssignPublish(a)} aria-label={a.is_published ? t('إلغاء النشر', 'Unpublish') : t('نشر', 'Publish')} title={a.is_published ? t('إلغاء النشر', 'Unpublish') : t('نشر', 'Publish')}>
                              {a.is_published ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
                            </Button>
                            <Button size="sm" variant="ghost" className="rounded-full text-xs text-red-500" onClick={() => removeAssign(a.id)} aria-label={t('حذف الواجب', 'Delete assignment')}><Trash2 className="h-3.5 w-3.5" /></Button>
                          </div>
                          {open && (
                            <div id={`assign-subs-${a.id}`} className="border-t border-amber-100 bg-white/60 p-3 space-y-3">
                              {subs.length === 0 ? (
                                <p className="text-sm text-gray-400 text-center py-3">{t('لا توجد تسليمات بعد', 'No submissions yet')}</p>
                              ) : subs.map((s: any) => (
                                <div key={s.id} className="rounded-lg border border-gray-100 bg-white p-3 space-y-2">
                                  <div className="flex items-center justify-between gap-2 flex-wrap">
                                    <p className="text-sm font-medium">{s.student?.name || '—'} <span className="text-gray-400 font-normal">({s.student?.email || ''})</span></p>
                                    <Badge className={s.status === 'graded' ? 'bg-green-50 text-green-600 border-0' : s.status === 'late' ? 'bg-amber-50 text-amber-600 border-0' : 'bg-blue-50 text-blue-600 border-0'}>{statusLabel[s.status] || s.status}</Badge>
                                  </div>
                                  {s.submission_text && <p className="text-sm text-gray-600 whitespace-pre-wrap break-words">{s.submission_text}</p>}
                                  {s.file_url && (
                                    <a href={s.file_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs text-teal-700 underline">
                                      <FileText className="h-3.5 w-3.5" />{s.file_name || t('الملف المرفق', 'Attached file')}
                                    </a>
                                  )}
                                  {s.grade !== null && s.grade !== undefined && (
                                    <p className="text-xs text-green-700">{t('الدرجة', 'Grade')}: <b>{s.grade}</b> / {a.points}{s.grade_comment ? ` — ${s.grade_comment}` : ''}</p>
                                  )}
                                  <div className="flex flex-wrap items-center gap-2">
                                    <Input type="number" value={gradeVals[s.id] ?? ''} onChange={(e) => setGradeVals((g) => ({ ...g, [s.id]: e.target.value }))} placeholder={t('الدرجة', 'Grade')} className="h-9 w-24" aria-label={t('الدرجة', 'Grade')} />
                                    <Input value={gradeCmts[s.id] ?? ''} onChange={(e) => setGradeCmts((g) => ({ ...g, [s.id]: e.target.value }))} placeholder={t('ملاحظة التصحيح (اختياري)', 'Grade comment (optional)')} className="h-9 flex-1 min-w-[160px]" aria-label={t('ملاحظة التصحيح', 'Grade comment')} />
                                    <Button size="sm" className="gap-1 rounded-full bg-teal-600 hover:bg-teal-700 text-white" disabled={gradingId === s.id} onClick={() => gradeSubmission(a, s)}>
                                      {gradingId === s.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <CheckCircle2 className="h-3.5 w-3.5" />}{t('حفظ التصحيح', 'Save grade')}
                                    </Button>
                                  </div>
                                  {Array.isArray(s.comments) && s.comments.length > 0 && (
                                    <div className="space-y-1 border-t border-gray-100 pt-2">
                                      {s.comments.map((c: any) => (
                                        <p key={c.id} className="text-xs text-gray-500"><b>{c.user_name || ''}</b>: {c.comment}</p>
                                      ))}
                                    </div>
                                  )}
                                  <div className="flex items-center gap-2">
                                    <Input value={commentVals[s.id] ?? ''} onChange={(e) => setCommentVals((c) => ({ ...c, [s.id]: e.target.value }))} placeholder={t('أضف تعليقاً...', 'Add a comment...')} className="h-9 flex-1" aria-label={t('تعليق', 'Comment')} />
                                    <Button size="sm" variant="outline" className="gap-1 rounded-full" disabled={commentingId === s.id} onClick={() => addComment(s)}>
                                      {commentingId === s.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Send className="h-3.5 w-3.5" />}{t('إرسال', 'Send')}
                                    </Button>
                                  </div>
                                </div>
                              ))}
                            </div>
                          )}
                        </div>
                      );
                    })}

                    {totalItems === 0 && (
                      <p className="text-sm text-gray-400 text-center py-6">{t('هذه الوحدة فارغة — أضف أول محتوى', 'This unit is empty — add your first content')}</p>
                    )}

                    {/* إضافة محتوى */}
                    <button onClick={() => openLesAdd(s.id)} className="w-full flex items-center justify-center gap-2 rounded-xl border-2 border-dashed border-teal-200 hover:border-teal-400 hover:bg-teal-50/40 text-teal-700 text-sm font-semibold py-3 transition">
                      <Plus className="h-4 w-4" />{t('إضافة محتوى', 'Add content')}
                    </button>
                  </div>
                  )}
                </CardContent>
              </Card>
            );
          })}

          <Button variant="outline" onClick={openSecAdd} className="gap-2 rounded-full w-full border-dashed py-3"><Plus className="h-4 w-4" />{t('إضافة وحدة', 'Add unit')}</Button>
        </div>
      )}
      </div>

      {/* ─── محرر الاختبار الموحّد — داخل مساحة محتوى الكورس (بلا تغطية الشاشة) ───────── */}
      {viewerExam && (
        <ExamEditor
          exam={viewerExam}
          sections={sections.map((s) => ({ id: s.id, title: s.title }))}
          questions={questions}
          onClose={() => setViewerExam(null)}
          onChanged={load}
        />
      )}

      {/* ─── حوار الوحدة ─────────────────────────── */}
      <Dialog open={secOpen} onOpenChange={setSecOpen}>
        <DialogContent><DialogHeader><DialogTitle>{secTarget ? t('تعديل الوحدة', 'Edit unit') : t('وحدة جديدة', 'New unit')}</DialogTitle></DialogHeader>
          <div className="space-y-3"><Label>{t('اسم الوحدة', 'Unit title')} *</Label><Input value={secTitle} onChange={(e) => setSecTitle(e.target.value)} className="h-11" /></div>
          <DialogFooter><Button variant="outline" onClick={() => setSecOpen(false)}>{t('إلغاء', 'Cancel')}</Button><Button onClick={saveSection}>{t('حفظ', 'Save')}</Button></DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ─── حوار الدرس ─────────────────────────── */}
      <Dialog open={lesOpen} onOpenChange={(v) => { setLesOpen(v); if (!v) { setLesTarget(null); setLesSection(''); setLes({ ...EMPTY_LESSON }); setLesContents([]); resetContentForm(); } }} className="max-w-2xl">
        <DialogContent><DialogHeader><div className="flex items-center justify-between gap-3"><DialogTitle>{lesTarget ? t('تعديل الدرس', 'Edit lesson') : t('درس جديد', 'New lesson')}</DialogTitle>{lesTarget && <a href={buildLearnUrl(id, { preview: true, lessonId: lesTarget.id })} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-xs font-semibold text-teal-700 hover:underline shrink-0"><Eye className="h-3.5 w-3.5" />{t('معاينة كطالب', 'Preview as student')}</a>}</div></DialogHeader>
          <div className="space-y-4">
            {!lesTarget && (
              <div className="space-y-2">
                <Label>{t('نوع المحتوى', 'Content type')}</Label>
                <div className="grid grid-cols-3 gap-2">
                  <button type="button" onClick={() => setLes({ ...les, kind: 'lesson' })}
                    className={`rounded-xl border-2 px-3 py-3 text-sm font-semibold transition ${les.kind === 'lesson' ? 'border-teal-500 bg-teal-50 text-teal-700' : 'border-gray-200 text-gray-500 hover:border-teal-300'}`}>
                    <BookOpen className="h-4 w-4 mx-auto mb-1" />{t('درس', 'Lesson')}
                  </button>
                  <button type="button" onClick={() => { setLesOpen(false); setLes({ ...EMPTY_LESSON }); openExamFromSection(lesSection); }}
                    className="rounded-xl border-2 border-gray-200 px-3 py-3 text-sm font-semibold text-gray-500 transition hover:border-violet-300">
                    <ClipboardList className="h-4 w-4 mx-auto mb-1" />{t('اختبار', 'Exam')}
                  </button>
                  <button type="button" onClick={() => { openLiveForLesson(); setLesOpen(false); }}
                    className="rounded-xl border-2 border-gray-200 px-3 py-3 text-sm font-semibold text-gray-500 transition hover:border-teal-300">
                    <Radio className="h-4 w-4 mx-auto mb-1" />{t('بث مباشر', 'Live')}
                  </button>
                  <button type="button" onClick={() => { setLesOpen(false); setLes({ ...EMPTY_LESSON }); setLesSection(lesSection); setAssignTarget(null); setAssign({ ...EMPTY_ASSIGN, section_id: lesSection }); setAssignOpen(true); }}
                    className="rounded-xl border-2 border-gray-200 px-3 py-3 text-sm font-semibold text-gray-500 transition hover:border-amber-300">
                    <ClipboardCheck className="h-4 w-4 mx-auto mb-1" />{t('واجب', 'Assignment')}
                  </button>
                </div>
              </div>
            )}
            <div className="space-y-2"><Label>{t('عنوان الدرس', 'Title')} *</Label><Input value={les.title} onChange={(e) => setLes({ ...les, title: e.target.value })} className="h-11" /></div>
            <div className="flex items-center justify-between gap-2 p-2.5 rounded-lg border">
              <span className={`flex items-center gap-2 text-sm ${les.is_free_preview ? 'text-green-700' : 'text-gray-500'}`}>
                {les.is_free_preview ? <Unlock className="h-4 w-4" /> : <Lock className="h-4 w-4" />}{les.is_free_preview ? t('معاينة', 'Preview') : t('مدفوع', 'Paid')}
              </span>
              <Switch checked={les.is_free_preview} onCheckedChange={(v) => setLes({ ...les, is_free_preview: v })} label={t('معاينة', 'Preview')} />
            </div>

            {lesTarget ? (
              <div className="space-y-3 border-t pt-4">
                <p className="text-sm font-bold">{t('محتوى الدرس', 'Lesson content')}</p>
                {lesContents.length === 0 ? (
                  <p className="text-sm text-gray-400">{t('لا يوجد محتوى بعد — أضف فيديو أو نصاً', 'No content yet — add video or text')}</p>
                ) : lesContents.map((c: any) => (
                  <div key={c.id} className="flex items-center gap-2.5 p-2.5 rounded-xl border bg-gray-50/60">
                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-white border shrink-0">{(() => { const I = contentIcon(c); return <I className="h-4 w-4 text-gray-600" />; })()}</div>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium truncate">{c.title || contentLabel(c)}</p>
                      <p className="text-[10px] text-gray-400">{contentLabel(c)}</p>
                    </div>
                    {(c.content_type === 'video' || c.content_type === 'text') && (
                      <Button variant="ghost" size="icon" className="h-7 w-7 text-teal-700" onClick={() => openContentEdit(c)}><Pencil className="h-3.5 w-3.5" /></Button>
                    )}
                    {c.content_type === 'video' && (
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 shrink-0 gap-1.5 rounded-full text-xs"
                        onClick={() => videoInputRef.current?.click()}
                        disabled={videoUploading}
                        aria-label={t('استبدال الفيديو', 'Replace video')}
                      >
                        {videoUploading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <UploadCloud className="h-3.5 w-3.5" />}
                        {t('استبدال الفيديو', 'Replace video')}
                      </Button>
                    )}
                    <Button variant="ghost" size="icon" className="h-7 w-7 text-red-500" onClick={() => removeContent(c.id)}><Trash2 className="h-3.5 w-3.5" /></Button>
                  </div>
                ))}
                <div className="flex flex-wrap gap-2">
                  {!hasVideoContent && (
                    <Button variant="outline" size="sm" className="gap-1.5 rounded-full text-xs" onClick={() => videoInputRef.current?.click()} disabled={videoUploading}>
                      {videoUploading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <UploadCloud className="h-3.5 w-3.5" />}{t('رفع فيديو جديد', 'Upload a new video')}
                    </Button>
                  )}
                  {!hasVideoContent && (
                    <Button variant="outline" size="sm" className="gap-1.5 rounded-full text-xs" onClick={() => setVideoLibraryOpen(true)} disabled={videoUploading}>
                      <Film className="h-3.5 w-3.5" />{t('اختيار فيديو موجود', 'Choose an existing video')}
                    </Button>
                  )}
                  {hasVideoContent && (
                    <Button variant="outline" size="sm" className="gap-1.5 rounded-full text-xs" onClick={() => setVideoLibraryOpen(true)}>
                      <Film className="h-3.5 w-3.5" />{t('استبدال بفيديو موجود', 'Replace with an existing video')}
                    </Button>
                  )}
                  <input ref={videoInputRef} type="file" className="hidden" accept="video/mp4,video/webm,video/quicktime,.mp4,.webm,.mov" onChange={onVideoSelected} />
                  {videoUploading && (
                    <div className="flex w-full items-center gap-2">
                      <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-teal-500 transition-all" style={{ width: `${videoUploadProgress}%` }} /></div>
                      <span className="text-xs text-gray-400">{videoUploadProgress}%</span>
                    </div>
                  )}
                  <Button variant="outline" size="sm" className="gap-1.5 rounded-full text-xs" onClick={() => openContentForm('video')}><Video className="h-3.5 w-3.5" />{t('رابط يوتيوب', 'YouTube link')}</Button>
                  <Button variant="outline" size="sm" className="gap-1.5 rounded-full text-xs" onClick={() => fileInputRef.current?.click()} disabled={uploading}>
                    {uploading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <FileText className="h-3.5 w-3.5" />}{t('رفع PDF (يتصفحه الطالب)', 'Upload PDF (student can view)')}
                  </Button>
                  <input ref={fileInputRef} type="file" className="hidden" accept="application/pdf,.pdf" onChange={onFileSelected} />
                  <Button variant="outline" size="sm" className="gap-1.5 rounded-full text-xs" onClick={() => openContentForm('text')}><FileText className="h-3.5 w-3.5" />{t('نص', 'Text')}</Button>
                  {videoUploadError && <p className="text-xs text-red-500 w-full">{videoUploadError}</p>}
                </div>
              </div>
            ) : (
              <p className="text-xs text-gray-400 bg-slate-50 rounded-xl p-3">{t('احفظ الدرس أولاً ثم افتحه لإضافة المحتوى (فيديو، PDF، صوت، نص، ملفات)', 'Save the lesson first, then open it to add content (video, PDF, audio, text, files)')}</p>
            )}

            {/* ربط تمرين بدرس الفيديو — نفس محرك الاختبارات بلا درجات مكررة */}
            {lesTarget && isVideoLesson(lesTarget) && (
              <div className="space-y-3 border-t pt-4">
                <div className="flex items-center gap-2">
                  <ClipboardList className="h-4 w-4 text-violet-600" />
                  <p className="text-sm font-bold">{t('تمرين مرتبط بالدرس', 'Exercise attached to lesson')}</p>
                </div>
                <p className="text-[11px] text-gray-400">{t('اربط اختباراً ليتدرّب الطالب بعد مشاهدة الفيديو. يُستخدم نفس نظام المحاولات والتصحيح — بلا نظام درجات مكرر.', 'Attach an exam so the student practises after the video. The same attempt & grading engine is used — no duplicated scores.')}</p>
                <div className="grid sm:grid-cols-2 gap-3">
                  <div className="space-y-1.5">
                    <Label className="text-xs">{t('الاختبار', 'Exam')}</Label>
                    <select value={les.exercise_exam_id || ''} onChange={(e) => setLes({ ...les, exercise_exam_id: e.target.value })} className="h-10 w-full rounded-xl border bg-background px-3 text-sm">
                      <option value="">{t('بدون تمرين', 'No exercise')}</option>
                      {exams.map((e: any) => <option key={e.id} value={e.id}>{e.title}{e.is_published ? '' : ` (${t('مسودة', 'draft')})`}</option>)}
                    </select>
                  </div>
                  {les.exercise_exam_id && (
                    <div className="space-y-1.5">
                      <Label className="text-xs">{t('الموضع', 'Position')}</Label>
                      <select value={les.exercise_position} onChange={(e) => setLes({ ...les, exercise_position: e.target.value })} className="h-10 w-full rounded-xl border bg-background px-3 text-sm">
                        <option value="after_video">{t('أسفل الفيديو مباشرة', 'Right below the video')}</option>
                        <option value="after_content">{t('بعد كل محتوى الدرس', 'After all lesson content')}</option>
                      </select>
                    </div>
                  )}
                </div>
                {les.exercise_exam_id && !exams.find((e: any) => e.id === les.exercise_exam_id)?.is_published && (
                  <p className="text-[11px] text-amber-600">{t('الاختبار مسودة — لن يظهر للطالب حتى تنشره.', 'The exam is a draft — students will not see it until you publish it.')}</p>
                )}
              </div>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => { setLesOpen(false); setLesTarget(null); setLesSection(''); setLes({ ...EMPTY_LESSON }); setLesContents([]); resetContentForm(); }}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={saveLesson}>{t('حفظ', 'Save')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ─── حوار البث المباشر (Google Meet) ─────────── */}
      <Dialog open={liveOpen} onOpenChange={(v) => { setLiveOpen(v); if (!v) setLiveError(''); }}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Radio className="h-5 w-5 text-rose-600" />{t('حصة بث مباشر جديدة', 'New live session')}
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>{t('عنوان الحصة', 'Session title')}</Label>
              <Input value={liveTitle} onChange={(e) => setLiveTitle(e.target.value)} className="h-11" placeholder={t('مثال: مراجعة الوحدة الأولى', 'e.g. Unit 1 review')} />
            </div>

            {/* خطوات إنشاء الاجتماع + زر يفتح Google Meet */}
            <div className="rounded-2xl border border-teal-100 bg-teal-50/60 p-4 space-y-3">
              <div className="flex items-start gap-2.5">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white border border-teal-100"><Video className="h-4 w-4 text-teal-600" /></span>
                <div className="text-sm">
                  <p className="font-semibold text-teal-900">{t('كيف أحصل على رابط Google Meet؟', 'How do I get a Google Meet link?')}</p>
                  <ol className="mt-1.5 space-y-1 text-[13px] text-teal-900/70 list-decimal ps-4">
                    <li>{t('اضغط الزر أدناه لفتح Google Meet.', 'Click the button below to open Google Meet.')}</li>
                    <li>{t('أنشئ اجتماعاً جديداً (New meeting).', 'Create a new meeting.')}</li>
                    <li>{t('انسخ الرابط (Copy link) وألصقه في الحقل.', 'Copy the link and paste it in the field.')}</li>
                  </ol>
                </div>
              </div>
              <a href="https://meet.google.com/new" target="_blank" rel="noopener noreferrer"
                className="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-teal-200 bg-white px-4 py-2.5 text-sm font-semibold text-teal-700 hover:bg-teal-50 transition-colors">
                <ExternalLink className="h-4 w-4" />{t('افتح Google Meet وأنشئ اجتماعاً', 'Open Google Meet & create a meeting')}
              </a>
            </div>

            {/* حقل الرابط */}
            <div className="space-y-2">
              <Label>{t('رابط الاجتماع', 'Meeting link')}</Label>
              <div className={`flex items-center rounded-xl border overflow-hidden bg-white ${liveError ? 'border-red-300' : liveMeetUrl && MEET_RE.test(liveMeetUrl.trim()) ? 'border-emerald-400' : 'border-slate-200'}`}>
                <span className="px-3 h-11 flex items-center text-gray-400 border-e border-slate-200 bg-slate-50"><Link2 className="h-4 w-4" /></span>
                <Input value={liveMeetUrl} onChange={(e) => { setLiveMeetUrl(e.target.value); setLiveError(''); }} dir="ltr" placeholder="https://meet.google.com/abc-defg-hij" className="h-11 border-0 rounded-none focus-visible:ring-0" />
                {liveMeetUrl && (
                  <button type="button" onClick={async () => { try { await navigator.clipboard.writeText(liveMeetUrl); toast.success(t('تم نسخ الرابط', 'Link copied')); } catch { /* ignore */ } }} className="px-3 h-11 text-gray-400 hover:text-teal-600" aria-label={t('نسخ', 'Copy')}><Copy className="h-4 w-4" /></button>
                )}
              </div>
              {liveError ? (
                <p className="text-xs text-red-500 inline-flex items-center gap-1"><XCircle className="h-3.5 w-3.5" />{liveError}</p>
              ) : liveMeetUrl && MEET_RE.test(liveMeetUrl.trim()) ? (
                <p className="text-xs text-emerald-600 inline-flex items-center gap-1"><CheckCircle2 className="h-3.5 w-3.5" />{t('رابط صحيح', 'Valid link')}</p>
              ) : (
                <p className="text-xs text-gray-400 inline-flex items-center gap-1"><Info className="h-3.5 w-3.5" />{t('يجب أن يبدأ الرابط بـ https://meet.google.com/', 'The link must start with https://meet.google.com/')}</p>
              )}
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setLiveOpen(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={saveLiveSession} disabled={liveBusy || !MEET_RE.test(liveMeetUrl.trim())} className="gap-2 bg-rose-600 hover:bg-rose-700 text-white">
              {liveBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Radio className="h-4 w-4" />}{t('إنشاء الحصة', 'Create session')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ─── حوار الواجب ─────────────────────────── */}
      <Dialog open={assignOpen} onOpenChange={(v) => { setAssignOpen(v); if (!v) { setAssignTarget(null); setAssign({ ...EMPTY_ASSIGN }); } }}>
        <DialogContent className="max-w-xl"><DialogHeader><DialogTitle>{assignTarget ? t('تعديل الواجب', 'Edit assignment') : t('واجب جديد', 'New assignment')}</DialogTitle></DialogHeader>
          <div className="space-y-3">
            <div className="space-y-2"><Label>{t('العنوان', 'Title')} *</Label><Input value={assign.title} onChange={(e) => setAssign({ ...assign, title: e.target.value })} className="h-11" /></div>
            <div className="space-y-2"><Label>{t('الوصف', 'Description')}</Label><Textarea value={assign.description} onChange={(e) => setAssign({ ...assign, description: e.target.value })} className="min-h-[70px]" /></div>
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-2"><Label>{t('النقاط', 'Points')}</Label><Input type="number" value={assign.points} onChange={(e) => setAssign({ ...assign, points: e.target.value })} className="h-11" /></div>
              <div className="space-y-2"><Label>{t('الموعد النهائي', 'Due date')}</Label><Input type="datetime-local" value={assign.due_at} onChange={(e) => setAssign({ ...assign, due_at: e.target.value })} className="h-11" /></div>
            </div>
            <div className="flex items-center gap-2 p-3 rounded-xl border">
              <Switch checked={assign.is_published} onCheckedChange={(v) => setAssign({ ...assign, is_published: v })} label={t('نشر للطلاب', 'Publish to students')} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => { setAssignOpen(false); setAssignTarget(null); setAssign({ ...EMPTY_ASSIGN }); }}>{t('إلغاء', 'Cancel')}</Button>
            {/* Delegates to saveAssign() so the request always carries course_id —
                the previous inline copy omitted it and POST /assignments 422'd. */}
            <Button onClick={saveAssign} disabled={assignUploading}>{t('حفظ', 'Save')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ─── حوار محتوى (رابط فيديو / نص) ────────── */}
      <Dialog open={contentType !== ''} onOpenChange={(v) => { if (!v) resetContentForm(); }}>
        <DialogContent><DialogHeader><DialogTitle>{contentType === 'video' ? t('فيديو بالرابط', 'Video URL') : t('نص', 'Text')}</DialogTitle></DialogHeader>
          <div className="space-y-3">
            {contentType === 'video' ? (
              <div className="space-y-2"><Label>{t('رابط الفيديو (YouTube/Vimeo...)', 'Video URL (YouTube/Vimeo...)')}</Label>
                <Input value={contentVideoUrl} onChange={(e) => setContentVideoUrl(e.target.value)} className="h-11" dir="ltr" placeholder="https://..." /></div>
            ) : (
              <div className="space-y-2"><Label>{t('النص', 'Text')}</Label><Textarea value={contentText} onChange={(e) => setContentText(e.target.value)} className="min-h-[150px]" /></div>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={resetContentForm}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={saveContent} disabled={contentSaving}>{contentSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : null}{t('حفظ', 'Save')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      {/* ─── شريط تقدم الرفع العام (يستمر حتى بعد إغلاق نافذة الدرس) ─── */}
      {videoUploading && (
        <div className="fixed bottom-4 inset-x-0 z-[60] flex justify-center px-4 pointer-events-none">
          <div className="pointer-events-auto w-full max-w-md rounded-2xl bg-slate-900 text-white p-4 shadow-2xl ring-1 ring-white/10 space-y-2">
            <div className="flex items-center justify-between gap-3 text-xs">
              <span className="flex items-center gap-2 font-semibold truncate">
                <UploadCloud className="h-4 w-4 text-teal-400 shrink-0" />
                <span className="truncate">{t('جاري رفع الفيديو', 'Uploading video')}: {videoUploadName || ''}</span>
              </span>
              <span className="font-bold text-teal-300 shrink-0">{videoUploadProgress}%</span>
            </div>
            <div className="h-2 rounded-full bg-white/10 overflow-hidden">
              <div className="h-full bg-gradient-to-r from-teal-500 to-teal-400 rounded-full transition-all duration-300" style={{ width: `${videoUploadProgress}%` }} />
            </div>
            <p className="text-[10px] text-white/40">{t('يمكنك متابعة عملك — الرفع مستمر في الخلفية', 'You can keep working — upload continues in the background')}</p>
          </div>
        </div>
      )}

      <VideoUploadQueue
        open={videoQueueOpen}
        onOpenChange={setVideoQueueOpen}
        sections={sections.map((s: any) => ({ id: s.id, title: s.title }))}
        lessons={sections.flatMap((s: any) => (s.lessons || []).map((l: any) => ({ id: l.id, title: l.title, section_id: s.id })))}
        onUploaded={load}
      />

      <VideoLibraryPicker
        open={videoLibraryOpen}
        onOpenChange={setVideoLibraryOpen}
        lessonId={lesTarget?.id || null}
        onAttached={() => { if (lesTarget) loadLesContents(lesTarget.id); load(); }}
      />

      <CourseGroupsManager open={groupsOpen} onOpenChange={setGroupsOpen} courseId={id} />

      {/* ─── حوار «إنشاء نسخة» (كورس/درس/اختبار) ─── */}
      <DuplicateDialog
        open={!!dup}
        onOpenChange={(v) => { if (!v) setDup(null); }}
        kind={dup?.kind || 'course'}
        id={dup?.id || null}
        originalTitle={dup?.title || ''}
        onDuplicated={load}
      />
    </div>
  );
}
