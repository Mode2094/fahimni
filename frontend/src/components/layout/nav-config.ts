import type { LucideIcon } from 'lucide-react';
import {
  LayoutDashboard, CreditCard, Layers, Users, GraduationCap, Ticket, Wallet, ReceiptText, LifeBuoy, Shield, Settings, BookOpen,
  ClipboardList, Video, CalendarDays, CalendarClock, HandCoins, FileQuestion,
  UsersRound, Newspaper, MessagesSquare, BarChart3, Bell, Megaphone, Award,
  BookMarked, Medal, CalendarCheck, UserCircle2, DatabaseBackup, Home, HelpCircle,
  ServerCog, Radio, Smartphone, Activity, Bug, Database,
} from 'lucide-react';

export type Locale = 'ar' | 'en';
export type NavItem = {
  href: string;
  icon: LucideIcon;
  ar: string;
  en: string;
  /** plan gate key — item hidden when the plan doesn't allow it */
  gate?: string;
  /** hidden entirely when the platform hides the student app (pre-launch) */
  hideWithApp?: boolean;
  /** extra path prefixes that should highlight this item */
  match?: string[];
  badge?: 'notifications' | 'messages';
};
export type NavGroup = { title?: { ar: string; en: string }; items: NavItem[] };

export const ADMIN_NAV: NavGroup[] = [
  {
    title: { ar: 'الرئيسية', en: 'Overview' },
    items: [
      { href: '/admin/dashboard', icon: LayoutDashboard, ar: 'لوحة الإدارة', en: 'Admin Dashboard' },
    ],
  },
  {
    title: { ar: 'الاشتراكات والخطط', en: 'Subscriptions' },
    items: [
      { href: '/admin/subscriptions', icon: CreditCard, ar: 'الاشتراكات', en: 'Subscriptions' },
      { href: '/admin/plans', icon: Layers, ar: 'الخطط', en: 'Plans' },
    ],
  },
  {
    title: { ar: 'المستخدمون', en: 'Users' },
    items: [
      { href: '/admin/teachers', icon: Users, ar: 'المعلمون', en: 'Teachers' },
      { href: '/admin/students', icon: GraduationCap, ar: 'الطلاب', en: 'Students' },
      { href: '/admin/quran-requests', icon: BookOpen, ar: 'مدرسو القرءان', en: 'Quran Teachers' },
    ],
  },
  {
    title: { ar: 'المبيعات', en: 'Sales' },
    items: [
      {
        href: '/admin/payments',
        icon: HandCoins,
        ar: 'المبيعات',
        en: 'Sales',
        match: ['/admin/payments', '/admin/invoices', '/admin/coupons'],
      },
    ],
  },
  {
    title: { ar: 'التشغيل', en: 'Operations' },
    items: [
      { href: '/admin/tickets', icon: LifeBuoy, ar: 'الدعم الفني', en: 'Support Tickets' },
      { href: '/admin/backups', icon: DatabaseBackup, ar: 'النسخ الاحتياطي', en: 'Backups' },
    ],
  },
  {
    title: { ar: 'المراقبة', en: 'Monitoring' },
    items: [
      { href: '/admin/monitoring', icon: Activity, ar: 'مراقبة النظام', en: 'System Monitoring', match: ['/admin/monitoring'] },
      { href: '/admin/monitoring/frontend', icon: Bug, ar: 'أخطاء الواجهة', en: 'Frontend Errors' },
    ],
  },
  {
    title: { ar: 'المنصة', en: 'Platform' },
    items: [
      { href: '/admin/bi', icon: BarChart3, ar: 'ذكاء الأعمال', en: 'Business Intelligence' },
      { href: '/admin/media', icon: ServerCog, ar: 'الوسائط والبنية التحتية', en: 'Media Infrastructure' },
      { href: '/admin/live', icon: Radio, ar: 'مزودو البث المباشر', en: 'Live Providers' },
      { href: '/admin/settings', icon: Settings, ar: 'الإعدادات', en: 'Settings' },
    ],
  },
];

export const TEACHER_NAV: NavGroup[] = [
  {
    items: [
      { href: '/teacher/dashboard', icon: LayoutDashboard, ar: 'لوحة التحكم', en: 'Dashboard' },
      { href: '/courses', icon: BookOpen, ar: 'الكورسات', en: 'Courses', match: ['/courses'] },
      { href: '/students', icon: Users, ar: 'الطلاب', en: 'Students' },
      { href: '/teacher/cards', icon: Ticket, ar: 'البطاقات', en: 'Cards' },
      { href: '/teacher/student-app', icon: Smartphone, ar: 'تطبيق الطلاب', en: 'Student App', hideWithApp: true },
    ],
  },
  {
    title: { ar: 'التدريس', en: 'Teaching' },
    items: [
      {
        href: '/teacher/appointments',
        icon: CalendarClock,
        ar: 'المواعيد والجلسات',
        en: 'Appointments & Sessions',
        match: ['/teacher/appointments', '/teacher/services'],
      },
      { href: '/teacher/live', icon: Radio, ar: 'البث المباشر', en: 'Live Sessions' },
      { href: '/teacher/staff', icon: UsersRound, ar: 'فريق العمل', en: 'Team', gate: 'multi_teacher_enabled' },
    ],
  },
  {
    title: { ar: 'المحتوى والتواصل', en: 'Content & Community' },
    items: [
      { href: '/blog', icon: Newspaper, ar: 'المدونة', en: 'Blog', gate: 'blog_enabled' },
      { href: '/community', icon: MessagesSquare, ar: 'المجتمع', en: 'Community', gate: 'community_enabled', match: ['/community'] },
      { href: '/announcements', icon: Megaphone, ar: 'الإعلانات', en: 'Announcements' },
    ],
  },
  {
    title: { ar: 'النمو', en: 'Growth' },
    items: [
      { href: '/analytics', icon: BarChart3, ar: 'التحليلات والتقارير', en: 'Analytics', gate: 'export_reports' },
    ],
  },
  {
    title: { ar: 'الحساب', en: 'Account' },
    items: [
      { href: '/teacher/support', icon: LifeBuoy, ar: 'الدعم الفني', en: 'Support' },
      { href: '/teacher/settings', icon: Settings, ar: 'الإعدادات', en: 'Settings' },
    ],
  },
];

export const STUDENT_NAV: NavGroup[] = [
  {
    title: { ar: 'التعلم', en: 'Learning' },
    items: [
      { href: '/my-learning', icon: BookOpen, ar: 'تعليمي', en: 'My Learning' },
      { href: '/exams', icon: ClipboardList, ar: 'الاختبارات', en: 'Exams', match: ['/exam'] },
      { href: '/results', icon: Award, ar: 'النتائج', en: 'Results' },
      { href: '/certificates', icon: BookMarked, ar: 'الشهادات', en: 'Certificates', gate: 'certificates_enabled' },
      { href: '/leaderboard', icon: Medal, ar: 'لوحة الأوائل', en: 'Leaderboard' },
    ],
  },
  {
    title: { ar: 'التواصل', en: 'Community' },
    items: [
      { href: '/community', icon: MessagesSquare, ar: 'المجتمع', en: 'Community', gate: 'community_enabled', match: ['/community'] },
      { href: '/messages', icon: MessagesSquare, ar: 'الرسائل', en: 'Messages', badge: 'messages' },
      { href: '/notifications', icon: Bell, ar: 'الإشعارات', en: 'Notifications', badge: 'notifications' },
      { href: '/announcements', icon: Megaphone, ar: 'الإعلانات', en: 'Announcements' },
    ],
  },
  {
    title: { ar: 'المواعيد', en: 'Bookings' },
    items: [
      { href: '/my-bookings', icon: CalendarCheck, ar: 'حجوزاتي', en: 'My Bookings', gate: 'appointments_enabled' },
    ],
  },
  {
    title: { ar: 'الحساب', en: 'Account' },
    items: [
      { href: '/profile', icon: UserCircle2, ar: 'ملفي', en: 'My Profile' },
    ],
  },
];

/** Bottom navigation (mobile) for students — the 5 most frequent destinations. */
export const STUDENT_BOTTOM_NAV: NavItem[] = [
  { href: '/my-learning', icon: Home, ar: 'الرئيسية', en: 'Home', match: ['/course'] },
  { href: '/exams', icon: ClipboardList, ar: 'الاختبارات', en: 'Exams', match: ['/exam'] },
  { href: '/community', icon: MessagesSquare, ar: 'المجتمع', en: 'Community' },
  { href: '/notifications', icon: Bell, ar: 'الإشعارات', en: 'Alerts' },
  { href: '/profile', icon: UserCircle2, ar: 'ملفي', en: 'Profile' },
];

/* ── Route classification (used by the route-aware shell) ── */

const STUDENT_ROUTES: { exact?: string[]; prefix?: string[] } = {
  exact: ['/my-learning', '/exams', '/results', '/certificates', '/leaderboard', '/messages', '/notifications', '/announcements', '/my-bookings', '/profile', '/checkout/success'],
  prefix: ['/community', '/course/', '/exam/', '/live/', '/book/'],
};

const TEACHER_ROUTES: { exact?: string[]; prefix?: string[] } = {
  exact: ['/students', '/analytics', '/blog'],
  prefix: ['/teacher', '/courses'],
};

export function isStudentRoute(path: string): boolean {
  if (STUDENT_ROUTES.exact!.some((r) => path === r)) return true;
  return STUDENT_ROUTES.prefix!.some((r) => path.startsWith(r));
}

export function isTeacherRoute(path: string): boolean {
  if (TEACHER_ROUTES.exact!.some((r) => path === r)) return true;
  return TEACHER_ROUTES.prefix!.some((r) => path.startsWith(r));
}

export function isAdminRoute(path: string): boolean {
  return path.startsWith('/admin');
}

/** Auth-only / public pages that get the slim public header + footer. */
const PUBLIC_SHELL_ROUTES: { exact?: string[]; prefix?: string[] } = {
  exact: ['/pricing', '/forgot-password', '/verify-email'],
  prefix: ['/register', '/verify/', '/checkout'],
};

export function isPublicShellRoute(path: string): boolean {
  if (path === '/checkout/success') return false;
  if (PUBLIC_SHELL_ROUTES.exact!.some((r) => path === r)) return true;
  return PUBLIC_SHELL_ROUTES.prefix!.some((r) => path.startsWith(r));
}

export function navLabelFor(path: string, locale: Locale, groups: NavGroup[]): string {
  const OVERRIDES: { test: RegExp; ar: string; en: string }[] = [
    { test: /^\/course\/[^/]+\/learn/, ar: 'مشغل الكورس', en: 'Course Player' },
    { test: /^\/exam\//, ar: 'الاختبار', en: 'Exam' },
    { test: /^\/live\//, ar: 'بث مباشر', en: 'Live Session' },
    { test: /^\/courses\//, ar: 'إدارة الكورس', en: 'Course Builder' },
    { test: /^\/community\/(groups|threads)/, ar: 'المجتمع', en: 'Community' },
    { test: /^\/verify\//, ar: 'التحقق', en: 'Verification' },
    { test: /^\/certificates\//, ar: 'الشهادة', en: 'Certificate' },
    { test: /^\/book\//, ar: 'الحجز', en: 'Booking' },
  ];
  for (const o of OVERRIDES) {
    if (o.test.test(path)) return locale === 'ar' ? o.ar : o.en;
  }
  for (const g of groups) {
    for (const item of g.items) {
      const match = item.href === path || (item.match ?? []).some((m) => m !== '/' && path.startsWith(m + '/'));
      if (match) return locale === 'ar' ? item.ar : item.en;
    }
  }
  const tail = path.split('/').filter(Boolean).pop() || '';
  return decodeURIComponent(tail).replace(/-/g, ' ');
}
