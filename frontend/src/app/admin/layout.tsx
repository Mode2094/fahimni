import type { Metadata } from 'next';
import AdminShell from '@/components/admin/admin-shell';
import { NOINDEX } from '@/lib/seo';

export const metadata: Metadata = {
  title: 'لوحة الأدمن',
  robots: NOINDEX,
};

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  return <AdminShell>{children}</AdminShell>;
}