import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import AdminDashboardPage from './page';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';

/** A resolved fetch-like response. */
const ok = (data: unknown) => Promise.resolve({ ok: true, json: async () => data } as any);

/** Route a stubbed global fetch by URL substring. */
function routeFetch(routes: Record<string, () => Promise<any>>) {
  return vi.fn((url: unknown, _options?: RequestInit) => {
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
  subscription_metrics: {
    total_teachers: 12,
    active_accounts: 11,
    valid_trials: 4,
    paid_subscribers: 2,
    admin_access: 1,
    expired_access: 3,
    effective_access: 10,
    unverified_access: 0,
    pending_subscriptions: 4,
    historical_records: 28,
    financials: {
      provider_status: 'available',
      collected_complete: true,
      gross_collected_by_currency: { USD: 4820.5 },
      refunds_by_currency: { USD: 20 },
      net_collected_by_currency: { USD: 4800.5 },
      mrr_by_currency: { USD: 250 },
      pending_payment_transactions: 0,
      pending_payment_amount_by_currency: { USD: 0 },
      pending_checkout_intents: 4,
    },
  },
  recent_payments: [],
};

const SUCCESS = { '/dashboard': () => ok(STATS) };
const EMPTY = { '/dashboard': () => ok({
  total_tenants: 0,
  total_users: 0,
  total_students: 0,
  total_courses: 0,
  subscription_metrics: {
    total_teachers: 0,
    active_accounts: 0,
    valid_trials: 0,
    paid_subscribers: 0,
    admin_access: 0,
    expired_access: 0,
    effective_access: 0,
    unverified_access: 0,
    pending_subscriptions: 0,
    historical_records: 0,
    financials: {
      provider_status: 'available',
      collected_complete: true,
      gross_collected_by_currency: { USD: 0 },
      refunds_by_currency: { USD: 0 },
      net_collected_by_currency: { USD: 0 },
      mrr_by_currency: { USD: 0 },
      pending_payment_transactions: 0,
      pending_payment_amount_by_currency: { USD: 0 },
      pending_checkout_intents: 0,
    },
  },
}) };

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

  it('renders distinct teacher, trial and paid counts from the shared snapshot', async () => {
    vi.stubGlobal('fetch', routeFetch(SUCCESS));

    render(<AdminDashboardPage />);

    expect(await screen.findByTestId('stats-grid')).toBeInTheDocument();
    expect(screen.getByTestId('metric-total_teachers')).toHaveTextContent('12');
    expect(screen.getByTestId('metric-active_accounts')).toHaveTextContent('11');
    expect(screen.getByTestId('metric-valid_trials')).toHaveTextContent('4');
    expect(screen.getByTestId('metric-paid_subscribers')).toHaveTextContent('2');
    expect(screen.getByText('1200')).toBeInTheDocument(); // students
    expect(screen.getByText('57')).toBeInTheDocument(); // courses
    expect(screen.getByText('348')).toBeInTheDocument(); // platform users (overview section)
    expect(screen.getByText('لوحة الإدارة')).toBeInTheDocument();
  });

  it('keeps financial figures in the expandable detail section', async () => {
    vi.stubGlobal('fetch', routeFetch(SUCCESS));

    render(<AdminDashboardPage />);
    await screen.findByTestId('stats-grid');

    const details = screen.getByText('تفاصيل الاشتراكات والمالية').closest('details');
    expect(details?.open).toBe(false);
    screen.getByText('تفاصيل الاشتراكات والمالية').click();
    expect(details?.open).toBe(true);
    expect(screen.getByTestId('collected-value')).toHaveTextContent('4820.50 USD');
    expect(screen.getByTestId('refunds-value')).toHaveTextContent('20.00 USD');
    expect(screen.getByTestId('mrr-value')).toHaveTextContent('250.00 USD');
  });

  it('blocks the global fetch helper from forwarding a stale browser-selected tenant', async () => {
    localStorage.setItem('tenant_id', 'stale-tenant-id');
    const fetchMock = routeFetch(SUCCESS);
    vi.stubGlobal('fetch', fetchMock);

    render(<AdminDashboardPage />);
    await screen.findByTestId('stats-grid');

    const dashboardRequest = fetchMock.mock.calls.find(([url]) => String(url).includes('/dashboard'));
    expect(dashboardRequest?.[1]?.headers).toEqual({ 'X-Tenant-Id': '', Accept: 'application/json' });
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

  it('lays out compact mobile metrics and quick actions that point to real pages', async () => {
    vi.stubGlobal('fetch', routeFetch(SUCCESS));

    const { container } = render(<AdminDashboardPage />);
    await screen.findByTestId('stats-grid');

    // 2-up on phones, 4-up on desktop.
    const statsGrid = screen.getByTestId('stats-grid');
    expect(statsGrid.className).toContain('grid-cols-2');
    expect(statsGrid.className).toContain('lg:grid-cols-4');

    // Count tiles open a list using the same definition as the metric.
    expect(container.querySelector('a[href="/admin/teachers"]')).toBeTruthy();
    expect(container.querySelector('a[href="/admin/teachers?subscription_state=paid"]')).toBeTruthy();
    expect(container.querySelector('a[href="/admin/teachers?subscription_state=valid_trial"]')).toBeTruthy();

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
    expect(screen.getByText('Paid subscribers')).toBeInTheDocument();
    expect(screen.getByText('Quick actions')).toBeInTheDocument();
    expect(screen.getByText('Platform users')).toBeInTheDocument();
  });
});
