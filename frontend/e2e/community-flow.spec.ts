import { test, expect, type Page, type BrowserContext } from '@playwright/test';

/**
 * Community works between two real users, driven through the real UI:
 * one member posts a question → another member sees it immediately, opens it
 * and answers → the author is notified (once) → the answer is liked and
 * accepted. Skipped unless accounts are provided (CI has no backend).
 *
 *   E2E_TEACHER_EMAIL=... E2E_TEACHER_PASSWORD=... \
 *   E2E_STUDENT_EMAIL=... E2E_STUDENT_PASSWORD=... \
 *   npx playwright test community-flow
 */

const TEACHER_EMAIL = process.env.E2E_TEACHER_EMAIL;
const TEACHER_PASSWORD = process.env.E2E_TEACHER_PASSWORD;
const STUDENT_EMAIL = process.env.E2E_STUDENT_EMAIL;
const STUDENT_PASSWORD = process.env.E2E_STUDENT_PASSWORD;
const configured = Boolean(TEACHER_EMAIL && TEACHER_PASSWORD && STUDENT_EMAIL && STUDENT_PASSWORD);

async function login(page: Page, email: string, password: string, landing: 'teacher' | 'student') {
  await page.goto(landing === 'student' ? '/student/login' : '/teacher/login');
  await page.fill('input[type=email]', email);
  await page.fill('input[type=password]', password);
  await page.click('button[type=submit]');
  await page.waitForURL(landing === 'student' ? '**/my-learning' : '**/teacher/dashboard', { timeout: 30_000 });
}

let teacher: Awaited<ReturnType<BrowserContext['storageState']>> | undefined;
let student: Awaited<ReturnType<BrowserContext['storageState']>> | undefined;

test.describe('Community — real users post, reply, get notified, like, accept', () => {
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

  test('post → other member replies → author notified → like → accept answer', async ({ browser }, testInfo) => {
    const stamp = Date.now();
    const title = `سؤال E2E ${stamp}`;
    const question = 'نص السؤال التجريبي الآلي لاختبار المجتمع.';
    const answer = `إجابة آلية ${stamp}`;

    // ── Member A posts a question ──
    const tctx = await browser.newContext({ storageState: teacher });
    const tpage = await tctx.newPage();
    await tpage.setViewportSize({ width: 1280, height: 900 });
    await tpage.goto('/community');
    await tpage.getByRole('button', { name: 'نشر موضوع' }).first().click();

    const dialog = tpage.locator('div.fixed.inset-0.z-50.flex');
    await dialog.getByRole('button', { name: 'سؤال', exact: true }).click();
    await dialog.locator('input').first().fill(title); // Title
    await dialog.locator('textarea').first().fill(question); // Content
    await dialog.getByRole('button', { name: 'نشر' }).click();

    await tpage.waitForURL('**/community/threads/**', { timeout: 20_000 });
    const threadUrl = tpage.url();
    await expect(tpage.getByRole('heading', { name: title })).toBeVisible();

    // ── Member B sees it immediately and answers ──
    const sctx = await browser.newContext({ storageState: student });
    const spage = await sctx.newPage();
    await spage.setViewportSize({ width: 390, height: 844 });
    await spage.goto('/community');
    const link = spage.getByRole('link', { name: title });
    await expect(link).toBeVisible({ timeout: 20_000 });
    await link.click();

    await spage.waitForURL('**/community/threads/**');
    await spage.locator('#comment').fill(answer);
    await spage.getByRole('button', { name: 'نشر' }).click();
    await expect(spage.getByText(answer)).toBeVisible({ timeout: 10_000 });

    // ── Author is notified exactly once, with a deep link ──
    await tpage.goto('/notifications');
    const notif = tpage.locator('button').filter({ hasText: answer });
    await expect(notif).toHaveCount(1, { timeout: 20_000 });
    await notif.first().click();
    await tpage.waitForURL('**/community/threads/**', { timeout: 20_000 });
    expect(tpage.url()).toBe(threadUrl);

    // ── Like the thread, then accept the answer ──
    const likeBtn = tpage.getByRole('button', { name: /إعجاب/ });
    await likeBtn.click();
    await expect(tpage.getByRole('button', { name: /1 إعجاب/ })).toBeVisible({ timeout: 10_000 });

    await tpage.getByRole('button', { name: 'اعتماد كإجابة' }).click();
    await expect(tpage.getByText('الإجابة المعتمدة')).toBeVisible({ timeout: 10_000 });
    await tpage.screenshot({ path: testInfo.outputPath('community-accepted.png') });

    // ── Member B reloads and sees the accepted answer + like ──
    await spage.reload();
    await expect(spage.getByText('الإجابة المعتمدة')).toBeVisible({ timeout: 20_000 });

    await tctx.close();
    await sctx.close();
  });
});
