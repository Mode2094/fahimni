import { test, expect, type Page } from '@playwright/test';

/**
 * Real-browser, real-account verification of the unified exam editor.
 *
 * This spec drives the actual product flow — teacher login → course → unit →
 * exam → edit — against a running backend, so it only runs when a trial
 * teacher account is provided. CI (no backend, no account) skips it and the
 * structural guarantees are still checked by Vitest
 * (`exam-editor.test.tsx`, `question-builder.test.tsx`).
 *
 *   E2E_TEACHER_EMAIL=... E2E_TEACHER_PASSWORD=... E2E_COURSE_ID=... \
 *   E2E_STUDENT_EMAIL=... E2E_STUDENT_PASSWORD=... \
 *   npm run test:e2e
 *
 * What it proves:
 *   1. the exam editor renders INSIDE the course content — the main sidebar
 *      stays visible and usable, with no `fixed inset-0 z-50` overlay;
 *   2. all ten question types are offered, including «سؤال تفاعلي» and the
 *      independent «سؤال HTML»;
 *   3. pasting an HTML/CSS/JS document previews, saves, and produces a
 *      `custom_html` question while the old `interactive_html` question keeps
 *      its own type;
 *   4. a student opens the exam and both sandboxed types render and submit.
 */

const EMAIL = process.env.E2E_TEACHER_EMAIL;
const PASSWORD = process.env.E2E_TEACHER_PASSWORD;
const COURSE_ID = process.env.E2E_COURSE_ID;
const STUDENT_EMAIL = process.env.E2E_STUDENT_EMAIL;
const STUDENT_PASSWORD = process.env.E2E_STUDENT_PASSWORD;

const configured = Boolean(EMAIL && PASSWORD && COURSE_ID);

async function loginTeacher(page: Page) {
  await page.goto('/teacher/login');
  await page.fill('input[type=email]', EMAIL!);
  await page.fill('input[type=password]', PASSWORD!);
  await page.click('button[type=submit]');
  await page.waitForURL('**/teacher/dashboard', { timeout: 30_000 });
}

function mainSidebar(page: Page) {
  return page.getByRole('navigation', { name: 'Main navigation' }).first();
}

/** Walk up from the editor's back button to prove there is no overlay ancestor. */
async function editorHasNoOverlay(page: Page): Promise<boolean> {
  return page.evaluate(() => {
    const btn = Array.from(document.querySelectorAll('button')).find((b) =>
      (b.textContent || '').includes('رجوع إلى الكورس'),
    );
    if (!btn) return false;

    let el: HTMLElement | null = btn;
    while (el && el !== document.body) {
      const cls = typeof el.className === 'string' ? el.className : '';
      if (cls.includes('fixed') && cls.includes('inset-0') && cls.includes('z-50')) {
        return false;
      }
      el = el.parentElement;
    }
    return true;
  });
}

test.describe('Unified exam editor', () => {
  test.skip(!configured, 'set E2E_TEACHER_EMAIL / E2E_TEACHER_PASSWORD / E2E_COURSE_ID to run');

  test('desktop + phone: edits in-content, keeps the sidebar reachable, adds an HTML question', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await loginTeacher(page);

    await page.goto(`/courses/${COURSE_ID}`);
    // The course page rendered (its own header is visible before editing).
    await expect(page.getByText('رجوع للكورسات')).toBeVisible({ timeout: 20_000 });

    // The main sidebar is present before editing…
    await expect(mainSidebar(page)).toBeVisible();

    // Open the exam inside its unit.
    await page.getByRole('button', { name: 'تحرير' }).first().click();

    // …and the editor is in the content area: back button visible, sidebar
    // still visible, and no full-screen overlay covers it.
    await expect(page.getByRole('button', { name: /رجوع إلى الكورس/ })).toBeVisible();
    await expect(mainSidebar(page)).toBeVisible();
    expect(await editorHasNoOverlay(page)).toBe(true);

    await page.screenshot({ path: 'test-results/exam-editor-desktop.png', fullPage: true });

    // All ten question types are offered, with the two HTML types independent.
    await page.getByRole('button', { name: 'سؤال جديد' }).click();
    for (const label of [
      'اختيار متعدد', 'صح / خطأ', 'سحب وإفلات', 'مطابقة', 'ترتيب',
      'املأ الفراغ', 'إجابة كتابية', 'رفع ملف', 'سؤال تفاعلي', 'سؤال HTML',
    ]) {
      await expect(page.getByRole('button', { name: label })).toBeVisible();
    }

    // Author a NEW HTML question with a full document.
    await page.getByRole('button', { name: 'سؤال HTML' }).click();
    await page.locator('textarea').first().fill('سؤال HTML من الاختبار');
    await page.getByPlaceholder('الصق وسم HTML هنا…').fill(
      '<!doctype html><html><body><h1 id="hello">مرحبا HTML</h1></body></html>',
    );

    // Live preview renders the pasted document inside the isolated sandbox.
    const sandbox = page.frameLocator('iframe[title="سؤال تفاعلي"], iframe[title="Interactive question"]').first();
    await expect(sandbox.locator('#hello')).toHaveText('مرحبا HTML', { timeout: 20_000 });

    // Save → toast + the question is listed under «سؤال HTML».
    await page.getByRole('button', { name: 'حفظ السؤال' }).click();
    await expect(page.getByText('تمت إضافة السؤال')).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText('سؤال HTML من الاختبار').first()).toBeVisible();

    // The pre-existing interactive question kept its own type.
    await expect(page.getByText('سؤال تفاعلي قديم').first()).toBeVisible();

    await page.screenshot({ path: 'test-results/exam-editor-desktop-saved.png', fullPage: true });

    // ── Phone: the editor stays usable and the sidebar opens via the header menu.
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByRole('button', { name: /رجوع إلى الكورس/ })).toBeVisible();
    await page.getByRole('button', { name: 'فتح القائمة' }).click();
    await expect(page.getByRole('navigation', { name: 'Main navigation' }).first()).toBeVisible();

    await page.screenshot({ path: 'test-results/exam-editor-phone.png', fullPage: true });
  });

  test('student: both sandboxed types render and the attempt submits', async ({ page }) => {
    test.skip(!STUDENT_EMAIL || !STUDENT_PASSWORD, 'set E2E_STUDENT_* to run the student flow');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/student/login');
    await page.fill('input[type=email]', STUDENT_EMAIL!);
    await page.fill('input[type=password]', STUDENT_PASSWORD!);
    await page.click('button[type=submit]');
    await page.waitForURL((u) => !u.pathname.endsWith('/student/login'), { timeout: 30_000 });

    // The exam id is resolved from the course exams API using the session.
    const examId = await page.evaluate(async ({ courseId, api }) => {
      const tenant = localStorage.getItem('tenant_id') || '';
      const res = await fetch(`${api}/exams?course_id=${courseId}`, {
        headers: { Accept: 'application/json', 'X-Tenant-Id': tenant },
        credentials: 'same-origin',
      });
      const data = await res.json().catch(() => ({}));
      const list = data.exams || data.data || (Array.isArray(data) ? data : []);
      return list[0]?.id || '';
    }, { courseId: COURSE_ID, api: process.env.NEXT_PUBLIC_API_URL || '/api/v1' });
    expect(examId).not.toBe('');

    await page.goto(`/exam/${examId}`);
    await page.getByRole('button', { name: 'ابدأ الاختبار' }).click();

    const sandboxFrame = () =>
      page.frameLocator('iframe[title="سؤال تفاعلي"], iframe[title="Interactive question"]').first();

    // Question 1 — the pre-existing interactive question renders in its sandbox.
    await expect(sandboxFrame().locator('#b')).toBeVisible({ timeout: 20_000 });

    // Walk the exam. The HTML question the teacher created must render for the
    // student inside its own sandbox; then submit on the last question.
    let sawHtml = false;
    for (let i = 0; i < 15; i++) {
      if (await sandboxFrame().locator('#hello').isVisible().catch(() => false)) {
        sawHtml = true;
        await expect(sandboxFrame().locator('#hello')).toHaveText('مرحبا HTML');
        await page.screenshot({ path: 'test-results/exam-student.png', fullPage: true });
      }

      const submit = page.getByRole('button', { name: 'تسليم الإجابات' });
      if (await submit.isVisible().catch(() => false)) {
        await submit.click();
        break;
      }

      await page.getByRole('button', { name: 'التالي' }).click();
      await page.waitForTimeout(200);
    }

    expect(sawHtml).toBe(true);

    // Submitting the attempt returns a real result.
    await expect(page.getByText(/مبروك!|حاول مرة أخرى/)).toBeVisible({ timeout: 20_000 });
  });
});
