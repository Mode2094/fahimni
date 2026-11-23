import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import AdminLoginPage from './page';
import { useLocale } from '@/store/locale';
import { useAuth } from '@/store';

const h = vi.hoisted(() => ({
  push: vi.fn(),
  replace: vi.fn(),
  login: vi.fn(),
  login2fa: vi.fn(),
}));

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: h.push, replace: h.replace }),
}));

vi.mock('@/components/auth/google-login-button', () => ({
  default: () => null,
}));

vi.mock('@/store', () => {
  const useAuth: any = (selector: any) =>
    selector({ login: h.login, login2fa: h.login2fa, initialized: false, user: null });
  useAuth.getState = () => ({ user: { is_super_admin: true } });
  useAuth.setState = vi.fn();
  return { useAuth };
});

const fetchMock = vi.fn();

beforeEach(() => {
  h.push.mockClear();
  h.replace.mockClear();
  h.login.mockReset();
  h.login2fa.mockReset();
  fetchMock.mockReset();
  vi.stubGlobal('fetch', fetchMock);
  useLocale.setState({ locale: 'en' });
  // CAPTCHA challenge for the form.
  fetchMock.mockResolvedValue({ ok: true, json: async () => ({ enabled: true, question: '2 + 3 = ?', captcha_id: 'cap-1' }) });
});

describe('Admin login', () => {
  it('sends the CAPTCHA id and answer with the credentials', async () => {
    h.login.mockResolvedValue({ requires2fa: false });
    const user = userEvent.setup();

    render(<AdminLoginPage />);

    await user.type(screen.getByLabelText('Email'), 'admin@test.local');
    await user.type(screen.getByLabelText('Password'), 'secret-123');
    await user.type(await screen.findByLabelText('Security check'), '5');
    await user.click(screen.getByRole('button', { name: /admin sign in/i }));

    await waitFor(() =>
      expect(h.login).toHaveBeenCalledWith('admin@test.local', 'secret-123', {
        captcha_id: 'cap-1',
        captcha_answer: '5',
      }),
    );
    await waitFor(() => expect(h.push).toHaveBeenCalledWith('/admin/dashboard'));
  });

  it('reveals a two-factor field and verifies the code (no admin lockout)', async () => {
    h.login.mockResolvedValue({ requires2fa: true });
    h.login2fa.mockResolvedValue(undefined);
    const user = userEvent.setup();

    render(<AdminLoginPage />);

    await user.type(screen.getByLabelText('Email'), 'admin@test.local');
    await user.type(screen.getByLabelText('Password'), 'secret-123');
    await user.type(await screen.findByLabelText('Security check'), '5');
    await user.click(screen.getByRole('button', { name: /admin sign in/i }));

    const code = await screen.findByLabelText(/two-factor/i);
    await user.type(code, '123456');
    await user.click(screen.getByRole('button', { name: /verify code/i }));

    await waitFor(() => expect(h.login2fa).toHaveBeenCalledWith('123456'));
  });
});
