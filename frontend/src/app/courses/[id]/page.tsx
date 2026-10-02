'use client';

import { useEffect, useRef, useState, type DragEvent } from 'react';
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
import {
  ArrowLeft, ArrowRight, Plus, Trash2, Video, FileText, Lock, Unlock, ChevronDown, ChevronUp, Loader2,
  Pencil, Layers, BookOpen, ClipboardList, HelpCircle, ArrowUp, ArrowDown, Eye, EyeOff, ListChecks, CheckCircle2, XCircle, GripVertical,
  Upload, FileAudio, FileVideo, FileImage, FileArchive, File, Search, ClipboardCheck, CalendarClock, Star, MessageSquare, Send,
  UploadCloud, Radio } from 'lucide-react';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';
import { QuestionBuilder, type QuestionDraft } from '@/components/exam/question-builder';
import { getDeviceId } from '@/lib/device';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const EMPTY_LESSON = { title: '', is_free_preview: false, kind: 'lesson' };
const EMPTY_EXAM = { title: '', description: '', duration_minutes: '30', pass_mark_percent: '50', max_attempts: '3', random_questions_count: '', shuffle_questions: true, anti_cheat_enabled: true, is_published: false };
const EMPTY_QUESTION: QuestionDraft = { question_text: '', question_type: 'multiple_choice', points: '1', exam_id: '', section_id: '', options: [{ option_text: '', is_correct: true }, { option_text: '', is_correct: false }, { option_text: '', is_correct: false }, { option_text: '', is_correct: false }], image_url: '', pairs: [], items: [], answers: [''], keywords: '' };

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
  const fileInputRef = useRef<HTMLInputElement>(null);
  const videoInputRef = useRef<HTMLInputElement>(null);

  const [secOpen, setSecOpen] = useState(false);
  const [secTarget, setSecTarget] = useState<any>(null);
  const [secTitle, setSecTitle] = useState('');
  const [lesOpen, setLesOpen] = useState(false);
  const [lesTarget, setLesTarget] = useState<any>(null);
  const [lesSection, setLesSection] = useState('');
  const [les, setLes] = useState<any>({ ...EMPTY_LESSON });
  const [examOpen, setExamOpen] = useState(false);
  const [examTarget, setExamTarget] = useState<any>(null);
  const [exam, setExam] = useState<any>({ ...EMPTY_EXAM });
  const [qOpen, setQOpen] = useState(false);
  const [qTarget, setQTarget] = useState<any>(null);
  const [q, setQ] = useState<any>({ ...EMPTY_QUESTION });
  const [examFilter, setExamFilter] = useState('');
  const [sectionFilter, setSectionFilter] = useState('');
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
    } catch { toast.error('Failed'); }
  };
  const removeSection = async (sid: string) => {
    if (!window.confirm(t('هل أنت متأكد من حذف هذا القسم؟ سيتم حذف جميع دروسه ومحتواها.', 'Delete this section? All its lessons and content will be removed.'))) return;
    try { const res = await fetch(`${API}/sections/${sid}`, { method: 'DELETE', headers: headers() }); if (!res.ok) throw new Error(); toast.success(t('تم الحذف', 'Deleted')); load(); } catch { toast.error('Failed'); }
  };
  const moveSection = async (s: any, dir: number) => {
    const idx = sections.findIndex((x) => x.id === s.id);
    const other = sections[idx + dir];
    if (!other) return;
    try {
      await fetch(`${API}/sections/${s.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ sort_order: other.sort_order }) });
      await fetch(`${API}/sections/${other.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ sort_order: s.sort_order }) });
      load();
    } catch { toast.error('Failed'); }
  };

  // ─── Lessons ───────────────────────────────────
  const openLesAdd = (sid: string) => { setLesTarget(null); setLesSection(sid); setLes({ ...EMPTY_LESSON }); setLesOpen(true); };
  const openLesEdit = (l: any) => {
    setLesTarget(l); setLesSection(l.section_id);
    setLes({ title: l.title || '', is_free_preview: !!l.is_free_preview, kind: 'lesson' });
    setLesOpen(true);
    resetContentForm();
    loadLesContents(l.id);
  };
  const saveLesson = async () => {
    if (!les.title.trim()) { toast.error(t('أدخل عنوان الدرس', 'Enter lesson title')); return; }
    try {
      const body = { section_id: lesSection, title: les.title, is_free_preview: les.is_free_preview, sort_order: lesTarget ? lesTarget.sort_order : undefined };
      const res = await fetch(`${API}/lessons${lesTarget ? `/${lesTarget.id}` : ''}`, { method: lesTarget ? 'PUT' : 'POST', headers: headers(), body: JSON.stringify(body) });
      if (!res.ok) throw new Error();
      toast.success(lesTarget ? t('تم تحديث الدرس', 'Lesson updated') : t('تم إضافة الدرس', 'Lesson added'));
      setLesOpen(false); setLesTarget(null); setLes({ ...EMPTY_LESSON }); load();
    } catch { toast.error('Failed'); }
  };
  const removeLesson = async (lid: string) => {
    if (!window.confirm(t('هل أنت متأكد من حذف هذا الدرس؟', 'Delete this lesson?'))) return;
    try { const res = await fetch(`${API}/lessons/${lid}`, { method: 'DELETE', headers: headers() }); if (!res.ok) throw new Error(); toast.success(t('تم الحذف', 'Deleted')); load(); } catch { toast.error('Failed'); }
  };
  const moveLesson = async (l: any, dir: number) => {
    const sec = sections.find((s) => s.id === l.section_id);
    const list = sec?.lessons || [];
    const idx = list.findIndex((x: any) => x.id === l.id);
    const other = list[idx + dir];
    if (!other) return;
    try {
      await fetch(`${API}/lessons/${l.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ sort_order: other.sort_order }) });
      await fetch(`${API}/lessons/${other.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ sort_order: l.sort_order }) });
      load();
    } catch { toast.error('Failed'); }
  };
  const reorderLessons = async (sectionId: string, lessonIds: string[]) => {
    try {
      const res = await fetch(`${API}/lessons/reorder`, { method: 'POST', headers: headers(), body: JSON.stringify({ section_id: sectionId, lesson_ids: lessonIds }) });
      if (!res.ok) throw new Error();
      toast.success(t('تم حفظ ترتيب الدروس', 'Lesson order saved'));
    } catch { toast.error(t('فشل حفظ الترتيب', 'Failed to save order')); load(); }
  };
  const onLessonDragStart = (e: DragEvent, l: any) => {
    setDragLesson(l.id);
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', l.id);
  };
  const onLessonDragOver = (e: DragEvent, target: any) => {
    e.preventDefault();
    if (dragLesson && dragLesson !== target.id) setDragOverLesson(target.id);
  };
  const onLessonDrop = async (e: DragEvent, target: any) => {
    e.preventDefault();
    const fromId = dragLesson;
    setDragLesson(null);
    setDragOverLesson(null);
    if (!fromId || fromId === target.id) return;
    const next = sections.map((s) => {
      if (s.id !== target.section_id) return s;
      const list = s.lessons || [];
      const from = list.findIndex((x: any) => x.id === fromId);
      const to = list.findIndex((x: any) => x.id === target.id);
      if (from === -1 || to === -1) return s;
      const arr = [...list];
      const [moved] = arr.splice(from, 1);
      arr.splice(to, 0, moved);
      return { ...s, lessons: arr };
    });
    const sec = next.find((s: any) => s.id === target.section_id);
    const ids = (sec?.lessons || []).map((x: any) => x.id);
    setSections(next);
    if (ids.length) await reorderLessons(target.section_id, ids);
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
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(contentTarget ? t('تم تحديث المحتوى', 'Content updated') : t('تمت إضافة المحتوى', 'Content added'));
      resetContentForm();
      loadLesContents(lesTarget.id);
    } catch { toast.error('Failed'); } finally { setContentSaving(false); }
  };
  const removeContent = async (cid: string) => {
    if (!window.confirm(t('هل تريد حذف هذا المحتوى؟', 'Delete this content?'))) return;
    try {
      const res = await fetch(`${API}/lesson-contents/${cid}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم حذف المحتوى', 'Content removed'));
      loadLesContents(lesTarget.id);
    } catch { toast.error('Failed'); }
  };
  const [liveBusy, setLiveBusy] = useState(false);
  const createLiveForLesson = async () => {
    if (liveBusy) return;
    setLiveBusy(true);
    try {
      let lessonId = lesTarget?.id;
      let lessonTitle = lesTarget?.title || les.title || t('بث مباشر', 'Live');

      // من "درس جديد": أنشئ الدرس أولاً (إن لم يكن محفوظاً بعد)
      if (!lessonId) {
        if (!lesSection) { toast.error(t('اختر وحدة أولاً', 'Pick a unit first')); return; }
        const lr = await fetch(`${API}/lessons`, {
          method: 'POST',
          headers: headers(),
          body: JSON.stringify({ section_id: lesSection, title: lessonTitle, is_free_preview: les.is_free_preview }),
        });
        const ld = await lr.json().catch(() => null);
        if (!lr.ok) { toast.error(ld?.message || 'Failed'); return; }
        lessonId = ld.lesson?.id || ld.id;
        if (!lessonId) { toast.error('Failed'); return; }
        toast.success(t('تم إنشاء الدرس', 'Lesson created'));
      }

      const meetingUrl = window.prompt(t('ألصق رابط Google Meet الخاص بك (https://meet.google.com/...)', 'Paste your Google Meet link (https://meet.google.com/...)'));
      if (!meetingUrl || !/^https:\/\/meet\.google\.com\/[a-z0-9-]+(\/)?$/i.test(meetingUrl.trim())) {
        toast.error(t('رابط Google Meet غير صحيح', 'Invalid Google Meet link'));
        return;
      }

      const res = await fetch(`${API}/teacher/live-sessions`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ title: `${lessonTitle}`, lesson_id: lessonId, provider: 'google_meet', meeting_url: meetingUrl.trim(), record_enabled: true }),
      });
      const d = await res.json().catch(() => null);
      if (!res.ok) { toast.error(d?.message || 'Failed'); return; }
      toast.success(t('تم إنشاء البث المباشر داخل الدرس', 'Live session created inside the lesson'));
      setLesOpen(false); setLesTarget(null); setLes({ ...EMPTY_LESSON });
      load();
    } catch { toast.error('Failed'); } finally { setLiveBusy(false); }
  };
  const onVideoSelected = async (e: any) => {
    const file = e.target.files?.[0];
    if (!file || videoUploading) return;
    // مفتاح عملية واحد لكل ملف: كل الطلبات (تذكرة + رفع + retry)
    // تحمل نفس المفتاح → حتى لو تكرر الطلب لن يتكرر الفيديو في Bunny.
    const uploadKey = crypto.randomUUID();
    setVideoUploading(true);
    setVideoUploadProgress(0);
    setVideoUploadError('');
    setVideoUploadName(file.name);
    try {
      // 1) تذكرة رفع مباشر من Media Engine (تحقق الخطة + حجز المساحة).
      const res = await fetch(`${API}/videos/upload-ticket`, {
        method: 'POST',
        headers: { ...headers(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ lesson_id: lesTarget.id, file_size: file.size, title: file.name.replace(/\.[^.]+$/, ''), original_filename: file.name, mime_type: file.type || 'video/mp4', idempotency_key: uploadKey }),
      });
      const d = await res.json().catch(() => null);
      if (!res.ok) {
        setVideoUploadError(d?.message || t('فشل إنشاء تذكرة الرفع', 'Failed to create upload ticket'));
        setVideoUploading(false);
        return;
      }

      // 2) رفع الملف عبر خادمنا (مفتاح Bunny لا يخرج للمتصفح أبداً) —
      //    شريط التقدم كامل عبر XHR.
      const fd = new FormData();
      fd.append('file', file);
      fd.append('lesson_id', lesTarget.id);
      fd.append('title', file.name.replace(/\.[^.]+$/, ''));
      fd.append('asset_id', d?.upload?.asset_id || '');
      fd.append('idempotency_key', uploadKey);
      await new Promise<void>((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', `${API}/videos/upload`);
        const hdrs: Record<string, string> = { ...headers(), 'X-Device-Id': getDeviceId() };
        Object.entries(hdrs).forEach(([k, v]) => { if (k.toLowerCase() !== 'content-type') xhr.setRequestHeader(k, v); });
        xhr.upload.onprogress = (ev) => {
          if (ev.lengthComputable) setVideoUploadProgress(Math.round((ev.loaded / ev.total) * 100));
        };
        xhr.onload = () => {
          if (xhr.status >= 200 && xhr.status < 300) resolve();
          else {
            let msg = `HTTP ${xhr.status}`;
            try { const j = JSON.parse(xhr.responseText); if (j?.message) msg = j.message; } catch { /* */ }
            reject(new Error(`HTTP ${xhr.status}${msg ? `: ${msg}` : ''}`));
          }
        };
        xhr.onerror = () => reject(new Error('network'));
        xhr.send(fd);
      });

      setVideoUploadProgress(100);
      toast.success(t('تم رفع الفيديو بنجاح — قيد المعالجة الآن', 'Video uploaded — processing now'));
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
      if (!res.ok) { toast.error(d?.message || 'Failed'); return; }
      toast.success(t('تم رفع الملف', 'File uploaded'));
      loadLesContents(lesTarget.id);
    } catch { toast.error('Failed'); } finally { setUploading(false); e.target.value = ''; }
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

  // ─── Exams ─────────────────────────────────────
  const openExamAdd = () => { setExamTarget(null); setExam({ ...EMPTY_EXAM }); setExamOpen(true); };
  const openExamFromSection = async (sid: string) => {
    try {
      const res = await fetch(`${API}/exams`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ course_id: id, section_id: sid, title: t('اختبار الوحدة', 'Unit exam'), duration_minutes: 30, pass_mark_percent: 50, max_attempts: 3, shuffle_questions: true, is_published: false }),
      });
      const d = await res.json();
      if (!res.ok) { toast.error(d?.message || 'Failed'); return; }
      toast.success(t('تم إنشاء الاختبار — أضف أسئلته', 'Exam created — add its questions'));
      load();
      setViewerExam(d.exam || d);
    } catch { toast.error('Failed'); }
  };
  const openExamEdit = (e: any) => {
    setExamTarget(e);
    setExam({
      title: e.title || '', description: e.description || '',
      duration_minutes: String(e.duration_minutes ?? '30'), pass_mark_percent: String(e.pass_mark_percent ?? '50'),
      max_attempts: String(e.max_attempts ?? '3'), shuffle_questions: !!e.shuffle_questions, is_published: !!e.is_published,
    });
  };
  const saveExam = async () => {
    if (!exam.title.trim()) { toast.error(t('أدخل اسم الاختبار', 'Enter exam title')); return; }
    try {
      const body = {
        course_id: id, section_id: exam.section_id || undefined, title: exam.title, description: exam.description,
        duration_minutes: Number(exam.duration_minutes) || 0, pass_mark_percent: Number(exam.pass_mark_percent) || 0,
        max_attempts: Number(exam.max_attempts) || 0, shuffle_questions: exam.shuffle_questions,
        is_published: exam.is_published,
      };
      const res = await fetch(`${API}/exams${examTarget ? `/${examTarget.id}` : ''}`, { method: examTarget ? 'PUT' : 'POST', headers: headers(), body: JSON.stringify(body) });
      if (!res.ok) throw new Error();
      toast.success(examTarget ? t('تم تحديث الاختبار', 'Exam updated') : t('تم إنشاء الاختبار — أضف أسئلته من تبويب بنك الأسئلة', 'Exam created — add its questions from the question bank tab'));
      setExamOpen(false); setExamTarget(null); setExam({ ...EMPTY_EXAM }); load();
    } catch { toast.error('Failed'); }
  };
  const removeExam = async (eid: string) => {
    if (!window.confirm(t('هل أنت متأكد من حذف هذا الاختبار؟', 'Delete this exam?'))) return;
    try { const res = await fetch(`${API}/exams/${eid}`, { method: 'DELETE', headers: headers() }); if (!res.ok) throw new Error(); toast.success(t('تم الحذف', 'Deleted')); load(); } catch { toast.error('Failed'); }
  };
  const toggleExamPublish = async (e: any) => {
    try {
      const res = await fetch(`${API}/exams/${e.id}/${e.is_published ? 'unpublish' : 'publish'}`, { method: 'POST', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(e.is_published ? t('تم الإيقاف', 'Unpublished') : t('تم النشر', 'Published'));
      load();
    } catch { toast.error('Failed'); }
  };
  const manageExamQuestions = (e: any) => { setExamFilter(e.id); setTab('questions'); };

  // ─── Questions ─────────────────────────────────
  const openQAdd = (sectionId = '') => { setQTarget(null); setQ({ ...EMPTY_QUESTION, exam_id: examFilter, section_id: sectionId }); setQOpen(true); };
  const openQEdit = (qq: any) => {
    setQTarget(qq);
    setQ({
      question_text: qq.question_text || '', question_type: qq.question_type || 'multiple_choice', points: String(qq.points ?? '1'),
      exam_id: examFilter, section_id: qq.section_id || '',
      options: (qq.options && qq.options.length ? qq.options : [{ option_text: '', is_correct: true }, { option_text: '', is_correct: false }]).map((o: any) => ({ option_text: o.option_text || '', is_correct: !!o.is_correct })),
      image_url: qq.metadata?.image_url || '',
      pairs: (qq.metadata?.pairs || []).map((p: any) => ({ left: p.left || '', right: p.right || '' })),
      items: (qq.metadata?.items || []).map((i: any) => typeof i === 'string' ? i : i.text || ''),
      answers: (qq.metadata?.answers || ['']).map((a: any) => String(a)),
      keywords: (qq.metadata?.keywords || []).join(', '),
    });
    setQOpen(true);
  };
  const setCorrect = (idx: number) => { const opts = q.options.map((o: any, i: number) => ({ ...o, is_correct: i === idx })); setQ({ ...q, options: opts }); };
  const saveQuestion = async () => {
    if (!q.question_text.trim()) { toast.error(t('أدخل نص السؤال', 'Enter question text')); return; }
    try {
      const meta: any = {};
      if (q.image_url) meta.image_url = q.image_url;
      if (q.question_type === 'drag_drop' || q.question_type === 'matching') meta.pairs = q.pairs.filter((p: any) => p.left.trim() && p.right.trim());
      if (q.question_type === 'ordering') meta.items = q.items.filter((i: any) => String(i).trim());
      if (q.question_type === 'fill_blank') meta.answers = q.answers.filter((a: any) => String(a).trim());
      if (q.question_type === 'writing') meta.keywords = q.keywords.split(',').map((k: string) => k.trim()).filter(Boolean);

      const payload: any = { question_text: q.question_text, question_type: q.question_type, points: Number(q.points) || 1, course_id: id, section_id: q.section_id || null, metadata: meta };
      if (q.question_type === 'multiple_choice') payload.options = q.options.filter((o: any) => o.option_text.trim());
      if (q.question_type === 'true_false') payload.options = [{ option_text: 'True', is_correct: true }, { option_text: 'False', is_correct: false }];
      const res = await fetch(`${API}/questions${qTarget ? `/${qTarget.id}` : ''}`, { method: qTarget ? 'PUT' : 'POST', headers: headers(), body: JSON.stringify(payload) });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      const qid = qTarget?.id || (await res.json().catch(() => null))?.question?.id;
      if (q.question_type === 'multiple_choice') await syncOptions(qid, payload.options);
      if (q.exam_id) await attachToExam(qid, q.exam_id);
      toast.success(qTarget ? t('تم تحديث السؤال', 'Question updated') : t('تم إضافة السؤال', 'Question added'));
      setQOpen(false); setQTarget(null); setQ({ ...EMPTY_QUESTION, exam_id: examFilter }); load();
    } catch { toast.error('Failed'); }
  };
  const syncOptions = async (qid: string, options: any[]) => {
    if (!qid || qTarget) return;
    for (const o of options) {
      await fetch(`${API}/questions/${qid}/options`, { method: 'POST', headers: headers(), body: JSON.stringify({ option_text: o.option_text, is_correct: o.is_correct }) });
    }
  };
  const attachToExam = async (qid: string, eid: string) => {
    await fetch(`${API}/exams/${eid}/add-questions`, { method: 'POST', headers: headers(), body: JSON.stringify({ questions: [{ question_id: qid }] }) });
  };
  const removeQuestion = async (qqid: string) => {
    if (!window.confirm(t('هل أنت متأكد من حذف هذا السؤال؟', 'Delete this question?'))) return;
    try {
      const res = await fetch(`${API}/questions/${qqid}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم الحذف', 'Deleted')); load();
    } catch { toast.error('Failed'); }
  };
  const detachQuestion = async (qqid: string) => {
    if (!window.confirm(t('إزالة هذا السؤال من الاختبار؟', 'Remove this question from the exam?'))) return;
    try {
      const res = await fetch(`${API}/exams/${examFilter}/questions/${qqid}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تمت إزالة السؤال من الاختبار', 'Removed from exam')); load();
    } catch { toast.error('Failed'); }
  };

  const visibleQuestions = questions.filter((qq) => {
    if (examFilter && !(qq.exams || []).some((e: any) => e.id === examFilter)) return false;
    if (sectionFilter && qq.section_id !== sectionFilter) return false;
    return true;
  });

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
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Upload failed'); return; }
      const d = await res.json();
      setAssign((a: any) => ({ ...a, file_url: d.url, file_name: d.name }));
    } catch { toast.error('Upload failed'); }
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
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(assignTarget ? t('تم تحديث الواجب', 'Assignment updated') : t('تم إنشاء الواجب', 'Assignment created'));
      setAssignOpen(false); setAssignTarget(null); setAssign({ ...EMPTY_ASSIGN }); load();
    } catch { toast.error('Failed'); }
  };
  const removeAssign = async (aid: string) => {
    if (!window.confirm(t('هل أنت متأكد من حذف هذا الواجب وجميع تسليماته؟', 'Delete this assignment and all its submissions?'))) return;
    try { const res = await fetch(`${API}/assignments/${aid}`, { method: 'DELETE', headers: headers() }); if (!res.ok) throw new Error(); toast.success(t('تم الحذف', 'Deleted')); if (viewSubm === aid) setViewSubm(null); load(); } catch { toast.error('Failed'); }
  };
  const toggleAssignPublish = async (a: any) => {
    try {
      const res = await fetch(`${API}/assignments/${a.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ is_published: !a.is_published }) });
      if (!res.ok) throw new Error();
      toast.success(a.is_published ? t('تم الإيقاف', 'Unpublished') : t('تم النشر', 'Published'));
      load();
    } catch { toast.error('Failed'); }
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
    } catch { toast.error('Failed'); }
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
    } catch { toast.error('Failed'); }
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

  // ─── محرر الاختبار المفتوح (إعدادات + أسئلة كيان واحد) ───
  const [viewerExam, setViewerExam] = useState<any>(null);
  const [examInnerTab, setExamInnerTab] = useState<'questions' | 'settings'>('questions');
  const [bankOpen, setBankOpen] = useState(false);
  const [bankForExam, setBankForExam] = useState<string>('');

  const sectionExams = (sid: string) => exams.filter((e) => e.section_id === sid);
  const sectionAssigns = (sid: string) => assignments.filter((a) => a.section_id === sid);
  const examQuestions = (eid: string) => questions.filter((qq) => qq.exam_id === eid);

  const openExamViewer = (e: any) => { setViewerExam(e); setExamInnerTab('questions'); };
  const openBank = (examId = '') => { setBankForExam(examId); setBankOpen(true); };

  const addQuestionToExam = async (qq: any) => {
    if (!bankForExam) return;
    await attachToExam(qq.id, bankForExam);
    toast.success(t('أُضيف السؤال للاختبار', 'Question added to exam'));
    load();
  };

  const qTypeIcon = (t: string) =>
    t === 'multiple_choice' ? '☑️' : t === 'true_false' ? '✅' : t === 'drag_drop' ? '🧲' : t === 'matching' ? '🔗' : t === 'ordering' ? '🔢' : t === 'fill_blank' ? '✍️' : t === 'written' ? '📝' : '📎';

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      {/* ─── رأس الكورس ─────────────────────────── */}
      <div className="flex items-start justify-between flex-wrap gap-4">
        <div>
          <a href="/courses" className="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800">{locale === 'ar' ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />}{t('رجوع للكورسات', 'Back to courses')}</a>
          <h1 className="text-2xl sm:text-3xl font-bold mt-2">{course?.title || t('إدارة الكورس', 'Course Builder')}</h1>
          {course?.description && <p className="text-gray-500 text-sm mt-1 max-w-xl">{course.description}</p>}
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <a href={`/course/${id}/learn`} target="_blank" rel="noreferrer"><Button variant="outline" className="gap-2 rounded-full"><Eye className="h-4 w-4" />{t('معاينة', 'Preview')}</Button></a>
          {course && (
            <Button variant={course.is_published ? 'outline' : 'default'} className="gap-2 rounded-full" onClick={() => {
              fetch(`${API}/courses/${id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ is_published: !course.is_published }) })
                .then((r) => r.json()).then(() => { load(); toast.success(course.is_published ? t('أُلغي النشر', 'Unpublished') : t('نُشر الكورس', 'Published')); }).catch(() => toast.error('Failed'));
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
                      <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => moveSection(s, -1)} disabled={si === 0}><ArrowUp className="h-3.5 w-3.5" /></Button>
                      <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => moveSection(s, 1)} disabled={si === sections.length - 1}><ArrowDown className="h-3.5 w-3.5" /></Button>
                      <Button size="sm" variant="ghost" className="rounded-full text-xs text-red-500" onClick={() => removeSection(s.id)}><Trash2 className="h-3.5 w-3.5" /></Button>
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
                          <div key={l.id} className="rounded-xl border border-rose-200 bg-rose-50/50 p-4 space-y-3">
                            <div className="flex items-center gap-3">
                              <GripVertical className="h-4 w-4 text-rose-300 shrink-0" />
                              <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-rose-100 shrink-0"><Radio className="h-4 w-4 text-rose-600" /></span>
                              <div className="flex-1 min-w-0">
                                <p className="font-semibold text-sm truncate">{l.title}</p>
                                <p className="text-[11px] text-gray-400 flex items-center gap-2">
                                  {live?.scheduled_at ? (
                                    <span className="flex items-center gap-1 text-rose-600 font-medium"><CalendarClock className="h-3 w-3" />{new Date(live.scheduled_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US')}</span>
                                  ) : <span>{t('لم يحدد موعد بعد', 'No schedule set yet')}</span>}
                                </p>
                              </div>
                              <a href="/teacher/live"><Button size="sm" className="gap-1 rounded-full bg-rose-600 hover:bg-rose-700 text-white"><Radio className="h-3.5 w-3.5" />{t('إدارة الحصة', 'Manage session')}</Button></a>
                              <Button size="sm" variant="ghost" className="rounded-full text-xs text-red-500" onClick={() => removeLesson(l.id)}><Trash2 className="h-3.5 w-3.5" /></Button>
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
                        <div key={l.id} className="flex items-center gap-3 rounded-xl border border-slate-100 p-3 hover:bg-slate-50/60 transition">
                          <GripVertical className="h-4 w-4 text-gray-300 shrink-0" />
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
                          <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => moveLesson(l, -1)} disabled={li === 0}><ArrowUp className="h-3.5 w-3.5" /></Button>
                          <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => moveLesson(l, 1)} disabled={li === secLessons.length - 1}><ArrowDown className="h-3.5 w-3.5" /></Button>
                          <Button size="sm" variant="ghost" className="rounded-full text-xs text-red-500" onClick={() => removeLesson(l.id)}><Trash2 className="h-3.5 w-3.5" /></Button>
                        </div>
                      );
                    })}

                    {/* اختبارات الوحدة */}
                    <div className="space-y-3">
                    {secEx.map((e: any) => (
                      <div key={e.id} className="flex items-center gap-3 rounded-xl border border-violet-100 bg-violet-50/40 p-3 hover:bg-violet-50 transition">
                        <GripVertical className="h-4 w-4 text-violet-300 shrink-0" />
                        <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-100 shrink-0"><ClipboardList className="h-4 w-4 text-violet-600" /></span>
                        <div className="flex-1 min-w-0">
                          <p className="font-semibold text-sm truncate">{e.title}</p>
                          <p className="text-[11px] text-gray-400">
                            {examQuestions(e.id).length} {t('سؤال', 'questions')} · {e.duration_minutes} {t('دقيقة', 'min')} · {t('نجاح', 'pass')} {e.pass_mark_percent}%
                          </p>
                        </div>
                        <Badge className={e.is_published ? 'bg-green-50 text-green-600 border-0' : 'bg-gray-100 text-gray-500 border-0'}>{e.is_published ? t('منشور', 'Published') : t('مسودة', 'Draft')}</Badge>
                        <div className="flex items-center gap-1">
                          <Button size="sm" className="gap-1 rounded-full bg-violet-600 hover:bg-violet-700 text-white" onClick={() => openExamViewer(e)}><Pencil className="h-3.5 w-3.5" />{t('تحرير', 'Edit')}</Button>
                          <Button size="sm" variant="ghost" className="rounded-full text-xs text-red-500" onClick={() => removeExam(e.id)}><Trash2 className="h-3.5 w-3.5" /></Button>
                        </div>
                      </div>
                    ))}
                    </div>

                    {/* واجبات الوحدة */}
                    {secAs.map((a: any) => (
                      <div key={a.id} className="flex items-center gap-3 rounded-xl border border-amber-100 bg-amber-50/40 p-3 hover:bg-amber-50 transition">
                        <GripVertical className="h-4 w-4 text-amber-300 shrink-0" />
                        <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-100 shrink-0"><ClipboardCheck className="h-4 w-4 text-amber-600" /></span>
                        <div className="flex-1 min-w-0">
                          <p className="font-semibold text-sm truncate">{a.title}</p>
                          <p className="text-[11px] text-gray-400">{a.points} {t('نقطة', 'pts')}{a.due_at ? ` · ${t('ينتهي', 'due')} ${new Date(a.due_at).toLocaleDateString()}` : ''}</p>
                        </div>
                        <Button size="sm" variant="ghost" className="rounded-full text-xs text-teal-700" onClick={() => openAssignEdit(a)}><Pencil className="h-3.5 w-3.5" />{t('تحرير', 'Edit')}</Button>
                        <Button size="sm" variant="ghost" className="rounded-full text-xs text-red-500" onClick={() => { if (window.confirm(t('حذف الواجب؟', 'Delete assignment?'))) { fetch(`${API}/assignments/${a.id}`, { method: 'DELETE', headers: headers() }).then(() => load()); } }}><Trash2 className="h-3.5 w-3.5" /></Button>
                      </div>
                    ))}

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

      {/* ─── محرر الاختبار (إعدادات + أسئلة) ───────── */}
      <Dialog open={!!viewerExam} onOpenChange={(v) => { if (!v) setViewerExam(null); }}>
        <DialogContent className="max-w-3xl max-h-[85vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2"><ClipboardList className="h-5 w-5 text-violet-600" />{viewerExam?.title || t('محرر الاختبار', 'Exam editor')}</DialogTitle>
          </DialogHeader>

          {viewerExam && (
            <div className="space-y-4">
              {/* تبويبات داخلية */}
              <div className="flex gap-1.5 rounded-full bg-slate-100 p-1.5 w-fit">
                <button onClick={() => setExamInnerTab('questions')} className={`px-5 py-2 rounded-full text-sm font-bold transition ${examInnerTab === 'questions' ? 'bg-white text-violet-700 shadow-sm' : 'text-gray-500'}`}>{t('الأسئلة', 'Questions')} ({examQuestions(viewerExam.id).length})</button>
                <button onClick={() => setExamInnerTab('settings')} className={`px-5 py-2 rounded-full text-sm font-bold transition ${examInnerTab === 'settings' ? 'bg-white text-violet-700 shadow-sm' : 'text-gray-500'}`}>{t('الإعدادات', 'Settings')}</button>
              </div>

              {examInnerTab === 'questions' ? (
                <div className="space-y-3">
                  {examQuestions(viewerExam.id).length === 0 && (
                    <p className="text-sm text-gray-400 text-center py-4">{t('لا أسئلة بعد — أضف سؤالاً أو استورد من البنك', 'No questions yet — add one or import from the bank')}</p>
                  )}
                  {examQuestions(viewerExam.id).map((qq, qi) => (
                    <div key={qq.id} className="rounded-xl border border-slate-100 p-4 space-y-2 hover:bg-slate-50/50 transition">
                      <div className="flex items-center justify-between gap-2">
                        <p className="font-semibold text-sm"><span className="text-gray-400 me-1.5">{qi + 1}.</span>{qq.question_text}</p>
                        <div className="flex items-center gap-1 shrink-0">
                          <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => openQEdit(qq)}><Pencil className="h-3.5 w-3.5" /></Button>
                          <Button size="sm" variant="ghost" className="rounded-full text-xs text-red-500" onClick={() => removeQuestion(qq.id)}><Trash2 className="h-3.5 w-3.5" /></Button>
                        </div>
                      </div>
                      <div className="flex items-center gap-2 text-[11px] text-gray-400">
                        <span>{qTypeIcon(qq.question_type)} {qq.question_type === 'multiple_choice' ? t('اختيار متعدد', 'MCQ') : qq.question_type === 'true_false' ? t('صح / خطأ', 'True/False') : qq.question_type}</span>
                        <span>· {qq.points} {t('نقطة', 'pts')}</span>
                      </div>
                      {qq.options?.filter((o: any) => o.is_correct).map((o: any, oi: number) => (
                        <p key={oi} className="text-xs text-green-600 font-medium">✓ {o.option_text}</p>
                      ))}
                    </div>
                  ))}

                  <div className="flex gap-2 flex-wrap">
                    <Button className="gap-2 rounded-full bg-violet-600 hover:bg-violet-700 text-white" onClick={() => { setExamFilter(viewerExam.id); openQAdd(viewerExam.section_id || ''); }}>
                      <Plus className="h-4 w-4" />{t('إضافة سؤال', 'Add question')}
                    </Button>
                    <Button variant="outline" className="gap-2 rounded-full" onClick={() => openBank(viewerExam.id)}>
                      <HelpCircle className="h-4 w-4" />{t('استيراد من بنك الأسئلة', 'Import from bank')}
                    </Button>
                    <Button variant="outline" className="gap-2 rounded-full" onClick={() => toggleExamPublish(viewerExam)}>
                      {viewerExam.is_published ? <XCircle className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />}{viewerExam.is_published ? t('إلغاء النشر', 'Unpublish') : t('نشر', 'Publish')}
                    </Button>
                  </div>
                </div>
              ) : (
                <div className="space-y-4">
                  <div className="space-y-2"><Label>{t('العنوان', 'Title')}</Label><Input value={viewerExam.title} onChange={(e) => setViewerExam({ ...viewerExam, title: e.target.value })} className="h-11" /></div>
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2"><Label>{t('المدة (دقائق)', 'Duration (min)')}</Label><Input type="number" value={viewerExam.duration_minutes} onChange={(e) => setViewerExam({ ...viewerExam, duration_minutes: e.target.value })} className="h-11" /></div>
                    <div className="space-y-2"><Label>{t('درجة النجاح %', 'Pass mark %')}</Label><Input type="number" value={viewerExam.pass_mark_percent} onChange={(e) => setViewerExam({ ...viewerExam, pass_mark_percent: e.target.value })} className="h-11" /></div>
                    <div className="space-y-2"><Label>{t('عدد المحاولات', 'Max attempts')}</Label><Input type="number" value={viewerExam.max_attempts} onChange={(e) => setViewerExam({ ...viewerExam, max_attempts: e.target.value })} className="h-11" /></div>
                    <div className="space-y-2"><Label>{t('الوصف', 'Description')}</Label><Textarea value={viewerExam.description || ''} onChange={(e) => setViewerExam({ ...viewerExam, description: e.target.value })} className="min-h-[40px]" /></div>
                  </div>
                  <div className="flex items-center gap-2 p-3 rounded-xl border">
                    <Switch checked={viewerExam.shuffle_questions} onCheckedChange={(v) => setViewerExam({ ...viewerExam, shuffle_questions: v })} label={t('خلط الأسئلة', 'Shuffle questions')} />
                  </div>
                  <Button className="gap-2 rounded-full bg-violet-600 hover:bg-violet-700 text-white" onClick={async () => {
                    await fetch(`${API}/exams/${viewerExam.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ title: viewerExam.title, description: viewerExam.description, duration_minutes: Number(viewerExam.duration_minutes), pass_mark_percent: Number(viewerExam.pass_mark_percent), max_attempts: Number(viewerExam.max_attempts), shuffle_questions: viewerExam.shuffle_questions, is_published: viewerExam.is_published }) });
                    toast.success(t('تم الحفظ', 'Saved')); load();
                  }}>
                    <CheckCircle2 className="h-4 w-4" />{t('حفظ الإعدادات', 'Save settings')}
                  </Button>
                </div>
              )}
            </div>
          )}
        </DialogContent>
      </Dialog>

      {/* ─── بنك الأسئلة ─────────────────────────── */}
      <Dialog open={bankOpen} onOpenChange={setBankOpen}>
        <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto">
          <DialogHeader><DialogTitle className="flex items-center gap-2"><HelpCircle className="h-5 w-5 text-teal-600" />{t('بنك الأسئلة', 'Question bank')}</DialogTitle></DialogHeader>
          <div className="space-y-3">
            <div className="flex gap-2">
              <div className="relative flex-1">
                <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                <Input value={searchQ} onChange={(e) => setSearchQ(e.target.value)} className="ps-9 h-10" placeholder={t('بحث في الأسئلة...', 'Search questions...')} />
              </div>
              <Button className="gap-2 rounded-full" onClick={() => { setExamFilter(''); openQAdd(); }}><Plus className="h-4 w-4" />{t('سؤال جديد', 'New question')}</Button>
            </div>
            {questions.length === 0 ? (
              <p className="text-sm text-gray-400 text-center py-6">{t('لا أسئلة في البنك بعد', 'No questions in the bank yet')}</p>
            ) : (
              <div className="space-y-2">
                {questions.filter((qq) => !searchQ || qq.question_text.toLowerCase().includes(searchQ.toLowerCase())).map((qq) => {
                  const used = examQuestions(bankForExam).some((x: any) => x.id === qq.id);
                  return (
                    <div key={qq.id} className="flex items-center gap-3 rounded-xl border border-slate-100 p-3">
                      <span>{qTypeIcon(qq.question_type)}</span>
                      <p className="flex-1 text-sm font-medium truncate">{qq.question_text}</p>
                      {bankForExam ? (
                        used ? <Badge className="bg-green-50 text-green-600 border-0">{t('مضاف', 'Added')}</Badge> :
                        <Button size="sm" className="gap-1 rounded-full bg-teal-600 hover:bg-teal-700 text-white" onClick={() => addQuestionToExam(qq)}><Plus className="h-3.5 w-3.5" />{t('إضافة للاختبار', 'Add to exam')}</Button>
                      ) : (
                        <Button size="sm" variant="ghost" className="rounded-full text-xs" onClick={() => openQEdit(qq)}><Pencil className="h-3.5 w-3.5" /></Button>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        </DialogContent>
      </Dialog>

      {/* ─── حوار الوحدة ─────────────────────────── */}
      <Dialog open={secOpen} onOpenChange={setSecOpen}>
        <DialogContent><DialogHeader><DialogTitle>{secTarget ? t('تعديل الوحدة', 'Edit unit') : t('وحدة جديدة', 'New unit')}</DialogTitle></DialogHeader>
          <div className="space-y-3"><Label>{t('اسم الوحدة', 'Unit title')} *</Label><Input value={secTitle} onChange={(e) => setSecTitle(e.target.value)} className="h-11" /></div>
          <DialogFooter><Button variant="outline" onClick={() => setSecOpen(false)}>{t('إلغاء', 'Cancel')}</Button><Button onClick={saveSection}>{t('حفظ', 'Save')}</Button></DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ─── حوار الدرس ─────────────────────────── */}
      <Dialog open={lesOpen} onOpenChange={(v) => { setLesOpen(v); if (!v) { setLesTarget(null); setLesSection(''); setLes({ ...EMPTY_LESSON }); setLesContents([]); resetContentForm(); } }} className="max-w-2xl">
        <DialogContent><DialogHeader><DialogTitle>{lesTarget ? t('تعديل الدرس', 'Edit lesson') : t('درس جديد', 'New lesson')}</DialogTitle></DialogHeader>
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
                  <button type="button" onClick={() => { setLesOpen(false); setLes({ ...EMPTY_LESSON }); setLesSection(lesSection); createLiveForLesson(); }}
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
                    <Button variant="ghost" size="icon" className="h-7 w-7 text-red-500" onClick={() => removeContent(c.id)}><Trash2 className="h-3.5 w-3.5" /></Button>
                  </div>
                ))}
                <div className="flex flex-wrap gap-2">
                  <Button variant="outline" size="sm" className="gap-1.5 rounded-full text-xs" onClick={() => videoInputRef.current?.click()} disabled={videoUploading}>
                    {videoUploading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <UploadCloud className="h-3.5 w-3.5" />}{t('رفع فيديو', 'Upload video')}
                  </Button>
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
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => { setLesOpen(false); setLesTarget(null); setLesSection(''); setLes({ ...EMPTY_LESSON }); setLesContents([]); resetContentForm(); }}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={saveLesson}>{t('حفظ', 'Save')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ─── حوار السؤال ─────────────────────────── */}
      <Dialog open={qOpen} onOpenChange={(v) => { setQOpen(v); if (!v) { setQTarget(null); setQ({ ...EMPTY_QUESTION, exam_id: examFilter }); } }}>
        <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto">
          <DialogHeader><DialogTitle>{qTarget ? t('تعديل السؤال', 'Edit question') : t('سؤال جديد', 'New question')}</DialogTitle></DialogHeader>
          <QuestionBuilder
            value={q}
            onChange={setQ}
            sections={sections.map((s) => ({ id: s.id, title: s.title }))}
            exams={exams.map((e) => ({ id: e.id, title: e.title }))}
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => { setQOpen(false); setQTarget(null); setQ({ ...EMPTY_QUESTION, exam_id: examFilter }); }}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={saveQuestion}>{t('حفظ السؤال', 'Save question')}</Button>
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
            <Button onClick={async () => {
              if (!assign.title.trim()) { toast.error(t('أدخل العنوان', 'Enter title')); return; }
              const body = { ...assign, section_id: assignTarget ? assignTarget.section_id : lesSection || undefined };
              const res = await fetch(`${API}/assignments${assignTarget ? `/${assignTarget.id}` : ''}`, { method: assignTarget ? 'PUT' : 'POST', headers: headers(), body: JSON.stringify(body) });
              if (!res.ok) { toast.error('Failed'); return; }
              toast.success(t('تم الحفظ', 'Saved')); setAssignOpen(false); setAssignTarget(null); setAssign({ ...EMPTY_ASSIGN }); load();
            }}>{t('حفظ', 'Save')}</Button>
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
    </div>
  );
}
