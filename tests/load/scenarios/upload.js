// Scenario H — Small/medium file uploads through the real upload endpoint.
// Uses the authenticated upload endpoint with a tiny in-memory payload.
// Intentionally avoids multi-GB video uploads (test those separately after
// the small upload path is proven).
import http from 'k6/http';
import { sleep } from 'k6';
import { API_URL, TEST_USER_EMAIL, TEST_USER_PASSWORD, TEST_TENANT_ID, THRESHOLDS, checkOk, login, jsonHeaders, smallPayload } from '../lib.js';

export const options = {
  scenarios: {
    uploads: {
      executor: 'constant-arrival-rate',
      rate: __ENV.RATE ? Number(__ENV.RATE) : 5,
      duration: __ENV.DURATION || '2m',
      preAllocatedVUs: 5,
      maxVUs: 20,
    },
  },
  thresholds: THRESHOLDS,
};

export default function () {
  const loginRes = login(TEST_USER_EMAIL, TEST_USER_PASSWORD, TEST_TENANT_ID);
  if (loginRes.status !== 200) return;

  const h = jsonHeaders(loginRes.token, TEST_TENANT_ID);

  const payload = smallPayload(1024);
  const res = http.post(
    `${API_URL}/upload`,
    { file: http.file(payload, 'test.txt', 'text/plain') },
    { headers: h },
  );

  checkOk('small upload', res, {
    'upload not 5xx': (r) => r.status < 500,
  });

  sleep(1);
}