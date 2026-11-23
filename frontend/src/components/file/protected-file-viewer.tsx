'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Download, FileText, Loader2, Ban, ShieldCheck, RefreshCw, ImageIcon } from 'lucide-react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

/** Where the moving viewer-name tag sits inside the preview. */
const MARK_POSITIONS = [
  { top: '4%', left: '4%' },
  { top: '4%', left: '58%' },
  { top: '84%', left: '58%' },
  { top: '84%', left: '4%' },
  { top: '44%', left: '4%' },
  { top: '44%', left: '58%' },
];

export type ViewerFile = {
  name?: string | null;
  mime_type?: string | null;
  size_bytes?: number | null;
  previewable?: boolean;
  is_downloadable?: boolean;
  /** Relative ticket endpoint, e.g. `/lessons/{id}/file-ticket?tenant_id=…`. */
  url?: string | null;
};

type Ticket = {
  url: string | null;
  preview_url: string | null;
  download_url: string | null;
  previewable: boolean;
  is_downloadable: boolean;
};

/**
 * In-app viewer for a lesson's protected file, honouring the teacher's
 * download policy and the current enrollment.
 *
 * A short-lived, user-bound signed URL is fetched for every view; the raw
 * storage path is never exposed. The preview is deliberately honest: it makes
 * the document comfortable to read inside the lesson and prints the viewer's
 * name over it for traceability, but it does **not** claim to stop a native
 * browser PDF/image viewer from allowing save or print. That guarantee does not
 * exist on the open web, so it is not promised.
 */
export default function ProtectedFileViewer({
  file,
  headers,
  className = '',
}: {
  file: ViewerFile;
  /** Extra auth headers (tenant, bearer) supplied by the host page. */
  headers?: Record<string, string>;
  className?: string;
}) {
  const { user } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [ticket, setTicket] = useState<Ticket | null>(null);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [mark, setMark] = useState(0);

  const headersRef = useRef(headers);
  headersRef.current = headers;

  // The signed URL expires after 15 minutes; refresh well before that on
  // long reading sessions so the frame never dies mid-document.
  const ticketRef = useRef(ticket);
  ticketRef.current = ticket;

  const loadTicket = useCallback(async () => {
    if (!file?.url) {
      setFailed(true);
      return;
    }
    setLoading(true);
    setFailed(false);
    try {
      const res = await fetch(`${API}${file.url}`, {
        headers: { Accept: 'application/json', ...(headersRef.current || {}) },
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data) {
        setFailed(true);
        return;
      }
      setTicket({
        url: data.url ?? null,
        preview_url: data.preview_url ?? null,
        download_url: data.download_url ?? null,
        previewable: !!data.previewable,
        is_downloadable: !!data.is_downloadable,
      });
    } catch {
      setFailed(true);
    } finally {
      setLoading(false);
    }
  }, [file?.url]);

  useEffect(() => {
    setTicket(null);
    setFailed(false);
    loadTicket();
  }, [loadTicket]);

  const previewUrl = ticket?.preview_url ?? (ticket?.previewable ? ticket.url : null);
  const downloadUrl = ticket?.download_url ?? (ticket?.is_downloadable ? ticket.url : null);

  const refreshTimer = useRef<ReturnType<typeof setInterval> | null>(null);
  useEffect(() => {
    if (!previewUrl) return;
    refreshTimer.current = setInterval(() => loadTicket(), 10 * 60 * 1000);
    return () => {
      if (refreshTimer.current) clearInterval(refreshTimer.current);
      refreshTimer.current = null;
    };
  }, [previewUrl, loadTicket]);

  // Drift the name tag so a passive screenshot is still traceable.
  useEffect(() => {
    const id = setInterval(() => setMark((p) => (p + 1) % MARK_POSITIONS.length), 30000);
    return () => clearInterval(id);
  }, []);

  const watermark = useMemo(() => {
    const name = user?.name || t('طالب', 'Student');
    return `${name} · ${new Date().toISOString().slice(0, 10)}`;
    // Re-stamp hourly is unnecessary: the date is what matters for tracing.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.name, locale]);

  const isImage = useMemo(() => {
    const mime = (file?.mime_type || '').toLowerCase();
    if (mime.startsWith('image/')) return true;
    const ext = (file?.name || '').split('.').pop()?.toLowerCase();
    return ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'].includes(ext || '');
  }, [file?.mime_type, file?.name]);

  const doDownload = async () => {
    if (!downloadUrl || downloading) return;
    setDownloading(true);
    try {
      window.location.href = downloadUrl;
    } finally {
      // Re-enable after a beat; navigation may not happen if blocked.
      setTimeout(() => setDownloading(false), 1500);
    }
  };

  // ── Not viewable and not downloadable (e.g. a locked .docx) ──
  if (failed) {
    return (
      <div className={`rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700 ${className}`}>
        <Ban className="mb-1 h-4 w-4" />
        {t('تعذّر تجهيز الملف. تحقق من صلاحيتك على الدرس أو أعد المحاولة.', 'Could not prepare the file. Check your access or try again.')}
      </div>
    );
  }

  if (loading && !ticket) {
    return (
      <div className={`flex items-center justify-center gap-2 rounded-2xl border bg-white p-10 text-sm text-gray-500 ${className}`}>
        <Loader2 className="h-4 w-4 animate-spin" />
        {t('جارٍ تجهيز الملف…', 'Preparing the file…')}
      </div>
    );
  }

  if (!ticket) return null;

  const canView = !!previewUrl;
  const canDownload = !!downloadUrl;

  if (!canView && !canDownload) {
    return (
      <div className={`flex items-start gap-2 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700 ${className}`}>
        <Ban className="mt-0.5 h-4 w-4 shrink-0" />
        {t('التنزيل معطّل من قبل المعلم، وهذا النوع من الملفات لا يمكن معاينته داخل التطبيق.', 'Downloads are disabled by your teacher and this file type cannot be previewed in the app.')}
      </div>
    );
  }

  return (
    <div className={className}>
      {/* ── Preview surface ── */}
      {canView ? (
        <div className="relative overflow-hidden rounded-2xl border bg-slate-100">
          {isImage ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={previewUrl as string}
              alt={file?.name || ''}
              draggable={false}
              onContextMenu={(e) => e.preventDefault()}
              onDragStart={(e) => e.preventDefault()}
              className="mx-auto block max-h-[75vh] w-auto select-none"
            />
          ) : (
            <iframe
              src={`${previewUrl}#toolbar=0&navpanes=0&scrollbar=1&view=FitH`}
              title={file?.name || t('ملف الدرس', 'Lesson file')}
              className="h-[75vh] w-full bg-white"
            />
          )}

          {/* Moving, viewer-bound name tag — deters casual resharing and makes
              a leaked copy attributable. It is not a content lock. */}
          <div
            aria-hidden
            className="pointer-events-none absolute select-none rounded-md bg-black/35 px-2 py-1 text-[11px] font-medium text-white/90 transition-all duration-[1200ms]"
            style={{ top: MARK_POSITIONS[mark].top, left: MARK_POSITIONS[mark].left }}
          >
            {watermark}
          </div>
        </div>
      ) : (
        <div className="flex items-center gap-2 rounded-2xl border bg-white p-4 text-sm text-gray-600">
          <ImageIcon className="h-4 w-4" />
          {t('هذا الملف غير قابل للمعاينة، لكن يمكنك تنزيله.', 'This file cannot be previewed, but you can download it.')}
        </div>
      )}

      {/* ── Actions + honest policy note ── */}
      <div className="mt-2 flex flex-wrap items-center gap-2">
        {canDownload && (
          <Button onClick={doDownload} disabled={downloading} className="gap-2 rounded-full" style={{ background: '#0d9488' }}>
            {downloading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
            {t('تحميل', 'Download')}
          </Button>
        )}
        {canView && (
          <Button variant="outline" onClick={loadTicket} disabled={loading} className="gap-2 rounded-full">
            <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
            {t('تحديث العرض', 'Refresh view')}
          </Button>
        )}
        <span className="flex items-center gap-1.5 text-xs text-gray-500">
          <FileText className="h-3.5 w-3.5" />
          {file?.name}
        </span>
      </div>

      <p className="mt-2 flex items-start gap-1.5 text-xs leading-relaxed text-gray-400">
        <ShieldCheck className="mt-0.5 h-3.5 w-3.5 shrink-0" />
        {t(
          'يُعرض الملف برابط موقّع مؤقت مرتبط بحسابك، ويحمل اسمك كعلامة للمتابعة. لن يمنع ذلك أي برنامج تصوير شاشة أو أمر حفظ في متصفحك.',
          'The file is shown through a short-lived link bound to your account and carries your name for traceability. This does not block screen recorders or a save action in your browser.',
        )}
      </p>
    </div>
  );
}
