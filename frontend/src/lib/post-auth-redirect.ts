/**
 * Post-authentication "return to where you were" intent.
 *
 * A student who lands on a course page is sent to sign up / log in with a
 * `?course=` marker. The target is stored locally so the SAME destination is
 * honoured after the password flow AND the Google OAuth round-trip (whose
 * signed state carries no arbitrary redirect, by design). Only same-site,
 * single-slash paths are ever stored or returned — an external or
 * protocol-relative value is discarded, so the marker cannot become an open
 * redirect.
 */
const KEY = 'lms_post_auth_redirect';

/** A safe in-app path: starts with a single `/`, never `//` or `/\`. */
export function isSafeInternalPath(value: unknown): value is string {
  return typeof value === 'string' && /^\/(?!\/|\\)/.test(value);
}

export function setPendingRedirect(path: unknown): void {
  if (typeof window === 'undefined' || !isSafeInternalPath(path)) return;
  try {
    localStorage.setItem(KEY, path);
  } catch {
    /* storage may be unavailable (private mode) — never a hard failure */
  }
}

/** Return the stored destination and clear it (single use). */
export function consumePendingRedirect(): string | null {
  if (typeof window === 'undefined') return null;
  try {
    const value = localStorage.getItem(KEY);
    localStorage.removeItem(KEY);
    return isSafeInternalPath(value) ? value : null;
  } catch {
    return null;
  }
}
