import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * The auth store owns the "am I still signed in?" decision. These tests pin the
 * status machine that stops the PWA from logging users out on a transient
 * network/5xx error while still honouring a real 401.
 */

const mocks = vi.hoisted(() => ({
  authFetch: vi.fn(),
  hasSessionHint: vi.fn(() => false),
}));

vi.mock('@/lib/auth-client', () => ({
  API_BASE: '/api/v1',
  authFetch: (...args: unknown[]) => mocks.authFetch(...args),
  hasSessionHint: () => mocks.hasSessionHint(),
  setSessionHint: (active: boolean) => {
    if (active) localStorage.setItem('lms_authed', '1');
    else localStorage.removeItem('lms_authed');
  },
  clearStoredUser: () => {
    localStorage.removeItem('lms_authed');
    localStorage.removeItem('user');
    localStorage.removeItem('tenant_id');
  },
}));

// Login success reloads tenant settings/plan — irrelevant here and would hit
// the network, so keep the dynamic imports inert.
vi.mock('@/store/settings', () => ({ useSettings: { getState: () => ({ load: vi.fn() }) } }));
vi.mock('@/store/plan', () => ({ usePlan: { getState: () => ({ load: vi.fn() }) } }));

import { useAuth } from './index';

const USER = { id: 'u1', email: 'student@test.local', roles: [] as string[] };

function res(status: number, body: unknown = {}) {
  return { ok: status >= 200 && status < 300, status, json: async () => body };
}

function resetStore() {
  useAuth.setState({
    user: null,
    loading: false,
    initialized: false,
    status: 'unknown',
    lastValidatedAt: 0,
  });
}

beforeEach(() => {
  localStorage.clear();
  mocks.authFetch.mockReset();
  mocks.hasSessionHint.mockReset();
  mocks.hasSessionHint.mockReturnValue(false);
  resetStore();
});

describe('auth store — session validation', () => {
  it('treats a 200 session check as authenticated and caches the profile', async () => {
    mocks.authFetch.mockResolvedValue(res(200, { user: USER }));

    await useAuth.getState().load();

    const s = useAuth.getState();
    expect(s.status).toBe('authenticated');
    expect(s.user?.email).toBe(USER.email);
    expect(s.initialized).toBe(true);
    expect(JSON.parse(localStorage.getItem('user')!)).toMatchObject({ id: 'u1' });
    expect(localStorage.getItem('lms_authed')).toBe('1');
  });

  it('treats a real 401 as unauthenticated and clears the cache', async () => {
    localStorage.setItem('user', JSON.stringify(USER));
    localStorage.setItem('lms_authed', '1');
    mocks.authFetch.mockResolvedValue(res(401, { message: 'Unauthenticated.' }));

    await useAuth.getState().load();

    const s = useAuth.getState();
    expect(s.status).toBe('unauthenticated');
    expect(s.user).toBeNull();
    expect(localStorage.getItem('user')).toBeNull();
    expect(localStorage.getItem('lms_authed')).toBeNull();
  });

  it('keeps the cached profile and reports unavailable on a 5xx', async () => {
    localStorage.setItem('user', JSON.stringify(USER));
    localStorage.setItem('lms_authed', '1');
    mocks.authFetch.mockResolvedValue(res(502, { message: 'Bad Gateway' }));

    await useAuth.getState().load();

    const s = useAuth.getState();
    expect(s.status).toBe('unavailable');
    expect(s.status).not.toBe('unauthenticated');
    expect(s.user?.id).toBe('u1');
    expect(localStorage.getItem('lms_authed')).toBe('1');
  });

  it('keeps the cached profile on a network failure (offline)', async () => {
    localStorage.setItem('user', JSON.stringify(USER));
    mocks.authFetch.mockRejectedValue(new TypeError('Failed to fetch'));

    await useAuth.getState().load();

    const s = useAuth.getState();
    expect(s.status).toBe('unavailable');
    expect(s.user?.id).toBe('u1');
  });

  it('returns false for an unavailable check when there is no cached user', async () => {
    mocks.hasSessionHint.mockReturnValue(true);
    mocks.authFetch.mockRejectedValue(new TypeError('Failed to fetch'));

    const result = await useAuth.getState().refreshSession();

    expect(result).toBe(false);
    expect(useAuth.getState().status).toBe('unavailable');
    expect(useAuth.getState().user).toBeNull();
  });

  it('runs concurrent checks through a single request (single-flight)', async () => {
    let resolve!: (value: unknown) => void;
    mocks.authFetch.mockReturnValue(new Promise((r) => { resolve = r; }));

    const a = useAuth.getState().load();
    const b = useAuth.getState().load();
    resolve(res(200, { user: USER }));

    await Promise.all([a, b]);

    expect(mocks.authFetch).toHaveBeenCalledTimes(1);
    expect(useAuth.getState().status).toBe('authenticated');
  });
});

describe('auth store — login', () => {
  it('authenticates and returns requires2fa:false on success', async () => {
    mocks.authFetch.mockResolvedValue(res(200, { user: USER }));

    const outcome = await useAuth.getState().login('student@test.local', 'secret');

    expect(outcome).toEqual({ requires2fa: false });
    expect(useAuth.getState().status).toBe('authenticated');
    expect(useAuth.getState().user?.id).toBe('u1');
  });

  it('leaves the existing session intact when a login attempt fails', async () => {
    useAuth.setState({ user: USER as never, status: 'authenticated', initialized: true });
    mocks.authFetch.mockResolvedValue(res(401, { message: 'Invalid credentials' }));

    await expect(useAuth.getState().login('student@test.local', 'wrong')).rejects.toThrow();

    const s = useAuth.getState();
    expect(s.status).toBe('authenticated');
    expect(s.user?.id).toBe('u1');
    expect(s.loading).toBe(false);
  });

  it('reports requires2fa and does not mark the user authenticated', async () => {
    mocks.authFetch.mockResolvedValue(res(200, { requires_2fa: true }));

    const outcome = await useAuth.getState().login('teacher@test.local', 'secret');

    expect(outcome).toEqual({ requires2fa: true });
    expect(useAuth.getState().status).toBe('unknown');
    expect(useAuth.getState().loading).toBe(false);
  });
});

describe('auth store — unauthorized signal', () => {
  it('clears the session when handleUnauthorized is called', () => {
    useAuth.setState({ user: USER as never, status: 'authenticated', initialized: true });
    localStorage.setItem('user', JSON.stringify(USER));
    localStorage.setItem('lms_authed', '1');

    useAuth.getState().handleUnauthorized();

    expect(useAuth.getState().status).toBe('unauthenticated');
    expect(useAuth.getState().user).toBeNull();
    expect(localStorage.getItem('lms_authed')).toBeNull();
  });

  it('ignores a stale 401 that predates the current login', async () => {
    mocks.authFetch.mockResolvedValue(res(200, { user: USER }));
    await useAuth.getState().login('student@test.local', 'secret');

    useAuth.getState().handleUnauthorized(Date.now() - 60_000);

    expect(useAuth.getState().status).toBe('authenticated');
    expect(useAuth.getState().user?.id).toBe('u1');
  });

  it('reacts to the global auth:unauthorized event', () => {
    useAuth.setState({ user: USER as never, status: 'authenticated', initialized: true });

    window.dispatchEvent(new CustomEvent('auth:unauthorized', { detail: { at: Date.now() } }));

    expect(useAuth.getState().status).toBe('unauthenticated');
  });
});

describe('auth store — refresh gate', () => {
  it('skips the network when there is no user and no session hint', async () => {
    mocks.hasSessionHint.mockReturnValue(false);

    const refreshed = await useAuth.getState().refreshSession();

    expect(refreshed).toBe(false);
    expect(mocks.authFetch).not.toHaveBeenCalled();
  });

  it('validates when a session hint is present', async () => {
    mocks.hasSessionHint.mockReturnValue(true);
    mocks.authFetch.mockResolvedValue(res(200, { user: USER }));

    const refreshed = await useAuth.getState().refreshSession();

    expect(refreshed).toBe(true);
    expect(useAuth.getState().status).toBe('authenticated');
  });
});
