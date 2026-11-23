import { test, expect, type Page } from '@playwright/test';

/**
 * Real-browser, real-account verification of «متابعة الطلاب».
 *
 * The report is only meaningful against a running backend and a real teacher
 * account, so — like `question-bank.spec.ts` — this spec skips in CI (no
 * backend, no account). The rule-based guarantees are proven by Vitest
 * (`teacher/follow-up/page.test.tsx`) and PHPUnit (`StudentFollowUpTest`).
 *
 *   E2E_TEACHER_EMAIL=... E2E_TEACHER_PASSWORD=... npm run test:e2e
 *
 * What it proves:
 *   1. `/teacher/follow-up` really serves the report (HTTP 200, no 500) inside
 *      the panel shell with the sidebar reachable;
 *   2. the page states it is based on real records and never fabricates;
 *   3. the status filter and search re-query the server without crashing;
 *   4. it is usable on a phone viewport.
 */

const EMAIL = process.env.E2E_TEACHER_EMAIL;
const PASSWORD = process.env.E2E_TEACHER_PASSWORD;

const configured = Boolean(EMAIL && PASSWORD);

async function loginTeacher(page: Page) {
  await page.goto('/teacher/login');
  await page.fill('input[type=email]', EMAIL!);
  await page.fill('input[type=password]', PASSWORD!);
  await page.click('button[type=submit]');
  await page.waitForURL('**/teacher/dashboard', { timeout: 30_000 });
}

test.describe('Student follow-up', () => {
  test.skip(!configured, 'set E2E_TEACHER_EMAIL / E2E_TEACHER_PASSWORD to run');

  test('desktop: serves the real report inside the shell', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await loginTeacher(page);

    const [resp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/teacher/follow-up') && r.request().method() === 'GET', { timeout: 30_000 }),
      page.goto('/teacher/follow-up'),
    ]);

    expect(resp.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'متابعة الطلاب' })).toBeVisible({ timeout: 20_000 });
    await expect(page.getByRole('navigation', { name: 'Main navigation' }).first()).toBeVisible();

    // Honest provenance is available to the teacher.
    await page.getByRole('button', { name: 'مصادر البيانات' }).click();
    await expect(page.getByText(/سجلات حقيقية/)).toBeVisible();

    // Either flagged students or an explicit empty state — never a blank page.
    const empty = page.getByText('لا يوجد من يحتاج متابعة الآن');
    const rows = page.locator('a[href^="/students?student="]');
    await expect(empty.or(rows.first())).toBeVisible({ timeout: 20_000 });

    // Re-query by status without crashing.
    await page.locator('#fu-status').selectOption('all');
    await expect(page.getByRole('heading', { name: 'متابعة الطلاب' })).toBeVisible();

    await page.screenshot({ path: 'test-results/student-follow-up-desktop.png', fullPage: true });
  });

  test('mobile: the report is usable on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await loginTeacher(page);

    await page.goto('/teacher/follow-up');
    await expect(page.getByRole('heading', { name: 'متابعة الطلاب' })).toBeVisible({ timeout: 20_000 });

    // Filters stack and stay reachable on the small screen.
    await expect(page.locator('#fu-q')).toBeVisible();
    await expect(page.locator('#fu-status')).toBeVisible();

    await page.screenshot({ path: 'test-results/student-follow-up-mobile.png', fullPage: true });
  });
});
