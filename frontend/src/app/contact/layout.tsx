import type { Metadata } from 'next';
import { ogBase, twitterBase } from '@/lib/seo';

const title = 'تواصل معنا — Contact Fahimni';
const description =
  'تواصل مع فريق فهمني — نساعدك في إعداد منصتك التعليمية والإجابة على استفساراتك خلال ساعة.';

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