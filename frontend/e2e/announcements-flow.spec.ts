import { test, expect, type Page, type BrowserContext } from '@playwright/test';

/**
 * End-to-end announcements flow, driven entirely through the real UI with real
 * accounts: a teacher creates an announcement for their students, publishes it,
 * and the targeted student receives an in-app notification, opens it from
 * /notifications and it is marked read. Also proves the permission boundary
 * (a student never sees the create/send controls).
 *
 * Skipped unless accounts are provided (CI has no backend).
 *
 *   E2E_TEACHER_EMAIL=... E2E_TEACHER_PASSWORD=... \
 *   E2E_STUDENT_EMAIL=... E2E_STUDENT_PASSWORD=... \
 *   npx playwright test announcements-flow
 */

const TEACHER_EMAIL = process.env.E2E_TEACHER_EMAIL;
const TEACHER_PASSWORD = process.env.E2E_TEACHER_PASSWORD;
const STUDENT_EMAIL = process.env.E2E_STUDENT_EMAIL;
const STUDENT_PASSWORD = process.env.E2E_STUDENT_PASSWORD;
const configured = Boolean(TEACHER_EMAIL && TEACHER_PASSWORD && STUDENT_EMAIL && STUDENT_PASSWORD);

async function login(page: Page, email: string, password: string, landing: string) {
  await page.goto(landing === 'student' ? '/student/login' : '/teacher/login');
  await page.fill('input[type=email]', email);
  await page.fill('input[type=password]', password);
  await page.click('button[type=submit]');
  await page.waitForURL(
    landing === 'student' ? '**/my-learning' : '**/teacher/dashboard',
    { timeout: 30_000 },
  );
}

let teacher: Awaited<ReturnType<BrowserContext['storageState']>> | undefined;
let student: Awaited<ReturnType<BrowserContext['storageState']>> | undefined;

test.describe('Announcements — teacher sends, student is notified', () => {
  test.skip(!configured, 'set E2E_TEACHER_* / E2E_STUDENT_* to run');

  test.beforeAll(async ({ browser }) => {
    if (!configured) return;

    const tc = await browser.newContext();
    const tp = await tc.newPage();
    await login(tp, TEACHER_EMAIL!, TEACHER_PASSWORD!, 'teacher');
    teacher = await tc.storageState();
    await tc.close();

    const sc = await browser.newContext();
    const sp = await sc.newPage();
    await login(sp, STUDENT_EMAIL!, STUDENT_PASSWORD!, 'student');
    student = await sc.storageState();
    await sc.close();
  });

  test('teacher creates + publishes; student opens it and it is marked read', async ({ browser }, testInfo) => {
    const title = `إعلان E2E ${Date.now()}`;
    const body = 'رسالة اختبار آلية: يرجى مراجعة موعد الاختبار النهائي.';

    // ── Teacher: create a students-only announcement and publish it ──
    const tctx = await browser.newContext({ storageState: teacher });
    const tpage = await tctx.newPage();
    await tpage.setViewportSize({ width: 1280, height: 900 });
    await tpage.goto('/announcements');
    await tpage.getByRole('button', { name: 'إعلان جديد' }).click();

    await tpage.getByPlaceholder('مثال: موعد الاختبار النهائي').fill(title);
    await tpage.locator('textarea').first().fill(body);
    await tpage.getByRole('button', { name: 'الطلاب', exact: true }).click();
    await tpage.getByRole('button', { name: 'إنشاء' }).click();
    await expect(tpage.getByText('تم إنشاء الإعلان')).toBeVisible({ timeout: 10_000 });

    // The draft is visible to its author with a send control.
    const card = tpage.getByTestId('announcement-card').filter({ hasText: title });
    await expect(card).toContainText('مسودة');

    // Publish it — the recipient count must be reported.
    await card.getByRole('button', { name: 'إرسال' }).click();
    await expect(tpage.getByText(/تم الإرسال إلى \d+ مستلم/)).toBeVisible({ timeout: 10_000 });
    await expect(card).toContainText('مرسل');
    await tpage.screenshot({ path: testInfo.outputPath('announcement-published.png') });
    await tctx.close();

    // ── Student: the notification arrives and opens the full announcement ──
    const sctx = await browser.newContext({ storageState: student });
    const spage = await sctx.newPage();
    await spage.setViewportSize({ width: 390, height: 844 });
    await spage.goto('/notifications');

    const row = spage.locator('button').filter({ hasText: title }).first();
    await expect(row).toBeVisible({ timeout: 20_000 });
    // Unread rows are highlighted.
    await expect(row).toHaveClass(/bg-teal-50/);

    await row.click();
    await spage.waitForURL('**/announcements?open=*', { timeout: 20_000 });

    // The full announcement is shown in a detail dialog (the flex overlay,
    // distinct from the mobile nav drawer), with the real body.
    const detail = spage.locator('div.fixed.inset-0.z-50.flex');
    await expect(detail).toBeVisible();
    await expect(detail.getByText(body)).toBeVisible();
    await spage.screenshot({ path: testInfo.outputPath('announcement-student-open.png') });

    // Back to the list: the notification is now read (no highlight).
    await spage.goto('/notifications');
    const rowAfter = spage.locator('button').filter({ hasText: title }).first();
    await expect(rowAfter).toBeVisible({ timeout: 20_000 });
    await expect(rowAfter).not.toHaveClass(/bg-teal-50/);

    await sctx.close();
  });

  test('a student never sees the create/send controls', async ({ browser }) => {
    const sctx = await browser.newContext({ storageState: student });
    const spage = await sctx.newPage();
    await spage.setViewportSize({ width: 390, height: 844 });
    await spage.goto('/announcements');
    // The page loads for the student…
    await expect(spage.getByRole('main').getByRole('heading', { name: 'الإعلانات' })).toBeVisible({ timeout: 20_000 });
    // …but the staff-only controls are absent.
    await expect(spage.getByRole('button', { name: 'إعلان جديد' })).toHaveCount(0);
    await expect(spage.getByRole('button', { name: 'إرسال' })).toHaveCount(0);
    await sctx.close();
  });
});
