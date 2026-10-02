'use client';

import { useEffect, useRef, useState } from 'react';
import { use } from 'react';
import { useLocale } from '@/store/locale';
import { useFocus } from '@/store/focus';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ProtectedVideoPlayer from '@/components/video/protected-video-player';
import { Play, FileText, Lock, ChevronDown, ChevronUp, Loader2, ArrowLeft, ArrowRight, BookOpen, Clock, ShieldCheck, Download, Eye, Ban, ClipboardCheck, CalendarClock, Star, Send, Upload, CheckCircle2, XCircle, ListChecks, Search, ClipboardList, Maximize2, Minimize2, Focus, Radio } from 'lucide-react';
import Link from 'next/link';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

function LiveLessonView({ live }: { live: any }) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  if (live.status === 'scheduled') {
    return (
      <div className="aspect-video bg-slate-900 rounded-2xl flex items-center justify-center">
        <div className="text-center text-white/70 space-y-2">
          <Radio className="h-12 w-12 mx-auto text-rose-400" />
          <p>{t('البث لم يبدأ بعد', 'The live stream has not started yet.')}</p>
          <p className="text-xs text-white/40">{live.title}</p>
          {live.scheduled_at && (
            <p className="text-sm font-semibold text-rose-300 flex items-center gap-1.5">
              <CalendarClock className="h-4 w-4" />
              {t('موعد البث:', 'Live at:')} {new Date(live.scheduled_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', { dateStyle: 'full', timeStyle: 'short' })}
            </p>
          )}
          {live.meeting_url && (
            <a href={live.meeting_url} target="_blank" rel="noopener noreferrer"
              className="inline-flex items-center gap-2 rounded-full bg-teal-600 hover:bg-teal-500 text-white text-sm font-bold px-6 py-2.5 transition mt-2">
              <Radio className="h-4 w-4" />{t('انضمام إلى الاجتماع', 'Join Meeting')}
            </a>
          )}
        </div>
      </div>
    );
  }

  if (live.status === 'live') {
    if (!live.meeting_url) {
      return (
        <div className="aspect-video bg-slate-900 rounded-2xl flex items-center justify-center">
          <p className="text-white/60 text-sm">{t('رابط الاجتماع غير متاح بعد', 'Meeting link is not available yet')}</p>
        </div>
      );
    }
    return (
      <div className="aspect-video bg-slate-900 rounded-2xl flex flex-col items-center justify-center gap-4">
        <div className="flex h-16 w-16 items-center justify-center rounded-full bg-teal-500/15">
          <Radio className="h-8 w-8 text-teal-400" />
        </div>
        <p className="text-white font-semibold">{t('الاجتماع جاهز', 'The meeting is ready')}</p>
        <a href={live.meeting_url} target="_blank" rel="noopener noreferrer"
          className="inline-flex items-center gap-2 rounded-full bg-teal-600 hover:bg-teal-500 text-white text-sm font-bold px-7 py-2.5 transition">
          <Radio className="h-4 w-4" />{t('انضمام إلى الاجتماع', 'Join Meeting')}
        </a>
      </div>
    );
  }

  return (
    <div className="aspect-video bg-slate-900 rounded-2xl flex items-center justify-center">
      <div className="text-center text-white/70 space-y-2">
        <Radio className="h-12 w-12 mx-auto text-slate-500" />
        <p>{t('انتهى البث', 'The live stream has ended.')}</p>
        {live.record_url && (
          <a href={live.record_url} target="_blank" rel="noreferrer" className="inline-block mt-2 text-rose-400 hover:underline">
            {t('شاهد التسجيل', 'Watch the recording')}
          </a>
        )}
      </div>
    </div>
  );
}


export default function LearnPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { locale } = useLocale();
  const { focused, setFocus } = useFocus();
  const [data, setData] = useState<any>(null);
  const [active, setActive] = useState<any>(null);
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [loading, setLoading] = useState(true);
  const [content, setContent] = useState<any>(null);
  const [contentLoading, setContentLoading] = useState(false);
  const [contentError, setContentError] = useState<string | null>(null);
  const [fileBusy, setFileBusy] = useState(false);
  const [viewTab, setViewTab] = useState<'lessons' | 'assignments' | 'exams'>('lessons');
  const [exams, setExams] = useState<any[]>([]);
  const [examsLoading, setExamsLoading] = useState(false);
  const [assignments, setAssignments] = useState<any[]>([]);
  const [assignLoading, setAssignLoading] = useState(false);
  const [acknowledged, setAcknowledged] = useState(true);
  const [searchQ, setSearchQ] = useState('');
  const [searchResults, setSearchResults] = useState<{ lessons: any[]; sections: any[] }>({ lessons: [], sections: [] });
  const [searching, setSearching] = useState(false);
  const [submitText, setSubmitText] = useState<Record<string, string>>({});
  const [submitFile, setSubmitFile] = useState<Record<string, { url: string; name: string }>>({});
  const [submittingId, setSubmittingId] = useState<string | null>(null);
  const [uploadingId, setUploadingId] = useState<string | null>(null);
  const [submitFileFor, setSubmitFileFor] = useState<string | null>(null);
  const submitFileInputRef = useRef<HTMLInputElement>(null);
  const [commentVals, setCommentVals] = useState<Record<string, string>>({});
  const [commentingId, setCommentingId] = useState<string | null>(null);
  const contentRef = useRef<HTMLDivElement | null>(null);
  const [contentFs, setContentFs] = useState(false);
  const [pageBlurred, setPageBlurred] = useState(false);

  /* إخفاء المحتوى التعليمي عند مغادرة الصفحة (نص وفيديو) */
  useEffect(() => {
    const hide = () => setPageBlurred(true);
    const show = () => setPageBlurred(false);
    const onVisibility = () => { if (document.hidden) hide(); else show(); };
    const onBlur = () => hide();
    const onFocus = () => show();
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('blur', onBlur);
    window.addEventListener('focus', onFocus);
    return () => {
      document.removeEventListener('visibilitychange', onVisibility);
      window.removeEventListener('blur', onBlur);
      window.removeEventListener('focus', onFocus);
    };
  }, []);

  /* ملء الشاشة الحقيقي للمحتوى (فيديو أو نص) */
  useEffect(() => {
    const onFs = () => setContentFs(!!document.fullscreenElement);
    document.addEventListener('fullscreenchange', onFs);
    return () => document.removeEventListener('fullscreenchange', onFs);
  }, []);

  const toggleContentFullscreen = () => {
    if (document.fullscreenElement) {
      document.exitFullscreen().catch(() => {});
    } else {
      contentRef.current?.requestFullscreen().catch(() => {});
    }
  };

  const authHeaders = (): any => {
    const headers: any = { Accept: 'application/json' };
    const tid = localStorage.getItem('tenant_id');
    if (tid) headers['X-Tenant-Id'] = tid;
    return headers;
  };

  const loadAssignments = () => {
    setAssignLoading(true);
    fetch(`${API}/assignments?course_id=${id}`, { headers: authHeaders() })
      .then(r => r.json()).then(d => setAssignments(d.assignments || [])).catch(() => {})
      .finally(() => setAssignLoading(false));
  };
  const loadExams = () => {
    setExamsLoading(true);
    fetch(`${API}/exams?course_id=${id}`, { headers: authHeaders() })
      .then(r => r.json()).then(d => setExams(d.exams || [])).catch(() => {})
      .finally(() => setExamsLoading(false));
  };
  useEffect(() => { if (viewTab === 'assignments' && assignments.length === 0) loadAssignments(); }, [viewTab]);
  useEffect(() => { if (viewTab === 'exams' && exams.length === 0) loadExams(); }, [viewTab]);

  useEffect(() => {
    fetch(`${API}/public/courses/${id}/sections`, { headers: authHeaders() })
      .then(r => r.json()).then(d => {
        setData(d);
        setAcknowledged(!d.course?.prerequisites || localStorage.getItem(`lms_prereq_ack_${id}`) === '1');
        const firstFree = d.sections?.flatMap((s: any) => s.lessons).find((l: any) => !l.locked);
        if (firstFree) setActive(firstFree);
        setExpanded(new Set((d.sections || []).slice(0, 1).map((s: any) => s.id)));
      }).catch(() => {}).finally(() => setLoading(false));
  }, [id]);

  useEffect(() => {
    if (!active || active.locked) {
      setContent(null);
      setContentError(null);
      return;
    }
    setContentLoading(true);
    setContentError(null);
    fetch(`${API}/public/courses/${id}/lessons/${active.id}`, { headers: authHeaders() })
      .then(r => r.json())
      .then(d => {
        if (d.unlock_at) {
          setContentError('locked');
        } else {
          setContent(d);
        }
      })
      .catch(() => setContentError('error'))
      .finally(() => setContentLoading(false));
  }, [id, active?.id]);

  if (loading) return <div className="flex items-center justify-center h-screen"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>;
  if (!data) return <div className="flex items-center justify-center h-screen text-gray-400">{locale === 'ar' ? 'غير موجود' : 'Not found'}</div>;

  const isVideo = (l: any) => l.lesson_type === 'video';

  const formatCountdown = (iso: string) => {
    const target = new Date(iso).getTime();
    const diff = Math.max(0, target - Date.now());
    const days = Math.floor(diff / 86400000);
    const hours = Math.floor((diff % 86400000) / 3600000);
    const mins = Math.floor((diff % 3600000) / 60000);
    if (days > 0) return `${days} ${locale === 'ar' ? 'يوم' : 'd'} ${hours} ${locale === 'ar' ? 'ساعة' : 'h'}`;
    if (hours > 0) return `${hours} ${locale === 'ar' ? 'ساعة' : 'h'} ${mins} ${locale === 'ar' ? 'دقيقة' : 'm'}`;
    return `${Math.max(1, mins)} ${locale === 'ar' ? 'دقيقة' : 'min'}`;
  };
  const toggle = (sid: string) => { const n = new Set(expanded); n.has(sid) ? n.delete(sid) : n.add(sid); setExpanded(n); };

  // ─── Search inside course ──────────────────────
  const searchCourse = async (q: string) => {
    setSearchQ(q);
    if (q.trim().length < 2) { setSearchResults({ lessons: [], sections: [] }); return; }
    setSearching(true);
    try {
      const res = await fetch(`${API}/courses/${id}/search?q=${encodeURIComponent(q.trim())}`, { headers: authHeaders() });
      if (!res.ok) throw new Error();
      const d = await res.json();
      setSearchResults(d.results || { lessons: [], sections: [] });
    } catch { setSearchResults({ lessons: [], sections: [] }); }
    setSearching(false);
  };
  const pickResult = (l: any) => {
    setSearchQ(''); setSearchResults({ lessons: [], sections: [] });
    if (l.locked) return;
    const sec = (data?.sections || []).find((s: any) => s.id === l.section_id);
    if (sec) {
      setExpanded((prev) => { const n = new Set(prev); n.add(sec.id); return n; });
      const found = (sec.lessons || []).find((x: any) => x.id === l.id);
      setActive(found || l);
    }
  };

  const videoContent = content?.contents?.find((c: any) => c.content_type === 'video');
  const liveContent = content?.contents?.find((c: any) => c.content_type === 'live');
  const textContent = content?.contents?.find((c: any) => c.content_type === 'text');
  const fileContent = content?.contents?.find((c: any) => c.content_type === 'file');

  const openFile = async (mode: 'download' | 'preview') => {
    if (!fileContent?.file?.url || fileBusy) return;
    setFileBusy(true);
    try {
      const res = await fetch(`${API}${fileContent.file.url}`, { headers: authHeaders() });
      const d = await res.json().catch(() => null);
      if (!res.ok || !d?.url) {
        toast.error(locale === 'ar' ? 'لا يمكن الوصول إلى الملف' : 'Cannot access the file');
        return;
      }
      if (mode === 'download') {
        window.location.href = d.url;
      } else {
        window.open(d.url, '_blank');
      }
    } catch {
      toast.error(locale === 'ar' ? 'فشل تحميل الملف' : 'Failed');
    }
    setFileBusy(false);
  };

  // ─── Assignments (student) ─────────────────────
  const uploadSubmitFile = async (aid: string, file: File) => {
    setUploadingId(aid);
    try {
      const fd = new FormData();
      fd.append('file', file);
      const res = await fetch(`${API}/assignment-upload`, { method: 'POST', headers: { 'X-Tenant-Id': localStorage.getItem('tenant_id') || '' }, body: fd });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Upload failed'); return; }
      const d = await res.json();
      setSubmitFile((s) => ({ ...s, [aid]: { url: d.url, name: d.name } }));
    } catch { toast.error('Upload failed'); }
    setUploadingId(null);
  };
  const submitAssignment = async (a: any) => {
    const s = submitText[a.id]?.trim();
    const f = submitFile[a.id];
    if (!s && !f) { toast.error(locale === 'ar' ? 'أدخل نصاً أو ارفع ملفاً' : 'Enter text or upload a file'); return; }
    setSubmittingId(a.id);
    try {
      const res = await fetch(`${API}/assignments/${a.id}/submit`, {
        method: 'POST', headers: authHeaders(), body: JSON.stringify({
          submission_text: s || '', file_url: f?.url || null, file_name: f?.name || null,
        }),
      });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(locale === 'ar' ? 'تم تسليم الواجب' : 'Assignment submitted');
      setSubmitText((x) => ({ ...x, [a.id]: '' })); setSubmitFile((x) => ({ ...x, [a.id]: undefined }));
      loadAssignments();
    } catch { toast.error('Failed'); }
    setSubmittingId(null);
  };
  const addComment = async (a: any) => {
    const my = a.my_submission;
    if (!my?.id) return;
    const text = commentVals[a.id]?.trim();
    if (!text) return;
    setCommentingId(a.id);
    try {
      const res = await fetch(`${API}/submissions/${my.id}/comments`, { method: 'POST', headers: authHeaders(), body: JSON.stringify({ comment: text }) });
      if (!res.ok) throw new Error();
      setCommentVals((c) => ({ ...c, [a.id]: '' }));
      loadAssignments();
    } catch { toast.error('Failed'); }
    setCommentingId(null);
  };
  const fmtDue = (iso: string | null) => {
    if (!iso) return locale === 'ar' ? 'بدون موعد' : 'No deadline';
    const d = new Date(iso);
    const past = d.getTime() < Date.now();
    const s = d.toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    return past ? `${s} (${locale === 'ar' ? 'انتهى' : 'past'})` : s;
  };

  return (
    <div className="flex flex-col lg:flex-row h-[calc(100dvh-4rem)] max-lg:h-[calc(100dvh-8.5rem)] -m-4 sm:-m-6 lg:-m-8">
      <div className="order-2 lg:order-1 w-full lg:w-80 xl:w-96 border-t lg:border-t-0 lg:border-e bg-card overflow-y-auto shrink-0 max-h-[45vh] lg:max-h-none">
        <div className="p-4 border-b"><h2 className="font-semibold">{data.course?.title || ''}</h2><p className="text-sm text-gray-500">{data.sections?.reduce((a: number, s: any) => a + s.lessons.length, 0) || 0} {locale === 'ar' ? 'درس' : 'lessons'}</p></div>
        <div className="p-3 border-b">
          <div className="relative">
            <Search className="h-4 w-4 text-gray-400 absolute start-3 top-1/2 -translate-y-1/2" />
            <input value={searchQ} onChange={(e) => searchCourse(e.target.value)} placeholder={locale === 'ar' ? 'ابحث داخل الكورس...' : 'Search inside the course...'} className="w-full h-9 ps-9 pe-8 text-sm border border-gray-200 rounded-full focus:outline-none focus:ring-2 focus:ring-teal-600" />
            {searching && <Loader2 className="h-4 w-4 animate-spin text-teal-600 absolute end-3 top-1/2 -translate-y-1/2" />}
          </div>
          {searchQ.trim().length >= 2 && (
            <div className="mt-2 max-h-64 overflow-y-auto rounded-xl border bg-card shadow-sm">
              {searchResults.lessons.length === 0 && searchResults.sections.length === 0 && !searching ? (
                <p className="text-center text-xs text-gray-400 py-4">{locale === 'ar' ? 'لا نتائج' : 'No results'}</p>
              ) : (
                <div className="p-1 space-y-0.5">
                  {searchResults.sections.map((s: any) => (
                    <button key={s.id} onClick={() => { const sec = (data?.sections || []).find((x: any) => x.id === s.id); if (sec) { setExpanded((prev) => { const n = new Set(prev); n.add(sec.id); return n; }); setSearchQ(''); setSearchResults({ lessons: [], sections: [] }); } }} className="w-full text-start flex items-center gap-2 px-2.5 py-2 rounded-lg hover:bg-muted/50 text-xs">
                      <ListChecks className="h-3.5 w-3.5 text-violet-500 shrink-0" />
                      <span className="font-medium truncate">{s.title}</span>
                    </button>
                  ))}
                  {searchResults.lessons.map((l: any) => (
                    <button key={l.id} onClick={() => pickResult(l)} disabled={l.locked} className={`w-full text-start flex items-start gap-2 px-2.5 py-2 rounded-lg hover:bg-muted/50 text-xs ${l.locked ? 'opacity-50 cursor-not-allowed' : ''}`}>
                      {l.locked ? <Lock className="h-3.5 w-3.5 text-gray-400 shrink-0 mt-0.5" /> : <Play className="h-3.5 w-3.5 text-teal-600 shrink-0 mt-0.5" />}
                      <div className="flex-1 min-w-0">
                        <p className="font-medium truncate">{l.title}</p>
                        <p className="text-[10px] text-gray-400 truncate">{l.section_title}</p>
                        {!l.locked && l.snippets?.slice(0, 1).map((sn: any, i: number) => (
                          <p key={i} className="text-[11px] text-gray-500 line-clamp-2 mt-0.5">{sn.text}</p>
                        ))}
                      </div>
                    </button>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
        {(data.sections || []).map((s: any) => (
          <div key={s.id}>
            <button onClick={() => toggle(s.id)} className="w-full p-4 bg-muted/30 flex items-center justify-between">
              <span className="text-sm font-medium">{s.title}</span>{expanded.has(s.id) ? <ChevronUp className="h-4 w-4 text-gray-400" /> : <ChevronDown className="h-4 w-4 text-gray-400" />}
            </button>
            {expanded.has(s.id) && (s.lessons || []).map((l: any) => (
              <button key={l.id} onClick={() => setActive(l)} className={`flex items-center gap-3 px-4 py-3 w-full text-start hover:bg-muted/50 ${active?.id === l.id ? 'bg-teal-50 border-s-2 border-s-teal-500' : 'border-s-2 border-s-transparent'}`}>
                {l.locked ? <Lock className="h-4 w-4 text-gray-400 shrink-0" /> : isVideo(l) ? <Play className="h-4 w-4 text-teal-600 shrink-0" /> : <FileText className="h-4 w-4 text-green-500 shrink-0" />}
                <div className="flex-1"><p className="text-sm">{l.title}</p><p className="text-xs text-gray-500">{l.duration_minutes || 0} min{l.locked && l.unlock_at && <span className="text-amber-600 ms-1"> · {formatCountdown(l.unlock_at)}</span>}</p></div>
              </button>
            ))}
          </div>
        ))}
      </div>
      <div className="order-1 lg:order-2 flex-1 flex flex-col min-w-0">
        <div className="flex flex-wrap items-center gap-3 p-4 border-b">
          <a href="/my-learning" className="flex items-center gap-1 text-sm text-gray-500 hover:text-gray-800 shrink-0">{locale === 'ar' ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />} {locale === 'ar' ? 'رجوع' : 'Back'}</a>
          <div className="flex gap-1 p-1 bg-muted/50 rounded-full overflow-x-auto max-w-full">
            <button onClick={() => setViewTab('lessons')} className={`px-4 py-1.5 rounded-full text-xs font-medium transition-all shrink-0 ${viewTab === 'lessons' ? 'bg-teal-600 text-white shadow' : 'text-gray-500 hover:text-gray-800'}`}>{locale === 'ar' ? 'الدروس' : 'Lessons'}</button>
            <button onClick={() => setViewTab('exams')} className={`flex items-center gap-1.5 px-4 py-1.5 rounded-full text-xs font-medium transition-all shrink-0 ${viewTab === 'exams' ? 'bg-teal-600 text-white shadow' : 'text-gray-500 hover:text-gray-800'}`}>
              <ClipboardList className="h-3.5 w-3.5" />{locale === 'ar' ? 'الاختبارات' : 'Exams'}
            </button>
            <button onClick={() => setViewTab('assignments')} className={`flex items-center gap-1.5 px-4 py-1.5 rounded-full text-xs font-medium transition-all shrink-0 ${viewTab === 'assignments' ? 'bg-teal-600 text-white shadow' : 'text-gray-500 hover:text-gray-800'}`}>
              <ClipboardCheck className="h-3.5 w-3.5" />{locale === 'ar' ? 'الواجبات' : 'Assignments'}{assignments.length > 0 && <span className="text-[10px]">{assignments.length}</span>}
            </button>
          </div>
          <div className="flex-1" />
          {videoContent?.video?.type === 'stream' && (
            <span className="flex items-center gap-1 text-xs text-green-600"><ShieldCheck className="h-4 w-4" /> {locale === 'ar' ? 'فيديو محمي' : 'Protected video'}</span>
          )}
          <Badge variant="outline" className="text-xs">{data.is_enrolled ? (locale === 'ar' ? 'مسجل' : 'Enrolled') : (locale === 'ar' ? 'غير مسجل' : 'Guest')}</Badge>
          <button
            onClick={toggleContentFullscreen}
            className="flex h-9 w-9 items-center justify-center rounded-xl border text-muted-foreground transition-colors hover:bg-muted shrink-0"
            title={contentFs ? (locale === 'ar' ? 'خروج من ملء الشاشة' : 'Exit fullscreen') : (locale === 'ar' ? 'ملء الشاشة' : 'Fullscreen')}
            aria-label={contentFs ? (locale === 'ar' ? 'خروج من ملء الشاشة' : 'Exit fullscreen') : (locale === 'ar' ? 'ملء الشاشة' : 'Fullscreen')}
          >
            {contentFs ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}
          </button>
          <button
            onClick={() => setFocus(!focused)}
            className="flex h-9 w-9 items-center justify-center rounded-xl border text-muted-foreground transition-colors hover:bg-muted shrink-0"
            title={focused ? (locale === 'ar' ? 'خروج من وضع التركيز' : 'Exit focus mode') : (locale === 'ar' ? 'وضع التركيز' : 'Focus mode')}
            aria-label={focused ? (locale === 'ar' ? 'خروج من وضع التركيز' : 'Exit focus mode') : (locale === 'ar' ? 'وضع التركيز' : 'Focus mode')}
          >
            <Focus className="h-4 w-4" />
          </button>
        </div>
        <div className="flex-1 overflow-y-auto p-6 sm:p-10 bg-gradient-to-b from-muted/30 to-background">
          {!acknowledged ? (
            <div className="max-w-2xl mx-auto space-y-6">
              <div className="bg-white border rounded-2xl p-6 sm:p-8 shadow-sm">
                <div className="flex items-center gap-3 mb-4">
                  <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 shrink-0"><ListChecks className="h-6 w-6 text-amber-600" /></div>
                  <div>
                    <h2 className="text-xl font-bold">{locale === 'ar' ? 'متطلبات قبل بدء الكورس' : 'Course prerequisites'}</h2>
                    <p className="text-sm text-gray-500">{locale === 'ar' ? 'تأكد من توفر التالي قبل البدء' : 'Make sure you have the following before you start'}</p>
                  </div>
                </div>
                <ul className="space-y-3">
                  {String(data.course?.prerequisites || '').split('\n').filter((p: string) => p.trim()).map((p: string, i: number) => (
                    <li key={i} className="flex items-start gap-3 text-sm">
                      <CheckCircle2 className="h-4 w-4 text-green-500 mt-0.5 shrink-0" />
                      <span className="text-gray-700 leading-relaxed">{p}</span>
                    </li>
                  ))}
                </ul>
                <button
                  onClick={() => { localStorage.setItem(`lms_prereq_ack_${id}`, '1'); setAcknowledged(true); }}
                  className="mt-8 w-full h-12 rounded-full font-medium text-white transition-all hover:opacity-90"
                  style={{ background: '#0d9488' }}
                >
                  {locale === 'ar' ? 'أوافق على المتطلبات وأبدأ الكورس' : 'I meet the requirements — start the course'}
                </button>
              </div>
            </div>
          ) : viewTab === 'exams' ? (
            <div className="max-w-3xl mx-auto space-y-5">
              <div>
                <h2 className="text-2xl font-bold flex items-center gap-2"><ClipboardList className="h-6 w-6 text-teal-700" />{locale === 'ar' ? 'اختبارات الكورس' : 'Course exams'}</h2>
                <p className="text-gray-500 text-sm mt-1">{locale === 'ar' ? 'أدِّ اختباراتك وتابع نتيجتك مباشرة' : 'Take your exams and see your result instantly'}</p>
              </div>
              {examsLoading ? <div className="flex justify-center py-12"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div> :
                exams.length === 0 ? (
                  <div className="bg-white border rounded-2xl p-10 text-center text-gray-400"><ClipboardList className="h-12 w-12 mx-auto mb-4 opacity-40" /><p>{locale === 'ar' ? 'لا توجد اختبارات في هذا الكورس' : 'No exams in this course'}</p></div>
                ) : (
                  <div className="space-y-3">
                    {exams.map((ex) => {
                      const done = (ex.attempts_count || 0) > 0;
                      return (
                        <div key={ex.id} className="bg-white border rounded-2xl p-5 flex flex-wrap items-center gap-4">
                          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-50 shrink-0"><ClipboardList className="h-5 w-5 text-teal-700" /></div>
                          <div className="flex-1 min-w-[180px]">
                            <p className="font-semibold">{ex.title}</p>
                            <p className="text-xs text-gray-500 mt-0.5">
                              {ex.questions_count ?? 0} {locale === 'ar' ? 'سؤال' : 'questions'}
                              {ex.duration_minutes ? ` · ${ex.duration_minutes} ${locale === 'ar' ? 'دقيقة' : 'min'}` : ''}
                              {ex.pass_mark_percent ? ` · ${locale === 'ar' ? 'نجاح' : 'pass'} ${ex.pass_mark_percent}%` : ''}
                              {ex.attempts_count > 0 ? ` · ${locale === 'ar' ? 'محاولاتك' : 'attempts'}: ${ex.attempts_count}` : ''}
                            </p>
                          </div>
                          <Link href={`/exam/${ex.id}`}>
                            <Button className="rounded-full text-white shrink-0" style={{ background: '#0d9488' }}>
                              {done ? (locale === 'ar' ? 'إعادة الاختبار' : 'Retake') : (locale === 'ar' ? 'ابدأ الاختبار' : 'Start exam')}
                            </Button>
                          </Link>
                        </div>
                      );
                    })}
                  </div>
                )}
            </div>
          ) : viewTab === 'assignments' ? (
            <div className="max-w-3xl mx-auto space-y-5">
              <div>
                <h2 className="text-2xl font-bold flex items-center gap-2"><ClipboardCheck className="h-6 w-6 text-amber-500" />{locale === 'ar' ? 'واجبات الكورس' : 'Course assignments'}</h2>
                <p className="text-gray-500 text-sm mt-1">{locale === 'ar' ? 'سلّم واجباتك قبل الموعد وراجع تقييم معلمك وتعليقاته' : 'Submit before the deadline and review your teacher\'s feedback'}</p>
              </div>
              {assignLoading ? <div className="flex justify-center py-12"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div> :
                assignments.length === 0 ? (
                  <div className="bg-white border rounded-2xl p-10 text-center text-gray-400"><ClipboardCheck className="h-12 w-12 mx-auto mb-4 opacity-40" /><p>{locale === 'ar' ? 'لا توجد واجبات' : 'No assignments'}</p></div>
                ) : assignments.map((a) => {
                  const my = a.my_submission;
                  const isLate = my?.status === 'late';
                  const duePast = a.due_at && new Date(a.due_at).getTime() < Date.now();
                  const canSubmit = a.is_published && (!duePast || a.allow_late);
                  return (
                    <div key={a.id} className="bg-white border rounded-2xl p-6 space-y-4">
                      <div className="flex flex-wrap items-start gap-3">
                        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-50 shrink-0"><ClipboardCheck className="h-5 w-5 text-amber-600" /></div>
                        <div className="flex-1 min-w-[200px]">
                          <h3 className="font-semibold text-lg">{a.title}</h3>
                          <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1.5 text-xs text-gray-500">
                            <span className="flex items-center gap-1"><CalendarClock className="h-3.5 w-3.5" />{locale === 'ar' ? 'موعد التسليم:' : 'Due:'} <b>{fmtDue(a.due_at)}</b>{a.allow_late && <span className="text-amber-600"> · {locale === 'ar' ? 'يسمح بالتأخير' : 'late allowed'}</span>}</span>
                            <span className="flex items-center gap-1"><Star className="h-3.5 w-3.5 text-amber-500" />{a.points} {locale === 'ar' ? 'درجة' : 'pts'}</span>
                          </div>
                          {a.description && <p className="text-sm text-gray-600 mt-2 whitespace-pre-wrap">{a.description}</p>}
                          {a.file_name && <a href={a.file_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 mt-2 text-xs text-teal-700 bg-teal-50 rounded-full px-3 py-1.5 hover:bg-teal-100"><FileText className="h-3.5 w-3.5" />{a.file_name}</a>}
                        </div>
                        <div className="flex flex-col items-end gap-1.5">
                          {my ? (
                            <>
                              <Badge className={isLate ? 'bg-amber-100 text-amber-700 border-0' : my.status === 'graded' ? 'bg-green-100 text-green-700 border-0' : 'bg-teal-100 text-teal-700 border-0'}>
                                {my.status === 'graded' ? (locale === 'ar' ? 'مصحح' : 'Graded') : isLate ? (locale === 'ar' ? 'متأخر' : 'Late') : (locale === 'ar' ? 'تم التسليم' : 'Submitted')}
                              </Badge>
                              {my.grade !== null && <span className="text-sm font-bold text-green-600">{my.grade} / {a.points}</span>}
                            </>
                          ) : <Badge variant="secondary">{locale === 'ar' ? 'لم تُسلم' : 'Not submitted'}</Badge>}
                        </div>
                      </div>

                      {my ? (
                        <div className="space-y-3 border-t pt-4">
                          {my.submission_text && <div className="bg-gray-50 rounded-xl p-3 text-sm whitespace-pre-wrap">{my.submission_text}</div>}
                          {my.file_name && <a href={my.file_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-xs text-teal-700 bg-teal-50 rounded-full px-3 py-1.5 hover:bg-teal-100"><FileText className="h-3.5 w-3.5" />{my.file_name}</a>}
                          {my.grade_comment && <p className="text-sm bg-green-50 border border-green-100 rounded-xl p-3"><b className="text-green-700">{locale === 'ar' ? 'ملاحظة المعلم:' : 'Teacher note:'}</b> <span className="text-gray-700">{my.grade_comment}</span></p>}
                          <div className="space-y-2">
                            {(my.comments || []).map((c: any) => (
                              <div key={c.id} className="flex items-start gap-2.5">
                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-gray-100 text-[11px] font-bold text-gray-500 shrink-0">{String(c.user_name || '؟').slice(0, 1)}</div>
                                <div className="bg-muted/50 rounded-xl rounded-tr-sm px-3 py-2 text-sm max-w-[85%]"><p className="text-[10px] text-gray-400">{c.user_name} · {new Date(c.created_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US')}</p><p className="text-gray-700 mt-0.5">{c.comment}</p></div>
                              </div>
                            ))}
                            <div className="flex items-center gap-2 pt-1">
                              <input value={commentVals[a.id] || ''} onChange={(e) => setCommentVals((c) => ({ ...c, [a.id]: e.target.value }))} placeholder={locale === 'ar' ? 'اسأل معلمك أو أضف تعليقاً...' : 'Ask your teacher or comment...'} className="flex-1 h-10 px-3 rounded-full border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-teal-600" />
                              <Button size="icon" variant="outline" className="h-10 w-10 rounded-full text-teal-700" onClick={() => addComment(a)} disabled={commentingId === a.id}>{commentingId === a.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}</Button>
                            </div>
                          </div>
                          {canSubmit && <p className="text-xs text-gray-400">{locale === 'ar' ? 'يمكنك إعادة التسليم — التقييم السابق يُعاد' : 'You can resubmit — the previous grade is reset'}</p>}
                        </div>
                      ) : (
                        <div className="space-y-3 border-t pt-4">
                          {canSubmit ? (
                            <>
                              <textarea value={submitText[a.id] || ''} onChange={(e) => setSubmitText((s) => ({ ...s, [a.id]: e.target.value }))} rows={3} placeholder={locale === 'ar' ? 'اكتب إجابتك هنا...' : 'Write your answer here...'} className="w-full border border-gray-200 rounded-xl p-3 text-sm focus:outline-none focus:ring-2 focus:ring-teal-600" />
                              <div className="flex flex-wrap items-center gap-2">
                                <Button variant="outline" size="sm" className="gap-1.5 rounded-full text-xs" onClick={() => { setSubmitFileFor(a.id); submitFileInputRef.current?.click(); }} disabled={uploadingId === a.id}>{uploadingId === a.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Upload className="h-3.5 w-3.5" />}{locale === 'ar' ? 'رفع ملف' : 'Upload file'}</Button>
                                {submitFile[a.id]?.name && <span className="inline-flex items-center gap-1.5 text-xs text-teal-700 bg-teal-50 rounded-full px-3 py-1.5"><FileText className="h-3.5 w-3.5" />{submitFile[a.id].name}<button onClick={() => setSubmitFile((x) => ({ ...x, [a.id]: undefined }))} className="text-red-500"><XCircle className="h-3.5 w-3.5" /></button></span>}
                                <Button size="sm" className="gap-1.5 rounded-full" style={{ background: '#0d9488' }} onClick={() => submitAssignment(a)} disabled={submittingId === a.id}>{submittingId === a.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <CheckCircle2 className="h-3.5 w-3.5" />}{locale === 'ar' ? 'تسليم الواجب' : 'Submit'}</Button>
                              </div>
                            </>
                          ) : (
                            <p className="text-xs text-red-500">{locale === 'ar' ? 'انتهى موعد التسليم ولا يسمح بالتأخير' : 'The deadline has passed and late submissions are not allowed'}</p>
                          )}
                        </div>
                      )}
                    </div>
                  );
                })}
            </div>
          ) : active ? (active.locked ? (
            <div className="max-w-2xl mx-auto text-center py-16 space-y-6">
              <div className="flex h-24 w-24 items-center justify-center rounded-full bg-gray-100 mx-auto"><Lock className="h-10 w-10 text-gray-400" /></div>
              <h2 className="text-2xl font-bold">{active.title}</h2>
              {active.unlock_at ? (
                <div className="space-y-3">
                  <div className="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-amber-50 border border-amber-200 text-amber-700 text-sm font-medium">
                    <Clock className="h-4 w-4" />{locale === 'ar' ? 'يُفتح هذا الدرس تدريجياً بعد' : 'This lesson unlocks in'} <b>{formatCountdown(active.unlock_at)}</b>
                  </div>
                  <p className="text-gray-500 text-sm">{locale === 'ar' ? 'المحتوى يُفتح تدريجياً (Drip) — تابع دروسك الحالية وسيُفتح الباقي تلقائياً' : 'Drip content — keep learning and lessons unlock automatically'}</p>
                </div>
              ) : (
                <>
                  <p className="text-gray-500">{locale === 'ar' ? 'هذا الدرس غير متاح، يرجى الاشتراك في الكورس' : 'This lesson is locked. Enroll to access.'}</p>
                  <a href="/my-learning"><Button className="rounded-full px-8">{locale === 'ar' ? 'سجل الآن' : 'Enroll'}</Button></a>
                </>
              )}
            </div>
          ) : (
            <div
              ref={contentRef}
              className={`relative ${contentFs
                ? 'w-full h-full max-w-none bg-slate-950 p-4 sm:p-6 overflow-y-auto flex flex-col justify-center [&_.aspect-video]:max-w-[1500px] [&_.aspect-video]:mx-auto [&_.aspect-video]:w-full'
                : 'max-w-3xl mx-auto space-y-6'} ${pageBlurred ? 'select-none' : ''}`}
              onCopy={pageBlurred ? undefined : (e) => { if (active?.lesson_type === 'text' || textContent) e.preventDefault(); }}
              onContextMenu={(e) => { if (active?.lesson_type === 'text' || textContent) e.preventDefault(); }}
            >
              {pageBlurred && (
                <div className={`absolute inset-0 z-30 flex items-center justify-center rounded-2xl backdrop-blur-md ${contentFs ? '' : 'bg-white/30'}`}>
                  <div className="text-center space-y-2 px-6 py-4 rounded-2xl bg-black/60 text-white">
                    <ShieldCheck className="h-8 w-8 mx-auto opacity-80" />
                    <p className="text-sm font-medium">{locale === 'ar' ? 'المحتوى مخفي أثناء مغادرة الصفحة' : 'Content hidden while you are away'}</p>
                    <p className="text-xs text-white/50">{locale === 'ar' ? 'عد إلى التبويب للاستئناف' : 'Return to this tab to continue'}</p>
                  </div>
                </div>
              )}
              <h2 className={`font-bold ${contentFs ? 'text-white text-xl mx-auto max-w-3xl w-full text-center' : 'text-2xl sm:text-3xl'}`}>{active.title}</h2>
              {contentLoading ? (
                <div className="aspect-video bg-black rounded-2xl overflow-hidden flex items-center justify-center"><Loader2 className="h-10 w-10 animate-spin text-white/60" /></div>
              ) : contentError === 'locked' ? (
                <div className="max-w-2xl mx-auto text-center py-16 space-y-3">
                  <Clock className="h-12 w-12 text-amber-500 mx-auto" />
                  <p className="text-gray-600">{locale === 'ar' ? 'هذا الدرس لم يُفتح بعد' : 'This lesson is not unlocked yet.'}</p>
                </div>
              ) : contentError ? (
                <div className="aspect-video bg-black rounded-2xl overflow-hidden flex items-center justify-center text-white/70">
                  {locale === 'ar' ? 'تعذر تحميل المحتوى' : 'Failed to load content'}
                </div>
              ) : (
                <>
                  {liveContent?.live && (
                    <LiveLessonView live={liveContent.live} />
                  )}
                  {videoContent?.video ? (
                    <ProtectedVideoPlayer videoId={videoContent.video.id ?? videoContent.id} />
                  ) : (
                    <div className="flex items-center gap-3 p-4 rounded-xl bg-white border"><FileText className="h-6 w-6 text-green-500" /><span className="font-medium">{locale === 'ar' ? 'درس نصي' : 'Text lesson'}</span></div>
                  )}
                  {textContent?.text?.body && (
                    <div className={`bg-white border rounded-2xl leading-relaxed whitespace-pre-wrap select-none ${contentFs ? 'max-w-3xl w-full mx-auto p-6 sm:p-10 text-lg' : 'p-6 sm:p-8'}`} onCopy={(e) => e.preventDefault()}>{textContent.text.body}</div>
                  )}
                  {fileContent?.file?.url && (
                    <div className={`flex flex-wrap items-center gap-3 ${contentFs ? 'max-w-3xl w-full mx-auto justify-center' : ''}`}>
                      {fileContent.file.is_downloadable ? (
                        <Button onClick={() => openFile('download')} disabled={fileBusy} className="gap-2 rounded-full" style={{ background: '#0d9488' }}>
                          {fileBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />} {locale === 'ar' ? 'تحميل الملف' : 'Download'} {fileContent.file.name}
                        </Button>
                      ) : fileContent.file.previewable ? (
                        <>
                          <Button variant="outline" onClick={() => openFile('preview')} disabled={fileBusy} className="gap-2 rounded-full">
                            {fileBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Eye className="h-4 w-4" />} {locale === 'ar' ? 'معاينة الملف (بدون تنزيل)' : 'Preview (no download)'} {fileContent.file.name}
                          </Button>
                          <span className="flex items-center gap-1.5 text-xs text-amber-600"><Ban className="h-3.5 w-3.5" />{locale === 'ar' ? 'التنزيل معطل من قبل المعلم' : 'Downloads disabled by your teacher'}</span>
                        </>
                      ) : (
                        <span className="flex items-center gap-1.5 text-xs text-amber-600 p-3 rounded-xl bg-amber-50 border border-amber-100"><Ban className="h-4 w-4" />{locale === 'ar' ? 'التنزيل معطل من قبل المعلم — هذا النوع من الملفات لا يمكن معاينته' : 'Downloads disabled by your teacher — this file type cannot be previewed'} ({fileContent.file.name})</span>
                      )}
                    </div>
                  )}
                </>
              )}
            </div>
          )) : (
            <div className="max-w-2xl mx-auto text-center py-16 space-y-4"><BookOpen className="h-16 w-16 text-gray-300 mx-auto" /><h2 className="text-2xl font-bold">{locale === 'ar' ? 'اختر درساً' : 'Pick a lesson'}</h2></div>
          )}
          <input ref={submitFileInputRef} type="file" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f && submitFileFor) uploadSubmitFile(submitFileFor, f); e.target.value = ''; }} />
        </div>
      </div>
    </div>
  );
}
