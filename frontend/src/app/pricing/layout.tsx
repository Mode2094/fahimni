import type { Metadata } from 'next';
import { ogBase, twitterBase } from '@/lib/seo';

const title = 'الأسعار والخطط — Pricing plans for teachers';
const description =
  'خطط أسعار بسيطة تناسب كل معلم: شهري أو سنوي، مع كل ما تحتاجه لإدارة طلابك ودوراتك. ابدأ اليوم ولا تدفع إلا لما تحتاجه فعلاً.';

export const metadata: Metadata = {
  title,
  description,
  alternates: { canonical: '/pricing' },
  openGraph: ogBase(title, description, '/pricing'),
  twitter: twitterBase(title, description),
};

export default function Layout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}