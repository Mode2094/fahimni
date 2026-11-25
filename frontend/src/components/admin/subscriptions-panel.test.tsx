import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import AdminSubscriptionsPanel from './subscriptions-panel';

const metrics = {
  total_teachers: 3,
  active_accounts: 3,
  valid_trials: 1,
  paid_subscribers: 0,
  admin_access: 1,
  expired_access: 1,
  effective_access: 2,
  unverified_access: 0,
  pending_subscriptions: 2,
  historical_records: 7,
  financials: {
    provider_status: 'available',
    collected_complete: true,
    gross_collected_by_currency: { USD: 0 },
    refunds_by_currency: { USD: 0 },
    net_collected_by_currency: { USD: 0 },
    mrr_by_currency: { USD: 0 },
    pending_payment_transactions: 0,
    pending_payment_amount_by_currency: { USD: 0 },
    pending_checkout_intents: 2,
  },
};

beforeEach(() => {
  useAuth.setState({ check: () => true } as any);
  useLocale.setState({ locale: 'ar' });
  useSettings.setState({
    formatPrice: (amount: number | string | null | undefined) => `${Number(amount || 0).toFixed(2)} USD`,
  } as any);

  vi.stubGlobal('fetch', vi.fn((input: unknown) => {
    const url = String(input);
    if (url.includes('/admin/subscriptions')) {
      return Promise.resolve({
        ok: true,
        json: async () => ({
          stats: { total: 7, active: 0, trialing: 1, pending: 2, cancelled: 1, expired: 1, monthly_revenue: 0 },
          subscription_metrics: metrics,
          subscriptions: [
            {
              id: 'trial-row',
              status: 'trialing',
              starts_at: '2026-11-01T00:00:00Z',
              ends_at: null,
              trial_ends_at: '2026-12-03T00:00:00Z',
              billing_cycle: 'monthly',
              tenant: { name: 'Academy' },
              plan: { name: 'Basic' },
            },
            {
              id: 'canceled-row',
              status: 'canceled',
              billing_cycle: 'monthly',
              tenant: { name: 'Old Academy' },
              plan: { name: 'Basic' },
            },
            {
              id: 'cancelled-row',
              status: 'cancelled',
              billing_cycle: 'yearly',
              tenant: { name: 'Another Academy' },
              plan: { name: 'Basic' },
            },
          ],
        }),
      });
    }

    return Promise.resolve({ ok: true, json: async () => ({ plans: [] }) });
  }));
});

describe('AdminSubscriptionsPanel', () => {
  it('shows the shared platform metrics and the actual trial end date', async () => {
    render(<AdminSubscriptionsPanel />);

    expect(await screen.findByTestId('metric-total_teachers')).toHaveTextContent('3');
    expect(screen.getByTestId('metric-valid_trials')).toHaveTextContent('1');
    expect(screen.getByTestId('metric-paid_subscribers')).toHaveTextContent('0');
    expect(screen.getByText(/نهاية التجربة المجانية: 2026-12-03/)).toBeInTheDocument();
  });

  it('uses one display/filter spelling for canceled and cancelled records without changing their data', async () => {
    render(<AdminSubscriptionsPanel />);

    expect(await screen.findByTestId('subscription-row-canceled-row')).toHaveTextContent('ملغي');
    expect(screen.getByTestId('subscription-row-cancelled-row')).toHaveTextContent('ملغي');
  });
});
