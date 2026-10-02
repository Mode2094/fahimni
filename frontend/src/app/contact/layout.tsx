import type { Metadata } from 'next';
import { ogBase, twitterBase } from '@/lib/seo';

const title = 'تواصل معنا — Contact Tadreesly';
const description =
  'تواصل مع فريق Tadreesly — نساعدك في إعداد منصتك التعليمية والإجابة على استفساراتك خلال ساعة.';

export const metadata: Metadata = {
  title,
  description,
  alternates: { canonical: '/contact' },
  openGraph: ogBase(title, description, '/contact'),
  twitter: twitterBase(title, description),
};

export default function Layout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}