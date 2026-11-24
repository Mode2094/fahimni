import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import GoogleLoginButton from './google-login-button';

describe('GoogleLoginButton student context', () => {
  it('passes the teacher slug and a safe card return path through signed OAuth state', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      json: async () => ({ message: 'Google OAuth is not configured yet.' }),
    });
    vi.stubGlobal('fetch', fetchMock);
    vi.stubGlobal('alert', vi.fn());

    render(
      <GoogleLoginButton
        role="student"
        tenantSlug="teacher-one"
        redirectTo="/card/ABCDEF123456"
        label="Continue with Google"
      />,
    );
    await userEvent.click(screen.getByRole('button', { name: 'Continue with Google' }));

    const requestUrl = new URL(String(fetchMock.mock.calls[0]?.[0]), window.location.origin);
    expect(requestUrl.searchParams.get('tenant_slug')).toBe('teacher-one');
    expect(requestUrl.searchParams.get('redirect_to')).toBe('/card/ABCDEF123456');
  });

  it('does not forward an external redirect target', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      json: async () => ({ message: 'Google OAuth is not configured yet.' }),
    });
    vi.stubGlobal('fetch', fetchMock);
    vi.stubGlobal('alert', vi.fn());

    render(<GoogleLoginButton role="student" redirectTo="//outside.example/path" />);
    await userEvent.click(screen.getByRole('button'));

    const requestUrl = new URL(String(fetchMock.mock.calls[0]?.[0]), window.location.origin);
    expect(requestUrl.searchParams.has('redirect_to')).toBe(false);
  });
});
