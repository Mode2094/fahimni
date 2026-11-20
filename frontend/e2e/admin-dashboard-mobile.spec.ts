import { test, expect, type Page, type BrowserContext } from '@playwright/test';

/**
 * Real-browser verification of the mobile-first Super-Admin dashboard.
 *
 * Drives the actual product — admin login → dashboard — and checks the phone
 * guarantees the redesign is about: no horizontal scroll, a 2×2 statistics
 * grid, one (not three) revenue card, quick actions that reach real pages,
 * numbers that match the API and are never clipped, and a sidebar drawer that
 * opens/closes without pushing the page sideways.
 *
 * It only runs when an admin account is provided (CI has no backend), so CI
 * skips it; the structural guarantees are still covered by Vitest
 * (`admin/dashboard/page.test.tsx`).
 *
 * The account logs in once (`beforeAll`) and every test reuses that session, so
 * a run never trips the login rate limiter.
 *
 *   E2E_ADMIN_EMAIL=... E2E_ADMIN_PASSWORD=... npx playwright test admin-dashboard-mobile
 */

const EMAIL = process.env.E2E_ADMIN_EMAIL;
const PASSWORD = process.env.E2E_ADMIN_PASSWORD;
const configured = Boolean(EMAIL && PASSWORD);

const PHONE_WIDTHS = [320, 360, 390, 430];

async function loginForm(page: Page) {
  await page.goto('/admin/login');
  await page.fill('input[type=email]', EMAIL!);
  await page.fill('input[type=password]', PASSWORD!);
  await page.click('button[type=submit]');
  await page.waitForURL('**/admin/dashboard', { timeout: 30_000 });
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

/** Is any element wider than its own box (i.e. clipped)? */
async function isClipped(locator: ReturnType<Page['getByTestId']>) {
  return locator.evaluate((el) => el.scrollWidth > el.clientWidth + 1);
}

let session: Awaited<ReturnType<BrowserContext['storageState']>> | undefined;

test.describe('Admin dashboard — mobile redesign', () => {
  test.skip(!configured, 'set E2E_ADMIN_EMAIL / E2E_ADMIN_PASSWORD to run');

  test.beforeAll(async ({ browser }) => {
    if (!configured) return;
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await loginForm(page);
    session = await ctx.storageState();
    await ctx.close();
  });

  test('phones 320–430: no horizontal scroll, 2×2 stats, revenue + quick actions on screen', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: session });
    const page = await ctx.newPage();

    for (const w of PHONE_WIDTHS) {
      await page.setViewportSize({ width: w, height: 844 });
      await page.goto('/admin/dashboard');
      await expect(page.getByTestId('stats-grid')).toBeVisible();

      expect(await overflow(page), `horizontal overflow at ${w}px`).toBeLessThanOrEqual(1);
      expect(await statColumns(page), `stats columns at ${w}px`).toBe(2);

      // The essentials are on the first screen: revenue and quick actions.
      await expect(page.getByTestId('revenue-card')).toBeVisible();
      await expect(page.getByTestId('quick-actions')).toBeVisible();
    }

    await ctx.close();
  });

  test('tablet + desktop: 4-up stats from 1024px, roomy and scroll-free', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: session });
    const page = await ctx.newPage();

    for (const [w, h] of [[768, 1024], [1024, 900], [1366, 900]] as const) {
      await page.setViewportSize({ width: w, height: h });
      await page.goto('/admin/dashboard');
      await expect(page.getByTestId('stats-grid')).toBeVisible();

      expect(await overflow(page), `horizontal overflow at ${w}px`).toBeLessThanOrEqual(1);
      // 4 columns from `lg` (1024px) up; 768px stays 2-up like a phone.
      expect(await statColumns(page), `stats columns at ${w}px`).toBe(w >= 1024 ? 4 : 2);
    }

    await ctx.close();
  });

  test('revenue appears once, is never clipped, and every tile matches the API', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: session });
    const page = await ctx.newPage();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/admin/dashboard');
    await expect(page.getByTestId('stats-grid')).toBeVisible();

    // One revenue card and one label — the three duplicated totals are gone.
    await expect(page.getByTestId('revenue-card')).toHaveCount(1);
    await expect(page.getByTestId('revenue-value')).toHaveCount(1);
    await expect(page.getByText('إجمالي إيرادات المنصة')).toHaveCount(1);
    await expect(page.getByTestId('revenue-value')).toHaveText(/[\d.,]+\s*[A-Za-z]{3}/);

    // Numbers and currency are fully visible, never cut off.
    expect(await isClipped(page.getByTestId('revenue-value'))).toBe(false);
    for (let i = 0; i < 4; i++) {
      expect(await isClipped(page.getByTestId(`stat-${i}`).locator('p').first())).toBe(false);
    }

    // The rendered figures are the real API figures, not placeholders.
    const api = await page.evaluate(async () => {
      const tid = localStorage.getItem('tenant_id') || '';
      const r = await fetch('/api/v1/dashboard', { headers: { 'X-Tenant-Id': tid, Accept: 'application/json' } });
      return r.json();
    });
    const tile = async (i: number) =>
      Number((await page.getByTestId(`stat-${i}`).locator('p').first().innerText()).trim());
    expect(await tile(0)).toBe(api.total_tenants);
    expect(await tile(1)).toBe(api.total_students);
    expect(await tile(2)).toBe(api.total_courses);
    expect(await tile(3)).toBe(api.active_subscriptions);

    await ctx.close();
  });

  test('quick actions point at real pages and navigate', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: session });
    const page = await ctx.newPage();
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto('/admin/dashboard');
    await expect(page.getByTestId('quick-actions')).toBeVisible();

    const hrefs = await page
      .getByTestId('quick-actions')
      .locator('a')
      .evaluateAll((els) => els.map((e) => (e as HTMLAnchorElement).getAttribute('href')));
    expect(hrefs).toEqual([
      '/admin/teachers',
      '/admin/plans',
      '/admin/tickets',
      '/admin/payments',
      '/admin/teacher-notifications',
    ]);

    // No dead links: every destination resolves.
    for (const href of hrefs) {
      const res = await page.request.get(href!);
      expect(res.status(), `${href} should resolve`).toBeLessThan(400);
    }

    // A real click lands on the right page.
    await page.getByTestId('quick-actions').locator('a[href="/admin/teachers"]').first().click();
    await page.waitForURL('**/admin/teachers', { timeout: 20_000 });

    await ctx.close();
  });

  test('phone: drawer opens/closes without pushing the page sideways', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: session });
    const page = await ctx.newPage();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/admin/dashboard');
    await expect(page.getByTestId('stats-grid')).toBeVisible();

    await page.getByRole('button', { name: 'فتح القائمة' }).click();
    const dialog = page.getByRole('dialog').first();
    await expect(dialog).toHaveClass(/translate-x-0/);
    expect(await overflow(page)).toBeLessThanOrEqual(1);

    await page.locator('[class*="bg-black/50"]').first().click();
    await expect(dialog).not.toHaveClass(/translate-x-0/);

    await ctx.close();
  });

  test('English / LTR stays clean on a phone', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: session });
    const page = await ctx.newPage();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/admin/dashboard');
    await expect(page.getByTestId('stats-grid')).toBeVisible();

    await page.getByRole('button', { name: 'Switch to English' }).click();
    await expect(page.getByRole('main').getByRole('heading', { name: 'Admin Dashboard' })).toBeVisible();

    expect(await overflow(page)).toBeLessThanOrEqual(1);
    expect(await page.evaluate(() => document.documentElement.dir)).toBe('ltr');

    await ctx.close();
  });
});
