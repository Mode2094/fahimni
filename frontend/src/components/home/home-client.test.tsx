import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import HomeClient from './home-client';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';

const ok = (data: unknown) => Promise.resolve({ ok: true, json: async () => data } as any);

const PLANS = [
  { id: 'p1', name: 'Starter', monthly_price: 49, annual_price: 490, features: [] },
  { id: 'p2', name: 'Professional', monthly_price: 99, annual_price: 990, features: [] },
  { id: 'p3', name: 'Enterprise', monthly_price: 199, annual_price: 1990, features: [] },
];

function stubPlans(plans = PLANS) {
  vi.stubGlobal(
    'fetch',
    vi.fn((url: unknown) => (String(url).includes('/public/plans') ? ok({ plans }) : Promise.resolve({ ok: false, json: async () => ({}) } as any))),
  );
}

beforeEach(() => {
  localStorage.clear();
  useLocale.setState({ locale: 'ar' });
  useSettings.setState({
    settings: {
      ...useSettings.getState().settings,
      platform_name: 'فهمني',
      currency: 'USD',
      logo_url: null,
      student_app_hidden: true,
    },
    load: async () => {},
    formatPrice: (n: unknown) => `${Number(n).toFixed(0)} $`,
  } as any);
});

describe('Landing page (home-client)', () => {
  it('renders the Arabic hero, brand and the live plans from the API', async () => {
    stubPlans();
    render(<HomeClient />);

    // Real hero copy (RTL) + brand from settings.
    expect(screen.getAllByText('منصتك التعليمية،').length).toBeGreaterThan(0);
    expect(screen.getAllByText('فهمني').length).toBeGreaterThan(0);

    // Plans come from the real endpoint, not hardcoded.
    expect(await screen.findByText('Starter')).toBeInTheDocument();
    expect(screen.getByText('Professional')).toBeInTheDocument();
    expect(screen.getByText('Enterprise')).toBeInTheDocument();
    // Prices render through the settings formatter (monthly is the default cycle).
    expect(screen.getAllByText('49').length).toBeGreaterThan(0);
  });

  it('shows a spinner while the plans are still loading', () => {
    vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
    const { container } = render(<HomeClient />);

    expect(container.querySelectorAll('.animate-spin').length).toBeGreaterThan(0);
  });

  it('expands a FAQ answer on demand (accessible disclosure)', async () => {
    stubPlans();
    const user = userEvent.setup();
    render(<HomeClient />);

    const first = screen.getByRole('button', { name: /كيف أبدأ مع المنصة/ });
    const second = screen.getByRole('button', { name: /هل يمكنني بيع الكورسات/ });

    // First item is open by default, the second is closed.
    expect(first).toHaveAttribute('aria-expanded', 'true');
    expect(second).toHaveAttribute('aria-expanded', 'false');

    await user.click(second);

    expect(second).toHaveAttribute('aria-expanded', 'true');
    expect(first).toHaveAttribute('aria-expanded', 'false');
  });

  it('switches the hero to English when the locale is English', async () => {
    useLocale.setState({ locale: 'en' });
    stubPlans();
    render(<HomeClient />);

    expect(screen.getAllByText('Your teaching platform,').length).toBeGreaterThan(0);
    // Secondary hero CTA now invites the visitor to see how the platform works.
    expect(screen.getAllByRole('button', { name: 'See how the platform works' }).length).toBeGreaterThan(0);
  });
});
