import axios, { AxiosError } from 'axios';
import { getDeviceId } from '@/lib/device';
import { clearStoredUser, ensureCsrfCookie } from '@/lib/auth-client';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const api = axios.create({
  baseURL: API,
  headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
  timeout: 15000,
  // Auth is an HttpOnly session cookie issued by the BFF — never a token.
  withCredentials: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
});

const UNSAFE_METHODS = new Set(['post', 'put', 'patch', 'delete']);

api.interceptors.request.use(async (config) => {
  if (typeof window !== 'undefined') {
    if (UNSAFE_METHODS.has((config.method || 'get').toLowerCase())) {
      await ensureCsrfCookie();
    }
    const tid = localStorage.getItem('tenant_id');
    if (tid) config.headers['X-Tenant-Id'] = tid;
    config.headers['X-Device-Id'] = getDeviceId();
  }
  return config;
});

api.interceptors.response.use(
  (r) => r,
  (e) => {
    if (e.response?.status === 402 && e.response?.data?.requires_subscription && typeof window !== 'undefined') {
      // Subscription/trial ended: take the teacher to the plans page to
      // subscribe and pay before using management features again.
      if (!window.location.pathname.startsWith('/pricing') && !window.location.pathname.startsWith('/checkout')) {
        window.location.href = '/pricing';
      }
      return Promise.reject(e);
    }
    if (e.response?.status === 401 && typeof window !== 'undefined') {
      clearStoredUser();
      try {
        const { useAuth } = require('@/store');
        useAuth.setState({ user: null, loading: false, initialized: true });
      } catch { /* */ }
    }
    return Promise.reject(e);
  },
);

/** استخراج رسالة الخطأ الموحّدة من استجابة الـ API (Laravel / axios) */
export function getApiErrorMessage(error: unknown, fallback = 'Something went wrong'): string {
  if (axios.isAxiosError(error)) {
    const err = error as AxiosError<{ message?: string | string[]; errors?: Record<string, string[]> }>;
    const data = err.response?.data;

    if (data?.errors) {
      const firstKey = Object.keys(data.errors)[0];
      if (firstKey) return data.errors[firstKey][0] ?? fallback;
    }

    if (data?.message) {
      if (Array.isArray(data.message)) return data.message[0] ?? fallback;
      return data.message;
    }

    if (err.code === 'ECONNABORTED' || err.code === 'ETIMEDOUT') return 'Request timed out';
    if (!err.response) return 'Network error — check your connection';
  }

  if (error instanceof Error && error.message) return error.message;

  return fallback;
}

export default api;
