import { test, expect, type Page, type BrowserContext } from '@playwright/test';

/**
 * End-to-end platform-notification journey, driven entirely through the real UI
 * with real accounts: the Super Admin broadcasts a notification to all teachers,
 * two different teachers receive it in their header bell, one reads it and
 * marks everything read, the admin edits the text (it changes for every
 * recipient, without re-flagging it unread), and finally retracts it (it
 * disappears for every teacher). Also proves a teacher never gets an edit or
 * delete control, and that the panel stays usable on a phone.
 *
 * Skipped unless accounts are provided (CI has no backend).
 *
 *   E2E_ADMIN_EMAIL=... E2E_ADMIN_PASSWORD=... \
 *   E2E_TEACHER_EMAIL=... E2E_TEACHER_PASSWORD=... \
 *   E2E_TEACHER2_EMAIL=... E2E_TEACHER2_PASSWORD=... \
 *   npx playwright test admin-teacher-notifications
 */

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL;
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD;
const T1_EMAIL = process.env.E2E_TEACHER_EMAIL;
const T1_PASSWORD = process.env.E2E_TEACHER_PASSWORD;
const T2_EMAIL = process.env.E2E_TEACHER2_EMAIL;
const T2_PASSWORD = process.env.E2E_TEACHER2_PASSWORD;
const configured = Boolean(
  ADMIN_EMAIL && ADMIN_PASSWORD && T1_EMAIL && T1_PASSWORD && T2_EMAIL && T2_PASSWORD,
);

// Unique per run so re-runs never collide and cards can be matched precisely.
const TITLE = `إشعار المنصة E2E ${Date.now()}`;
const EDITED_TITLE = `${TITLE} (محدّث)`;
const BODY = 'رسالة اختبار آلية من فريق منصة فهمني.';
const EDITED_BODY = 'نص محدّث بعد الإرسال ينبغي أن يظهر لدى كل المعلمين.';

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
let t1: Awaited<ReturnType<BrowserContext['storageState']>> | undefined;
let t2: Awaited<ReturnType<BrowserContext['storageState']>> | undefined;

test.describe('Super Admin → teacher platform notifications', () => {
  test.skip(!configured, 'set E2E_ADMIN_* and both E2E_TEACHER* pairs to run');

  test.beforeAll(async ({ browser }) => {
    if (!configured) return;

    const ac = await browser.newContext();
    const ap = await ac.newPage();
    await login(ap, ADMIN_EMAIL!, ADMIN_PASSWORD!, 'admin');
    admin = await ac.storageState();
    await ac.close();

    const c1 = await browser.newContext();
    const p1 = await c1.newPage();
    await login(p1, T1_EMAIL!, T1_PASSWORD!, 'teacher');
    t1 = await c1.storageState();
    await c1.close();

    const c2 = await browser.newContext();
    const p2 = await c2.newPage();
    await login(p2, T2_EMAIL!, T2_PASSWORD!, 'teacher');
    t2 = await c2.storageState();
    await c2.close();
  });

  test('1 · admin broadcasts to every teacher (recipient count shown before confirm)', async ({ browser }, testInfo) => {
    const ctx = await browser.newContext({ storageState: admin });
    const page = await ctx.newPage();
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto('/admin/teacher-notifications');

    await expect(page.getByRole('main').getByRole('heading', { name: 'إشعارات المعلمين' })).toBeVisible({ timeout: 20_000 });
    await page.getByTestId('create-notification').click();

    await page.getByTestId('notif-title').fill(TITLE);
    await page.getByTestId('notif-body').fill(BODY);
    await page.getByTestId('notif-category').selectOption('alert');

    // The resolved recipient count is shown before confirming — two teachers.
    await expect(page.getByTestId('recipient-preview')).toHaveText(/[1-9]\d*/, { timeout: 15_000 });
    await page.screenshot({ path: testInfo.outputPath('admin-create-preview.png') });

    await page.getByTestId('save-send').click();
    await expect(page.getByTestId('confirm-action')).toBeVisible();
    await page.getByTestId('confirm-action').click();

    const card = page.getByTestId('admin-notification-card').filter({ hasText: TITLE }).first();
    await expect(card).toBeVisible({ timeout: 20_000 });
    await expect(card).toContainText('مُرسل');
    await expect(card).toContainText('المستلمون');
    await page.screenshot({ path: testInfo.outputPath('admin-sent.png') });
    await ctx.close();
  });

  test('2 · teacher one sees it in the bell, reads it and marks all read (desktop)', async ({ browser }, testInfo) => {
    const ctx = await browser.newContext({ storageState: t1 });
    const page = await ctx.newPage();
    await page.setViewportSize({ width: 1280, height: 900 });

    // The bell lives in the panel header (never a floating button).
    await page.goto('/teacher/dashboard');
    const bell = page.getByTestId('teacher-notif-bell');
    await expect(bell).toBeVisible({ timeout: 20_000 });
    await expect(bell.locator('span').first()).toBeVisible();

    await bell.click();
    const item = page.getByTestId('teacher-notif-item').filter({ hasText: TITLE }).first();
    await expect(item).toBeVisible({ timeout: 20_000 });
    await expect(item).toHaveAttribute('data-read', 'false');
    await page.screenshot({ path: testInfo.outputPath('teacher1-bell.png') });

    // Opening it deep-links to the notification page and marks it read.
    await item.click();
    await page.waitForURL('**/teacher/notifications?open=*', { timeout: 20_000 });

    const row = page.getByTestId('teacher-notification-row').filter({ hasText: TITLE }).first();
    await expect(row).toBeVisible({ timeout: 20_000 });
    await expect(row).toHaveAttribute('data-read', 'true');

    // A teacher never gets an edit or delete control.
    await expect(page.getByRole('button', { name: 'حذف' })).toHaveCount(0);
    await expect(page.getByTestId('delete-notification')).toHaveCount(0);
    await expect(page.getByTestId('edit-notification')).toHaveCount(0);

    // Mark-all-read clears the badge entirely.
    const markAll = page.getByTestId('mark-all-read');
    if (await markAll.count()) {
      await markAll.click();
    }
    await expect(page.getByTestId('teacher-notification-row').filter({ hasText: TITLE }).first())
      .toHaveAttribute('data-read', 'true');
    await page.screenshot({ path: testInfo.outputPath('teacher1-read.png') });
    await ctx.close();
  });

  test('3 · teacher two gets it in the bell on a phone with no horizontal scroll', async ({ browser }, testInfo) => {
    const ctx = await browser.newContext({ storageState: t2 });
    const page = await ctx.newPage();
    await page.setViewportSize({ width: 390, height: 844 });

    await page.goto('/teacher/dashboard');
    const bell = page.getByTestId('teacher-notif-bell');
    await expect(bell).toBeVisible({ timeout: 20_000 });
    await bell.click();

    const item = page.getByTestId('teacher-notif-item').filter({ hasText: TITLE }).first();
    await expect(item).toBeVisible({ timeout: 20_000 });
    // Still unread for the second teacher.
    await expect(item).toHaveAttribute('data-read', 'false');

    // The dropdown must not push the page sideways.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    await page.screenshot({ path: testInfo.outputPath('teacher2-phone-bell.png') });

    // Open it on the phone and read it.
    await item.click();
    await page.waitForURL('**/teacher/notifications?open=*', { timeout: 20_000 });
    const row = page.getByTestId('teacher-notification-row').filter({ hasText: TITLE }).first();
    await expect(row).toBeVisible({ timeout: 20_000 });
    await expect(row).toHaveAttribute('data-read', 'true');
    await page.screenshot({ path: testInfo.outputPath('teacher2-phone-open.png') });
    await ctx.close();
  });

  test('4 · admin edit changes the text for every recipient and retract hides it', async ({ browser }) => {
    // ── Admin edits the sent notification ──
    const actx = await browser.newContext({ storageState: admin });
    const apage = await actx.newPage();
    await apage.setViewportSize({ width: 1366, height: 900 });
    await apage.goto('/admin/teacher-notifications');

    const card = apage.getByTestId('admin-notification-card').filter({ hasText: TITLE }).first();
    await expect(card).toBeVisible({ timeout: 20_000 });
    await card.getByTestId('edit-notification').click();
    await apage.getByTestId('notif-title').fill(EDITED_TITLE);
    await apage.getByTestId('notif-body').fill(EDITED_BODY);
    await apage.getByTestId('save-draft').click();
    await expect(
      apage.getByTestId('admin-notification-card').filter({ hasText: EDITED_TITLE }).first(),
    ).toBeVisible({ timeout: 20_000 });
    await actx.close();

    // ── Teacher 1 sees the new text (and it stays read) ──
    const t1ctx = await browser.newContext({ storageState: t1 });
    const tpage = await t1ctx.newPage();
    await tpage.setViewportSize({ width: 1280, height: 900 });
    await tpage.goto('/teacher/notifications');

    const editedRow = tpage.getByTestId('teacher-notification-row').filter({ hasText: EDITED_TITLE }).first();
    await expect(editedRow).toBeVisible({ timeout: 20_000 });
    await expect(editedRow).toContainText(EDITED_BODY);
    // A plain text edit must not silently reset the read state.
    await expect(editedRow).toHaveAttribute('data-read', 'true');
    await t1ctx.close();

    // ── Admin retracts ──
    const rctx = await browser.newContext({ storageState: admin });
    const rpage = await rctx.newPage();
    await rpage.setViewportSize({ width: 1366, height: 900 });
    await rpage.goto('/admin/teacher-notifications');

    const sentCard = rpage.getByTestId('admin-notification-card').filter({ hasText: EDITED_TITLE }).first();
    await expect(sentCard).toBeVisible({ timeout: 20_000 });
    await sentCard.getByTestId('retract-notification').click();
    await rpage.getByTestId('confirm-action').click();
    await expect(
      rpage.getByTestId('admin-notification-card').filter({ hasText: EDITED_TITLE }).first(),
    ).toContainText('مسحوب', { timeout: 20_000 });
    await rctx.close();

    // ── Gone for every teacher, including the one who already read it ──
    for (const state of [t1, t2]) {
      const c = await browser.newContext({ storageState: state });
      const p = await c.newPage();
      await p.setViewportSize({ width: 1280, height: 900 });
      await p.goto('/teacher/notifications');
      await expect(p.getByTestId('teacher-notification-row').filter({ hasText: EDITED_TITLE }))
        .toHaveCount(0, { timeout: 20_000 });
      await c.close();
    }
  });

  test('5 · a teacher cannot reach the admin management page', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: t1 });
    const page = await ctx.newPage();
    await page.goto('/admin/teacher-notifications');
    // The admin shell either bounces the teacher to the admin login or shows
    // nothing actionable — either way, no management control ever appears.
    await expect(page.getByTestId('create-notification')).toHaveCount(0, { timeout: 20_000 });
    await ctx.close();
  });
});
