// Scenario C — Authenticated user flow: login → me → dashboard → courses → search.
import http from 'k6/http';
import { sleep } from 'k6';
import { API_URL, TEST_USER_EMAIL, TEST_USER_PASSWORD, THRESHOLDS, checkOk, login, jsonHeaders } from '../lib.js';

export const options = {
  scenarios: {
    auth_user: {
      executor: 'ramping-vus',
      stages: [
        { duration: '1m', target: 10 },
        { duration: '2m', target: 25 },
        { duration: '1m', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
  },
  thresholds: THRESHOLDS,
};

export default function () {
  const loginRes = login(TEST_USER_EMAIL, TEST_USER_PASSWORD);

  if (loginRes.status !== 200) {
    return; // login failure is counted by the error-rate threshold
  }

  const token = loginRes.token;
  const h = jsonHeaders(token);

  const me = http.get(`${API_URL}/auth/me`, { headers: h });
  checkOk('me', me);

  const dashboard = http.get(`${API_URL}/dashboard`, { headers: h });
  checkOk('dashboard', dashboard);

  const courses = http.get(`${API_URL}/courses?page=1`, { headers: h });
  checkOk('courses', courses);

  const search = http.get(`${API_URL}/courses?search=ا`, { headers: h });
  checkOk('search', search);

  sleep(1);
}