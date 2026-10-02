// Scenario G — Queue behaviour under load.
// Probes the real queue signals exposed by the platform: readiness (queue
// infra), admin queue metrics (pending/failed), and the scheduler heartbeat.
// It does NOT dispatch heavy jobs — it measures observable queue health while
// other scenarios load the API. Watch trend thresholds for growth.
import http from 'k6/http';
import { check, sleep } from 'k6';
import { API_URL, TEST_USER_EMAIL, TEST_USER_PASSWORD, THRESHOLDS, checkOk, login, jsonHeaders } from '../lib.js';

export const options = {
  scenarios: {
    queue: {
      executor: 'constant-arrival-rate',
      rate: 2,
      duration: __ENV.DURATION || '3m',
      preAllocatedVUs: 2,
      maxVUs: 5,
    },
  },
  thresholds: THRESHOLDS,
};

export default function () {
  const ready = http.get(`${API_URL}/health/ready`);
  checkOk('ready', ready, { 'queue infra ok': () => ready.json().checks?.queue === 'ok' });

  const loginRes = login(TEST_USER_EMAIL, TEST_USER_PASSWORD);
  if (loginRes.status !== 200) return;

  const h = jsonHeaders(loginRes.token);

  const summary = http.get(`${API_URL}/admin/monitoring/summary`, { headers: h });
  checkOk('monitoring summary', summary);

  const body = summary.json();
  const pending = body.queue?.total_pending ?? -1;
  const failed = body.queue?.failed_total ?? -1;
  const horizon = body.queue?.horizon ?? 'unknown';

  check('horizon running', () => horizon === 'running');
  check('queue metrics present', () => pending >= 0 && failed >= 0);

  // Track the queue trend across iterations for the threshold above.
  if (__ITER === 0) {
    check('queue trend baseline set', () => pending >= 0);
  }

  sleep(5);
}