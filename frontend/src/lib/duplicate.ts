/**
 * «إنشاء نسخة» — مساعدات نقية لتقارير النسخ.
 *
 * النسخة الجديدة تبدأ دائماً مسودة، ولا تنسخ أي بيانات طلاب. الوسائط الثقيلة
 * (فيديو Bunny/الملفات) تُعاد بالمرجع، وأسئلة الاختبارات تُعاد ربطها من بنك
 * الأسئلة — لذلك يعرض التقرير «أُعيد استخدامها» لا «نُسخت».
 */

export type DuplicateReport = {
  sections: number;
  lessons: number;
  contents: number;
  videos_reused: number;
  files_reused: number;
  exams: number;
  questions_reused: number;
  assignments: number;
  skipped: { type: string; title: string }[];
};

export type DuplicateSummary = {
  title: string;
  detail: string;
  skipped: string[];
  hasWarnings: boolean;
};

/** الاسم الافتراضي للنسخة (~ نسخة). */
export function defaultDuplicateTitle(original: string, locale: string): string {
  const base = (original || '').trim();
  const suffix = locale === 'ar' ? ' — نسخة' : ' — copy';

  return (base || (locale === 'ar' ? 'بدون عنوان' : 'Untitled')) + suffix;
}

const SKIP_LABELS: Record<string, [string, string]> = {
  live: ['البث المباشر', 'Live session'],
  question: ['سؤال غير متاح', 'Unavailable question'],
};

/** رسالة واضحة: ماذا نُسخ بالضبط، وما الذي تعذّر نسخه بأمان. */
export function summarizeDuplicateReport(report: DuplicateReport, locale: string): DuplicateSummary {
  const ar = locale === 'ar';
  const t = (a: string, e: string) => (ar ? a : e);

  const first: string[] = [];
  if (report.sections > 0) first.push(`${report.sections} ${t('وحدة', 'units')}`);
  if (report.lessons > 0) first.push(`${report.lessons} ${t('درس', 'lessons')}`);
  if (report.exams > 0) first.push(`${report.exams} ${t('اختبار', 'exams')}`);
  if (report.assignments > 0) first.push(`${report.assignments} ${t('واجب', 'assignments')}`);

  const reused: string[] = [];
  if (report.videos_reused > 0) reused.push(`${report.videos_reused} ${t('فيديو', 'videos')}`);
  if (report.files_reused > 0) reused.push(`${report.files_reused} ${t('ملف', 'files')}`);

  const title = t('تم إنشاء النسخة (مسودة)', 'Copy created (draft)');

  const parts: string[] = [];
  if (first.length) parts.push(t('تم نسخ', 'Copied') + ': ' + first.join('، '));
  if (reused.length) {
    parts.push(
      t('أُعيد استخدام', 'Reused') + ': ' + reused.join('، ') + ' ' + t('(بلا نسخ تخزين إضافي)', '(no extra storage)'),
    );
  }
  if (report.questions_reused > 0) {
    parts.push(t(`أُعيد ربط ${report.questions_reused} سؤال من بنك الأسئلة`, `${report.questions_reused} question(s) relinked from the bank`));
  }
  if (!parts.length) parts.push(t('نسخة فارغة جاهزة للتحرير', 'An empty copy ready to edit'));

  const skipped = (report.skipped || []).map((s) => {
    const label = SKIP_LABELS[s.type]?.[ar ? 0 : 1] || s.type;
    return s.title ? `${label}: ${s.title}` : label;
  });

  if (skipped.length) {
    parts.push(t('لم يُنسخ', 'Not copied') + ': ' + skipped.join('، '));
  }

  return { title, detail: parts.join(' · '), skipped, hasWarnings: skipped.length > 0 };
}
