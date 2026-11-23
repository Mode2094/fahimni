/**
 * Student PWA helpers — dependency-free and unit-tested.
 *
 * These functions are deliberately pure (or take their inputs explicitly) so the
 * install/standalone/dismissal logic can be tested without a browser. The
 * service-worker constants live here too so the worker, the registration code
 * and the tests all agree on one source of truth.
 */

/** Same-origin service worker that powers offline support + push. */
export const SW_PATH = '/sw.js';

/** Cached, script-free fallback shown when a navigation cannot reach the network. */
export const OFFLINE_PATH = '/offline';

/** localStorage key holding the last time the soft install prompt was dismissed. */
export const INSTALL_DISMISS_KEY = 'lms_pwa_install_dismissed_at';

/** How long a dismissed install prompt stays quiet (ms). */
export const INSTALL_DISMISS_FOR = 14 * 24 * 60 * 60 * 1000;

/** Routes where we never interrupt the student with an app-update reload. */
const NO_UPDATE_PREFIXES = ['/exam/', '/course/'];

type StandaloneSource = {
  matchMedia?: (query: string) => { matches: boolean };
  navigator?: { standalone?: boolean };
};

/** True when the app is running as an installed PWA (Android/desktop or iOS). */
export function isStandalone(source?: StandaloneSource): boolean {
  const s = source ?? (typeof window !== 'undefined' ? (window as unknown as StandaloneSource) : undefined);
  if (!s) return false;

  const matched = s.matchMedia?.('(display-mode: standalone)')?.matches === true;

  return matched || s.navigator?.standalone === true;
}

/** iOS/iPadOS detection, including iPadOS which reports as a touch Mac. */
export function isIosDevice(ua: string, maxTouchPoints = 0, platform = ''): boolean {
  if (/iPad|iPhone|iPod/.test(ua)) return true;

  return /Mac/.test(platform) && maxTouchPoints > 1;
}

/**
 * Whether the soft "install the app" prompt may be shown right now.
 *
 * Suppressed when already installed, when dismissed within the cool-down
 * window, or when the caller has no way to actually install (no native
 * beforeinstallprompt *and* not iOS, where we show manual Safari steps).
 */
export function shouldOfferInstall(opts: {
  installed: boolean;
  ios: boolean;
  nativePrompt: boolean;
  dismissedAt?: number | null;
  now?: number;
  cooldown?: number;
}): boolean {
  if (opts.installed) return false;

  const now = opts.now ?? Date.now();
  const cooldown = opts.cooldown ?? INSTALL_DISMISS_FOR;
  if (opts.dismissedAt && now - opts.dismissedAt < cooldown) return false;

  return opts.nativePrompt || opts.ios;
}

/** True when the current location must not be reloaded by an app update. */
export function isUpdateBlockedPath(pathname: string): boolean {
  return NO_UPDATE_PREFIXES.some((p) => pathname.startsWith(p));
}

/** Read the dismissal timestamp (ms) for install prompts, or null. */
export function readInstallDismissedAt(): number | null {
  if (typeof window === 'undefined') return null;
  try {
    const raw = window.localStorage.getItem(INSTALL_DISMISS_KEY);
    const n = raw ? Number(raw) : NaN;

    return Number.isFinite(n) ? n : null;
  } catch {
    return null;
  }
}

/** Persist a dismissal so the prompt stays quiet for the cool-down window. */
export function writeInstallDismissedAt(now: number = Date.now()): void {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.setItem(INSTALL_DISMISS_KEY, String(now));
  } catch {
    /* storage blocked — the prompt simply reappears, never a crash */
  }
}
