/**
 * Web Push client plumbing for the student PWA.
 *
 * Reuses the EXISTING backend push pipeline (`POST /push-tokens` + the FCM HTTP
 * v1 sender in `App\Services\PushService`) instead of building a parallel
 * system. To make the token usable by that sender, the subscription must be
 * created with the FCM *Web Push certificate* public key — the derived
 * registration token (the last segment of the FCM endpoint) is exactly what
 * FCM HTTP v1 expects.
 *
 * Nothing is invented: when NEXT_PUBLIC_WEBPUSH_PUBLIC_KEY is unset the feature
 * reports itself as unavailable and no request is made.
 */

const PUBLIC_KEY = (process.env.NEXT_PUBLIC_WEBPUSH_PUBLIC_KEY || '').trim();

export function webPushKey(): string {
  return PUBLIC_KEY;
}

/** Whether the build has a push key configured at all. */
export function pushConfigured(): boolean {
  return PUBLIC_KEY.length > 0;
}

/** Browser capability check (Chrome/Edge/Firefox, Safari 16.4+ when installed). */
export function pushSupported(): boolean {
  return (
    typeof window !== 'undefined' &&
    typeof navigator !== 'undefined' &&
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window
  );
}

/** VAPID/FCM public key (base64url) → the byte array `pushManager.subscribe` wants. */
export function urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer> {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = window.atob(base64);
  const output = new Uint8Array(raw.length);

  for (let i = 0; i < raw.length; i += 1) output[i] = raw.charCodeAt(i);

  return output;
}

/**
 * The FCM registration token for an endpoint, or null when the endpoint is not
 * served by FCM (a third-party push service we cannot deliver to with the
 * current backend).
 */
export function fcmTokenFromEndpoint(endpoint: string): string | null {
  try {
    const url = new URL(endpoint);
    if (url.hostname !== 'fcm.googleapis.com') return null;

    const parts = url.pathname.split('/').filter(Boolean);
    const last = parts[parts.length - 1];
    if (!last) return null;

    // `/wp/<regid>` and `/fcm/send/<token>` both end with the registration token.
    return decodeURIComponent(last);
  } catch {
    return null;
  }
}

/**
 * The active registration, without ever hanging. `navigator.serviceWorker.ready`
 * never resolves when no worker is registered (or registration is blocked), so
 * callers that may run before/without a worker (logout, a page that failed to
 * register) must not await it unguarded.
 */
async function readyRegistration(timeoutMs = 3000): Promise<ServiceWorkerRegistration | null> {
  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return null;

  const existing = await navigator.serviceWorker.getRegistration();
  if (existing) return existing;

  return Promise.race([
    navigator.serviceWorker.ready,
    new Promise<null>((resolve) => {
      setTimeout(() => resolve(null), timeoutMs);
    }),
  ]);
}

/** The endpoint of this device's current subscription, if any. */
export async function currentPushEndpoint(): Promise<string | null> {
  const registration = await readyRegistration();
  const subscription = registration?.pushManager ? await registration.pushManager.getSubscription() : null;

  return subscription?.endpoint ?? null;
}

/**
 * Ask for permission + subscribe. Returns the registration token to send to the
 * backend, or null when unsupported / not configured / denied.
 */
export async function subscribeForPush(): Promise<{ endpoint: string; token: string } | null> {
  if (!pushSupported() || !pushConfigured()) return null;

  const permission = await Notification.requestPermission();
  if (permission !== 'granted') return null;

  const registration = await readyRegistration();
  if (!registration?.pushManager) return null;

  const existing = await registration.pushManager.getSubscription();
  const subscription =
    existing ??
    (await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(PUBLIC_KEY),
    }));

  const token = fcmTokenFromEndpoint(subscription.endpoint);
  if (!token) {
    // Subscribed, but not through FCM → the current backend cannot deliver.
    // Leave the browser subscription in place; report failure to the caller.
    return null;
  }

  return { endpoint: subscription.endpoint, token };
}

/** Cancel this device's subscription and return its token (for de-registration). */
export async function unsubscribeFromPush(): Promise<string | null> {
  const registration = await readyRegistration();
  const subscription = registration?.pushManager ? await registration.pushManager.getSubscription() : null;
  if (!subscription) return null;

  const token = fcmTokenFromEndpoint(subscription.endpoint);
  await subscription.unsubscribe().catch(() => {});

  return token;
}
