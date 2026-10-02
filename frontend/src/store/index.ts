import { create } from 'zustand';
import type { User } from '@/types/api';
import { API_BASE, authFetch, clearStoredUser, hasSessionHint, setSessionHint } from '@/lib/auth-client';

type LoginOutcome = { requires2fa: boolean };

type AuthState = {
  user: User | null;
  loading: boolean;
  initialized: boolean;
  login: (e: string, p: string, extra?: Record<string, unknown>) => Promise<LoginOutcome>;
  login2fa: (code: string) => Promise<void>;
  loginAndRedirect: (e: string, p: string) => Promise<string>;
  logout: () => Promise<void>;
  load: () => Promise<void>;
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

async function fetchCurrentUser(): Promise<User | null> {
  const res = await authFetch('/api/session/me');
  if (!res.ok) return null;

  const data = await res.json().catch(() => null);

  return (data?.user ?? null) as User | null;
}

/**
 * Heal a missing/stale tenant id on existing sessions: without it every
 * tenant-scoped API call fails (400) and the dashboard shows "—". A stale id
 * from a previous session on a shared browser is cleared when the account has
 * no (or a different) tenant.
 */
async function healTenant(user: User): Promise<void> {
  const tid = typeof window !== 'undefined' ? localStorage.getItem('tenant_id') : null;

  if (user.tenants?.length) {
    if (!tid || !user.tenants.some((t: any) => t.id === tid)) {
      try {
        const res = await authFetch(`${API_BASE}/tenants`);
        if (res.ok) {
          const data = await res.json().catch(() => ({}));
          const first = (data.tenants || [])[0];
          if (first?.id) localStorage.setItem('tenant_id', first.id);
          else localStorage.removeItem('tenant_id');
        }
      } catch {
        /* non-blocking */
      }
    }
  } else if (typeof window !== 'undefined') {
    localStorage.removeItem('tenant_id');
  }
}

export const useAuth = create<AuthState>((set, get) => ({
  user: null,
  loading: false,
  initialized: false,

  login: async (email, password, extra) => {
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

      localStorage.setItem('user', JSON.stringify(user));
      setSessionHint(true);
      await healTenant(user);
      set({ user, loading: false, initialized: true });

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

      localStorage.setItem('user', JSON.stringify(user));
      setSessionHint(true);
      await healTenant(user);
      set({ user, loading: false, initialized: true });

      try {
        await import('@/store/settings').then((m) => m.useSettings.getState().load());
        await import('@/store/plan').then((m) => m.usePlan.getState().load());
      } catch { /* */ }
    } catch (error) {
      set({ loading: false });
      throw error;
    }
  },

  loginAndRedirect: async (email, password) => {
    await get().login(email, password);
    const u = get().user;
    if (!u) return '/teacher/login';
    if (u.is_super_admin) return '/admin/dashboard';
    if (u.roles?.includes('Teacher')) return '/teacher/dashboard';
    return '/my-learning';
  },

  logout: async () => {
    const u = get().user;
    let target = '/student/login';
    if (u?.is_super_admin) target = '/admin/login';
    else if (u?.roles?.includes('Teacher')) target = '/teacher/login';

    try {
      await authFetch('/api/session/logout', { method: 'POST' });
    } catch { /* */ }

    // Keep locale + device identity + remembered email — only session data is
    // wiped. Passwords are never persisted (legacy copies are removed).
    const locale = localStorage.getItem('locale');
    const deviceId = localStorage.getItem('lms_device_id');
    const rememberedEmail = localStorage.getItem('lms_remembered_email');
    localStorage.clear();
    if (locale) localStorage.setItem('locale', locale);
    if (deviceId) localStorage.setItem('lms_device_id', deviceId);
    if (rememberedEmail) localStorage.setItem('lms_remembered_email', rememberedEmail);
    set({ user: null, loading: false, initialized: true });
    if (typeof window !== 'undefined') window.location.href = target;
  },

  load: async () => {
    set({ loading: true });

    try {
      const user = await fetchCurrentUser();

      if (user) {
        localStorage.setItem('user', JSON.stringify(user));
        setSessionHint(true);
        await healTenant(user);
        set({ user, loading: false, initialized: true });

        return;
      }

      clearStoredUser();
      set({ user: null, loading: false, initialized: true });
    } catch {
      set({ loading: false, initialized: true });
    }
  },

  check: () => !!get().user || hasSessionHint(),
  hasRole: (...roles) => roles.some((r) => get().user?.roles?.includes(r) ?? false),
  hasPerm: (perm) => get().user?.permissions?.includes(perm) ?? false,
}));
