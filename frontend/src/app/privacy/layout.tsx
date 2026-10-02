import type { Metadata } from 'next';
import { ogBase, twitterBase } from '@/lib/seo';

const title = 'سياسة الخصوصية — Privacy policy';
const description =
  'سياسة الخصوصية في منصة Tadreesly — كيف نحمي بياناتك وبيانات طلابك ونتعامل معها بأمان.';

export const metadata: Metadata = {
  title,
  description,
  alternates: { canonical: '/privacy' },
  openGraph: ogBase(title, description, '/privacy'),
  twitter: twitterBase(title, description),
};

export default function Layout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}