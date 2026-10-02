// Scenario I — Database behaviour under read-heavy load.
// Runs representative read queries through the API while observing DB health
// through the admin monitoring summary (connections, slow queries, locks).
// Requires an admin token to read the DB metrics — set K6_ADMIN_EMAIL/PASSWORD.
import http from 'k6/http';
import { sleep } from 'k6';
import { API_URL, TEST_USER_EMAIL, TEST_USER_PASSWORD, TEST_TENANT_ID, THRESHOLDS, checkOk, login, jsonHeaders } from '../lib.js';

export const options = {
  scenarios: {
    database: {
      executor: 'ramping-vus',
      stages: [
        { duration: '1m', target: 10 },
        { duration: '2m', target: 40 },
        { duration: '1m', target: 0 },
      ],
    },
  },
  thresholds: THRESHOLDS,
};

const ADMIN_EMAIL = __ENV.K6_ADMIN_EMAIL || __ENV.TEST_USER_EMAIL;
const ADMIN_PASSWORD = __ENV.K6_ADMIN_PASSWORD || __ENV.TEST_USER_PASSWORD;

export default function () {
  const loginRes = login(TEST_USER_EMAIL, TEST_USER_PASSWORD, TEST_TENANT_ID);
  if (loginRes.status !== 200) return;

  const h = jsonHeaders(loginRes.token, TEST_TENANT_ID);

  const search = http.get(`${API_URL}/courses?search=علم`, { headers: h });
  checkOk('db search', search);

  const lessons = http.get(`${API_URL}/lessons?page=1`, { headers: h });
  checkOk('db lessons', lessons);

  const exam = http.get(`${API_URL}/exam-questions?page=1`, { headers: h });
  checkOk('db exam questions', exam);

  const adminLogin = login(ADMIN_EMAIL, ADMIN_PASSWORD);
  if (adminLogin.status === 200 && adminLogin.token) {
    const dbMetrics = http.get(`${API_URL}/admin/monitoring/summary`, { headers: jsonHeaders(adminLogin.token) });
    checkOk('db metrics', dbMetrics, {
      'connections present': () => dbMetrics.json().database?.connections_total !== undefined,
      'slow queries present': () => dbMetrics.json().database?.slow_queries_running !== undefined,
    });
  }

  sleep(1);
}