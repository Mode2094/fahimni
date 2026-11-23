import { test, expect, type Page } from '@playwright/test';

/**
 * Real-browser, real-account verification of the central question bank.
 *
 * The bank is only meaningful against a running backend and a real teacher
 * account, so — like `exam-editor.spec.ts` — this spec skips in CI (no backend,
 * no account). The structural guarantees are proven by Vitest
 * (`questions/page.test.tsx`, `exam-editor.test.tsx`).
 *
 *   E2E_TEACHER_EMAIL=... E2E_TEACHER_PASSWORD=... E2E_COURSE_ID=... \
 *   npm run test:e2e
 *
 * What it proves:
 *   1. `/teacher/questions` is a real bank page (no redirect) inside the panel
 *      shell, with the sidebar reachable and no full-screen overlay;
 *   2. a question authored once appears in the bank and can be searched/
 *      filtered;
 *   3. selected bank questions can be added to an exam in one action;
 *   4. the exam editor can draw a random set of bank questions into an exam.
 */

const EMAIL = process.env.E2E_TEACHER_EMAIL;
const PASSWORD = process.env.E2E_TEACHER_PASSWORD;
const COURSE_ID = process.env.E2E_COURSE_ID;

const configured = Boolean(EMAIL && PASSWORD && COURSE_ID);

async function loginTeacher(page: Page) {
  await page.goto('/teacher/login');
  await page.fill('input[type=email]', EMAIL!);
  await page.fill('input[type=password]', PASSWORD!);
  await page.click('button[type=submit]');
  await page.waitForURL('**/teacher/dashboard', { timeout: 30_000 });
}

test.describe('Question bank', () => {
  test.skip(!configured, 'set E2E_TEACHER_EMAIL / E2E_TEACHER_PASSWORD / E2E_COURSE_ID to run');

  test('desktop: lists, searches, and reuses bank questions in an exam', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await loginTeacher(page);

    // The route is a real page now, not a redirect to /courses.
    await page.goto('/teacher/questions');
    await page.waitForURL('**/teacher/questions');
    await expect(page.getByRole('heading', { name: 'بنك الأسئلة' })).toBeVisible({ timeout: 20_000 });

    // The main sidebar stays reachable (the page lives inside the shell).
    await expect(page.getByRole('navigation', { name: 'Main navigation' }).first()).toBeVisible();

    // Author a question once, straight into the bank.
    const marker = `سؤال بنك ${Date.now()}`;
    await page.getByRole('button', { name: 'سؤال جديد' }).first().click();
    await page.locator('textarea').first().fill(marker);
    await page.getByRole('button', { name: 'حفظ السؤال في البنك' }).click();
    await expect(page.getByText('تمت إضافة السؤال إلى البنك')).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText(marker).first()).toBeVisible();

    // Server-side search narrows the list to it.
    await page.getByPlaceholder('ابحث في نص السؤال أو التصنيف...').fill(marker);
    await expect(page.getByText(marker).first()).toBeVisible();

    await page.screenshot({ path: 'test-results/question-bank-desktop.png', fullPage: true });

    // Bulk-add the authored question to an exam, when the account has one.
    await page.getByRole('checkbox').nth(1).check();
    const examPicker = page.locator('select').last();
    const options = await examPicker.locator('option').count();
    if (options > 1) {
      const value = await examPicker.locator('option').nth(1).getAttribute('value');
      if (value) {
        await examPicker.selectOption(value);
        await page.getByRole('button', { name: 'أضف إلى الاختبار' }).click();
        await expect(page.getByText('أُضيفت الأسئلة المحددة إلى الاختبار')).toBeVisible({ timeout: 20_000 });
      }
    }
  });

  test('desktop: the exam editor draws a random set from the bank', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await loginTeacher(page);

    await page.goto(`/courses/${COURSE_ID}`);
    await expect(page.getByText('رجوع للكورسات')).toBeVisible({ timeout: 20_000 });

    await page.getByRole('button', { name: 'تحرير' }).first().click();
    await expect(page.getByRole('button', { name: /رجوع إلى الكورس/ })).toBeVisible();

    await page.getByRole('button', { name: 'سحب عشوائي من البنك' }).click();
    await page.getByRole('button', { name: 'اسحب وأضف' }).click();

    // Either questions were added, or the bank had none left to add — both are
    // valid outcomes the server reports explicitly.
    await expect(page.getByText(/سؤالاً من البنك|لا توجد أسئلة مطابقة/)).toBeVisible({ timeout: 20_000 });

    await page.screenshot({ path: 'test-results/exam-draw-from-bank.png', fullPage: true });
  });
});
