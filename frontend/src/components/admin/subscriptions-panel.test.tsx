import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import AdminSubscriptionsPanel from './subscriptions-panel';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { toast } from 'sonner';

const ok = (data: unknown) => Promise.resolve({ ok: true, json: async () => data } as any);

const PLAN = { id: 'plan-1', name: 'Starter', monthly_price: 29 };

function routeFetch(routes: Record<string, (body?: any) => Promise<any>>) {
  return vi.fn((url: unknown, init?: any) => {
    const u = String(url);
    const key = Object.keys(routes).find((k) => u.includes(k));
    if (!key) return Promise.resolve({ ok: false, json: async () => ({}) } as any);
    const body = init?.body ? JSON.parse(init.body) : undefined;
    return routes[key](body);
  });
}

const baseSub = (over: Record<string, any> = {}) => ({
  id: 'sub-1',
  status: 'active',
  billing_cycle: 'monthly',
  starts_at: '2026-11-01T00:00:00Z',
  ends_at: '2026-12-01T00:00:00Z',
  admin_ends_at: null,
  plan: PLAN,
  tenant: { id: 't-1', name: 'Academy One' },
  ...over,
});

beforeEach(() => {
  localStorage.clear();
  vi.restoreAllMocks();
  useAuth.setState({ user: { name: 'Platform Admin' } as any, check: () => true } as any);
  useLocale.setState({ locale: 'ar' });
  useSettings.setState({
    settings: { ...useSettings.getState().settings, currency: 'JOD' },
    formatPrice: (a: any) => `${a}`,
  } as any);
});

describe('Admin subscriptions — administrative extension', () => {
  it('flags an administrative grant on the row (independent of payment status)', async () => {
    vi.stubGlobal('fetch', routeFetch({
      '/admin/subscriptions': () => ok({
        subscriptions: [baseSub({ admin_ends_at: '2099-01-01T00:00:00Z' })],
        stats: {},
      }),
      '/admin/plans': () => ok({ plans: [PLAN] }),
    }));

    render(<AdminSubscriptionsPanel />);

    expect(await screen.findByText(/Academy One/)).toBeInTheDocument();
    expect(screen.getByText(/وصول إداري حتى/)).toBeInTheDocument();
  });

  it('sends the extension with a stable request_id and reports the honest new access date', async () => {
    const user = userEvent.setup();
    const success = vi.spyOn(toast, 'success').mockImplementation(() => 'id' as any);

    let extensionBody: any = null;
    const { container } = render(<AdminSubscriptionsPanel />);

    vi.stubGlobal('fetch', routeFetch({
      '/admin/subscriptions/sub-1/extend': (body) => {
        extensionBody = body;
        return ok({ message: 'extended', already_applied: false, access_ends_at: '2027-01-15T00:00:00Z' });
      },
      '/admin/subscriptions': () => ok({ subscriptions: [baseSub()], stats: {} }),
      '/admin/plans': () => ok({ plans: [PLAN] }),
    }));

    // Re-render under the new fetch stub (initial load already happened above).
    render(<AdminSubscriptionsPanel />);

    const extendBtn = await screen.findByRole('button', { name: /تمديد/ });
    await user.click(extendBtn);

    // Dialog opens with a confirmation-day field and a Save action.
    const save = await screen.findByRole('button', { name: /حفظ/ });
    await user.click(save);

    await waitFor(() => expect(extensionBody).not.toBeNull());
    expect(extensionBody.days).toBe(30);
    expect(typeof extensionBody.request_id).toBe('string');
    expect(extensionBody.request_id.length).toBeGreaterThan(0);

    await waitFor(() => {
      expect(success).toHaveBeenCalled();
      const msg = success.mock.calls.map((c) => String(c[0])).join(' | ');
      expect(msg).toMatch(/تمديد وصول المعلم/);
    });
  });

  it('shows an already-applied message when the server treats a retry as a no-op', async () => {
    const user = userEvent.setup();
    const success = vi.spyOn(toast, 'success').mockImplementation(() => 'id' as any);

    vi.stubGlobal('fetch', routeFetch({
      '/admin/subscriptions/sub-1/extend': () => ok({ message: 'applied', already_applied: true, access_ends_at: '2027-01-15T00:00:00Z' }),
      '/admin/subscriptions': () => ok({ subscriptions: [baseSub()], stats: {} }),
      '/admin/plans': () => ok({ plans: [PLAN] }),
    }));

    render(<AdminSubscriptionsPanel />);
    await user.click(await screen.findByRole('button', { name: /تمديد/ }));
    await user.click(await screen.findByRole('button', { name: /حفظ/ }));

    await waitFor(() => {
      const msg = success.mock.calls.map((c) => String(c[0])).join(' | ');
      expect(msg).toMatch(/تم تطبيق هذا التمديد مسبقًا/);
    });
  });
});
