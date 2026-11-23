import { defineConfig, devices } from '@playwright/test';

/**
 * Real-browser tests for the interactive-HTML question sandbox.
 *
 * The sandbox is the one part of this feature that CANNOT be proven by
 * TypeScript or a unit test: it depends on the browser actually enforcing the
 * CSP, the `sandbox` iframe attribute, opaque origins and `postMessage`. These
 * specs load the real harness document (served by `next start`) in Chromium and
 * drive the whole protocol.
 *
 *   1. build:  npm run build
 *   2. test:   npm run test:e2e
 */
const PORT = Number(process.env.E2E_PORT || 4319);
const BASE_URL = `http://127.0.0.1:${PORT}`;

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  timeout: 45_000,
  expect: { timeout: 10_000 },
  reporter: [['list']],
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: `npx next start -p ${PORT}`,
    url: `${BASE_URL}/embed/question`,
    reuseExistingServer: true,
    timeout: 120_000,
  },
});
