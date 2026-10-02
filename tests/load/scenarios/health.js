// Scenario A — Health endpoints liveness/readiness.
import { check } from 'k6';
import http from 'k6/http';
import { BASE_URL, API_URL, THRESHOLDS, checkOk } from '../lib.js';

export const options = {
  scenarios: {
    health: {
      executor: 'constant-arrival-rate',
      rate: __ENV.RATE ? Number(__ENV.RATE) : 10,
      duration: __ENV.DURATION || '1m',
      preAllocatedVUs: __ENV.VUS ? Number(__ENV.VUS) : 10,
      maxVUs: __ENV.MAX_VUS ? Number(__ENV.MAX_VUS) : 100,
    },
  },
  thresholds: THRESHOLDS,
};

export default function () {
  const base = http.get(`${BASE_URL}/up`);
  checkOk('up', base);

  const live = http.get(`${API_URL}/health/live`);
  checkOk('health/live', live, { 'live status ok': (r) => r.json().status === 'ok' });

  const ready = http.get(`${API_URL}/health/ready`);
  const readyBody = ready.json();
  check('health/ready', {
    'ready status ok': () => ready.status === 200 && readyBody.status === 'ok',
    'db check ok': () => readyBody.checks?.database === 'ok',
    'redis check ok': () => readyBody.checks?.redis === 'ok',
    'filesystem check ok': () => readyBody.checks?.filesystem === 'ok',
    'queue check ok': () => readyBody.checks?.queue === 'ok',
  });
}