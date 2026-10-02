import type { Metadata } from 'next';
import TeacherShell from '@/components/teacher/teacher-shell';
import { NOINDEX } from '@/lib/seo';

export const metadata: Metadata = {
  title: 'لوحة المعلم',
  robots: NOINDEX,
};

export default function TeacherLayout({ children }: { children: React.ReactNode }) {
  return <TeacherShell>{children}</TeacherShell>;
}