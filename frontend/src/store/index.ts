import { create } from 'zustand';
import type { User } from '@/types/api';
import { API_BASE, authFetch, clearStoredUser, getStoredTenantId, hasSessionHint, setSessionHint, setStoredTenantId } from '@/lib/auth-client';
import { dashboardPathFor } from '@/lib/auth-routes';

type LoginOutcome = { requires2fa: boolean };

export type ViewAsState = {
  active: boolean;
  read_only: boolean;
  actor_id?: string | null;
  actor_name?: string | null;
  user_id: string;
  user_name?: string | null;
  started_at?: string | null;
};

/**
 * `unknown`     — boot: the HttpOnly session has not been checked yet.
 * `authenticated` — a live session (or a cached profile during a transient
 *                   outage) exists.
 * `unauthenticated` — the server explicitly answered 401.
 * `unavailable` — the check failed for network/5xx reasons; local state is
 *                 preserved and the user is NOT sent to the login page.
 */
export type AuthStatus = 'unknown' | 'authenticated' | 'unauthenticated' | 'unavailable';

type AuthState = {
  user: User | null;
  /** Active admin "view-as (read-only)" state, resolved from the session. */
  viewAs: ViewAsState | null;
  loading: boolean;
  initialized: boolean;
  status: AuthStatus;
  /** Last successful server validation (ms) — drives background refresh. */
  lastValidatedAt: number;
  login: (e: string, p: string, extra?: Record<string, unknown>) => Promise<LoginOutcome>;
  login2fa: (code: string) => Promise<void>;
  completeProfile: (name: string, phone: string) => Promise<{ tenantId: string | null; trialStarted: boolean }>;
  loginAndRedirect: (e: string, p: string) => Promise<string>;
  logout: () => Promise<void>;
  load: () => Promise<void>;
  stopViewAs: () => Promise<void>;
  refreshSession: () => Promise<boolean>;
  handleUnauthorized: (at?: number) => void;
  check: () => boolean;
  hasRole: (...r: string[]) => boolean;
  hasPerm: (p: string) => boolean;
};

async function postJson(path: string, body: unknown): Promise<{ ok: boolean; data: any }> {
  const res = await authFetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));

  return { ok: res.ok, data };
}

function readCachedUser(): User | null {
  if (typeof window === 'undefined') return null;

  try {
    const raw = localStorage.getItem('user');
    if (!raw) return null;
    const parsed = JSON.parse(raw);

    return parsed && typeof parsed === 'object' && parsed.id ? (parsed as User) : null;
  } catch {
    return null;
  }
}

function cacheUser(user: User | null): void {
  if (typeof window === 'undefined') return;

  if (user) {
    try {
      localStorage.setItem('user', JSON.stringify(user));
    } catch {
      // The HttpOnly session remains valid when browser storage is restricted.
    }
    setSessionHint(true);
  } else {
    clearStoredUser();
  }
}

/**
 * Heal a missing/stale tenant id on existing sessions: without it every
 * tenant-scoped API call fails (400) and the dashboard shows "—". A stale id
 * from a previous session on a shared browser is cleared when the account has
 * no (or a different) tenant.
 */
async function healTenant(user: User): Promise<void> {
  if (typeof window === 'undefined') return;

  const tid = getStoredTenantId();

  if (user.tenants?.length) {
    const activeTenants = user.tenants.filter((tenant: any) => tenant?.id && tenant.status === 'active');
    if (tid && activeTenants.some((tenant: any) => tenant.id === tid)) return;

    if (activeTenants.length === 1) {
      setStoredTenantId(activeTenants[0].id);
      return;
    }

    // Never guess the first tenant for a multi-workspace student. The teacher
    // URL/card or a saved, server-validated selection supplies the context.
    setStoredTenantId(null);
  } else if (typeof window !== 'undefined') {
    setStoredTenantId(null);
  }
}

let validationInFlight: Promise<boolean> | null = null;
let lastAuthAt = 0;
let loggingOut = false;

/**
 * Validate the HttpOnly session exactly once at a time.
 *
 * - 200 + user  → authenticated (cache the profile, heal tenant context)
 * - 401         → unauthenticated (clear the cache; the server said no)
 * - 5xx/network → unavailable (keep the cached profile; never fake a logout)
 */
function validateSession(): Promise<boolean> {
  if (validationInFlight) return validationInFlight;

  validationInFlight = (async () => {
    const cached = useAuth.getState().user ?? readCachedUser();

    // Show the cached profile immediately: protected shells render the app
    // instead of a blank screen or a login redirect while the check runs.
    useAuth.setState({
      user: cached,
      status: cached ? 'authenticated' : 'unknown',
      loading: true,
    });

    try {
      const res = await authFetch('/api/session/me', { cache: 'no-store' });

      if (res.ok) {
        const data = await res.json().catch(() => null);
        const user = (data?.user ?? null) as User | null;
        const viewAs = (data?.view_as ?? null) as ViewAsState | null;

        if (user) {
          cacheUser(user);
          await healTenant(user);
          useAuth.setState({
            user,
            viewAs,
            status: 'authenticated',
            loading: false,
            initialized: true,
            lastValidatedAt: Date.now(),
          });

          return true;
        }
      }

      if (res.status === 401) {
        cacheUser(null);
        useAuth.setState({
          user: null,
          viewAs: null,
          status: 'unauthenticated',
          loading: false,
          initialized: true,
          lastValidatedAt: 0,
        });

        return false;
      }

      throw new Error(`Session check failed with ${res.status}`);
    } catch {
      // Offline, deploy in progress, 502/500, DNS failure: the cookie may
      // still be perfectly valid. Preserve everything and retry later.
      const current = useAuth.getState();
      const keepUser = current.user ?? cached;

      useAuth.setState({
        user: keepUser,
        status: 'unavailable',
        loading: false,
        initialized: true,
      });

      return !!keepUser;
    }
  })().finally(() => {
    validationInFlight = null;
  });

  return validationInFlight;
}

export const useAuth = create<AuthState>((set, get) => ({
  user: null,
  viewAs: null,
  loading: false,
  initialized: false,
  status: 'unknown',
  lastValidatedAt: 0,

  login: async (email, password, extra) => {
    loggingOut = false;
    set({ loading: true });

    try {
      const { ok, data } = await postJson('/api/session/login', { email, password, ...(extra || {}) });

      if (!ok) {
        throw Object.assign(new Error(data?.message || 'Login failed'), { response: { data } });
      }

      if (data?.requires_2fa) {
        set({ loading: false });

        return { requires2fa: true };
      }

      const user = (data?.user ?? null) as User | null;

      if (!user) {
        set({ loading: false });

        throw new Error('Login failed');
      }

      lastAuthAt = Date.now();
      setStoredTenantId(data?.tenant_id ? String(data.tenant_id) : null);
      cacheUser(user);
      await healTenant(user);
      set({
        user,
        viewAs: null,
        loading: false,
        initialized: true,
        status: 'authenticated',
        lastValidatedAt: Date.now(),
      });

      // إعادة تحميل إعدادات المنصة (اللوجو/الاسم) بعد الدخول — فالجلب كضيف
      // (بلا X-Tenant-Id) يعيد لوجو المنصة العام ويبقى عالقاً طوال الجلسة.
      try {
        await import('@/store/settings').then((m) => m.useSettings.getState().load());
        await import('@/store/plan').then((m) => m.usePlan.getState().load());
      } catch { /* */ }

      return { requires2fa: false };
    } catch (error) {
      set({ loading: false });
      throw error;
    }
  },

  login2fa: async (code) => {
    loggingOut = false;
    set({ loading: true });

    try {
      const { ok, data } = await postJson('/api/session/2fa', { code });

      if (!ok) {
        throw Object.assign(new Error(data?.message || 'Invalid 2FA code'), { response: { data } });
      }

      const user = (data?.user ?? null) as User | null;

      if (!user) {
        throw new Error('Login failed');
      }

      lastAuthAt = Date.now();
      setStoredTenantId(data?.tenant_id ? String(data.tenant_id) : null);
      cacheUser(user);
      await healTenant(user);
      set({
        user,
        viewAs: null,
        loading: false,
        initialized: true,
        status: 'authenticated',
        lastValidatedAt: Date.now(),
      });

      try {
        await import('@/store/settings').then((m) => m.useSettings.getState().load());
        await import('@/store/plan').then((m) => m.usePlan.getState().load());
      } catch { /* */ }
    } catch (error) {
      set({ loading: false });
      throw error;
    }
  },

  completeProfile: async (name, phone) => {
    const { ok, data } = await postJson('/api/session/complete-profile', { name, phone });

    if (!ok) {
      throw Object.assign(new Error(data?.message || 'Failed'), { response: { data } });
    }

    const user = (data?.user ?? null) as User | null;

    if (user) {
      setStoredTenantId(data?.tenant_id ? String(data.tenant_id) : null);
      cacheUser(user);
      await healTenant(user);
      set({
        user,
        loading: false,
        initialized: true,
        status: 'authenticated',
        lastValidatedAt: Date.now(),
      });
    }

    try {
      await import('@/store/settings').then((m) => m.useSettings.getState().load());
      await import('@/store/plan').then((m) => m.usePlan.getState().load());
    } catch { /* non-blocking */ }

    return {
      tenantId: (data?.tenant_id as string) ?? null,
      trialStarted: !!data?.trial_started,
    };
  },

  loginAndRedirect: async (email, password) => {
    await get().login(email, password);
    const u = get().user;
    if (!u) return '/teacher/login';

    return dashboardPathFor(u);
  },

  logout: async () => {
    const u = get().user;
    let target = '/student/login';
    if (u?.is_super_admin) target = '/admin/login';
    else if (u?.roles?.includes('Teacher')) target = '/teacher/login';

    loggingOut = true;
    lastAuthAt = 0;

    // Drop this device's web-push subscription for the signing-out account
    // FIRST (while the session is still valid) so a shared device never keeps
    // receiving the previous student's notifications. Best-effort only.
    try {
      const [{ unsubscribeFromPush }, { pushTokens }] = await Promise.all([
        import('@/lib/push'),
        import('@/services/endpoints'),
      ]);
      const token = await unsubscribeFromPush();
      if (token) await pushTokens.remove(token).catch(() => {});
    } catch { /* push is optional and must never block sign-out */ }

    try {
      await authFetch('/api/session/logout', { method: 'POST' });
    } catch { /* the BFF expires cookies even when the upstream is unreachable */ }

    // Keep locale + device identity + remembered email — only auth data is
    // wiped. Never call localStorage.clear(): it would erase unrelated PWA
    // preferences and cached UI state.
    cacheUser(null);
    if (typeof window !== 'undefined') {
      for (const key of ['token', 'user_role', 'lms_remembered_password', 'lms_admin_remembered_password']) {
        try {
          localStorage.removeItem(key);
        } catch {
          // Server-side logout has already invalidated the session.
        }
      }
    }

    set({
      user: null,
      viewAs: null,
      loading: false,
      initialized: true,
      status: 'unauthenticated',
      lastValidatedAt: 0,
    });

    if (typeof window !== 'undefined') {
      // replace() so the back button cannot reopen a protected page.
      window.location.replace(target);
    }
  },

  stopViewAs: async () => {
    try {
      await authFetch(`${API_BASE}/admin/view-as`, { method: 'DELETE' });
    } catch { /* fall through to reload — the session decides */ }

    // A full reload re-resolves the restored admin session everywhere.
    if (typeof window !== 'undefined') window.location.href = '/admin/dashboard';
  },

  load: async () => {
    await validateSession();
  },

  refreshSession: async () => {
    if (loggingOut) return false;
    if (!get().user && !hasSessionHint()) return false;

    return validateSession();
  },

  /**
   * Called from the global `auth:unauthorized` signal. Ignores 401s that were
   * already in flight before a newer successful login (another tab).
   */
  handleUnauthorized: (at = Date.now()) => {
    if (loggingOut || at < lastAuthAt) return;
    if (get().status === 'unauthenticated') return;

    cacheUser(null);
    set({
      user: null,
      viewAs: null,
      loading: false,
      initialized: true,
      status: 'unauthenticated',
      lastValidatedAt: 0,
    });
  },

  check: () => !!get().user || hasSessionHint(),
  hasRole: (...roles) => roles.some((r) => get().user?.roles?.includes(r) ?? false),
  hasPerm: (perm) => get().user?.permissions?.includes(perm) ?? false,
}));

if (typeof window !== 'undefined') {
  window.addEventListener('auth:unauthorized', (event) => {
    const at = Number((event as CustomEvent<{ at?: number }>).detail?.at) || Date.now();
    useAuth.getState().handleUnauthorized(at);
  });
}
