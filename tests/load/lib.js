/**
 * Shared helpers for SHAHEAD LMS k6 scenarios.
 *
 * Env contract (all optional, overridable):
 *   BASE_URL        http://nginx:80        (inside docker network) or https://your-vps
 *   API_URL         http://nginx:80/api/v1  or https://api.yourdomain/api/v1
 *   TEST_USER_EMAIL / TEST_USER_PASSWORD   pre-seeded test account
 *   TEST_TENANT_ID  tenant id for tenant-scoped calls
 *   PLATFORM_SLUG   public site slug for public scenarios
 */
import http from 'k6/http';
import { check } from 'k6';

export const BASE_URL = __ENV.BASE_URL || 'http://nginx:80';
export const API_URL = __ENV.API_URL || 'http://nginx:80/api/v1';
export const PLATFORM_SLUG = __ENV.PLATFORM_SLUG || 'demo';
export const TEST_USER_EMAIL = __ENV.TEST_USER_EMAIL || '';
export const TEST_USER_PASSWORD = __ENV.TEST_USER_PASSWORD || '';
export const TEST_TENANT_ID = __ENV.TEST_TENANT_ID || '';

export const THRESHOLDS = {
  // Safe capacity defaults — adjust from real results, never assume.
  errorRate: ['rate<0.01'], // < 1% errors
  p95: ['p(95)<500'], // < 500ms
  p99: ['p(99)<1000'],
  httpReqFailed: ['rate<0.01'],
};

export function jsonHeaders(token, tenantId) {
  const h = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  };
  if (token) h['Authorization'] = `Bearer ${token}`;
  if (tenantId) h['X-Tenant-Id'] = tenantId;
  return h;
}

export function checkOk(name, res, extraChecks) {
  return check(res, {
    [`${name} status 2xx`]: (r) => r.status >= 200 && r.status < 300,
    ...(extraChecks || {}),
  });
}

export function login(email, password, tenantId) {
  const res = http.post(
    `${API_URL}/auth/login`,
    JSON.stringify({ email, password }),
    { headers: jsonHeaders() },
  );
  const ok = res.status === 200;
  let token = null;
  let user = null;
  if (ok) {
    try {
      const body = res.json();
      token = body.token || body.data?.token || null;
      user = body.user || body.data?.user || null;
    } catch (e) {
      /* ignore */
    }
  }
  return { ok, status: res.status, token, user, body: ok ? res.json() : null };
}

export function mobileLogin(email, password, tenantId) {
  const res = http.post(
    `${API_URL}/mobile/auth/login`,
    JSON.stringify({ email, password }),
    { headers: jsonHeaders() },
  );
  const ok = res.status === 200;
  let token = null;
  if (ok) {
    try {
      const body = res.json();
      token = body.token || body.data?.token || null;
    } catch (e) {
      /* ignore */
    }
  }
  return { ok, status: res.status, token, body: ok ? res.json() : null };
}

/** Generate a small deterministic payload for upload tests (no real files). */
export function smallPayload(bytes = 1024) {
  return 'x'.repeat(bytes);
}