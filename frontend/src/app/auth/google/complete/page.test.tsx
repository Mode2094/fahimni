import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';

import GoogleCompletePage from './page';

const h = vi.hoisted(() => ({
  replace: vi.fn(),
  setState: vi.fn(),
}));

vi.mock('next/navigation', () => ({
  useRouter: () => ({ replace: h.replace, push: vi.fn() }),
}));

vi.mock('@/store', () => {
  const useAuth: any = { setState: h.setState };
  return { useAuth };
});

vi.mock('@/lib/analytics', () => ({ track: vi.fn() }));

const fetchMock = vi.fn();

function setUrl(url: string) {
  window.history.replaceState({}, '', url);
}

beforeEach(() => {
  h.replace.mockClear();
  h.setState.mockClear();
  fetchMock.mockReset();
  vi.stubGlobal('fetch', fetchMock);
  setUrl('/auth/google/complete');
});

describe('Google custom-domain completion', () => {
  it('redeems the fragment ticket and routes the student in', async () => {
    fetchMock.mockResolvedValue({
      ok: true,
      json: async () => ({
        user: { id: 'u1', name: 'S', email: 's@test.local', roles: ['Student'] },
        tenant_id: 't1',
        needs_profile_completion: false,
        redirect_to: null,
      }),
    });

    setUrl('/auth/google/complete#ticket=' + 'a'.repeat(64));
    render(<GoogleCompletePage />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalled());

    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toBe('/api/session/google/redeem');
    expect(init.method).toBe('POST');
    expect(JSON.parse(init.body)).toEqual({ ticket: 'a'.repeat(64) });

    // The ticket is stripped from the address bar immediately.
    await waitFor(() => expect(window.location.hash).toBe(''));
    await waitFor(() => expect(h.replace).toHaveBeenCalledWith('/my-learning'));
  });

  it('never places a token in a URL and shows a non-retryable error for two-factor accounts', async () => {
    setUrl('/auth/google/complete?error=two_factor_required');
    render(<GoogleCompletePage />);

    expect(await screen.findByText(/التحقق بخطوتين/)).toBeTruthy();
    expect(fetchMock).not.toHaveBeenCalled();
    expect(screen.getByRole('link', { name: /العودة لتسجيل الدخول/ })).toHaveAttribute('href', '/student/login');
  });

  it('reports a missing ticket instead of silently failing', async () => {
    setUrl('/auth/google/complete');
    render(<GoogleCompletePage />);

    expect(await screen.findByText(/رابط تسجيل الدخول غير مكتمل/)).toBeTruthy();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
