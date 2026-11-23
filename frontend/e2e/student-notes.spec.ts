import { test, expect, type Page } from '@playwright/test';

/**
 * Real-browser, real-account verification of «ملاحظاتي وعلاماتي».
 *
 * Notes are stored server-side and scoped to the signed-in student, so a
 * meaningful end-to-end check needs a running backend and a real student
 * account. Like `student-follow-up.spec.ts`, this spec skips in CI (no backend,
 * no account); the behavioural guarantees are proven by Vitest
 * (`components/notes/lesson-notes-panel.test.tsx`, `app/my-notes/page.test.tsx`,
 * `lib/lesson-notes.test.ts`) and PHPUnit (`LessonNoteTest`).
 *
 *   E2E_STUDENT_EMAIL=... E2E_STUDENT_PASSWORD=... npm run test:e2e
 *
 * What it proves:
 *   1. `/my-notes` really serves the student's private notes page (HTTP 200,
 *      no 500) inside the shell, with a privacy hint;
 *   2. either real notes or an explicit empty state is shown — never blank;
 *   3. the search box re-queries the server without crashing;
 *   4. it is usable on a phone viewport.
 */

const EMAIL = process.env.E2E_STUDENT_EMAIL;
const PASSWORD = process.env.E2E_STUDENT_PASSWORD;

const configured = Boolean(EMAIL && PASSWORD);

async function loginStudent(page: Page) {
  await page.goto('/student/login');
  await page.fill('#email', EMAIL!);
  await page.fill('#password', PASSWORD!);
  await page.click('button[type=submit]');
  await page.waitForURL('**/my-learning', { timeout: 30_000 });
}

test.describe('Student notes & bookmarks', () => {
  test.skip(!configured, 'set E2E_STUDENT_EMAIL / E2E_STUDENT_PASSWORD to run');

  test('desktop: serves the private notes page inside the shell', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await loginStudent(page);

    const [resp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/my-notes') && r.request().method() === 'GET', { timeout: 30_000 }),
      page.goto('/my-notes'),
    ]);

    expect(resp.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'ملاحظاتي وعلاماتي' })).toBeVisible({ timeout: 20_000 });
    await expect(page.getByRole('navigation', { name: 'Main navigation' }).first()).toBeVisible();

    // The page states the notes are private to this student.
    await expect(page.getByText('خاصة بك وحدك — لا يراها غيرك')).toBeVisible();

    // Either real notes or an explicit empty state — never a blank page.
    const empty = page.getByText('لا توجد ملاحظات بعد');
    const cards = page.locator('a[href*="/course/"][href*="learn"]');
    await expect(empty.or(cards.first())).toBeVisible({ timeout: 20_000 });

    // Re-query by search without crashing.
    await page.locator('#notes-q').fill('اختبار');
    await expect(page.getByRole('heading', { name: 'ملاحظاتي وعلاماتي' })).toBeVisible();

    await page.screenshot({ path: 'test-results/student-notes-desktop.png', fullPage: true });
  });

  test('mobile: the notes page is usable on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await loginStudent(page);

    await page.goto('/my-notes');
    await expect(page.getByRole('heading', { name: 'ملاحظاتي وعلاماتي' })).toBeVisible({ timeout: 20_000 });

    // Filters stack and stay reachable on the small screen.
    await expect(page.locator('#notes-q')).toBeVisible();
    await expect(page.locator('#notes-course')).toBeVisible();

    await page.screenshot({ path: 'test-results/student-notes-mobile.png', fullPage: true });
  });
});
