// Scenario F — Tenant isolation under concurrent load.
// FAILS the test on ANY cross-tenant data leak: user A must never see tenant B's data.
import http from 'k6/http';
import { check, sleep } from 'k6';
import { API_URL, TEST_USER_EMAIL, TEST_USER_PASSWORD, TEST_TENANT_ID, THRESHOLDS, checkOk, jsonHeaders } from '../lib.js';

export const options = {
  scenarios: {
    isolation: {
      executor: 'ramping-vus',
      stages: [
        { duration: '1m', target: 10 },
        { duration: '1m', target: 25 },
        { duration: '30s', target: 0 },
      ],
    },
  },
  thresholds: THRESHOLDS,
};

const OTHER_TENANT_ID = __ENV.OTHER_TENANT_ID || '00000000-0000-0000-0000-000000000000'; // impossible tenant

export default function () {
  const loginRes = login(TEST_USER_EMAIL, TEST_USER_PASSWORD, TEST_TENANT_ID);
  if (loginRes.status !== 200) return;

  const h = jsonHeaders(loginRes.token, TEST_TENANT_ID);

  const own = http.get(`${API_URL}/courses?page=1`, { headers: h });
  checkOk('own tenant courses', own);

  // Attempt to read another tenant's course list by spoofing the header.
  const spoofed = http.get(`${API_URL}/courses?page=1`, { headers: jsonHeaders(loginRes.token, OTHER_TENANT_ID) });
  check('NO cross-tenant data leak', () => spoofed.status === 403 || spoofed.status === 422 || spoofed.status === 401, {
    'spoofed request denied': () => spoofed.status >= 400,
    'no tenant data returned': () => !String(spoofed.body).includes('"courses"') || spoofed.status >= 400,
  });

  // The API must never return tenant B data with the user's own header.
  const body = own.json();
  const leaked = body.courses?.some((c) => c.tenant_id && c.tenant_id !== TEST_TENANT_ID);

  check('own tenant payload contains no foreign tenant_id', () => !leaked, {
    'foreign tenant_id absent': () => !leaked,
  });

  sleep(1);
}