import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useLocale } from '@/store/locale';
import ManualSubscriptionModal from './manual-subscription-modal';

const plans = [
  { id: 'plan-starter', name: 'الانطلاقة', monthly_price: 29, yearly_price: 290, currency: 'USD' },
  { id: 'plan-pro', name: 'المحترف', monthly_price: 59, yearly_price: 590, currency: 'USD' },
];

const preview = {
  operation_type: 'activate',
  billing_cycle: 'monthly',
  duration_days: 30,
  starts_at: '2026-11-26T00:00:00Z',
  ends_at: '2026-12-26T00:00:00Z',
  previous_ends_at: '2026-12-03T00:00:00Z',
  remaining_days_preserved: true,
  amount: 29,
  currency: 'USD',
  is_free_grant: false,
  plan: { id: 'plan-starter', name: 'الانطلاقة' },
  warnings: [],
};

function stubFetch(overrides: Record<string, any> = {}) {
  return vi.fn((input: unknown, _init?: RequestInit) => {
    const url = String(input);
    if (url.includes('/subscription/preview')) {
      return Promise.resolve({ ok: true, json: async () => ({ preview: { ...preview, ...(overrides.preview || {}) } }) });
    }
    if (url.includes('/subscription/manual')) {
      return Promise.resolve({ ok: true, json: async () => ({ already_applied: false, operation: { ends_at: '2026-12-26T00:00:00Z' } }) });
    }
    return Promise.resolve({ ok: true, json: async () => ({}) });
  });
}

beforeEach(() => {
  useLocale.setState({ locale: 'ar' });
});

describe('ManualSubscriptionModal', () => {
  it('renders the plan list and the current teacher name (RTL-ready)', async () => {
    vi.stubGlobal('fetch', stubFetch());

    render(
      <ManualSubscriptionModal
        open
        onOpenChange={() => {}}
        teacher={{ id: 't1', name: 'أستاذ أحمد' }}
        current={{ plan_name: 'المحترف', status: 'active', source: 'legacy' }}
        plans={plans}
        onDone={() => {}}
      />,
    );

    expect(screen.getByTestId('manual-subscription-modal')).toBeInTheDocument();
    expect(screen.getByText('أستاذ أحمد')).toBeInTheDocument();
    expect(screen.getByText(/تفعيل \/ إدارة الاشتراك يدويًا/)).toBeInTheDocument();
    // Both plans are selectable.
    expect(screen.getByRole('option', { name: /الانطلاقة/ })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: /المحترف/ })).toBeInTheDocument();
  });

  it('shows the server-computed preview before confirming', async () => {
    vi.stubGlobal('fetch', stubFetch());

    render(
      <ManualSubscriptionModal
        open
        onOpenChange={() => {}}
        teacher={{ id: 't1', name: 'أستاذ أحمد' }}
        current={null}
        plans={plans}
        onDone={() => {}}
      />,
    );

    expect(await screen.findByTestId('manual-preview')).toBeInTheDocument();
    expect(screen.getByText(/سيتم الحفاظ على الأيام المدفوعة المتبقية/)).toBeInTheDocument();
  });

  it('blocks a paid operation until the admin confirms receipt', async () => {
    vi.stubGlobal('fetch', stubFetch());
    const onOpenChange = vi.fn();

    render(
      <ManualSubscriptionModal
        open
        onOpenChange={onOpenChange}
        teacher={{ id: 't1', name: 'أستاذ أحمد' }}
        current={null}
        plans={plans}
        onDone={() => {}}
      />,
    );

    fireEvent.click(screen.getByTestId('manual-submit'));

    expect(await screen.findByText(/يجب تأكيد التحقق من استلام الدفعة/)).toBeInTheDocument();
    expect(onOpenChange).not.toHaveBeenCalled();
  });

  it('submits a confirmed paid operation and reports completion', async () => {
    const fetchMock = stubFetch();
    vi.stubGlobal('fetch', fetchMock);
    const onOpenChange = vi.fn();
    const onDone = vi.fn();

    render(
      <ManualSubscriptionModal
        open
        onOpenChange={onOpenChange}
        teacher={{ id: 't1', name: 'أستاذ أحمد' }}
        current={null}
        plans={plans}
        onDone={onDone}
      />,
    );

    fireEvent.change(screen.getByTestId('payment-reference'), { target: { value: 'CLIQ-TEST-1' } });
    fireEvent.click(screen.getByRole('switch'));
    fireEvent.click(screen.getByTestId('manual-submit'));

    await waitFor(() => expect(onDone).toHaveBeenCalledTimes(1));
    expect(onOpenChange).toHaveBeenCalledWith(false);

    const manualCall = fetchMock.mock.calls.find(([input]) => String(input).includes('/subscription/manual'));
    expect(manualCall).toBeTruthy();
    const body = JSON.parse((manualCall![1] as RequestInit).body as string);
    expect(body.operation_type).toBe('activate');
    expect(body.payment_method).toBe('cliq');
    expect(body.payment_reference).toBe('CLIQ-TEST-1');
    expect(body.confirm_payment_received).toBe(true);
  });
});
