import type { Metadata } from 'next';
import NotFoundView from '@/components/not-found-view';

/**
 * Root 404. Next.js injects `<meta name="robots" content="noindex">` for
 * every 404 response, but the root layout otherwise applies its site-wide
 * `robots: { index: true, follow: true, googleBot: { … } }`. Without an
 * override here, a 404 would emit *both* the injected `noindex` and a
 * conflicting `index, follow` (plus a `googlebot: index, follow`). Override
 * `robots` (and `googleBot`) for this segment so 404s are unambiguously
 * noindex while real pages keep the site-wide indexing directives.
 */
export const metadata: Metadata = {
  robots: { index: false, follow: false, googleBot: { index: false, follow: false } },
};

export default function NotFound() {
  return <NotFoundView />;
}
