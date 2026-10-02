// Scenario B — Public (unauthenticated) API endpoints that actually exist.
import http from 'k6/http';
import { API_URL, PLATFORM_SLUG, THRESHOLDS, checkOk } from '../lib.js';

export const options = {
  scenarios: {
    public_api: {
      executor: 'constant-arrival-rate',
      rate: __ENV.RATE ? Number(__ENV.RATE) : 20,
      duration: __ENV.DURATION || '2m',
      preAllocatedVUs: __ENV.VUS ? Number(__ENV.VUS) : 20,
      maxVUs: __ENV.MAX_VUS ? Number(__ENV.MAX_VUS) : 100,
    },
  },
  thresholds: THRESHOLDS,
};

export default function () {
  const endpoints = [
    `${API_URL}/platform-settings`,
    `${API_URL}/payment-gateways`,
    `${API_URL}/public/plans`,
    `${API_URL}/public/${PLATFORM_SLUG}`,
    `${API_URL}/public/${PLATFORM_SLUG}/courses`,
  ];

  for (const url of endpoints) {
    const res = http.get(url, { headers: { Accept: 'application/json' } });
    checkOk('public endpoint', res, { 'endpoint not 5xx': (r) => r.status < 500 });
  }
}