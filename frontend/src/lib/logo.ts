const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';
// جذر الخادم (بدون /api/v1) — الملفات العامة تحت /storage مباشرة.
const API_ORIGIN = API.replace(/\/api\/v1\/?$/, '');

/** يحول مسار اللوجو النسبي (/storage/...) إلى مسار كامل عبر الخادم — وإلا سيفشل التحميل من Next (3000). */
export function resolveLogoUrl(url?: string | null): string {
  if (!url) return '/logo.png';
  if (url.startsWith('http://') || url.startsWith('https://')) return url;
  if (url.startsWith('/storage/') || url.startsWith('/uploads/')) return `${API_ORIGIN}${url}`;
  return url;
}
