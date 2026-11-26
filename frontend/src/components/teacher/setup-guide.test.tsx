import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import SetupGuide from './setup-guide';
import { authFetch } from '@/lib/auth-client';
import { useLocale } from '@/store/locale';

vi.mock('@/lib/auth-client', () => ({
  API_BASE: '/api/v1',
  authFetch: vi.fn(),
}));

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const authFetchMock = authFetch as unknown as ReturnType<typeof vi.fn>;

const GUIDE = {
  steps: [
    { key: 'profile', done: true, href: '/teacher/complete-profile', optional: false },
    { key: 'course', done: false, href: '/teacher/courses', optional: false },
    { key: 'lesson', done: false, href: '/teacher/courses', optional: false },
    { key: 'content', done: false, href: '/teacher/courses', optional: false },
    { key: 'exam', done: false, href: '/teacher/exams', optional: true },
    { key: 'enrollment', done: false, href: '/teacher/cards', optional: false },
  ],
  completed: 1,
  total: 6,
  required_completed: 1,
  required_total: 5,
  all_done: false,
  dismissed: false,
};

const json = (data: unknown) => Promise.resolve({ ok: true, json: async () => data } as any);

beforeEach(() => {
  authFetchMock.mockReset();
  localStorage.clear();
  useLocale.setState({ locale: 'ar' });
});

describe('SetupGuide', () => {
  it('renders the guide with real progress, help text and an optional badge', async () => {
    authFetchMock.mockImplementation(() => json(GUIDE));

    render(<SetupGuide />);

    expect(await screen.findByTestId('setup-guide')).toBeTruthy();
    // Progress reflects required steps only: 1 of 5.
    expect(screen.getByTestId('setup-guide-progress')).toHaveTextContent('1 / 5');
    // Step labels + Arabic help.
    expect(screen.getByText('أنشئ أول كورس')).toBeInTheDocument();
    expect(screen.getByText('الكورس هو الحاوية التي تجمع دروسك واختباراتك.')).toBeInTheDocument();
    // The exam is flagged optional.
    expect(screen.getByText('اختياري')).toBeInTheDocument();
    // Each step links to a real page.
    expect(screen.getByTestId('setup-step-course').getAttribute('href')).toBe('/teacher/courses');
  });

  it('hides the guide (persisted server-side) and offers a restore affordance', async () => {
    const user = userEvent.setup();
    authFetchMock.mockImplementation((url: string, init?: RequestInit) => {
      if (init?.method === 'POST' && String(url).endsWith('/dismiss')) return json({ dismissed: true });
      return json(GUIDE);
    });

    render(<SetupGuide />);
    await screen.findByTestId('setup-guide');

    await user.click(screen.getByText('إخفاء الدليل'));

    await waitFor(() => expect(screen.getByTestId('setup-guide-restore')).toBeTruthy());
    expect(screen.queryByTestId('setup-guide')).toBeNull();
    // The hide is a real API call, not just local state.
    expect(authFetchMock).toHaveBeenCalledWith('/api/v1/teacher/onboarding/dismiss', expect.objectContaining({ method: 'POST' }));
  });

  it('restores a previously hidden guide', async () => {
    const user = userEvent.setup();
    authFetchMock.mockImplementation((url: string, init?: RequestInit) => {
      if (init?.method === 'POST' && String(url).endsWith('/restore')) return json({ dismissed: false });
      return json({ ...GUIDE, dismissed: true });
    });

    render(<SetupGuide />);
    await screen.findByTestId('setup-guide-restore');

    await user.click(screen.getByText('استعادة الدليل'));

    expect(await screen.findByTestId('setup-guide')).toBeTruthy();
  });

  it('stays out of the way once the required steps are complete', async () => {
    authFetchMock.mockImplementation(() => json({ ...GUIDE, completed: 6, required_completed: 5, all_done: true }));

    const { container } = render(<SetupGuide />);

    await waitFor(() => expect(authFetchMock).toHaveBeenCalled());
    expect(container.firstChild).toBeNull();
  });

  it('renders nothing (never breaks the dashboard) when the request fails', async () => {
    authFetchMock.mockRejectedValue(new Error('network down'));

    const { container } = render(<SetupGuide />);

    await waitFor(() => expect(authFetchMock).toHaveBeenCalled());
    expect(container.firstChild).toBeNull();
  });
});
