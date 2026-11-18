/**
 * Client-side analytics helpers (Meta Pixel + Google Analytics 4).
 *
 * Deliberately dependency-free and defensive: every call no-ops when the
 * vendor script is absent (trackers disabled, blocked, or not configured yet),
 * so components can fire events without guarding.
 *
 * IDs come from platform settings (admin panel) and are injected at runtime by
 * `<Analytics />` — no rebuild is needed to change them.
 */

declare global {
  interface Window {
    fbq?: (...args: unknown[]) => void;
    _fbq?: unknown;
    gtag?: (...args: unknown[]) => void;
    dataLayer?: unknown[];
  }
}

export type EventParams = Record<string, unknown>;

/** Meta Pixel event (e.g. 'Lead', 'CompleteRegistration', 'Purchase'). */
export function trackMeta(event: string, params?: EventParams): void {
  try {
    window.fbq?.('track', event, params || {});
  } catch {
    /* analytics must never throw into the UI */
  }
}

/** GA4 event. */
export function trackGa(event: string, params?: EventParams): void {
  try {
    window.gtag?.('event', event, params || {});
  } catch {
    /* no-op */
  }
}

/** Fire the same event on every configured provider (optional GA-specific name). */
export function track(event: string, params?: EventParams, gaEvent?: string): void {
  trackMeta(event, params);
  trackGa(gaEvent || event, params);
}

export function pageView(path: string): void {
  try {
    window.fbq?.('track', 'PageView');
  } catch {
    /* no-op */
  }
  try {
    window.gtag?.('event', 'page_view', { page_path: path });
  } catch {
    /* no-op */
  }
}

/** True when at least one provider is live in the browser. */
export function analyticsReady(): boolean {
  return typeof window !== 'undefined' && (typeof window.fbq === 'function' || typeof window.gtag === 'function');
}
