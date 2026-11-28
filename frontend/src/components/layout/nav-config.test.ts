import { describe, it, expect } from 'vitest';
import {
  TEACHER_NAV,
  ADMIN_NAV,
  STUDENT_NAV,
  STUDENT_BOTTOM_NAV,
  filterNavGroups,
  isMoreNavRoute,
  isTeacherRoute,
  navLabelFor,
} from './nav-config';

/**
 * The AI assistant lives in the teacher sidebar (not a floating button):
 * there must be exactly one «اسألني عن المنصة» entry and it must open inside
 * the teacher panel shell.
 */
describe('teacher assistant nav entry', () => {
  const items = TEACHER_NAV.flatMap((g) => g.items);

  it('exposes the assistant as a single sidebar item', () => {
    const assistantItems = items.filter((i) => i.href === '/teacher/assistant');
    expect(assistantItems).toHaveLength(1);
    expect(assistantItems[0].ar).toBe('اسألني عن المنصة');
    // No plan gate: the assistant is available on every plan.
    expect(assistantItems[0].gate).toBeFalsy();
  });

  it('renders the assistant page inside the teacher shell', () => {
    expect(isTeacherRoute('/teacher/assistant')).toBe(true);
  });
});

/**
 * Regression: the "Live Sessions" nav item was rendered for every plan, so a
 * teacher on a plan without live streaming saw the link and got a 403 API
 * error on the page. It must be gated by `live_stream_enabled`.
 */
describe('teacher nav plan gating', () => {
  const items = TEACHER_NAV.flatMap((g) => g.items);

  it('gates the live sessions item behind live_stream_enabled', () => {
    const live = items.find((i) => i.href === '/teacher/live');
    expect(live, 'live nav item must exist').toBeTruthy();
    expect(live?.gate).toBe('live_stream_enabled');
  });

  it('every gated teacher item references a real plan gate key', () => {
    const gated = items.filter((i) => i.gate);
    // Guard against typos in gate keys — the shell hides items by exact match.
    for (const item of gated) {
      expect(item.gate).toMatch(/^[a-z_]+_enabled$|^export_reports$/);
    }
  });
});

describe('student mobile navigation', () => {
  it('keeps the daily destinations visible and leaves secondary links in More', () => {
    expect(STUDENT_BOTTOM_NAV.map((item) => item.href)).toEqual([
      '/my-learning',
      '/exams',
      '/messages',
      '/my-bookings',
    ]);
    expect(STUDENT_BOTTOM_NAV.find((item) => item.href === '/messages')?.badge).toBe('messages');
    expect(STUDENT_BOTTOM_NAV.find((item) => item.href === '/my-bookings')?.gate).toBe('appointments_enabled');

    const allStudentLinks = STUDENT_NAV.flatMap((group) => group.items.map((item) => item.href));
    expect(allStudentLinks).toEqual(expect.arrayContaining([
      '/notifications',
      '/community',
      '/announcements',
      '/results',
      '/certificates',
      '/leaderboard',
      '/profile',
      '/my-notes',
    ]));
    expect(isMoreNavRoute('/notifications', STUDENT_BOTTOM_NAV)).toBe(true);
    expect(isMoreNavRoute('/course/course-1/learn', STUDENT_BOTTOM_NAV)).toBe(false);
  });
});

describe('role-aware teacher navigation', () => {
  it('makes conversations reachable from the teacher panel', () => {
    const messages = TEACHER_NAV.flatMap((group) => group.items).filter((item) => item.href === '/messages');
    expect(messages).toHaveLength(1);
    expect(messages[0].badge).toBe('messages');
    expect(isTeacherRoute('/messages')).toBe(true);
  });
});

describe('navigation search', () => {
  it('filters by the selected locale and keeps matching groups only', () => {
    const arabic = filterNavGroups(TEACHER_NAV, 'الرسائل', 'ar');
    expect(arabic).toHaveLength(1);
    expect(arabic[0].items.map((item) => item.href)).toEqual(['/messages']);

    const english = filterNavGroups(TEACHER_NAV, 'settings', 'en');
    expect(english.flatMap((group) => group.items.map((item) => item.href))).toContain('/teacher/settings');
  });

  it('does not hide or reorder links when the query is blank', () => {
    expect(filterNavGroups(TEACHER_NAV, '   ', 'ar')).toBe(TEACHER_NAV);
  });
});

/**
 * The Super-Admin → teacher notification centre is a distinct system from
 * teacher announcements: it must have its own admin entry and a teacher
 * badge-backed entry that opens inside the teacher panel.
 */
describe('platform notification navigation', () => {
  it('adds a single admin entry for teacher notifications', () => {
    const items = ADMIN_NAV.flatMap((g) => g.items);
    const entry = items.filter((i) => i.href === '/admin/teacher-notifications');
    expect(entry).toHaveLength(1);
    expect(entry[0].ar).toBe('إشعارات المعلمين');
  });

  it('adds a teacher entry that carries the admin-notification badge', () => {
    const items = TEACHER_NAV.flatMap((g) => g.items);
    const entry = items.filter((i) => i.href === '/teacher/notifications');
    expect(entry).toHaveLength(1);
    expect(entry[0].badge).toBe('adminNotifications');
    expect(isTeacherRoute('/teacher/notifications')).toBe(true);
  });
});

/**
 * Mobile header consistency: every admin route must show a real Arabic/English
 * label, never the raw URL slug. Regression: `/admin/invoices` and
 * `/admin/coupons` showed "invoices"/"coupons" and
 * `/admin/monitoring/frontend` showed its parent's title.
 */
describe('admin page titles', () => {
  it('titles a sales tab by the section name, not its raw slug', () => {
    expect(navLabelFor('/admin/invoices', 'ar', ADMIN_NAV)).toBe('المبيعات');
    expect(navLabelFor('/admin/coupons', 'ar', ADMIN_NAV)).toBe('المبيعات');
    expect(navLabelFor('/admin/payments', 'ar', ADMIN_NAV)).toBe('المبيعات');
    expect(navLabelFor('/admin/coupons', 'en', ADMIN_NAV)).toBe('Sales');
  });

  it('prefers the most specific item so a leaf beats its parent', () => {
    expect(navLabelFor('/admin/monitoring/frontend', 'ar', ADMIN_NAV)).toBe('أخطاء الواجهة');
    expect(navLabelFor('/admin/monitoring', 'ar', ADMIN_NAV)).toBe('مراقبة النظام');
  });

  it('resolves dedicated admin leaves', () => {
    expect(navLabelFor('/admin/quran-requests', 'ar', ADMIN_NAV)).toBe('مدرسو القرءان');
    expect(navLabelFor('/admin/bi', 'ar', ADMIN_NAV)).toBe('ذكاء الأعمال');
  });

  it('humanises an unknown route instead of showing nothing', () => {
    expect(navLabelFor('/admin/some-unknown-page', 'en', ADMIN_NAV)).toBe('some unknown page');
  });
});
