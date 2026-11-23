import { test, expect, type Page, type BrowserContext } from '@playwright/test';

/**
 * Real-browser verification of the teacher setup guide on a phone.
 *
 * Logs in as a teacher and, when the guide is still showing (a fresh account),
 * drives the whole loop: the progress indicator, the per-step action links, the
 * hide → restore round trip, and the mobile guarantee (no horizontal scroll).
 *
 * It only runs when a teacher account is provided (CI has no backend), so CI
 * skips it; the structural guarantees are covered by Vitest
 * (`components/teacher/setup-guide.test.tsx`).
 *
 *   E2E_TEACHER_EMAIL=... E2E_TEACHER_PASSWORD=... npx playwright test teacher-setup-guide
 */

const EMAIL = process.env.E2E_TEACHER_EMAIL;
const PASSWORD = process.env.E2E_TEACHER_PASSWORD;
const configured = Boolean(EMAIL && PASSWORD);

async function login(page: Page) {
  await page.goto('/teacher/login');
  await page.fill('input[type=email]', EMAIL!);
  await page.fill('input[type=password]', PASSWORD!);
  await page.click('button[type=submit]');
  await page.waitForURL('**/teacher/dashboard', { timeout: 30_000 });
}

let session: Awaited<ReturnType<BrowserContext['storageState']>> | undefined;

test.describe('Teacher setup guide', () => {
  test.skip(!configured, 'set E2E_TEACHER_EMAIL / E2E_TEACHER_PASSWORD to run');

  test.beforeAll(async ({ browser }) => {
    if (!configured) return;
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await login(page);
    session = await ctx.storageState();
    await ctx.close();
  });

  test('phone: the guide renders, links to real pages, and hides/restores cleanly', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: session });
    const page = await ctx.newPage();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/teacher/dashboard');

    await expect(page.getByTestId('stats-grid')).toBeVisible({ timeout: 20_000 });

    // No sideways scroll with the guide on the page.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow, 'horizontal overflow on the dashboard').toBeLessThanOrEqual(1);

    const guide = page.getByTestId('setup-guide');
    const restore = page.getByTestId('setup-guide-restore');

    if (await guide.count()) {
      // Progress indicator is real (n / m).
      await expect(page.getByTestId('setup-guide-progress')).toBeVisible();

      // Every rendered step links to a real in-app page.
      const hrefs = await page.locator('[data-testid^="setup-step-"]').evaluateAll(
        (els) => els.map((el) => el.getAttribute('href') || ''),
      );
      expect(hrefs.length).toBeGreaterThan(0);
      expect(hrefs.every((h) => h.startsWith('/'))).toBeTruthy();

      // Hide → the restore affordance appears and persists across a reload.
      await page.getByText(/إخفاء الدليل|Hide guide/).click();
      await expect(restore).toBeVisible({ timeout: 10_000 });

      await page.reload();
      await expect(restore).toBeVisible({ timeout: 20_000 });
      await expect(guide).toHaveCount(0);

      // Restore → the guide comes back.
      await page.getByText(/استعادة الدليل|Restore guide/).click();
      await expect(guide).toBeVisible({ timeout: 10_000 });
    }

    await ctx.close();
  });
});
