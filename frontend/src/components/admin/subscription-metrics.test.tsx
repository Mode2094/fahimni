import { describe, expect, it } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { useLocale } from '@/store/locale';
import AdminSubscriptionMetrics, { type AdminSubscriptionMetricsData } from './subscription-metrics';

const METRICS: AdminSubscriptionMetricsData = {
  total_teachers: 14,
  active_accounts: 13,
  valid_trials: 8,
  paid_subscribers: 2,
  admin_access: 1,
  expired_access: 3,
  effective_access: 11,
  unverified_access: 1,
  pending_subscriptions: 4,
  historical_records: 28,
  financials: {
    provider_status: 'available',
    collected_complete: true,
    gross_collected_by_currency: { USD: 120 },
    refunds_by_currency: { USD: 20 },
    net_collected_by_currency: { USD: 100 },
    mrr_by_currency: { USD: 50 },
    pending_payment_transactions: 1,
    pending_payment_amount_by_currency: { USD: 15 },
    pending_checkout_intents: 4,
  },
};

describe('AdminSubscriptionMetrics', () => {
  it('keeps four key values compact in a two-column mobile grid', () => {
    useLocale.setState({ locale: 'ar' });
    render(<AdminSubscriptionMetrics metrics={METRICS} />);

    const grid = screen.getByTestId('stats-grid');
    expect(grid.className).toContain('grid-cols-2');
    expect(screen.getByText('إجمالي المعلمين')).toBeInTheDocument();
    expect(screen.getByText('حسابات مفعّلة')).toBeInTheDocument();
    expect(screen.getByText('تجربة مجانية')).toBeInTheDocument();
    expect(screen.getByText('مشترك مدفوع')).toBeInTheDocument();
    expect(screen.getByText('14')).toBeInTheDocument();
    expect(screen.getByText('13')).toBeInTheDocument();
  });

  it('links each subscription count to the matching backend filter', () => {
    useLocale.setState({ locale: 'ar' });
    render(<AdminSubscriptionMetrics metrics={METRICS} />);

    expect(screen.getByRole('link', { name: /تجربة مجانية/ })).toHaveAttribute(
      'href',
      '/admin/teachers?subscription_state=valid_trial',
    );
    expect(screen.getByRole('link', { name: /مشترك مدفوع/ })).toHaveAttribute(
      'href',
      '/admin/teachers?subscription_state=paid',
    );

    fireEvent.click(screen.getByText('تفاصيل الاشتراكات والمالية'));
    expect(screen.getByRole('link', { name: /وصول إداري/ })).toHaveAttribute(
      'href',
      '/admin/teachers?subscription_state=admin',
    );
    expect(screen.getByRole('link', { name: /منتهي/ })).toHaveAttribute(
      'href',
      '/admin/teachers?subscription_state=expired',
    );
    expect(screen.getByRole('link', { name: /قيد الدفع/ })).toHaveAttribute(
      'href',
      '/admin/subscriptions?status=pending',
    );
    expect(screen.getByRole('link', { name: /سجلات الاشتراكات التاريخية/ })).toHaveAttribute(
      'href',
      '/admin/subscriptions',
    );
  });

  it('separates collected revenue, refunds, MRR and pending amounts by currency', () => {
    useLocale.setState({ locale: 'ar' });
    render(<AdminSubscriptionMetrics metrics={METRICS} />);

    fireEvent.click(screen.getByText('تفاصيل الاشتراكات والمالية'));
    expect(screen.getByText('120.00 USD')).toBeInTheDocument();
    expect(screen.getByText('20.00 USD')).toBeInTheDocument();
    expect(screen.getByText('100.00 USD')).toBeInTheDocument();
    expect(screen.getByText('50.00 USD')).toBeInTheDocument();
    expect(screen.getByText('15.00 USD')).toBeInTheDocument();
  });

  it('shows unavailable provider totals as unknown, never as zero', () => {
    useLocale.setState({ locale: 'en' });
    render(
      <AdminSubscriptionMetrics
        metrics={{
          ...METRICS,
          financials: {
            ...METRICS.financials,
            provider_status: 'unavailable',
            collected_complete: false,
            gross_collected_by_currency: null,
            refunds_by_currency: null,
            net_collected_by_currency: null,
          },
        }}
      />,
    );

    fireEvent.click(screen.getByText('Subscription and finance details'));
    expect(screen.getByTestId('collected-value')).toHaveTextContent('—');
    expect(screen.getByText(/Freemius totals are unavailable/)).toBeInTheDocument();
  });
});
