import type { Metadata } from 'next';
import { ogBase, twitterBase } from '@/lib/seo';

const title = 'الدعم الفني — 24/7 Support';
const description =
  'دعم فني متواصل 24/7 من فريق Tadreesly — نرد خلال ساعة على استفساراتك حول منصتك التعليمية.';

export const metadata: Metadata = {
  title,
  description,
  alternates: { canonical: '/support' },
  openGraph: ogBase(title, description, '/support'),
  twitter: twitterBase(title, description),
};

export default function Layout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}