import type { Metadata } from 'next';
import { ogBase, twitterBase } from '@/lib/seo';

const title = 'سياسة الاسترداد — Refund policy';
const description =
  'سياسة استرداد المبالغ في منصة Tadreesly — شروط واضحة وعادلة للطلاب والمعلمين على حد سواء.';

export const metadata: Metadata = {
  title,
  description,
  alternates: { canonical: '/refund-policy' },
  openGraph: ogBase(title, description, '/refund-policy'),
  twitter: twitterBase(title, description),
};

export default function Layout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}