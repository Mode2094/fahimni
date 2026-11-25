import type { LucideIcon } from 'lucide-react';
import {
  LayoutDashboard, Layers, Users, GraduationCap, Ticket, Wallet, ReceiptText, LifeBuoy, Shield, Settings, BookOpen,
  ClipboardList, Video, CalendarDays, CalendarClock, HandCoins,
  UsersRound, Newspaper, MessagesSquare, BarChart3, Bell, Megaphone, Award,
  BookMarked, Medal, CalendarCheck, UserCircle2, Home, HelpCircle,
  ServerCog, Radio, Smartphone, Activity, Bug, Database, Bot, BellRing, Package,
  MessageCircleQuestion, Eye, Puzzle, NotebookPen,
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
  badge?: 'notifications' | 'messages' | 'adminNotifications';
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
    title: { ar: 'المعلمون والاشتراكات', en: 'Teachers & Subscriptions' },
    items: [
      { href: '/admin/teachers', icon: Users, ar: 'المعلمون والاشتراكات', en: 'Teachers & Subscriptions', match: ['/admin/teachers', '/admin/subscriptions'] },
      { href: '/admin/plans', icon: Layers, ar: 'الخطط والأسعار', en: 'Plans & Pricing' },
    ],
  },
  {
    title: { ar: 'المستخدمون', en: 'Users' },
    items: [
      { href: '/admin/students', icon: GraduationCap, ar: 'الطلاب', en: 'Students' },
      { href: '/admin/view-as', icon: Eye, ar: 'معاينة حساب', en: 'Preview Account' },
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
      { href: '/admin/transactions', icon: ReceiptText, ar: 'المعاملات المالية', en: 'Financial Transactions' },
    ],
  },
  {
    title: { ar: 'التشغيل', en: 'Operations' },
    items: [
      { href: '/admin/tickets', icon: LifeBuoy, ar: 'الدعم الفني', en: 'Support Tickets' },
      { href: '/admin/teacher-notifications', icon: BellRing, ar: 'إشعارات المعلمين', en: 'Teacher Notifications' },
    ],
  },
  {
    title: { ar: 'المراقبة', en: 'Monitoring' },
    items: [
      { href: '/admin/monitoring', icon: Activity, ar: 'مراقبة النظام', en: 'System Monitoring', match: ['/admin/monitoring'] },
      { href: '/admin/monitoring/frontend', icon: Bug, ar: 'أخطاء الواجهة', en: 'Frontend Errors' },
      { href: '/admin/logs', icon: Shield, ar: 'السجلات', en: 'Logs' },
    ],
  },
  {
    title: { ar: 'المنصة', en: 'Platform' },
    items: [
      { href: '/admin/bi', icon: BarChart3, ar: 'ذكاء الأعمال', en: 'Business Intelligence' },
      { href: '/admin/media', icon: ServerCog, ar: 'الوسائط والبنية التحتية', en: 'Media Infrastructure' },
      { href: '/admin/live', icon: Radio, ar: 'مزودو البث المباشر', en: 'Live Providers' },
      { href: '/admin/cards', icon: Ticket, ar: 'بطاقات التفعيل', en: 'Activation Cards' },
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
      { href: '/teacher/follow-up', icon: Activity, ar: 'متابعة الطلاب', en: 'Student Follow-up' },
      { href: '/teacher/terms', icon: CalendarDays, ar: 'الفصول الدراسية', en: 'Academic Terms', match: ['/teacher/terms'] },
      { href: '/teacher/cards', icon: Ticket, ar: 'البطاقات', en: 'Cards' },
      { href: '/teacher/bundles', icon: Package, ar: 'الحزم', en: 'Bundles' },
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
      { href: '/teacher/live', icon: Radio, ar: 'البث المباشر', en: 'Live Sessions', gate: 'live_stream_enabled' },
      { href: '/teacher/schedule', icon: CalendarDays, ar: 'الدروس المجدولة', en: 'Scheduled Lessons', gate: 'scheduled_lessons_enabled' },
      { href: '/teacher/exams', icon: ClipboardList, ar: 'الاختبارات', en: 'Exams' },
      { href: '/teacher/qa', icon: MessageCircleQuestion, ar: 'أسئلة الطلاب', en: 'Student Questions' },
      { href: '/teacher/questions', icon: Puzzle, ar: 'بنك الأسئلة', en: 'Question Bank' },
      { href: '/teacher/staff', icon: UsersRound, ar: 'فريق العمل', en: 'Team', gate: 'multi_teacher_enabled' },
    ],
  },
  {
    title: { ar: 'المحتوى والتواصل', en: 'Content & Community' },
    items: [
      { href: '/blog', icon: Newspaper, ar: 'المدونة', en: 'Blog', gate: 'blog_enabled' },
      { href: '/community', icon: MessagesSquare, ar: 'المجتمع', en: 'Community', gate: 'community_enabled', match: ['/community'] },
      { href: '/messages', icon: MessagesSquare, ar: 'الرسائل', en: 'Messages', badge: 'messages' },
      { href: '/announcements', icon: Megaphone, ar: 'الإعلانات', en: 'Announcements' },
      { href: '/teacher/notifications', icon: BellRing, ar: 'إشعارات الإدارة', en: 'Platform Notifications', badge: 'adminNotifications' },
      { href: '/teacher/assistant', icon: Bot, ar: 'اسألني عن المنصة', en: 'Ask about the platform' },
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
      { href: '/my-notes', icon: NotebookPen, ar: 'ملاحظاتي', en: 'My Notes' },
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

/** Primary mobile destinations; secondary student links live in the More drawer. */
export const STUDENT_BOTTOM_NAV: NavItem[] = [
  { href: '/my-learning', icon: Home, ar: 'الرئيسية', en: 'Home', match: ['/course'] },
  { href: '/exams', icon: ClipboardList, ar: 'الاختبارات', en: 'Exams', match: ['/exam'] },
  { href: '/messages', icon: MessagesSquare, ar: 'الرسائل', en: 'Messages', badge: 'messages' },
  { href: '/my-bookings', icon: CalendarCheck, ar: 'المواعيد', en: 'Bookings', gate: 'appointments_enabled' },
];

/* ── Route classification (used by the route-aware shell) ── */

const STUDENT_ROUTES: { exact?: string[]; prefix?: string[] } = {
  exact: ['/my-learning', '/my-notes', '/exams', '/results', '/certificates', '/leaderboard', '/messages', '/notifications', '/announcements', '/my-bookings', '/profile', '/checkout/success'],
  prefix: ['/community', '/course/', '/exam/', '/live/', '/book/'],
};

const TEACHER_ROUTES: { exact?: string[]; prefix?: string[] } = {
  exact: ['/students', '/analytics', '/blog', '/messages'],
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

function normalizePath(path: string): string {
  return path.replace(/\/+$/, '') || '/';
}

function matchesNavItem(path: string, item: NavItem): boolean {
  const normalized = normalizePath(path);
  if (normalized === item.href) return true;
  return [item.href, ...(item.match ?? [])].some((prefix) =>
    prefix !== '/' && normalized.startsWith(prefix + '/'),
  );
}

/** True when the current student destination is available from the More drawer. */
export function isMoreNavRoute(path: string, primaryItems: NavItem[]): boolean {
  return !primaryItems.some((item) => matchesNavItem(path, item));
}

/** Filter navigation by its localized label, section title, or route. */
export function filterNavGroups(groups: NavGroup[], query: string, locale: Locale): NavGroup[] {
  const normalizedQuery = query.trim().toLocaleLowerCase(locale);
  if (!normalizedQuery) return groups;

  return groups.flatMap((group) => {
    const groupMatches = (locale === 'ar' ? group.title?.ar : group.title?.en)
      ?.toLocaleLowerCase(locale)
      .includes(normalizedQuery);
    const items = groupMatches
      ? group.items
      : group.items.filter((item) =>
          [item.ar, item.en, item.href]
            .some((value) => value.toLocaleLowerCase(locale).includes(normalizedQuery)),
        );

    return items.length > 0 ? [{ ...group, items }] : [];
  });
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

  const norm = path.replace(/\/+$/, '') || '/';
  const items = groups.flatMap((g) => g.items);

  // 1) Exact match wins first, so a leaf route (`/admin/invoices`) shows its own
  //    label instead of the parent section's, and a tab of a section is titled
  //    like the section rather than falling back to its raw path slug.
  const exact = items.find((i) => i.href === norm || (i.match ?? []).includes(norm));
  if (exact) return locale === 'ar' ? exact.ar : exact.en;

  // 2) Otherwise the most specific prefix (longest match) wins.
  let best: NavItem | null = null;
  let bestLen = -1;
  for (const item of items) {
    for (const m of [item.href, ...(item.match ?? [])]) {
      if (m && m !== '/' && norm.startsWith(m + '/') && m.length > bestLen) {
        best = item;
        bestLen = m.length;
      }
    }
  }
  if (best) return locale === 'ar' ? best.ar : best.en;

  const tail = norm.split('/').filter(Boolean).pop() || '';
  return decodeURIComponent(tail).replace(/-/g, ' ');
}
