'use client';

import { useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Loader2, Copy, Info, CheckCircle2, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { defaultDuplicateTitle, summarizeDuplicateReport, type DuplicateReport } from '@/lib/duplicate';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export type DuplicateKind = 'course' | 'lesson' | 'exam';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  kind: DuplicateKind;
  id: string | null;
  originalTitle: string;
  /** Called after a successful copy so the host page can refresh its lists. */
  onDuplicated?: (newId: string) => void;
};

/**
 * «إنشاء نسخة» — يطلب اسماً للنسخة ويعرض نتيجة واضحة (ما نُسخ وما أُعيد
 * استخدامه وما لم يُنسخ). النسخة تبدأ دائماً مسودة وبلا أي بيانات طلاب.
 */
export function DuplicateDialog({ open, onOpenChange, kind, id, originalTitle, onDuplicated }: Props) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [title, setTitle] = useState('');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ title: string; detail: string; hasWarnings: boolean; newId: string } | null>(null);

  useEffect(() => {
    if (open) {
      setTitle(defaultDuplicateTitle(originalTitle, locale));
      setResult(null);
    }
  }, [open, originalTitle, locale]);

  const kindLabel = kind === 'course' ? t('الكورس', 'course') : kind === 'lesson' ? t('الدرس', 'lesson') : t('الاختبار', 'exam');
  const resource = kind === 'course' ? 'courses' : kind === 'lesson' ? 'lessons' : 'exams';

  const run = async () => {
    if (!id) return;
    setBusy(true);
    try {
      const headers = {
        'Content-Type': 'application/json',
        'X-Tenant-Id': localStorage.getItem('tenant_id') || '',
        Accept: 'application/json',
      };
      const res = await fetch(`${API}/${resource}/${id}/duplicate`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ title: title.trim() || undefined }),
      });
      const d = await res.json();
      if (!res.ok) {
        toast.error(d.message || t('تعذّر إنشاء النسخة', 'Could not create the copy'));
        return;
      }
      const summary = summarizeDuplicateReport(d.report as DuplicateReport, locale);
      const newId = d.course?.id || d.lesson?.id || d.exam?.id || '';
      setResult({ ...summary, newId });
      toast.success(summary.title);
      onDuplicated?.(newId);
    } catch {
      toast.error(t('تعذّر إنشاء النسخة', 'Could not create the copy'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!busy) onOpenChange(v); }}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Copy className="h-5 w-5 text-teal-600" />
            {result ? t('تم إنشاء النسخة', 'Copy created') : t('إنشاء نسخة من ', 'Duplicate ') + kindLabel}
          </DialogTitle>
        </DialogHeader>

        {result ? (
          <div className="space-y-3">
            <div className="flex items-start gap-2 rounded-xl border border-emerald-100 bg-emerald-50/60 p-3">
              <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
              <div className="space-y-1 text-sm">
                <p className="font-semibold text-emerald-800">{t('نسخة جديدة (مسودة)', 'New copy (draft)')}</p>
                <p className="text-emerald-700">{result.detail}</p>
              </div>
            </div>
            {result.hasWarnings && (
              <div className="flex items-start gap-2 rounded-xl border border-amber-100 bg-amber-50/60 p-3 text-sm text-amber-800">
                <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                <p>{t('بعض العناصر لم تُنسخ — راجع التفاصيل أعلى.', 'Some items were not copied — see the details above.')}</p>
              </div>
            )}
          </div>
        ) : (
          <div className="space-y-3">
            <div className="space-y-2">
              <Label>{t('اسم النسخة', 'Copy name')}</Label>
              <Input value={title} onChange={(e) => setTitle(e.target.value)} className="h-11" autoFocus
                onKeyDown={(e) => { if (e.key === 'Enter' && !busy) run(); }} />
            </div>
            <div className="flex items-start gap-2 rounded-xl border border-slate-100 bg-slate-50/70 p-3 text-xs text-slate-500">
              <Info className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
              <p>
                {t('تُنسخ البنية والمحتوى والإعدادات فقط، وتبدأ النسخة كمسودة. لا تُنسخ بيانات الطلاب (التسجيلات، المحاولات، الدرجات، المدفوعات). ',
                  'Only structure, content and settings are copied, and the copy starts as a draft. Student data (enrollments, attempts, grades, payments) is never copied. ')}
                {t('الفيديوهات والملفات تُعاد بالمرجع (بلا نسخ تخزين إضافي أو نسخة Bunny ثانية).',
                  'Videos and files are reused by reference (no extra storage and no second Bunny copy).')}
              </p>
            </div>
          </div>
        )}

        <DialogFooter>
          {result ? (
            kind === 'course' && result.newId ? (
              <>
                <Button variant="outline" onClick={() => onOpenChange(false)}>{t('إغلاق', 'Close')}</Button>
                <a href={`/courses/${result.newId}`}><Button>{t('فتح النسخة', 'Open copy')}</Button></a>
              </>
            ) : (
              <Button onClick={() => onOpenChange(false)}>{t('تم', 'Done')}</Button>
            )
          ) : (
            <>
              <Button variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>{t('إلغاء', 'Cancel')}</Button>
              <Button onClick={run} disabled={busy} className="gap-2">
                {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Copy className="h-4 w-4" />}
                {t('إنشاء النسخة', 'Create copy')}
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
