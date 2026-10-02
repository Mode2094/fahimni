const INDEXNOW_KEY = process.env.INDEXNOW_KEY || '';
const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';

/**
 * Best-effort, non-blocking IndexNow notification. Never throws.
 * Only call with PUBLIC indexable URLs — never private/dashboard URLs.
 *
 * Integration points (create/update/delete of a public page):
 *   notifyIndexNow([`/t/${slug}`, `/pricing`])
 *
 * Prerequisite: serve the key file at https://<host>/{INDEXNOW_KEY}.txt
 * (drop `<key>.txt` into frontend/public/ when INDEXNOW_KEY is set).
 */
export async function notifyIndexNow(urls: string[], host?: string): Promise<void> {
  if (!INDEXNOW_KEY || !Array.isArray(urls) || urls.length === 0) return;
  const h = host || process.env.NEXT_PUBLIC_PLATFORM_DOMAIN || 'tadreesly.com';
  const urlList = urls.map((u) => (u.startsWith('http') ? u : `https://${h}${u.startsWith('/') ? u : `/${u}`}`));
  try {
    await fetch(INDEXNOW_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=utf-8' },
      body: JSON.stringify({ host: h, key: INDEXNOW_KEY, urlList }),
      signal: AbortSignal.timeout(5000),
    });
  } catch {
    /* fire-and-forget */
  }
}