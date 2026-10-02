import type { Metadata } from 'next';
import { ogBase, twitterBase } from '@/lib/seo';

const title = 'المدونة والأدلة — Blog & guides for teachers';
const description =
  'أدلة ومقالات عملية للمعلمين: إنشاء الدورات أونلاين، إدارة الطلاب، بيع الكورسات، والصفوف المباشرة.';

export const metadata: Metadata = {
  title,
  description,
  alternates: { canonical: '/blog' },
  openGraph: ogBase(title, description, '/blog'),
  twitter: twitterBase(title, description),
};

export default function Layout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}