import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { useLocale } from '@/store/locale';

const activate = vi.fn();

vi.mock('@/lib/auth-client', () => ({
  authFetch: (...args: any[]) => activate(...args),
}));

import AdminSupportEnterPage from './page';

beforeEach(() => {
  useLocale.setState({ locale: 'ar' });
  activate.mockReset();
  window.history.replaceState({}, '', '/admin/support/enter');
});

describe('AdminSupportEnterPage', () => {
  it('shows an error and a way back when the token is missing', async () => {
    render(<AdminSupportEnterPage />);

    expect(await screen.findByText(/رابط الدخول غير مكتمل/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /العودة إلى الإدارة/ })).toHaveAttribute('href', '/admin/dashboard');
  });

  it('surfaces the server rejection for an invalid or already-used token', async () => {
    window.history.replaceState({}, '', '/admin/support/enter?token=bad');
    activate.mockResolvedValue({ ok: false, json: async () => ({ message: 'انتهت صلاحية الرابط أو تم استخدامه.' }) });

    render(<AdminSupportEnterPage />);

    expect(await screen.findByText('انتهت صلاحية الرابط أو تم استخدامه.')).toBeInTheDocument();
    expect(activate).toHaveBeenCalledTimes(1);
  });
});
