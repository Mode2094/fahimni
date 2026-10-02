// Scenario D — Teacher flows: dashboard, courses, students, lessons, analytics.
import http from 'k6/http';
import { sleep } from 'k6';
import { API_URL, TEST_USER_EMAIL, TEST_USER_PASSWORD, TEST_TENANT_ID, THRESHOLDS, checkOk, login, jsonHeaders } from '../lib.js';

export const options = {
  scenarios: {
    teacher: {
      executor: 'ramping-vus',
      stages: [
        { duration: '1m', target: 5 },
        { duration: '2m', target: 15 },
        { duration: '1m', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
  },
  thresholds: THRESHOLDS,
};

export default function () {
  const loginRes = login(TEST_USER_EMAIL, TEST_USER_PASSWORD, TEST_TENANT_ID);
  if (loginRes.status !== 200) return;

  const h = jsonHeaders(loginRes.token, TEST_TENANT_ID);

  const dashboard = http.get(`${API_URL}/teacher/dashboard`, { headers: h });
  checkOk('teacher dashboard', dashboard);

  const courses = http.get(`${API_URL}/courses?page=1`, { headers: h });
  checkOk('teacher courses', courses);

  const students = http.get(`${API_URL}/teacher/students`, { headers: h });
  checkOk('teacher students', students);

  const lessons = http.get(`${API_URL}/lessons?page=1`, { headers: h });
  checkOk('teacher lessons', lessons);

  const analytics = http.get(`${API_URL}/teacher/analytics`, { headers: h });
  checkOk('teacher analytics', analytics);

  sleep(1);
}