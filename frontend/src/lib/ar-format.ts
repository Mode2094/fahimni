/**
 * Arabic-aware number/plural/duration formatting.
 *
 * The app is Arabic-first and RTL, but before this helper every count was
 * rendered as `{n} {word}` (singular) or `{n} {plural}` — never the Arabic
 * dual or the correct plural category. It also mixed English into the Arabic
 * UI ("0 min", "5 min"). This module is the single place that decides the
 * wording of a counted noun and a duration.
 *
 * Grammar used (Modern Standard Arabic, integer counts):
 *   0        → negation form        "لا دروس"
 *   1        → singular + واحد      "درس واحد"
 *   2        → dual (carries 2)     "درسان"
 *   3–10     → plural + the number  "3 دروس"
 *   11–99    → singular + the number "11 درساً"
 *   100, 101 → singular + the number "100 درس" (last-part rule kept simple)
 */

export type Locale = 'ar' | 'en';

export interface ArCountForms {
  /** 0 — e.g. "لا دروس" */
  zero?: string;
  /** 1 — includes the word "واحد/واحدة", e.g. "درس واحد" */
  one: string;
  /** 2 — the dual, e.g. "درسان" */
  two: string;
  /** 3–10 */
  few: string;
  /** 11–99 (and 100+), the singular noun after the number */
  many: string;
  /** English fallback plural (also used for 100+ in English) */
  enOne?: string;
  enMany?: string;
}

type ArCategory = 'zero' | 'one' | 'two' | 'few' | 'many';

function count(n: number): number {
  return Number.isFinite(n) ? Math.max(0, Math.floor(n)) : 0;
}

export function arabicPluralCategory(n: number): ArCategory {
  const num = count(n);
  if (num === 0) return 'zero';
  if (num === 1) return 'one';
  if (num === 2) return 'two';
  const mod100 = num % 100;
  if (mod100 >= 3 && mod100 <= 10) return 'few';
  return 'many'; // 11–99, 100, 101, …
}

/**
 * Format a counted noun. In Arabic the number is included where grammar needs
 * it; the dual and the singular word already carry it.
 */
export function formatCount(n: number, forms: ArCountForms, locale: Locale = 'ar'): string {
  const num = count(n);
  if (locale !== 'ar') {
    const one = forms.enOne ?? forms.one;
    const many = forms.enMany ?? forms.many;
    return `${num} ${num === 1 ? one : many}`;
  }

  const cat = arabicPluralCategory(num);
  if (cat === 'zero') return forms.zero ?? `لا ${forms.many}`;
  if (cat === 'one') return forms.one;
  if (cat === 'two') return forms.two;
  return `${num} ${forms[cat]}`;
}

const LESSON_FORMS: ArCountForms = {
  zero: 'لا دروس',
  one: 'درس واحد',
  two: 'درسان',
  few: 'دروس',
  many: 'درساً',
  enOne: 'lesson',
  enMany: 'lessons',
};

const MINUTE_FORMS: ArCountForms = {
  zero: 'لا دقائق',
  one: 'دقيقة واحدة',
  two: 'دقيقتان',
  few: 'دقائق',
  many: 'دقيقة',
  enOne: 'minute',
  enMany: 'minutes',
};

const HOUR_FORMS: ArCountForms = {
  zero: 'لا ساعات',
  one: 'ساعة واحدة',
  two: 'ساعتان',
  few: 'ساعات',
  many: 'ساعة',
  enOne: 'hour',
  enMany: 'hours',
};

const QUESTION_FORMS: ArCountForms = {
  zero: 'لا أسئلة',
  one: 'سؤال واحد',
  two: 'سؤالان',
  few: 'أسئلة',
  many: 'سؤالاً',
  enOne: 'question',
  enMany: 'questions',
};

const STUDENT_FORMS: ArCountForms = {
  zero: 'لا طلاب',
  one: 'طالب واحد',
  two: 'طالبان',
  few: 'طلاب',
  many: 'طالباً',
  enOne: 'student',
  enMany: 'students',
};

const COURSE_FORMS: ArCountForms = {
  zero: 'لا كورسات',
  one: 'كورس واحد',
  two: 'كورسان',
  few: 'كورسات',
  many: 'كورساً',
  enOne: 'course',
  enMany: 'courses',
};

const UNIT_FORMS: ArCountForms = {
  zero: 'لا وحدات', one: 'وحدة واحدة', two: 'وحدتان', few: 'وحدات', many: 'وحدة',
  enOne: 'unit', enMany: 'units',
};

const EXAM_FORMS: ArCountForms = {
  zero: 'لا اختبارات', one: 'اختبار واحد', two: 'اختباران', few: 'اختبارات', many: 'اختباراً',
  enOne: 'exam', enMany: 'exams',
};

const ASSIGNMENT_FORMS: ArCountForms = {
  zero: 'لا واجبات', one: 'واجب واحد', two: 'واجبان', few: 'واجبات', many: 'واجباً',
  enOne: 'assignment', enMany: 'assignments',
};

const VIDEO_FORMS: ArCountForms = {
  zero: 'لا فيديوهات', one: 'فيديو واحد', two: 'فيديوهان', few: 'فيديوهات', many: 'فيديو',
  enOne: 'video', enMany: 'videos',
};

const FILE_FORMS: ArCountForms = {
  zero: 'لا ملفات', one: 'ملف واحد', two: 'ملفان', few: 'ملفات', many: 'ملفاً',
  enOne: 'file', enMany: 'files',
};

export const plural = {
  lessons: (n: number, l: Locale = 'ar') => formatCount(n, LESSON_FORMS, l),
  minutes: (n: number, l: Locale = 'ar') => formatCount(n, MINUTE_FORMS, l),
  hours: (n: number, l: Locale = 'ar') => formatCount(n, HOUR_FORMS, l),
  questions: (n: number, l: Locale = 'ar') => formatCount(n, QUESTION_FORMS, l),
  students: (n: number, l: Locale = 'ar') => formatCount(n, STUDENT_FORMS, l),
  courses: (n: number, l: Locale = 'ar') => formatCount(n, COURSE_FORMS, l),
  units: (n: number, l: Locale = 'ar') => formatCount(n, UNIT_FORMS, l),
  exams: (n: number, l: Locale = 'ar') => formatCount(n, EXAM_FORMS, l),
  assignments: (n: number, l: Locale = 'ar') => formatCount(n, ASSIGNMENT_FORMS, l),
  videos: (n: number, l: Locale = 'ar') => formatCount(n, VIDEO_FORMS, l),
  files: (n: number, l: Locale = 'ar') => formatCount(n, FILE_FORMS, l),
  lessonsLabel: (l: Locale = 'ar') => (l === 'ar' ? 'دروس' : 'lessons'),
};

/**
 * Human duration from whole minutes. Never renders the old "0 min" bug:
 * an unknown/zero duration becomes "بدون مدة" instead of a misleading zero.
 */
export function formatDuration(minutes: number | null | undefined, locale: Locale = 'ar'): string {
  const m = Number(minutes);
  if (!Number.isFinite(m) || m <= 0) {
    return locale === 'ar' ? 'بدون مدة' : 'No duration';
  }
  const whole = Math.round(m);
  const hours = Math.floor(whole / 60);
  const mins = whole % 60;

  if (hours === 0) return plural.minutes(mins, locale);
  if (mins === 0) return plural.hours(hours, locale);
  if (locale === 'ar') return `${plural.hours(hours, locale)} و${plural.minutes(mins, locale)}`;
  return `${plural.hours(hours, locale)} ${plural.minutes(mins, locale)}`;
}

/** Compact clock-like duration for tight UI (player, lists): "5:30", "1:05:00". */
export function formatClock(totalSeconds: number | null | undefined): string {
  const s = Number(totalSeconds);
  if (!Number.isFinite(s) || s <= 0) return '0:00';
  const secs = Math.floor(s);
  const h = Math.floor(secs / 3600);
  const m = Math.floor((secs % 3600) / 60);
  const sec = secs % 60;
  const mm = String(m).padStart(2, '0');
  const ss = String(sec).padStart(2, '0');
  return h > 0 ? `${h}:${mm}:${ss}` : `${m}:${ss}`;
}
