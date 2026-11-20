/**
 * Shared presentation metadata for platform (Super-Admin → teacher)
 * notifications. Kept in one place so the admin panel, the teacher page and
 * the header bell always agree on labels and colours.
 */

export const PLATFORM_SENDER = { ar: 'إدارة منصة فهمني', en: 'Fahimni Platform Team' };

export const CATEGORY_META: Record<string, { ar: string; en: string; className: string }> = {
  news: { ar: 'خبر', en: 'News', className: 'bg-sky-500/10 text-sky-600' },
  update: { ar: 'تحديث', en: 'Update', className: 'bg-teal-500/10 text-teal-700' },
  alert: { ar: 'تنبيه مهم', en: 'Important alert', className: 'bg-rose-500/10 text-rose-600' },
  maintenance: { ar: 'صيانة', en: 'Maintenance', className: 'bg-amber-500/10 text-amber-600' },
};

export const CATEGORY_OPTIONS = ['news', 'update', 'alert', 'maintenance'] as const;

export const STATUS_META: Record<string, { ar: string; en: string; className: string }> = {
  draft: { ar: 'مسودة', en: 'Draft', className: 'bg-gray-100 text-gray-500' },
  sent: { ar: 'مُرسل', en: 'Sent', className: 'bg-teal-500/10 text-teal-700' },
  retracted: { ar: 'مسحوب', en: 'Retracted', className: 'bg-amber-500/10 text-amber-600' },
};

export function categoryLabel(key: string, locale: string): string {
  const meta = CATEGORY_META[key];
  return meta ? (locale === 'ar' ? meta.ar : meta.en) : key;
}

export function statusLabel(key: string, locale: string): string {
  const meta = STATUS_META[key];
  return meta ? (locale === 'ar' ? meta.ar : meta.en) : key;
}

export function formatDateTime(value?: string | null): string {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}
