'use client';

import { useEffect } from 'react';
import { usePathname } from 'next/navigation';
import { useSettings } from '@/store/settings';
import { pageView } from '@/lib/analytics';

/**
 * Runtime analytics loader.
 *
 * Reads the public IDs from platform settings (admin panel) and injects the
 * Meta Pixel / GA4 scripts on the client. This is required because the app is
 * served under a strict nonce CSP (`'strict-dynamic'`): a script created by an
 * already-trusted bundle chunk stays trusted, so runtime injection works while
 * arbitrary third-party tags would not.
 *
 * Sends a PageView on every route change (GA is configured with
 * `send_page_view: false` so nothing is double-counted).
 */

type Loaded = { meta: string; ga: string; gtm: string };

const loaded: Loaded = { meta: '', ga: '', gtm: '' };

function injectMetaPixel(id: string): void {
  if (typeof window === 'undefined') return;

  if (typeof window.fbq !== 'function') {
    const fbq = function (...args: unknown[]) {
      const self = fbq as unknown as { callMethod?: (...a: unknown[]) => void; queue: unknown[] };
      if (typeof self.callMethod === 'function') self.callMethod.apply(self, args);
      else self.queue.push(args);
    } as unknown as ((...args: unknown[]) => void) & {
      callMethod?: (...a: unknown[]) => void;
      queue: unknown[];
      push: unknown;
      loaded: boolean;
      version: string;
    };

    fbq.queue = [];
    fbq.loaded = true;
    fbq.version = '2.0';
    fbq.push = fbq;
    window.fbq = fbq;
    window._fbq = fbq;

    const script = document.createElement('script');
    script.async = true;
    script.src = 'https://connect.facebook.net/en_US/fbevents.js';
    document.head.appendChild(script);
  }

  window.fbq?.('init', id);
}

function injectGa4(id: string): void {
  if (typeof window === 'undefined') return;

  const script = document.createElement('script');
  script.async = true;
  script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`;
  document.head.appendChild(script);

  window.dataLayer = window.dataLayer || [];
  if (typeof window.gtag !== 'function') {
    window.gtag = function (...args: unknown[]) {
      window.dataLayer?.push(args);
    };
  }
  window.gtag('js', new Date());
  // page_view is emitted manually on route change — avoid double counting.
  window.gtag('config', id, { send_page_view: false });
}

export function Analytics() {
  const { settings } = useSettings();
  const pathname = usePathname();
  const enabled = settings.analytics_enabled !== false;
  const pixelId = enabled ? (settings.meta_pixel_id || '').trim() : '';
  const gaId = enabled ? (settings.ga_measurement_id || '').trim() : '';

  // Bootstrap each provider exactly once per unique ID.
  useEffect(() => {
    if (!pixelId || loaded.meta === pixelId) return;
    loaded.meta = pixelId;
    injectMetaPixel(pixelId);
  }, [pixelId]);

  useEffect(() => {
    if (!gaId || loaded.ga === gaId) return;
    loaded.ga = gaId;
    injectGa4(gaId);
  }, [gaId]);

  // PageView on every route change (and once the pixel finishes booting).
  useEffect(() => {
    if (!pixelId && !gaId) return;
    // Defer so a bootstrap that ran in this same commit lands first.
    const id = window.setTimeout(() => pageView(pathname || '/'), 0);
    return () => window.clearTimeout(id);
  }, [pathname, pixelId, gaId]);

  return null;
}

/**
 * Renders the Facebook domain-verification meta tag when configured in the
 * admin panel (alternative to a DNS TXT record).
 */
export function MetaVerification() {
  const verification = (useSettings((s) => s.settings.meta_domain_verification) || '').trim();

  useEffect(() => {
    if (!verification) return;
    document
      .querySelectorAll('meta[name="facebook-domain-verification"]')
      .forEach((el) => el.remove());
    const meta = document.createElement('meta');
    meta.name = 'facebook-domain-verification';
    meta.content = verification;
    document.head.appendChild(meta);
  }, [verification]);

  return null;
}
