import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import TeacherDashboard from './page';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';

/** A resolved fetch-like response. */
const ok = (data: unknown) => Promise.resolve({ ok: true, json: async () => data } as any);

/** Route a stubbed global fetch by URL substring. */
function routeFetch(routes: Record<string, () => Promise<any>>) {
  return vi.fn((url: unknown) => {
    const u = String(url);
    const key = Object.keys(routes).find((k) => u.includes(k));
    return key ? routes[key]() : Promise.resolve({ ok: false, json: async () => ({}) } as any);
  });
}

const PLAN_SUCCESS = ok({
  plan: { plan_name: 'الاحترافية' },
  usage: {
    students: { used: 12, limit: 200, pct: 6 },
    courses: { used: 3, limit: 20, pct: 15 },
    exams: { used: 4, limit: 100, pct: 4 },
    videos: { used: 5, limit: 200 },
    live_sessions: { used: 1, limit: 50 },
    recordings: { used: 0, limit: 5 },
    files: { used: 7, limit: 100 },
    pdfs: { used: 2, limit: 50 },
  },
});

const ANALYTICS_SUCCESS = ok({
  overview: { total_attempts: 27, completion_rate: 64, watch_seconds: 7200, pass_rate: 80, monthly_infra_cost: 1.5 },
});

const LESSONS_SUCCESS = ok({ lessons: [{ title: 'درس الرياضيات', scheduled_at: '2026-12-01T10:00:00Z' }] });
const BOOKINGS_SUCCESS = ok({ bookings: [{ student: { name: 'طالب أحمد' }, date: '2099-12-02', time: '09:00', status: 'confirmed' }] });
const STUDENT_APP_SUCCESS = ok({ student_app: { enabled: true, teacher_code: 'T-123' } });

const FULL_SUCCESS = {
  '/teacher/plan': () => PLAN_SUCCESS,
  '/teacher/analytics': () => ANALYTICS_SUCCESS,
  '/teacher/scheduled-lessons': () => LESSONS_SUCCESS,
  '/teacher/bookings': () => BOOKINGS_SUCCESS,
  '/teacher/student-app': () => STUDENT_APP_SUCCESS,
};

const EMPTY = {
  '/teacher/plan': () => ok({}),
  '/teacher/analytics': () => ok({}),
  '/teacher/scheduled-lessons': () => ok({}),
  '/teacher/bookings': () => ok({}),
  '/teacher/student-app': () => ok({}),
};

beforeEach(() => {
  localStorage.clear();
  // Real zustand stores, seeded per test — no module mocks, so the page runs
  // against the same store the app uses.
  useAuth.setState({ user: { name: 'أ. سامي' } as any, check: () => true } as any);
  useLocale.setState({ locale: 'ar' });
  useSettings.setState({
    settings: { ...useSettings.getState().settings, student_app_hidden: true, logo_url: null },
    load: async () => {},
  } as any);
});

describe('Teacher Dashboard', () => {
  it('shows skeleton placeholders while the data is still loading', () => {
    vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));

    const { container } = render(<TeacherDashboard />);

    expect(container.querySelectorAll('.animate-pulse').length).toBeGreaterThan(0);
  });

  it('renders real data (Arabic / RTL) once the endpoints respond', async () => {
    useSettings.setState({
      settings: { ...useSettings.getState().settings, student_app_hidden: false, logo_url: null },
    } as any);
    vi.stubGlobal('fetch', routeFetch(FULL_SUCCESS));

    render(<TeacherDashboard />);

    expect(await screen.findByText(/أ\. سامي/)).toBeInTheDocument();
    expect(screen.getByText('الاحترافية')).toBeInTheDocument();
    expect(screen.getByText('27')).toBeInTheDocument(); // attempts stat
    expect(screen.getByText('64%')).toBeInTheDocument(); // completion rate
    expect(screen.getByText('درس الرياضيات')).toBeInTheDocument();
    expect(screen.getByText('طالب أحمد')).toBeInTheDocument();
    expect(screen.getByText('الدروس القادمة')).toBeInTheDocument();
    expect(screen.getByText('استخدام خطتك')).toBeInTheDocument();
    // Student app card surfaces the real teacher code, LTR-isolated.
    expect(screen.getByText('T-123')).toBeInTheDocument();
  });

  it('renders the empty states when the tenant has no data yet', async () => {
    vi.stubGlobal('fetch', routeFetch(EMPTY));

    render(<TeacherDashboard />);

    expect(await screen.findByText('لا توجد دروس مجدولة')).toBeInTheDocument();
    expect(screen.getByText('لا توجد مواعيد قادمة')).toBeInTheDocument();
    expect(
      screen.getByText('لا توجد بيانات كافية بعد — أضف اختبارات وفيديوهات لطلابك'),
    ).toBeInTheDocument();
  });

  it('does not get stuck on skeletons when every endpoint fails', async () => {
    // Pre-fix this left the dashboard on skeletons forever (no catch around the
    // core plan/analytics calls).
    vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new Error('network down'))));

    const { container } = render(<TeacherDashboard />);

    expect(await screen.findByText('الدروس القادمة')).toBeInTheDocument();
    await waitFor(() => expect(container.querySelectorAll('.animate-pulse').length).toBe(0));
  });

  it('replaced the always-empty media tiles with real file usage', async () => {
    vi.stubGlobal('fetch', routeFetch(FULL_SUCCESS));

    const { container } = render(<TeacherDashboard />);

    expect(await screen.findByText('الوسائط والملفات')).toBeInTheDocument();
    // The two dead tiles (always 0 / $0.00) are gone.
    expect(screen.queryByText('التسجيلات')).toBeNull();
    expect(screen.queryByText('التكلفة التقديرية (الشهر)')).toBeNull();
    expect(container.textContent).not.toContain('$0.00');
    // Real counted resources replace them.
    expect(screen.getByText('الملفات')).toBeInTheDocument();
    expect(screen.getByText('ملفات PDF')).toBeInTheDocument();
    expect(screen.getByText('7 / 100')).toBeInTheDocument();
    expect(screen.getByText('2 / 50')).toBeInTheDocument();
  });

  it('shows the platform publish card with the teacher link and a settings shortcut', async () => {
    vi.stubGlobal('fetch', routeFetch({
      ...FULL_SUCCESS,
      '/teacher/domain': () => ok({ slug: 'sami', page_url: 'https://fahimni.me/t/sami', domain: null, verified: false }),
    }));

    const { container } = render(<TeacherDashboard />);

    expect(await screen.findByText('رابط منصتك')).toBeInTheDocument();
    // The link is shown without the scheme, LTR-isolated, and openable.
    expect(screen.getByText('fahimni.me/t/sami')).toBeInTheDocument();
    expect(container.querySelector('a[href="https://fahimni.me/t/sami"]')).toBeTruthy();
    // The quick route into the platform settings that the teacher asked for.
    expect(container.querySelector('a[href="/teacher/settings"]')).toBeTruthy();
  });

  it('lays out quick actions and a 2×2 statistics grid that links to real pages', async () => {
    vi.stubGlobal('fetch', routeFetch(FULL_SUCCESS));

    const { container } = render(<TeacherDashboard />);
    await screen.findByText(/أ\. سامي/);

    // The two primary quick actions are present and point somewhere real.
    expect(screen.getByRole('button', { name: /إنشاء كورس/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /إدارة الكورسات/ })).toBeInTheDocument();

    // Every statistic keeps its real destination.
    expect(container.querySelector('a[href="/students"]')).toBeTruthy();
    expect(container.querySelectorAll('a[href="/courses"]').length).toBeGreaterThan(0);
    expect(container.querySelector('a[href="/analytics"]')).toBeTruthy();

    // The statistics container is 2-up on phones and 4-up on desktop.
    const statsGrid = Array.from(container.querySelectorAll('div')).find(
      (d) => d.className.includes('grid-cols-2') && d.className.includes('lg:grid-cols-4'),
    );
    expect(statsGrid).toBeTruthy();
  });

  it('collapses the lower sections on phones and expands them on tap', async () => {
    const user = userEvent.setup();
    vi.stubGlobal('fetch', routeFetch(FULL_SUCCESS));

    render(<TeacherDashboard />);
    await screen.findByText('استخدام خطتك');

    // On a phone the plan-usage section starts collapsed…
    const header = screen.getByRole('button', { name: /استخدام خطتك/ });
    expect(header).toHaveAttribute('aria-expanded', 'false');

    // …and opens when the teacher taps it.
    await user.click(header);
    expect(header).toHaveAttribute('aria-expanded', 'true');
  });

  it('switches every label to English when the locale is English', async () => {
    useLocale.setState({ locale: 'en' });
    vi.stubGlobal('fetch', routeFetch(FULL_SUCCESS));

    render(<TeacherDashboard />);

    expect(await screen.findByText('Upcoming lessons')).toBeInTheDocument();
    expect(screen.getByText('Upcoming appointments')).toBeInTheDocument();
    expect(screen.getByText(/Good (morning|afternoon|evening)/)).toBeInTheDocument();
  });
});
