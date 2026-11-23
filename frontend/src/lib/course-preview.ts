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
