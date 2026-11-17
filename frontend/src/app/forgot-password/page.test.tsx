import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ForgotPage from './page';
import { useLocale } from '@/store/locale';
import { toast } from 'sonner';

const fetchMock = vi.fn();

beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal('fetch', fetchMock);
  useLocale.setState({ locale: 'en' });
});

async function submitEmail(email = 'user@test.local') {
  const user = userEvent.setup();
  await user.type(screen.getByRole('textbox'), email);
  await user.click(screen.getByRole('button', { name: /send/i }));
}

describe('forgot-password page', () => {
  it('promises an email only when the backend confirms delivery is configured', async () => {
    fetchMock.mockResolvedValue({ ok: true, json: async () => ({ message: 'sent', delivery: 'email' }) });
    render(<ForgotPage />);

    await submitEmail();

    expect(await screen.findByText('Check your email')).toBeInTheDocument();
    expect(screen.queryByText('Request received')).not.toBeInTheDocument();
  });

  it('does NOT claim a mail was sent when delivery is not configured', async () => {
    fetchMock.mockResolvedValue({ ok: true, json: async () => ({ message: 'sent', delivery: 'unconfigured' }) });
    render(<ForgotPage />);

    await submitEmail();

    expect(await screen.findByText('Request received')).toBeInTheDocument();
    expect(screen.getByText(/email delivery is not configured/i)).toBeInTheDocument();
    // It must never say the message was sent.
    expect(screen.queryByText('Check your email')).not.toBeInTheDocument();
  });

  it('treats a missing delivery flag as unconfigured (fails safe)', async () => {
    fetchMock.mockResolvedValue({ ok: true, json: async () => ({ message: 'sent' }) });
    render(<ForgotPage />);

    await submitEmail();

    expect(await screen.findByText('Request received')).toBeInTheDocument();
  });

  it('shows an error and stays on the form when the request fails', async () => {
    fetchMock.mockResolvedValue({ ok: false, json: async () => ({ message: 'Too many requests' }) });
    render(<ForgotPage />);

    await submitEmail();

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Too many requests'));
    expect(screen.queryByText('Check your email')).not.toBeInTheDocument();
    expect(screen.queryByText('Request received')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /send/i })).toBeInTheDocument();
  });
});
