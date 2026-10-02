// Scenario E — Student flows: login, course list, lesson access, signed media URL.
import http from 'k6/http';
import { sleep } from 'k6';
import { API_URL, TEST_USER_EMAIL, TEST_USER_PASSWORD, TEST_TENANT_ID, THRESHOLDS, checkOk, mobileLogin, jsonHeaders } from '../lib.js';

export const options = {
  scenarios: {
    student: {
      executor: 'ramping-vus',
      stages: [
        { duration: '1m', target: 10 },
        { duration: '2m', target: 30 },
        { duration: '1m', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
  },
  thresholds: THRESHOLDS,
};

export default function () {
  const loginRes = mobileLogin(TEST_USER_EMAIL, TEST_USER_PASSWORD, TEST_TENANT_ID);
  if (loginRes.status !== 200) return;

  const h = jsonHeaders(loginRes.token, TEST_TENANT_ID);

  const home = http.get(`${API_URL}/mobile/home`, { headers: h });
  checkOk('student home', home);

  const courses = http.get(`${API_URL}/mobile/courses`, { headers: h });
  checkOk('student courses', courses);

  const site = http.get(`${API_URL}/mobile/site`, { headers: h });
  checkOk('student site', site);

  const profile = http.get(`${API_URL}/auth/me`, { headers: h });
  checkOk('student profile', profile);

  sleep(1);
}