const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export type ProgressStatus = 'not_started' | 'in_progress' | 'completed';

function tenantHeader(): Record<string, string> {
  const tenantId = typeof window !== 'undefined' ? localStorage.getItem('tenant_id') : null;
  return tenantId ? { 'X-Tenant-Id': tenantId } : {};
}

/**
 * Persist a student's watch progress for one lesson.
 *
 * Best-effort by design: a failed autosave must never interrupt playback, so
 * errors resolve to `null`. Marking COMPLETED is server-enforced (valid
 * enrollment, unlocked lesson, minimum watch time for videos) — a `null` here
 * means the server rejected it, and the caller surfaces that honestly.
 */
export async function saveLessonProgress(
  enrollmentId: string,
  lessonId: string,
  payload: { status?: ProgressStatus; watch_time_seconds?: number; metadata?: Record<string, unknown> },
): Promise<{ status?: string } | null> {
  try {
    const res = await fetch(`${API}/enrollments/${enrollmentId}/progress/${lessonId}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...tenantHeader(),
      },
      body: JSON.stringify({
        status: payload.status ?? 'in_progress',
        watch_time_seconds: Math.max(0, Math.floor(payload.watch_time_seconds ?? 0)),
        metadata: payload.metadata,
      }),
    });
    if (!res.ok) return null;
    const data = await res.json().catch(() => null);
    return data?.progress ?? null;
  } catch {
    return null;
  }
}
