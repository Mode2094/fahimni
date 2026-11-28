'use client';

import { useCallback, useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/empty-state';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Film, Loader2, Link2, Clock, Layers } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

type LibraryAsset = {
  id: string;
  title?: string | null;
  status?: string;
  duration_seconds?: number | null;
  thumbnail_url?: string | null;
  reference_count?: number;
};

export function formatDuration(seconds?: number | null): string {
  const total = Math.max(0, Math.floor(seconds || 0));
  if (!total) return '—';
  const m = Math.floor(total / 60);
  const s = total % 60;
  const hours = Math.floor(m / 60);
  if (hours > 0) return `${hours}:${String(m % 60).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  return `${m}:${String(s).padStart(2, '0')}`;
}

/**
 * Reuse an already-uploaded video from the teacher's media library in another
 * lesson — no re-upload, no second copy on the provider, no extra storage.
 * Only READY videos are offered; picking one links the same provider video to
 * the target lesson via the server, which keeps a single storage charge.
 */
export default function VideoLibraryPicker({
  open, onOpenChange, lessonId, onAttached,
}: {
  open: boolean;
  onOpenChange: (v: boolean) => void;
  lessonId: string | null;
  onAttached?: () => void;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [assets, setAssets] = useState<LibraryAsset[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [linkingId, setLinkingId] = useState<string | null>(null);

  const tid = () => (typeof window !== 'undefined' ? localStorage.getItem('tenant_id') || '' : '');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await fetch(`${API}/teacher/media/?kind=video&status=ready`, {
        headers: { 'X-Tenant-Id': tid(), Accept: 'application/json' },
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      setAssets((data.assets || []).filter((a: LibraryAsset) => a.status === 'ready'));
    } catch {
      setError(t('تعذّر تحميل مكتبة الفيديو. حاول مرة أخرى.', 'Could not load your video library. Please try again.'));
    } finally {
      setLoading(false);
    }
  }, [locale]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => { if (open) void load(); }, [open, load]);

  const attach = async (asset: LibraryAsset) => {
    if (!lessonId || linkingId) return;
    setLinkingId(asset.id);
    try {
      const res = await fetch(`${API}/teacher/media/assets/${asset.id}/attach`, {
        method: 'POST',
        headers: { 'X-Tenant-Id': tid(), Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ lesson_id: lessonId }),
      });
      const data = await res.json().catch(() => null);
      if (!res.ok) throw new Error(data?.message || `HTTP ${res.status}`);
      toast.success(t('تم ربط الفيديو بالدرس بلا إعادة رفع', 'Video linked to the lesson — no re-upload'));
      onAttached?.();
      onOpenChange(false);
    } catch (e: any) {
      toast.error(e?.message || t('تعذّر ربط الفيديو', 'Could not link the video'));
    } finally {
      setLinkingId(null);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange} className="max-w-2xl">
      <DialogContent>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Film className="h-5 w-5 text-teal-600" />
            {t('اختيار فيديو موجود', 'Choose an existing video')}
          </DialogTitle>
        </DialogHeader>

        <p className="text-xs text-gray-500">
          {t('أعد استخدام فيديو رفعته سابقاً — بلا رفع جديد، ولا تُحتسب المساحة مرتين.', 'Reuse a video you already uploaded — no new upload, and storage is never counted twice.')}
        </p>

        {loading ? (
          <div className="flex items-center justify-center py-10 text-gray-400"><Loader2 className="h-5 w-5 animate-spin" /></div>
        ) : error ? (
          <div className="space-y-3 py-6 text-center">
            <p className="text-sm text-red-500">{error}</p>
            <Button variant="outline" size="sm" onClick={() => void load()}>{t('إعادة المحاولة', 'Retry')}</Button>
          </div>
        ) : assets.length === 0 ? (
          <EmptyState icon={Film} title={t('لا توجد فيديوهات جاهزة', 'No ready videos yet')} description={t('ارفع فيديو أولاً ليظهر هنا.', 'Upload a video first and it will appear here.')} />
        ) : (
          <div className="max-h-[55vh] space-y-2 overflow-y-auto pe-1">
            {assets.map((a) => {
              const usedIn = a.reference_count || 0;
              return (
                <div key={a.id} className="flex items-center gap-3 rounded-xl border bg-gray-50/60 p-2.5">
                  <div className="flex h-12 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-white">
                    {a.thumbnail_url
                      /* eslint-disable-next-line @next/next/no-img-element */
                      ? <img src={a.thumbnail_url} alt="" className="h-full w-full object-cover" />
                      : <Film className="h-5 w-5 text-gray-400" />}
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{a.title || t('فيديو', 'Video')}</p>
                    <div className="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-gray-400">
                      <span className="inline-flex items-center gap-1"><Clock className="h-3 w-3" />{formatDuration(a.duration_seconds)}</span>
                      {usedIn > 1 && (
                        <Badge variant="secondary" className="gap-1 rounded-full text-[10px]">
                          <Layers className="h-3 w-3" />{t(`مستخدم في ${usedIn} دروس`, `Used in ${usedIn} lessons`)}
                        </Badge>
                      )}
                    </div>
                  </div>
                  <Button size="sm" className="shrink-0 gap-1.5 rounded-full" disabled={!!linkingId} onClick={() => attach(a)}>
                    {linkingId === a.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Link2 className="h-3.5 w-3.5" />}
                    {t('ربط', 'Link')}
                  </Button>
                </div>
              );
            })}
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
