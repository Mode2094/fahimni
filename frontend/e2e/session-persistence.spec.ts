import { test, expect, type Page, type Route } from '@playwright/test';

/**
 * Real-browser proof that the PWA keeps a returning user signed in, and never
 * turns a transient outage into a logout.
 *
 * These specs run against the production `next start` build with the BFF
 * session endpoints stubbed at the network layer, so they exercise the actual
 * client state machine (boot validation → shell routing → reload) in Chromium
 * without needing the Laravel backend. They complement — and never replace —
 * on-device testing against the real server.
 *
 *   npm run build
 *   npx playwright test session-persistence
 */

const STUDENT = {
  id: 'stu-1',
  name: 'طالب تجريبي',
  email: 'student@test.local',
  is_super_admin: false,
  roles: [] as string[],
  permissions: [] as string[],
};

async function stubSession(page: Page, handler: (route: Route) => Promise<void> | void) {
  await page.route('**/api/session/me', (route) => handler(route));
}

test.describe('session persistence (real browser)', () => {
  test('restores an authenticated session and survives a full reload', async ({ page }) => {
    await stubSession(page, (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ user: STUDENT }) }),
    );

    await page.goto('/my-learning');
    await expect(page).toHaveURL(/\/my-learning$/);
    await expect
      .poll(() => page.evaluate(() => localStorage.getItem('lms_authed')))
      .toBe('1');

    // A brand-new document (PWA relaunch / phone restart) must not bounce to
    // the login screen: the HttpOnly cookie is validated again and the user
    // stays put.
    await page.reload();
    await expect(page).toHaveURL(/\/my-learning$/);
    expect(await page.evaluate(() => localStorage.getItem('lms_authed'))).toBe('1');
    expect(JSON.parse((await page.evaluate(() => localStorage.getItem('user'))) || '{}').id).toBe('stu-1');
  });

  test('a 502 session check shows the recoverable offline state, never a logout', async ({ page }) => {
    await stubSession(page, (route) =>
      route.fulfill({ status: 502, contentType: 'application/json', body: JSON.stringify({ message: 'Bad Gateway' }) }),
    );

    await page.goto('/my-learning');

    // The user is not sent to the login page, and no session is destroyed.
    await expect(page).not.toHaveURL(/\/student\/login/);
    await expect(page.getByRole('status')).toBeVisible();
    await expect(page.getByRole('button', { name: /إعادة المحاولة|Retry/ }).first()).toBeVisible();
    expect(await page.evaluate(() => localStorage.getItem('lms_authed'))).toBeNull();
  });

  test('a real 401 is the only thing that redirects to login', async ({ page }) => {
    await stubSession(page, (route) =>
      route.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ message: 'Unauthenticated.' }) }),
    );

    await page.goto('/my-learning');

    await expect(page).toHaveURL(/\/student\/login/);
  });

  test('the PWA entry point sends a signed-in user to their dashboard', async ({ page }) => {
    await stubSession(page, (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ user: STUDENT }) }),
    );

    await page.goto('/app');

    await expect(page).toHaveURL(/\/my-learning$/);
  });
});
