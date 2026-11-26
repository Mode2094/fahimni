import { test, expect } from '@playwright/test';

/**
 * Real-browser regression for a teacher's CUSTOM DOMAIN.
 *
 * A custom domain serves the teacher's public site from the host ROOT — there
 * is no `/t/{slug}` prefix and the platform chrome must never appear. These
 * guarantees are exactly the ones that broke (a blank `/courses`, a 404 on
 * `/register/student`, the platform login identity) and cannot be proven by
 * unit tests, so they run against the live domain.
 *
 *   E2E_CUSTOM_DOMAIN=www.maryammath.com \
 *   E2E_TENANT_NAME="مريم ابراهيم القطاونه" \
 *   npx playwright test custom-domain
 *
 * Runs only when E2E_CUSTOM_DOMAIN is set, because it needs the deployed edge
 * (DNS + TLS + the Cloudflare-for-SaaS hostname) — CI has none of those.
 */

const DOMAIN = process.env.E2E_CUSTOM_DOMAIN;
const TENANT_NAME = process.env.E2E_TENANT_NAME || '';
const configured = Boolean(DOMAIN);
const origin = DOMAIN ? `https://${DOMAIN}` : '';

test.describe('Teacher custom domain — public site', () => {
  test.skip(!configured, 'set E2E_CUSTOM_DOMAIN to run');

  test('home serves the teacher landing (not the platform home)', async ({ page }) => {
    const res = await page.goto(`${origin}/`);
    expect(res?.status()).toBe(200);
    await expect(page.getByTestId('storefront-header')).toBeVisible();
    if (TENANT_NAME) {
      await expect(page.locator('body')).toContainText(TENANT_NAME);
    }
  });

  test('/courses renders the catalogue instead of a blank auth gate', async ({ page }) => {
    const res = await page.goto(`${origin}/courses`);
    expect(res?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: /جميع الكورسات/ })).toBeVisible();
    // The real defect: the page mounted but rendered nothing (innerText 0).
    expect((await page.locator('body').innerText()).trim().length).toBeGreaterThan(20);
  });

  test('/register/student does not 404 — it serves the tenant sign-up', async ({ page }) => {
    const res = await page.goto(`${origin}/register/student`);
    expect(res?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: /سجل كطالب|تسجيل/ })).toBeVisible();
  });

  test('the student login keeps the teacher identity', async ({ page }) => {
    const res = await page.goto(`${origin}/student/login`);
    expect(res?.status()).toBe(200);
    await expect(page.locator('#email')).toBeVisible();
    if (TENANT_NAME) {
      await expect(page.locator('body')).toContainText(TENANT_NAME);
    }
  });

  test('public links never leak the internal /t/{slug} prefix', async ({ page }) => {
    await page.goto(`${origin}/courses`);
    const hrefs = await page
      .locator('a[href]')
      .evaluateAll((els) => els.map((el) => el.getAttribute('href') || ''));
    for (const href of hrefs) {
      expect(href, `link leaked /t/{slug}: ${href}`).not.toMatch(/^\/t\//);
    }
  });
});
