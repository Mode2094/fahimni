import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { SessionStatusBanner } from './session-status-banner';
import { useLocale } from '@/store/locale';

const h = vi.hoisted(() => ({ refreshSession: vi.fn() }));

vi.mock('@/store', () => {
  const useAuth: any = (selector: any) =>
    selector({
      status: 'unavailable',
      user: { id: 1 },
      loading: false,
      refreshSession: h.refreshSession,
    });
  return { useAuth };
});

beforeEach(() => {
  h.refreshSession.mockReset();
  useLocale.setState({ locale: 'ar' });
});

describe('SessionStatusBanner', () => {
  it('never claims a logout, offers a retry, and uses an AA-contrast surface', async () => {
    render(<SessionStatusBanner />);

    // The message must reassure the user that the session survives.
    expect(screen.getByRole('status')).toHaveTextContent('تعذر الاتصال بالخادم');
    expect(screen.getByRole('status')).toHaveTextContent('محفوظة');

    const retry = screen.getByRole('button', { name: 'إعادة المحاولة' });
    // White-on-amber-500 failed WCAG AA; the banner now uses dark text on a
    // light amber surface.
    expect(retry.className).toContain('text-amber-950');

    await userEvent.click(retry);
    expect(h.refreshSession).toHaveBeenCalledTimes(1);
  });
});
