// Scenario J — Capacity discovery: staged concurrent users.
// Runs a ramp through 10 → 25 → 50 → 100 → 250 → 500 → 1000 concurrent users.
// The point is to FIND the real breaking point — NOT to prove a number.
// Thresholds here are informational (abortOnFail=false): the run completes and
// the report tells you where it degraded.
import http from 'k6/http';
import { check, sleep } from 'k6';
import { API_URL, TEST_USER_EMAIL, TEST_USER_PASSWORD, TEST_TENANT_ID, checkOk, login, jsonHeaders } from '../lib.js';

const VUS = Number(__ENV.VUS || 1000);
const STAGE_MIN = Number(__ENV.STAGE_MIN || 0.5);

export const options = {
  scenarios: {
    capacity: {
      executor: 'ramping-vus',
      stages: [
        { duration: `${STAGE_MIN * 1}m`, target: 10 },
        { duration: `${STAGE_MIN * 1}m`, target: 25 },
        { duration: `${STAGE_MIN * 1}m`, target: 50 },
        { duration: `${STAGE_MIN * 1}m`, target: 100 },
        ...(VUS >= 250 ? [{ duration: `${STAGE_MIN * 1}m`, target: 250 }] : []),
        ...(VUS >= 500 ? [{ duration: `${STAGE_MIN * 1}m`, target: 500 }] : []),
        ...(VUS >= 1000 ? [{ duration: `${STAGE_MIN * 1}m`, target: 1000 }] : []),
        { duration: '30s', target: 0 },
      ],
      gracefulRampDown: '1m',
    },
  },
  thresholds: {
    http_req_failed: [{ threshold: 'rate<0.01', abortOnFail: false }],
    http_req_duration: [{ threshold: 'p(95)<1000', abortOnFail: false }],
  },
};

export default function () {
  const loginRes = login(TEST_USER_EMAIL, TEST_USER_PASSWORD, TEST_TENANT_ID);
  if (loginRes.status !== 200) return;

  const h = jsonHeaders(loginRes.token, TEST_TENANT_ID);

  const me = http.get(`${API_URL}/auth/me`, { headers: h });
  checkOk('me', me);

  const courses = http.get(`${API_URL}/courses?page=1`, { headers: h });
  checkOk('courses', courses);

  const lessons = http.get(`${API_URL}/lessons?page=1`, { headers: h });
  checkOk('lessons', lessons);

  sleep(1);
}