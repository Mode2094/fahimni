import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import AdminDashboardPage from './page';
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

const STATS = {
  total_tenants: 12,
  total_users: 348,
  total_students: 1200,
  total_courses: 57,
  active_subscriptions: 9,
  total_revenue: 4820.5,
  recent_payments: [],
};

const SUCCESS = { '/dashboard': () => ok(STATS) };
const EMPTY = { '/dashboard': () => ok({ total_tenants: 0, total_users: 0, total_students: 0, total_courses: 0, active_subscriptions: 0, total_revenue: 0 }) };

beforeEach(() => {
  localStorage.clear();
  // Real zustand stores, seeded per test — no module mocks, so the page runs
  // against the same stores the app uses.
  useAuth.setState({ user: { name: 'مسؤول المنصة' } as any, check: () => true } as any);
  useLocale.setState({ locale: 'ar' });
  useSettings.setState({
    settings: { ...useSettings.getState().settings, currency: 'USD', logo_url: null },
    load: async () => {},
    formatPrice: (amount: number | string | null | undefined) => `${Math.round((Number(amount) || 0) * 100) / 100} USD`,
  } as any);
});

describe('Admin Dashboard', () => {
  it('shows skeleton placeholders while the data is still loading (no fake zeroes)', () => {
    vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));

    const { container } = render(<AdminDashboardPage />);

    expect(screen.getByTestId('admin-dashboard-loading')).toBeInTheDocument();
    expect(container.querySelectorAll('.animate-pulse').length).toBeGreaterThan(0);
    // The statistics grid is not painted until real data arrives.
    expect(screen.queryByTestId('stats-grid')).toBeNull();
  });

  it('renders the real figures (Arabic / RTL) once the endpoint responds', async () => {
    vi.stubGlobal('fetch', routeFetch(SUCCESS));

    render(<AdminDashboardPage />);

    expect(await screen.findByTestId('stats-grid')).toBeInTheDocument();
    expect(screen.getByText('12')).toBeInTheDocument(); // teachers
    expect(screen.getByText('1200')).toBeInTheDocument(); // students
    expect(screen.getByText('57')).toBeInTheDocument(); // courses
    expect(screen.getByText('9')).toBeInTheDocument(); // active subscriptions
    expect(screen.getByText('348')).toBeInTheDocument(); // platform users (overview section)
    expect(screen.getByText('4820.5 USD')).toBeInTheDocument(); // revenue, once
    expect(screen.getByText('لوحة الإدارة')).toBeInTheDocument();
  });

  it('shows the revenue exactly once — the repeated totals are gone', async () => {
    vi.stubGlobal('fetch', routeFetch(SUCCESS));

    const { container } = render(<AdminDashboardPage />);
    await screen.findByTestId('stats-grid');

    // One revenue card, one value, one label — everywhere.
    expect(container.querySelectorAll('[data-testid="revenue-card"]').length).toBe(1);
    expect(container.querySelectorAll('[data-testid="revenue-value"]').length).toBe(1);
    expect(screen.getAllByText('إجمالي إيرادات المنصة').length).toBe(1);
    expect(screen.getAllByText('4820.5 USD').length).toBe(1);
  });

  it('uses the platform currency from settings for the revenue value', async () => {
    useSettings.setState({
      settings: { ...useSettings.getState().settings, currency: 'JOD' },
      formatPrice: () => '1,234.5 JOD',
    } as any);
    vi.stubGlobal('fetch', routeFetch(SUCCESS));

    render(<AdminDashboardPage />);

    expect(await screen.findByText('1,234.5 JOD')).toBeInTheDocument();
  });

  it('renders zeroes only after an empty payload loads, never as a placeholder', async () => {
    vi.stubGlobal('fetch', routeFetch(EMPTY));

    render(<AdminDashboardPage />);

    expect(await screen.findByTestId('stats-grid')).toBeInTheDocument();
    expect(screen.getAllByText('0').length).toBeGreaterThanOrEqual(5);
  });

  it('shows a clear error state and recovers on retry', async () => {
    const user = userEvent.setup();
    let calls = 0;
    vi.stubGlobal('fetch', vi.fn((url: unknown) => {
      if (!String(url).includes('/dashboard')) return Promise.resolve({ ok: false, json: async () => ({}) } as any);
      calls += 1;
      if (calls === 1) return Promise.reject(new Error('network down'));
      return ok(STATS);
    }));

    render(<AdminDashboardPage />);

    expect(await screen.findByTestId('admin-dashboard-error')).toBeInTheDocument();
    await user.click(screen.getByTestId('dashboard-retry'));

    expect(await screen.findByTestId('stats-grid')).toBeInTheDocument();
    expect(screen.getByText('12')).toBeInTheDocument();
  });

  it('lays out a 2×2 statistics grid and quick actions that all point to real pages', async () => {
    vi.stubGlobal('fetch', routeFetch(SUCCESS));

    const { container } = render(<AdminDashboardPage />);
    await screen.findByTestId('stats-grid');

    // 2-up on phones, 4-up on desktop.
    const statsGrid = screen.getByTestId('stats-grid');
    expect(statsGrid.className).toContain('grid-cols-2');
    expect(statsGrid.className).toContain('lg:grid-cols-4');

    // Every statistic keeps a real destination.
    expect(container.querySelector('a[href="/admin/teachers"]')).toBeTruthy();
    expect(container.querySelector('a[href="/admin/students"]')).toBeTruthy();
    expect(container.querySelector('a[href="/admin/subscriptions"]')).toBeTruthy();

    // Quick actions: every link resolves to an existing admin page (no dead ends).
    const quick = screen.getByTestId('quick-actions');
    const hrefs = Array.from(quick.querySelectorAll('a')).map((a) => a.getAttribute('href'));
    expect(hrefs).toEqual([
      '/admin/teachers',
      '/admin/plans',
      '/admin/tickets',
      '/admin/payments',
      '/admin/teacher-notifications',
    ]);
  });

  it('collapses the overview section on phones and expands it on tap', async () => {
    const user = userEvent.setup();
    vi.stubGlobal('fetch', routeFetch(SUCCESS));

    render(<AdminDashboardPage />);
    await screen.findByTestId('stats-grid');

    const header = screen.getByRole('button', { name: /نظرة عامة على المنصة/ });
    expect(header).toHaveAttribute('aria-expanded', 'false');
    await user.click(header);
    expect(header).toHaveAttribute('aria-expanded', 'true');
  });

  it('switches every label to English when the locale is English', async () => {
    useLocale.setState({ locale: 'en' });
    vi.stubGlobal('fetch', routeFetch(SUCCESS));

    render(<AdminDashboardPage />);

    expect(await screen.findByText('Admin Dashboard')).toBeInTheDocument();
    expect(screen.getByText('Teachers')).toBeInTheDocument();
    expect(screen.getByText('Active subscriptions')).toBeInTheDocument();
    expect(screen.getByText('Quick actions')).toBeInTheDocument();
    expect(screen.getByText('Platform users')).toBeInTheDocument();
  });
});
