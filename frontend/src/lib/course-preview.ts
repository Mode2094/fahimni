/**
 * "Preview as student" URL helpers.
 *
 * The teacher opens the real student experience (the course player or the exam
 * taker) in a new tab. `preview=1` is only a client hint that turns on the
 * preview banner and disables student-side write actions; the server always
 * authorises preview access independently (tenant staff / course author), so a
 * student passing `preview=1` gains nothing and can never bypass publishing or
 * a subscription.
 */

export interface PreviewQuery {
  /** The teacher explicitly opened the student view in preview mode. */
  preview: boolean;
  /** A specific lesson to open, when the preview was launched from a lesson. */
  lessonId: string | null;
}

/** Parse the `preview` / `lesson` hints from a location search string. */
export function parsePreviewQuery(search: string): PreviewQuery {
  const params = new URLSearchParams(search.startsWith('?') ? search : `?${search}`);
  return {
    preview: params.get('preview') === '1',
    lessonId: params.get('lesson') || null,
  };
}

/** Build the student-facing course player URL, optionally in preview mode. */
export function buildLearnUrl(
  courseId: string,
  opts: { preview?: boolean; lessonId?: string } = {},
): string {
  const params = new URLSearchParams();
  if (opts.preview) params.set('preview', '1');
  if (opts.lessonId) params.set('lesson', opts.lessonId);
  const qs = params.toString();
  return `/course/${courseId}/learn${qs ? `?${qs}` : ''}`;
}

/** Build the exam-taking URL, keeping preview mode inside a previewed course. */
export function buildExamUrl(examId: string, opts: { preview?: boolean } = {}): string {
  return `/exam/${examId}${opts.preview ? '?preview=1' : ''}`;
}

export interface LearnTaskQuery {
  /** The course-player tab the caller asked to open, if it is one we support. */
  tab: 'assignments' | 'exams' | null;
  /** A specific assignment to focus once the assignments tab has loaded. */
  assignmentId: string | null;
  /** A specific exam to focus once the exams tab has loaded. */
  examId: string | null;
}

/**
 * Parse the task deep-link hints from a course-player location search string.
 *
 * This is only a client hint: it selects a tab and scroll target, and grants no
 * access on its own — the server still authorises every assignment and exam
 * independently, so an unknown id simply finds nothing to focus.
 */
export function parseLearnTaskQuery(search: string): LearnTaskQuery {
  const params = new URLSearchParams(search.startsWith('?') ? search : `?${search}`);
  const tab = params.get('tab');
  return {
    tab: tab === 'assignments' || tab === 'exams' ? tab : null,
    assignmentId: params.get('assignment') || null,
    examId: params.get('exam') || null,
  };
}

/** Build the course-player URL that opens the assignments tab focused on one task. */
export function buildLearnAssignmentUrl(courseId: string, assignmentId: string): string {
  const params = new URLSearchParams({ tab: 'assignments', assignment: assignmentId });
  return `/course/${encodeURIComponent(courseId)}/learn?${params.toString()}`;
}
