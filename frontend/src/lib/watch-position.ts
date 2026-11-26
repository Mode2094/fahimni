/**
 * منطق موضع المشاهدة (استكمال الفيديو) — دوال نقية قابلة للاختبار.
 *
 * الخادم هو المصدر الحقيقي (يعزل لكل طالب، ويهمل المواضع غير الموثوقة)، لكن
 * هذه الدوال تمنع طلبات/قفزات لا معنى لها في الواجهة وتبقى متسقة مع قواعد
 * الخادم.
 */

export type WatchResume = { position_seconds?: number; duration_seconds?: number } | null | undefined;

/**
 * هل يصلح الموضع المحفوظ للاستكمال؟
 *  - يتجاهل المواضع التافهة (أقل من ثوانٍ قليلة) فلا نزعج الطالب بخيار لا معنى له.
 *  - فيديو قارب على الانتهاء (آخره ~2% أو 5 ثوانٍ) يُعدّ منتهياً — لا استئناف عند النهاية.
 */
export function canResume(resume: WatchResume, thresholdSeconds = 5): boolean {
  const rawPos = Number(resume?.position_seconds ?? 0);
  if (!Number.isFinite(rawPos)) return false;
  const pos = Math.floor(rawPos);
  if (pos <= thresholdSeconds) return false;

  const rawDur = Number(resume?.duration_seconds ?? 0);
  const duration = Number.isFinite(rawDur) && rawDur > 0 ? Math.floor(rawDur) : 0;
  if (duration > 0 && pos >= duration - Math.max(thresholdSeconds, Math.round(duration * 0.02))) {
    return false;
  }

  return true;
}

/**
 * هل يستحق الموضع الحالي الكتابة؟ نتجاهل النبضات بلا تقدّم فعلي (تخفيف الطلبات).
 */
export function shouldPersist(currentSeconds: number, lastSavedSeconds: number, minDelta = 2): boolean {
  if (!Number.isFinite(currentSeconds) || currentSeconds <= 0) return false;
  return Math.abs(Math.floor(currentSeconds) - Math.floor(lastSavedSeconds)) >= minDelta;
}

/** تنسيق زمني: m:ss أو h:mm:ss. */
export function formatClock(seconds: number): string {
  const s = Math.max(0, Math.floor(Number.isFinite(seconds) ? seconds : 0));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const r = s % 60;
  const pad = (n: number) => String(n).padStart(2, '0');
  return h > 0 ? `${h}:${pad(m)}:${pad(r)}` : `${m}:${pad(r)}`;
}

/**
 * تسجيل ثواني المشاهدة الحقيقية في مجموعة «مغطّاة».
 *
 * نضيف فقط ما شوهد بتشغيل فعلي: قفزة أكبر من `maxStepSeconds` (سحب/تقديم)
 * لا تُحتسب إطلاقاً — لذا لا يمكن للطالب أن يُكمل درساً بالسحب إلى النهاية.
 * إعادة مشاهدة المقطع نفسه لا تزيد العدّ (الثانية تُحسب مرة واحدة).
 * تعيد هل تغيّرت المجموعة.
 */
export function addWatchedBucket(
  covered: Set<number>,
  fromSeconds: number,
  toSeconds: number,
  maxStepSeconds = 3,
): boolean {
  if (!Number.isFinite(fromSeconds) || !Number.isFinite(toSeconds)) return false;
  const from = Math.floor(Math.min(fromSeconds, toSeconds));
  const to = Math.floor(Math.max(fromSeconds, toSeconds));
  if (to - from > maxStepSeconds) return false; // سحب لا مشاهدة
  let changed = false;
  for (let s = from; s <= to; s++) {
    if (!covered.has(s)) {
      covered.add(s);
      changed = true;
    }
  }
  return changed;
}

/** نسبة المشاهدة الحقيقية من مدة الفيديو (0..1). */
export function coverageFraction(covered: Set<number>, durationSeconds: number): number {
  const d = Math.floor(Number(durationSeconds));
  if (!Number.isFinite(d) || d <= 0) return 0;
  return Math.min(1, covered.size / d);
}

/**
 * هل بلغت المشاهدة الحقيقية العتبة المطلوبة (بالثواني)؟ عتبة ≤ 0 تعني «بلا شرط».
 */
export function reachedWatchRequirement(covered: Set<number>, requiredSeconds: number): boolean {
  const required = Math.floor(Number(requiredSeconds));
  if (!Number.isFinite(required) || required <= 0) return true;
  return covered.size >= required;
}
