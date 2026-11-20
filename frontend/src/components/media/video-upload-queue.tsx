'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
  Upload, Loader2, CheckCircle2, XCircle, RefreshCw, Ban, UploadCloud, Film, AlertTriangle,
} from 'lucide-react';
import { toast } from 'sonner';
import {
  addFiles, markUploading, markProgress, markProcessing, markDone, markFailed,
  cancelPending, cancelAllPending, retryFailed, retryOne, nextPending, counts,
  humanBytes, MAX_FILE_BYTES, type QueueState, type QueueItem,
} from '@/lib/video-upload-queue';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const EMPTY: QueueState = { items: [], rejected: [] };

type Section = { id: string; title: string };
type Lesson = { id: string; title: string; section_id?: string };

/**
 * Multi-video upload with a real queue: several files, independent progress,
 * per-file status, retry of only the failed ones, cancel of the pending ones,
 * and one organized lesson created per video (with the teacher's confirmation).
 *
 * Files are uploaded ONE AT A TIME on purpose: the `video-upload` rate limit is
 * per-IP and every file costs two requests, so a bounded, sequential queue is
 * the only way to stay under it and never trip a 429 mid-batch.
 */
export default function VideoUploadQueue({
  open, onOpenChange, sections, lessons, onUploaded,
}: {
  open: boolean;
  onOpenChange: (v: boolean) => void;
  sections: Section[];
  lessons: Lesson[];
  onUploaded?: () => void;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [queue, setQueue] = useState<QueueState>(EMPTY);
  const [destination, setDestination] = useState<'create' | 'existing'>('create');
  const [sectionId, setSectionId] = useState(sections[0]?.id || '');
  const [lessonId, setLessonId] = useState(lessons[0]?.id || '');
  const [remainingSlots, setRemainingSlots] = useState<number | null>(null);
  const [confirmCreate, setConfirmCreate] = useState(true);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!open) return;
    // Ask the server for the real remaining video quota so we never queue more
    // than the plan allows (shown as the true operational limit).
    const tid = localStorage.getItem('tenant_id') || '';
    fetch(`${API}/teacher/media/usage`, { headers: { 'X-Tenant-Id': tid, Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        const v = d?.usage?.videos;
        if (v && !v.unlimited && typeof v.limit === 'number') {
          setRemainingSlots(Math.max(0, v.limit - v.used));
        } else {
          setRemainingSlots(null);
        }
      })
      .catch(() => setRemainingSlots(null));
  }, [open]);

  const pickFiles = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(e.target.files || []);
    e.target.value = '';
    if (!files.length) return;

    if (destination === 'existing' && files.length > 1) {
      toast.error(t('درس واحد يستضيف فيديو واحداً فقط. اختر «إنشاء درس لكل فيديو» بدلاً من ذلك.',
        'One lesson holds a single video. Choose "a lesson per video" instead.'));
      return;
    }

    setQueue((s) => addFiles(s, files, { remainingSlots, makeId: () => crypto.randomUUID() }));
  };

  // One lesson per queue item is created at most ONCE: its id is remembered so
  // retrying a failed upload never leaves an orphan lesson behind.
  const createdLessonsRef = useRef<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const workerLockRef = useRef(false);

  const uploadOne = useCallback(async (item: QueueItem) => {
    const tid = localStorage.getItem('tenant_id') || '';
    const jsonHeaders = { 'X-Tenant-Id': tid, Accept: 'application/json', 'Content-Type': 'application/json' };
    // A stable idempotency key per item: a retry can never create a second
    // video or charge the file twice against the plan (the server replays it).
    const idempotencyKey = `vq-${item.id}`;

    let targetLesson = item.lessonId || createdLessonsRef.current[item.id] || (destination === 'existing' ? lessonId : '');

    if (!targetLesson) {
      const res = await fetch(`${API}/lessons`, {
        method: 'POST',
        headers: jsonHeaders,
        body: JSON.stringify({ section_id: sectionId, title: item.name.replace(/\.[^.]+$/, ''), lesson_type: 'video' }),
      });
      const d = await res.json().catch(() => null);
      if (!res.ok || !d?.lesson?.id) throw new Error(d?.message || t('فشل إنشاء الدرس', 'Could not create lesson'));
      targetLesson = d.lesson.id as string;
      createdLessonsRef.current[item.id] = targetLesson;
    }

    const ticketRes = await fetch(`${API}/videos/upload-ticket`, {
      method: 'POST',
      headers: jsonHeaders,
      body: JSON.stringify({
        lesson_id: targetLesson,
        file_size: item.size,
        title: item.name.replace(/\.[^.]+$/, ''),
        original_filename: item.name,
        mime_type: 'video/mp4',
        idempotency_key: idempotencyKey,
      }),
    });
    const ticket = await ticketRes.json().catch(() => null);
    if (!ticketRes.ok) throw new Error(ticket?.message || t('فشل إنشاء تذكرة الرفع', 'Could not create upload ticket'));

    const assetId = ticket?.upload?.asset_id || item.assetId || '';

    await new Promise<void>((resolve, reject) => {
      const file = item.file;
      if (!file) { reject(new Error(t('الملف غير متاح — أعد اختياره', 'File is no longer available — pick it again'))); return; }
      const fd = new FormData();
      fd.append('file', file);
      fd.append('lesson_id', targetLesson);
      if (assetId) fd.append('asset_id', assetId);
      fd.append('idempotency_key', idempotencyKey);

      const xhr = new XMLHttpRequest();
      xhr.open('POST', `${API}/videos/upload`);
      xhr.setRequestHeader('X-Tenant-Id', tid);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = (ev) => {
        if (ev.lengthComputable) {
          setQueue((s) => markProgress(s, item.id, (ev.loaded / ev.total) * 100));
        }
      };
      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          resolve();
        } else {
          let msg = t('فشل رفع الفيديو', 'Video upload failed');
          try { msg = JSON.parse(xhr.responseText)?.message || msg; } catch { /* keep default */ }
          reject(new Error(msg));
        }
      };
      xhr.onerror = () => reject(new Error(t('انقطع الاتصال أثناء الرفع', 'Connection lost during upload')));
      xhr.ontimeout = () => reject(new Error(t('انتهت مهلة الرفع', 'Upload timed out')));
      xhr.timeout = 60 * 60 * 1000;
      xhr.send(fd);
    });
  }, [destination, lessonId, sectionId, t]);

  // Sequential worker: exactly one item uploads at a time, and only a `pending`
  // item the teacher has not cancelled is ever started.
  useEffect(() => {
    if (!open || busy || workerLockRef.current) return;
    const item = nextPending(queue);
    if (!item) return;

    // Synchronous lock: React StrictMode's double-invoked effects must not
    // start the same file twice.
    workerLockRef.current = true;
    setBusy(true);
    setQueue((s) => markUploading(s, item.id));
    (async () => {
      try {
        await uploadOne(item);
        setQueue((s) => markProcessing(s, item.id));
        setQueue((s) => markDone(s, item.id, item.lessonId || createdLessonsRef.current[item.id]));
        onUploaded?.();
      } catch (err: any) {
        setQueue((s) => markFailed(s, item.id, err?.message || t('فشل', 'Failed')));
      } finally {
        workerLockRef.current = false;
        setBusy(false);
      }
    })();
  }, [queue, open, busy, uploadOne, onUploaded, t]);


  const items = queue.items;
  const c = counts(queue);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="space-y-4">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2"><UploadCloud className="h-5 w-5 text-teal-600" />{t('رفع فيديوهات متعددة', 'Upload multiple videos')}</DialogTitle>
        </DialogHeader>

        {/* Destination */}
        <div className="space-y-3 rounded-xl border p-3">
          <div className="flex flex-wrap gap-2">
            <button type="button" onClick={() => setDestination('create')} className={`px-3 py-1.5 rounded-full text-xs font-medium ${destination === 'create' ? 'bg-teal-600 text-white' : 'bg-gray-100 text-gray-500'}`}>
              {t('إنشاء درس لكل فيديو', 'A lesson per video')}
            </button>
            <button type="button" onClick={() => setDestination('existing')} className={`px-3 py-1.5 rounded-full text-xs font-medium ${destination === 'existing' ? 'bg-teal-600 text-white' : 'bg-gray-100 text-gray-500'}`}>
              {t('إلى درس موجود', 'Into an existing lesson')}
            </button>
          </div>

          {destination === 'create' ? (
            <div className="space-y-2">
              <Label>{t('الوحدة التي ستُنشأ فيها الدروس', 'Unit the lessons go into')}</Label>
              <select value={sectionId} onChange={(e) => setSectionId(e.target.value)} className="h-10 w-full rounded-xl border bg-background px-3 text-sm">
                {sections.map((s) => <option key={s.id} value={s.id}>{s.title}</option>)}
              </select>
              <label className="flex items-start gap-2 text-xs text-gray-600">
                <input type="checkbox" checked={confirmCreate} onChange={(e) => setConfirmCreate(e.target.checked)} className="mt-0.5" />
                {t('أوافق على إنشاء درس منظّم لكل فيديو باسم الملف.', 'I agree to create one organized lesson per video, named after the file.')}
              </label>
            </div>
          ) : (
            <div className="space-y-2">
              <Label>{t('الدرس', 'Lesson')}</Label>
              <select value={lessonId} onChange={(e) => setLessonId(e.target.value)} className="h-10 w-full rounded-xl border bg-background px-3 text-sm">
                {lessons.map((l) => <option key={l.id} value={l.id}>{l.title}</option>)}
              </select>
              <p className="text-[11px] text-amber-600">{t('الدرس الواحد يستضيف فيديو واحداً فقط.', 'One lesson holds a single video.')}</p>
            </div>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <input ref={inputRef} type="file" accept="video/mp4,video/webm,video/quicktime,.mp4,.webm,.mov,.mkv,.avi" multiple className="hidden" onChange={pickFiles} />
          <Button type="button" onClick={() => inputRef.current?.click()} disabled={destination === 'create' && !confirmCreate} className="gap-2 rounded-full bg-teal-600 text-white hover:bg-teal-700">
            <Upload className="h-4 w-4" />{t('اختيار ملفات', 'Choose files')}
          </Button>
          <span className="text-[11px] text-gray-400">
            {t(`الحد ${Math.round(MAX_FILE_BYTES / 1024 ** 3)} جيجابايت للملف`, `Max ${Math.round(MAX_FILE_BYTES / 1024 ** 3)} GB per file`)}
            {remainingSlots !== null && <> · {t('متبقٍ', 'left')}: {remainingSlots} {t('فيديو', 'videos')}</>}
          </span>
        </div>

        {queue.rejected.length > 0 && (
          <div className="space-y-1 rounded-xl bg-amber-50 p-2.5 text-[11px] text-amber-700">
            {queue.rejected.map((r, i) => (
              <p key={i} className="flex items-center gap-1.5"><AlertTriangle className="h-3.5 w-3.5" />{r.name} — {r.reason === 'quota' ? t('تجاوز حدود الخطة أو حجم الملف', 'over your plan limit or too large') : t('ليس ملف فيديو', 'not a video file')}</p>
            ))}
          </div>
        )}

        {items.length > 0 && (
          <div className="max-h-64 space-y-2 overflow-y-auto">
            {items.map((it) => (
              <div key={it.id} className="rounded-xl border p-2.5 space-y-1.5">
                <div className="flex items-center justify-between gap-2">
                  <span className="flex min-w-0 items-center gap-2 text-sm"><Film className="h-4 w-4 shrink-0 text-teal-600" /><span className="truncate">{it.name}</span></span>
                  <StatusBadge status={it.status} t={t} />
                </div>
                <div className="flex items-center gap-2">
                  <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                    <div className={`h-full rounded-full transition-all ${it.status === 'failed' ? 'bg-rose-500' : it.status === 'done' ? 'bg-emerald-500' : 'bg-teal-500'}`} style={{ width: `${it.progress}%` }} />
                  </div>
                  <span className="w-10 text-end text-[11px] text-gray-400">{it.progress}%</span>
                  <span className="w-16 text-end text-[11px] text-gray-400">{humanBytes(it.size)}</span>
                </div>
                {it.error && <p className="text-[11px] text-rose-600">{it.error}</p>}
                <div className="flex gap-1.5">
                  {it.status === 'failed' && <Button size="sm" variant="outline" className="h-7 gap-1 rounded-full text-xs" onClick={() => setQueue((s) => retryOne(s, it.id))}><RefreshCw className="h-3 w-3" />{t('إعادة المحاولة', 'Retry')}</Button>}
                  {it.status === 'pending' && <Button size="sm" variant="ghost" className="h-7 gap-1 rounded-full text-xs text-gray-500" onClick={() => setQueue((s) => cancelPending(s, it.id))}><Ban className="h-3 w-3" />{t('إلغاء', 'Cancel')}</Button>}
                </div>
              </div>
            ))}
          </div>
        )}

        {queue.items.some((i) => i.status === 'done') && (
          <p className="text-[11px] text-emerald-600">{t(`${c.done} فيديو اكتمل رفعه وهو قيد المعالجة لدى المزوّد.`, `${c.done} video(s) uploaded and are being processed by the provider.`)}</p>
        )}

        <div className="flex justify-end gap-2">
          {c.failed > 0 && <Button variant="outline" className="gap-1.5 rounded-full" onClick={() => setQueue((s) => retryFailed(s))}><RefreshCw className="h-4 w-4" />{t('إعادة محاولة الفاشل', 'Retry failed')}</Button>}
          <Button variant="outline" className="rounded-full" onClick={() => { setQueue(cancelAllPending(queue)); onUploaded?.(); onOpenChange(false); }}>{t('تم', 'Done')}</Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}

function StatusBadge({ status, t }: { status: QueueItem['status']; t: (a: string, b: string) => string }) {
  if (status === 'done') return <Badge className="border-0 bg-emerald-50 text-emerald-600 gap-1"><CheckCircle2 className="h-3 w-3" />{t('اكتمل', 'Done')}</Badge>;
  if (status === 'uploading') return <Badge className="border-0 bg-teal-50 text-teal-600 gap-1"><Loader2 className="h-3 w-3 animate-spin" />{t('رفع', 'Uploading')}</Badge>;
  if (status === 'processing') return <Badge className="border-0 bg-violet-50 text-violet-600 gap-1"><Loader2 className="h-3 w-3 animate-spin" />{t('معالجة', 'Processing')}</Badge>;
  if (status === 'failed') return <Badge className="border-0 bg-rose-50 text-rose-600 gap-1"><XCircle className="h-3 w-3" />{t('فشل', 'Failed')}</Badge>;
  if (status === 'canceled') return <Badge className="border-0 bg-gray-100 text-gray-500">{t('ملغى', 'Canceled')}</Badge>;
  return <Badge className="border-0 bg-slate-100 text-slate-600">{t('بالانتظار', 'Queued')}</Badge>;
}
