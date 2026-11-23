import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ResetPasswordPage from './page';
import { useLocale } from '@/store/locale';

const h = vi.hoisted(() => ({
  push: vi.fn(),
  params: new URLSearchParams(),
}));

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: h.push }),
  useSearchParams: () => h.params,
}));

const fetchMock = vi.fn();

beforeEach(() => {
  h.push.mockClear();
  fetchMock.mockReset();
  vi.stubGlobal('fetch', fetchMock);
  useLocale.setState({ locale: 'en' });
});

function setParams(query: string) {
  h.params = new URLSearchParams(query);
}

describe('reset-password page', () => {
  it('shows the account email and rejects mismatched passwords without calling the API', async () => {
    setParams('token=tok-123&email=user%40test.local');
    render(<ResetPasswordPage />);

    expect(await screen.findByText('user@test.local')).toBeInTheDocument();

    const user = userEvent.setup();
    await user.type(screen.getByLabelText('New password'), 'new-password-1');
    await user.type(screen.getByLabelText('Confirm password'), 'different-2');
    await user.click(screen.getByRole('button', { name: /reset password/i }));

    expect(await screen.findByText('The passwords do not match.')).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('posts the token+email and shows success, then routes to the role login', async () => {
    setParams('token=tok-123&email=user%40test.local');
    fetchMock.mockResolvedValue({
      ok: true,
      json: async () => ({ message: 'reset', login_path: '/teacher/login' }),
    });

    render(<ResetPasswordPage />);
    const user = userEvent.setup();
    await user.type(await screen.findByLabelText('New password'), 'new-password-1');
    await user.type(screen.getByLabelText('Confirm password'), 'new-password-1');
    await user.click(screen.getByRole('button', { name: /reset password/i }));

    expect(await screen.findByText('Password updated')).toBeInTheDocument();

    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toContain('/auth/reset-password');
    expect(JSON.parse((init as any).body)).toMatchObject({
      email: 'user@test.local',
      token: 'tok-123',
      password: 'new-password-1',
      password_confirmation: 'new-password-1',
    });

    await user.click(screen.getByRole('button', { name: /log in/i }));
    expect(h.push).toHaveBeenCalledWith('/teacher/login');
  });

  it('surfaces an invalid/expired token (400) and offers a new link', async () => {
    setParams('token=expired&email=user%40test.local');
    fetchMock.mockResolvedValue({
      ok: false,
      status: 400,
      json: async () => ({ message: 'This password reset token is invalid.' }),
    });

    render(<ResetPasswordPage />);
    const user = userEvent.setup();
    await user.type(await screen.findByLabelText('New password'), 'new-password-1');
    await user.type(screen.getByLabelText('Confirm password'), 'new-password-1');
    await user.click(screen.getByRole('button', { name: /reset password/i }));

    expect(await screen.findByText('This reset link is invalid or has expired. Request a new one.')).toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('link', { name: /request a new link/i })).toBeInTheDocument());
  });

  it('refuses to render the form when the link has no token', async () => {
    setParams('email=user%40test.local');
    render(<ResetPasswordPage />);

    expect(await screen.findByText(/incomplete/i)).toBeInTheDocument();
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument();
  });
});
