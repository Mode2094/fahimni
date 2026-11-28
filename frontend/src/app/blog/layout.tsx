import type { Metadata } from 'next';
import { NOINDEX } from '@/lib/seo';

/**
 * NOTE: `/blog` is the AUTH-GATED teacher blog manager (create/edit/publish
 * posts) — it is NOT a public marketing blog. It must never be indexed.
 * Public blog content lives at `/t/{slug}/blog`.
 */
const title = 'مدير مقالات المعلم — Teacher blog manager';

export const metadata: Metadata = {
  title,
  robots: NOINDEX,
};

export default function Layout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}
