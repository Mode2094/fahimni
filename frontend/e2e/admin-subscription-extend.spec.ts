import { test, expect, type Page, type BrowserContext } from '@playwright/test';

/**
 * Super Admin subscription extension, end-to-end through the real UI:
 * the admin opens a teacher, extends access by N days with a confirmation,
 * then the SAME teacher account logs in and is NOT sent to /pricing — the
 * workspace is active immediately, with no payment step.
 *
 * Skipped unless accounts are provided (CI has no backend). It MUTATES the
 * selected teacher's access, so point it at a dedicated test tenant:
 *
 *   E2E_ADMIN_EMAIL=... E2E_ADMIN_PASSWORD=... \
 *   E2E_TEACHER_EMAIL=... E2E_TEACHER_PASSWORD=... \
 *   E2E_EXTEND_DAYS=30 \
 *   npx playwright test admin-subscription-extend
 */

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL;
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD;
const TEACHER_EMAIL = process.env.E2E_TEACHER_EMAIL;
const TEACHER_PASSWORD = process.env.E2E_TEACHER_PASSWORD;
const DAYS = Number(process.env.E2E_EXTEND_DAYS || 30);

const configured = Boolean(ADMIN_EMAIL && ADMIN_PASSWORD && TEACHER_EMAIL && TEACHER_PASSWORD);

async function login(page: Page, email: string, password: string, landing: 'admin' | 'teacher') {
  await page.goto(landing === 'admin' ? '/admin/login' : '/teacher/login');
  await page.fill('input[type=email]', email);
  await page.fill('input[type=password]', password);
  await page.click('button[type=submit]');
  await page.waitForURL(
    landing === 'admin' ? '**/admin/dashboard' : '**/teacher/dashboard',
    { timeout: 30_000 },
  );
}

let admin: Awaited<ReturnType<BrowserContext['storageState']>> | undefined;
let teacher: Awaited<ReturnType<BrowserContext['storageState']>> | undefined;

test.describe('Super Admin subscription extension → immediate teacher access', () => {
  test.skip(!configured, 'set E2E_ADMIN_* and E2E_TEACHER_* to run');

  test.beforeAll(async ({ browser }) => {
    if (!configured) return;

    const ac = await browser.newContext();
    const ap = await ac.newPage();
    await login(ap, ADMIN_EMAIL!, ADMIN_PASSWORD!, 'admin');
    admin = await ac.storageState();
    await ac.close();

    const tc = await browser.newContext();
    const tp = await tc.newPage();
    await login(tp, TEACHER_EMAIL!, TEACHER_PASSWORD!, 'teacher');
    teacher = await tc.storageState();
    await tc.close();
  });

  test('admin extends a teacher and the teacher gets in with no payment', async ({ browser }, testInfo) => {
    // ── Admin: open the teacher, view access, extend ──
    const actx = await browser.newContext({ storageState: admin });
    const apage = await actx.newPage();
    await apage.setViewportSize({ width: 1366, height: 900 });
    await apage.goto('/admin/teachers');

    await expect(apage.getByRole('heading', { name: 'المعلمون' })).toBeVisible({ timeout: 20_000 });

    // Find the exact teacher card by email, then open the details dialog.
    const card = apage.locator('[class*="card-hover"]').filter({ hasText: TEACHER_EMAIL! }).first();
    await expect(card).toBeVisible({ timeout: 20_000 });
    await card.getByRole('button', { name: 'تفاصيل' }).click();

    // Access is shown separately from the payment state.
    await expect(apage.getByTestId('access-status')).toBeVisible({ timeout: 20_000 });
    await apage.screenshot({ path: testInfo.outputPath('admin-teacher-access-before.png') });

    await apage.getByTestId('extend-access').click();
    await apage.getByTestId('extend-days').fill(String(DAYS));
    await apage.getByTestId('extend-confirm').click();

    // A confirmation is required before the grant is applied.
    await expect(apage.getByTestId('confirm-action')).toBeVisible({ timeout: 15_000 });
    await apage.getByTestId('confirm-action').click();

    // Honest success message mentioning the access window.
    await expect(apage.getByText(/تمديد وصول المعلم/)).toBeVisible({ timeout: 20_000 });
    await expect(apage.getByTestId('admin-access-banner')).toBeVisible({ timeout: 20_000 });
    await apage.screenshot({ path: testInfo.outputPath('admin-teacher-access-after.png') });
    await actx.close();
  });

  test('the same teacher logs in and is not sent to the payment page', async ({ browser }) => {
    const tctx = await browser.newContext({ storageState: teacher });
    const tpage = await tctx.newPage();
    await tpage.setViewportSize({ width: 1366, height: 900 });

    await tpage.goto('/teacher/dashboard');

    // The workspace is active: no forced redirect to /pricing.
    await expect(tpage).not.toHaveURL(/\/pricing/, { timeout: 20_000 });
    await expect(tpage.getByRole('main')).toBeVisible({ timeout: 20_000 });

    // And a management route answers (no 402 redirection).
    await tpage.goto('/teacher/courses');
    await expect(tpage).not.toHaveURL(/\/pricing/, { timeout: 20_000 });
    await tctx.close();
  });
});
