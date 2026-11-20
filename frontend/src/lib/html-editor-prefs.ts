/**
 * Per-teacher HTML-editor preferences (height, grading mode, auto-capture),
 * persisted server-side on the user row (never per device) and scoped per
 * tenant, so switching account or tenant never bleeds settings across.
 *
 * Only these non-secret editor fields are ever stored — the answer key and any
 * grading secret stay on the question, never in preferences.
 */
const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export type HtmlEditorPrefs = {
  height: string;
  grading_mode: 'auto' | 'manual';
  grading_rule: string;
  auto_capture: boolean;
};

export const HTML_EDITOR_DEFAULTS: HtmlEditorPrefs = {
  height: '360',
  grading_mode: 'manual',
  grading_rule: 'single_choice',
  auto_capture: true,
};

function headers(): Record<string, string> {
  return {
    'Content-Type': 'application/json',
    'X-Tenant-Id': localStorage.getItem('tenant_id') || '',
    Accept: 'application/json',
  };
}

/** Coerce anything read back from the server into a safe, valid prefs object. */
export function sanitizeHtmlEditorPrefs(raw: unknown): HtmlEditorPrefs {
  const source = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>;
  const height = Number(source.height);
  return {
    height: Number.isFinite(height) && height >= 120 && height <= 1600
      ? String(Math.round(height))
      : HTML_EDITOR_DEFAULTS.height,
    grading_mode: source.grading_mode === 'auto' ? 'auto' : 'manual',
    grading_rule: typeof source.grading_rule === 'string' && source.grading_rule
      ? source.grading_rule
      : HTML_EDITOR_DEFAULTS.grading_rule,
    auto_capture: source.auto_capture !== false,
  };
}

export async function fetchHtmlEditorPrefs(): Promise<HtmlEditorPrefs | null> {
  try {
    const res = await fetch(`${API}/me/preferences`, { headers: headers(), credentials: 'same-origin' });
    if (!res.ok) return null;
    const data = await res.json().catch(() => null);
    const group = data?.preferences?.html_editor;
    return group ? sanitizeHtmlEditorPrefs(group) : null;
  } catch {
    return null;
  }
}

export async function saveHtmlEditorPrefs(prefs: Partial<HtmlEditorPrefs>): Promise<void> {
  try {
    await fetch(`${API}/me/preferences`, {
      method: 'PUT',
      headers: headers(),
      credentials: 'same-origin',
      body: JSON.stringify({ preferences: { html_editor: prefs } }),
    });
  } catch {
    /* best-effort: a preference save must never block authoring */
  }
}
