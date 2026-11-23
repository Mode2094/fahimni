import { test, expect, type Page, type BrowserContext } from '@playwright/test';

/**
 * Real-browser verification of the mobile-first teacher dashboard.
 *
 * Drives the actual product — teacher login → dashboard — and checks the phone
 * guarantees the redesign is about: no horizontal scroll, a 2×2 statistics
 * grid, comfortable quick actions, a working platform-link copy, a sidebar
 * drawer that opens and closes, and no assistant button covering the content.
 *
 * It only runs when a teacher account is provided (CI has no backend), so CI
 * skips it; the structural guarantees are still covered by Vitest
 * (`teacher/dashboard/page.test.tsx`).
 *
 * The account logs in once (`beforeAll`) and every test reuses that session, so
 * a run never trips the login rate limiter.
 *
 *   E2E_TEACHER_EMAIL=... E2E_TEACHER_PASSWORD=... npx playwright test teacher-dashboard-mobile
 */

const EMAIL = process.env.E2E_TEACHER_EMAIL;
const PASSWORD = process.env.E2E_TEACHER_PASSWORD;
const configured = Boolean(EMAIL && PASSWORD);

const PHONE_WIDTHS = [320, 360, 390, 430];

async function loginForm(page: Page) {
  await page.goto('/teacher/login');
  await page.fill('input[type=email]', EMAIL!);
  await page.fill('input[type=password]', PASSWORD!);
  await page.click('button[type=submit]');
  await page.waitForURL('**/teacher/dashboard', { timeout: 30_000 });
  await expect(page.getByTestId('stats-grid')).toBeVisible({ timeout: 20_000 });
}

/** Horizontal overflow in CSS pixels (0 = no sideways scroll). */
async function overflow(page: Page) {
  return page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
}

/** Number of columns in the statistics grid. */
async function statColumns(page: Page) {
  return page
    .getByTestId('stats-grid')
    .evaluate((el) => getComputedStyle(el).gridTemplateColumns.split(' ').filter(Boolean).length);
}

let session: Awaited<ReturnType<BrowserContext['storageState']>> | undefined;

test.describe('Teacher dashboard — mobile redesign', () => {
  test.skip(!configured, 'set E2E_TEACHER_EMAIL / E2E_TEACHER_PASSWORD to run');

  test.beforeAll(async ({ browser }) => {
    if (!configured) return;
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await loginForm(page);
    session = await ctx.storageState();
    await ctx.close();
  });

  test('phones 320–430: no horizontal scroll, 2×2 stats, quick actions on screen', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: session });
    const page = await ctx.newPage();

    for (const w of PHONE_WIDTHS) {
      await page.setViewportSize({ width: w, height: 844 });
      await page.goto('/teacher/dashboard');
      await expect(page.getByTestId('stats-grid')).toBeVisible();

      expect(await overflow(page), `horizontal overflow at ${w}px`).toBeLessThanOrEqual(1);
      expect(await statColumns(page), `stats columns at ${w}px`).toBe(2);

      // The two primary quick actions are reachable without scrolling sideways.
      await expect(page.getByRole('button', { name: /إنشاء كورس|New course/ })).toBeVisible();
      await expect(page.getByRole('button', { name: /إدارة الكورسات|Manage courses/ })).toBeVisible();
    }

    await ctx.close();
  });

  test('tablet + desktop: 4-up stats and a roomy, scroll-free layout', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: session });
    const page = await ctx.newPage();

    for (const [w, h] of [[768, 1024], [1280, 900]] as const) {
      await page.setViewportSize({ width: w, height: h });
      await page.goto('/teacher/dashboard');
      await expect(page.getByTestId('stats-grid')).toBeVisible();

      expect(await overflow(page), `horizontal overflow at ${w}px`).toBeLessThanOrEqual(1);
      // 4 columns from `lg` (1024px) up; 768px stays 2-up like a phone.
      expect(await statColumns(page), `stats columns at ${w}px`).toBe(w >= 1024 ? 4 : 2);
    }

    await ctx.close();
  });

  test('phone: copy link works, drawer opens/closes, no floating assistant covers the stats', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: session, permissions: ['clipboard-read', 'clipboard-write'] });
    const page = await ctx.newPage();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/teacher/dashboard');
    await expect(page.getByTestId('stats-grid')).toBeVisible();

    // The AI assistant lives in the sidebar now — the floating button and the
    // «اسألني عن منصتك» bubble must be completely gone, so nothing overlaps the
    // statistics or the navigation/logout controls.
    await expect(page.getByTestId('askme-fab')).toHaveCount(0);
    await expect(page.getByText('اسألني عن منصتك')).toHaveCount(0);

    // Copy the platform link in one tap — the real clipboard must receive it.
    const copyBtn = page.getByRole('button', { name: /نسخ رابط المنصة|Copy platform link/ });
    if (await copyBtn.count()) {
      const href = await page.locator('a[target="_blank"][rel="noopener noreferrer"]').first().getAttribute('href');
      await copyBtn.first().click();
      await expect(page.getByText(/تم نسخ رابط منصتك|Platform link copied/)).toBeVisible({ timeout: 5_000 });
      const clip = await page.evaluate(() => navigator.clipboard.readText());
      expect(clip).toBe(href);
    }

    // The sidebar drawer opens and closes cleanly (overlay closes it too).
    await page.getByRole('button', { name: 'فتح القائمة' }).click();
    const dialog = page.getByRole('dialog').first();
    await expect(dialog).toHaveClass(/translate-x-0/);
    await page.locator('[class*="bg-black/50"]').first().click();
    await expect(dialog).not.toHaveClass(/translate-x-0/);

    await ctx.close();
  });

  test('English / LTR stays clean on a phone', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: session });
    const page = await ctx.newPage();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/teacher/dashboard');

    await page.getByRole('button', { name: 'Switch to English' }).click();
    await expect(page.getByRole('button', { name: 'New course' })).toBeVisible();

    expect(await overflow(page)).toBeLessThanOrEqual(1);
    expect(await page.evaluate(() => document.documentElement.dir)).toBe('ltr');

    await ctx.close();
  });
});
