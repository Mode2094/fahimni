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
  addFiles, markUploading, markProgress, markProcessing, markDone, markFailed, markAsset,
  markCanceled, cancelAllPending, retryFailed, retryOne, nextPendingBatch, counts,
  overallProgress, humanBytes, MAX_FILE_BYTES, MAX_PARALLEL, uploadIdempotencyKey,
  type QueueState, type QueueItem,
} from '@/lib/video-upload-queue';
import { uploadVideoDirect, type TusHandle } from '@/lib/tus-upload';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const EMPTY: QueueState = { items: [], rejected: [] };

type Section = { id: string; title: string };
type Lesson = { id: string; title: string; section_id?: string };

const baseName = (n: string) => n.replace(/\.[^.]+$/, '');

/** POST JSON with a single automatic retry on the per-user ticket rate limit. */
async function postJson(url: string, body: unknown, tid: string, retry = 0): Promise<any> {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'X-Tenant-Id': tid, Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => null);
  if (res.status === 429 && retry < 2) {
    await new Promise((r) => setTimeout(r, 1500 * (retry + 1)));
    return postJson(url, body, tid, retry + 1);
  }
  if (!res.ok) throw new Error(data?.message || `HTTP ${res.status}`);
  return data;
}

/**
 * Multi-video upload that goes DIRECTLY to Bunny (TUS) — the video bytes never
 * pass through our server. Up to {@link MAX_PARALLEL} files upload at once with
 * independent progress; failures retry individually (a succeeded file is never
 * re-uploaded), pending/active items can be cancelled, and an interrupted
 * upload resumes from its last acknowledged chunk.
 *
 * Each queue item gets a unique idempotency key (file fingerprint + a per-operation
 * id), so a retry reuses the same video instead of creating a duplicate, while
 * two different videos that happen to share a filename never collide.
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

  const tenantId = () => (typeof window !== 'undefined' ? localStorage.getItem('tenant_id') || '' : '');

  // The course page mounts this dialog before `sections`/`lessons` finish
  // loading, so the initial state would capture `''`. Keep the selection valid
  // as the async data arrives: adopt the first item only when the current one
  // is empty/unknown, and NEVER clobber a choice the teacher made on purpose.
  useEffect(() => {
    setSectionId((prev) => (prev && sections.some((s) => s.id === prev) ? prev : (sections[0]?.id || '')));
  }, [sections]);
  useEffect(() => {
    setLessonId((prev) => (prev && lessons.some((l) => l.id === prev) ? prev : (lessons[0]?.id || '')));
  }, [lessons]);

  // A batch is only launchable once an explicit destination target exists.
  const destinationReady = destination === 'create'
    ? (confirmCreate && Boolean(sectionId))
    : Boolean(lessonId);

  useEffect(() => {
    if (!open) return;
    const tid = tenantId();
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

    // Never start a batch whose destination target is not resolved yet — that
    // is exactly what shipped the empty `section_id` to the server.
    if (!destinationReady) {
      toast.error(destination === 'create'
        ? t('اختر الوحدة (القسم) وتأكّد من الموافقة على إنشاء الدروس قبل الرفع.', 'Choose the unit (section) and confirm lesson creation before uploading.')
        : t('اختر درساً قبل الرفع.', 'Choose a lesson before uploading.'));
      return;
    }

    setQueue((s) => addFiles(s, files, { remainingSlots, makeId: () => crypto.randomUUID() }));
  };

  // One lesson per queue item is created at most ONCE: its id is remembered so
  // retrying a failed upload never leaves an orphan lesson behind.
  const createdLessonsRef = useRef<Record<string, string>>({});
  const abortsRef = useRef<Record<string, TusHandle['abort']>>({});
  const activeRef = useRef<Set<string>>(new Set());
  const [pump, setPump] = useState(0);

  // Latest queue state for async callbacks (closures would otherwise read a
  // stale snapshot — e.g. missing a "canceled" flag set while a file uploads).
  const queueRef = useRef(queue);
  useEffect(() => { queueRef.current = queue; }, [queue]);
  const currentItem = (id: string) => queueRef.current.items.find((i) => i.id === id);

  const uploadOne = useCallback(async (item: QueueItem) => {
    const tid = tenantId();
    const file = item.file;
    if (!file) throw new Error(t('الملف غير متاح — أعد اختياره', 'File is no longer available — pick it again'));
    const idempotencyKey = uploadIdempotencyKey(item, { destination, lessonId });

    // Validate the destination target right before we reserve quota. Guarding
    // here (not only in pickFiles) covers a course switch racing the batch.
    if (destination === 'existing' && !lessonId) {
      throw new Error(t('اختر درساً قبل الرفع', 'Choose a lesson before uploading'));
    }
    if (destination === 'create' && !sectionId) {
      throw new Error(t('اختر الوحدة (القسم) قبل الرفع', 'Choose a section before uploading'));
    }

    // 1) Resumable (TUS) ticket FIRST: reserves quota + signs a direct Bunny
    // upload. `key` is deterministic per file, so a retry/reload reuses the
    // same video (and TUS can resume) instead of creating a duplicate.
    const ticket = await postJson(`${API}/teacher/media/resumable-ticket`, {
      file_size: item.size,
      title: baseName(item.name),
      original_filename: item.name,
      mime_type: file.type || 'video/mp4',
      idempotency_key: idempotencyKey,
    }, tid);

    const upload = ticket?.upload || {};
    const assetId = upload.asset_id as string;
    if (!assetId) throw new Error(t('فشل إنشاء تذكرة الرفع', 'Could not create upload ticket'));

    setQueue((s) => markAsset(s, item.id, assetId));

    // 2) Upload the bytes straight to Bunny — await the client's own retries.
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
          onProgress: (p) => setQueue((s) => markProgress(s, item.id, p)),
          onSuccess: () => resolve(),
          onError: (m) => reject(new Error(m || t('فشل رفع الفيديو', 'Video upload failed'))),
        },
        { resume: Boolean(upload.reused) },
      )
        .then((handle) => { abortsRef.current[item.id] = handle.abort; })
        .catch(reject);
    });

    // A cancel during the transfer must win over "done".
    if (currentItem(item.id)?.status === 'canceled') return;

    // 3) Destination lesson — created only AFTER the bytes landed, so a failed
    // upload never leaves an empty lesson behind. Remembered per item so a
    // retried completion never creates a second lesson.
    let targetLesson = item.lessonId || createdLessonsRef.current[item.id];
    if (!targetLesson) {
      if (destination === 'existing') {
        targetLesson = lessonId;
      } else {
        const d = await postJson(`${API}/lessons`, {
          section_id: sectionId, title: baseName(item.name), lesson_type: 'video',
        }, tid);
        if (!d?.lesson?.id) throw new Error(t('فشل إنشاء الدرس', 'Could not create lesson'));
        targetLesson = d.lesson.id as string;
        createdLessonsRef.current[item.id] = targetLesson;
      }
    }
    if (!targetLesson) throw new Error(t('اختر درساً', 'Choose a lesson'));

    // 4) Attach to the lesson. The video stays `processing` until Bunny
    // confirms the upload + transcode — it is never shown ready early.
    setQueue((s) => markProcessing(s, item.id, assetId));
    await postJson(`${API}/teacher/media/resumable-complete`, { asset_id: assetId, lesson_id: targetLesson }, tid);
    setQueue((s) => (currentItem(item.id)?.status === 'canceled' ? s : markDone(s, item.id, targetLesson, assetId)));
    onUploaded?.();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [destination, lessonId, sectionId, t, onUploaded]);

  // Parallel worker: start up to MAX_PARALLEL pending items, no more. The
  // synchronous `activeRef` guard keeps React StrictMode's double effect from
  // starting the same file twice.
  useEffect(() => {
    if (!open) return;
    const free = MAX_PARALLEL - activeRef.current.size;
    if (free <= 0) return;

    const batch = nextPendingBatch(queue, free);
    if (batch.length === 0) return;

    for (const item of batch) {
      activeRef.current.add(item.id);
      setQueue((s) => markUploading(s, item.id));
      uploadOne(item)
        .catch((err: any) => {
          setQueue((s) => (s.items.find((i) => i.id === item.id)?.status === 'canceled'
            ? s
            : markFailed(s, item.id, err?.message || t('فشل', 'Failed'))));
        })
        .finally(() => {
          activeRef.current.delete(item.id);
          delete abortsRef.current[item.id];
          setPump((p) => p + 1);
        });
    }
  }, [queue, open, pump, uploadOne, t]);

  /** Cancel one item: abort a live upload, else just drop it from the queue. */
  const cancelOne = useCallback((item: QueueItem) => {
    const live = currentItem(item.id) ?? item;
    if (live.status === 'pending') {
      setQueue((s) => markCanceled(s, item.id));
      return;
    }
    if (live.status === 'uploading' || live.status === 'processing') {
      abortsRef.current[item.id]?.();
      activeRef.current.delete(item.id);
      setQueue((s) => markCanceled(s, item.id));
      // Free the reserved space + discard the unfinished provider asset.
      const assetId = live.assetId;
      if (assetId) {
        fetch(`${API}/teacher/media/assets/${assetId}`, {
          method: 'DELETE',
          headers: { 'X-Tenant-Id': tenantId(), Accept: 'application/json' },
        }).catch(() => { /* cleanup is best-effort */ });
      }
    }
  }, []);

  const cancelAll = useCallback(() => {
    // Iterate the live snapshot (side effects must stay out of the updater).
    for (const it of queueRef.current.items) {
      if (it.status === 'pending' || it.status === 'uploading' || it.status === 'processing') {
        cancelOne(it);
      }
    }
    setQueue((s) => cancelAllPending(s));
    setPump((p) => p + 1);
  }, [cancelOne]);

  const items = queue.items;
  const c = counts(queue);
  const overall = overallProgress(queue);
  const active = c.pending + c.uploading + c.processing;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="space-y-4 max-h-[90vh] overflow-y-auto">
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
              {sections.length === 0 ? (
                <p className="rounded-xl border border-dashed p-3 text-xs text-amber-600">{t('لا توجد وحدات بعد — أضف وحدة (قسم) في الكورس أولاً لتتمكّن من إنشاء الدروس.', 'No units yet — add a section to the course first so lessons can be created.')}</p>
              ) : (
                <select value={sectionId} onChange={(e) => setSectionId(e.target.value)} className="h-10 w-full rounded-xl border bg-background px-3 text-sm">
                  {sections.map((s) => <option key={s.id} value={s.id}>{s.title}</option>)}
                </select>
              )}
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
          <Button type="button" onClick={() => inputRef.current?.click()} disabled={!destinationReady} className="gap-2 rounded-full bg-teal-600 text-white hover:bg-teal-700">
            <Upload className="h-4 w-4" />{t('اختيار ملفات', 'Choose files')}
          </Button>
          <span className="text-[11px] text-gray-400">
            {t(`الحد ${Math.round(MAX_FILE_BYTES / 1024 ** 3)} جيجابايت للملف`, `Max ${Math.round(MAX_FILE_BYTES / 1024 ** 3)} GB per file`)}
            {remainingSlots !== null && <> · {t('متبقٍ', 'left')}: {remainingSlots} {t('فيديو', 'videos')}</>}
          </span>
        </div>

        {/* Overall progress — real aggregate of every file in the batch. */}
        {overall.total > 0 && (
          <div className="space-y-1.5 rounded-xl bg-slate-50 p-3">
            <div className="flex items-center justify-between text-xs font-medium text-slate-600">
              <span>{t('التقدم الإجمالي', 'Overall progress')} · {overall.settled}/{overall.total}</span>
              <span className="font-bold text-teal-600">{overall.percent}%</span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-slate-200">
              <div className={`h-full rounded-full transition-all ${c.failed > 0 ? 'bg-rose-400' : 'bg-teal-500'}`} style={{ width: `${overall.percent}%` }} />
            </div>
            <div className="flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-gray-500">
              {c.uploading + c.processing > 0 && <span>{t('قيد الرفع', 'Uploading')}: {c.uploading + c.processing}</span>}
              {c.pending > 0 && <span>{t('بالانتظار', 'Queued')}: {c.pending}</span>}
              {c.done > 0 && <span className="text-emerald-600">{t('اكتمل', 'Done')}: {c.done}</span>}
              {c.failed > 0 && <span className="text-rose-600">{t('فشل', 'Failed')}: {c.failed}</span>}
              {c.canceled > 0 && <span>{t('ملغى', 'Canceled')}: {c.canceled}</span>}
            </div>
          </div>
        )}

        {queue.rejected.length > 0 && (
          <div className="space-y-1 rounded-xl bg-amber-50 p-2.5 text-[11px] text-amber-700">
            {queue.rejected.map((r, i) => (
              <p key={i} className="flex items-center gap-1.5"><AlertTriangle className="h-3.5 w-3.5" />{r.name} — {r.reason === 'quota' ? t('تجاوز حدود الخطة أو حجم الملف', 'over your plan limit or too large') : t('ليس ملف فيديو', 'not a video file')}</p>
            ))}
          </div>
        )}

        {items.length > 0 && (
          <div className="max-h-72 space-y-2 overflow-y-auto pe-1">
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
                  {(it.status === 'pending' || it.status === 'uploading' || it.status === 'processing') && <Button size="sm" variant="ghost" className="h-7 gap-1 rounded-full text-xs text-gray-500" onClick={() => cancelOne(it)}><Ban className="h-3 w-3" />{t('إلغاء', 'Cancel')}</Button>}
                </div>
              </div>
            ))}
          </div>
        )}

        {(c.uploading > 0 || c.processing > 0) && (
          <p className="text-[11px] text-gray-400">{t('يتم الرفع مباشرة إلى Bunny — الفيديو لا يمر عبر خادمنا، ويمكنك متابعة عملك.',
            'Uploading straight to Bunny — the video never passes through our server, and you can keep working.')}</p>
        )}
        {c.done > 0 && (
          <p className="text-[11px] text-emerald-600">{t(`${c.done} فيديو اكتمل رفعه وهو قيد المعالجة لدى المزوّد.`, `${c.done} video(s) uploaded and are being processed by the provider.`)}</p>
        )}

        <div className="flex flex-wrap justify-end gap-2">
          {active > 0 && <Button variant="outline" className="gap-1.5 rounded-full text-gray-500" onClick={cancelAll}><Ban className="h-4 w-4" />{t('إلغاء الباقي', 'Cancel remaining')}</Button>}
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
