/**
 * Shared, exam-mode display rules.
 *
 * A teacher can mark an exam as an ungraded "practice" (`assessment_mode ===
 * 'practice'`). A practice has no pass mark and no pass/fail outcome, so every
 * student-facing surface must hide pass-mark and passing-score indicators for
 * it — while graded exams keep their normal pass/fail behaviour untouched.
 */

export type ExamModeCarrier = { assessment_mode?: string | null } | null | undefined;

/** True for an ungraded practice; false for a normal graded exam. */
export function isPracticeExam(exam: ExamModeCarrier): boolean {
  return exam?.assessment_mode === 'practice';
}

/**
 * Should this exam surface show its pass mark / passing indicators at all?
 * Only real graded exams do — never a practice.
 */
export function showsPassMark(exam: ExamModeCarrier): boolean {
  return !isPracticeExam(exam);
}
