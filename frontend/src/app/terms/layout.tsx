import type { Metadata } from 'next';
import { ogBase, twitterBase } from '@/lib/seo';

const title = 'الشروط والأحكام — Terms of service';
const description =
  'شروط وأحكام استخدام منصة Tadreesly للمعلمين والطلاب — اقرأها قبل بدء استخدام المنصة.';

export const metadata: Metadata = {
  title,
  description,
  alternates: { canonical: '/terms' },
  openGraph: ogBase(title, description, '/terms'),
  twitter: twitterBase(title, description),
};

export default function Layout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}