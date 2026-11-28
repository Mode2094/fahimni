import { test, expect, type Page } from '@playwright/test';

/**
 * Real-browser verification of "Preview as student".
 *
 * Teacher login → course builder → open the student view → the preview banner,
 * the real lessons/exams render, and NOTHING is recorded (no attempt, no
 * progress). A student is proven unable to reuse the preview link for an
 * unpublished course.
 *
 * Runs only when a teacher account (and optionally a draft course / student)
 * is provided — CI without a backend skips it, and the structural guarantees
 * stay covered by Vitest + PHPUnit.
 *
 *   E2E_TEACHER_EMAIL=... E2E_TEACHER_PASSWORD=... E2E_COURSE_ID=... \
 *   E2E_DRAFT_COURSE_ID=... (optional) \
 *   E2E_STUDENT_EMAIL=... E2E_STUDENT_PASSWORD=... (optional) \
 *   npm run test:e2e
 */

const EMAIL = process.env.E2E_TEACHER_EMAIL;
const PASSWORD = process.env.E2E_TEACHER_PASSWORD;
const COURSE_ID = process.env.E2E_COURSE_ID;
const DRAFT_COURSE_ID = process.env.E2E_DRAFT_COURSE_ID;
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

test.describe('Preview as student', () => {
  test.skip(!configured, 'set E2E_TEACHER_EMAIL / E2E_TEACHER_PASSWORD / E2E_COURSE_ID to run');

  test('teacher opens the real student course view with a persistent preview banner', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await loginTeacher(page);
    await page.goto(`/courses/${COURSE_ID}`);
    await expect(page.getByText('رجوع للكورسات')).toBeVisible({ timeout: 20_000 });

    // The clear entry point exists in the course builder.
    const previewLink = page.getByRole('link', { name: /معاينة كطالب/ }).first();
    await expect(previewLink).toBeVisible();

    // It opens the student player in preview mode (new tab), with the banner.
    const [studentView] = await Promise.all([
      page.waitForEvent('popup'),
      previewLink.click(),
    ]);
    await studentView.waitForLoadState('domcontentloaded');
    expect(studentView.url()).toContain('preview=1');
    await expect(studentView.getByText(/وضع المعاينة كطالب/)).toBeVisible({ timeout: 20_000 });
    await expect(studentView.getByText(/العودة للتحرير/).first()).toBeVisible();

    await studentView.screenshot({ path: 'test-results/teacher-preview-course.png', fullPage: true });

    // Phone: the banner and the student layout stay usable.
    await studentView.setViewportSize({ width: 390, height: 844 });
    await expect(studentView.getByText(/وضع المعاينة كطالب/)).toBeVisible();
    await studentView.screenshot({ path: 'test-results/teacher-preview-course-phone.png', fullPage: true });
  });

  test('exam preview shows no attempt recorded', async ({ page }) => {
    await loginTeacher(page);
    await page.goto(`/courses/${COURSE_ID}`);
    await expect(page.getByText('رجوع للكورسات')).toBeVisible({ timeout: 20_000 });

    // Resolve an exam id (and its attempt count) from the session API.
    const exam = await page.evaluate(async ({ courseId, api }) => {
      const tenant = localStorage.getItem('tenant_id') || '';
      const res = await fetch(`${api}/exams?course_id=${courseId}`, {
        headers: { Accept: 'application/json', 'X-Tenant-Id': tenant },
        credentials: 'same-origin',
      });
      const data = await res.json().catch(() => ({}));
      const list = data.exams || data.data || (Array.isArray(data) ? data : []);
      const e = list[0];
      if (!e) return null;
      const att = await fetch(`${api}/exams/${e.id}/attempts`, {
        headers: { Accept: 'application/json', 'X-Tenant-Id': tenant },
        credentials: 'same-origin',
      });
      const ad = await att.json().catch(() => ({}));
      const attempts = ad.attempts || ad.data || (Array.isArray(ad) ? ad : []);
      return { id: e.id, attempts: attempts.length };
    }, { courseId: COURSE_ID, api: process.env.NEXT_PUBLIC_API_URL || '/api/v1' });
    test.skip(!exam, 'no exam in the course — set up one to run this check');

    await page.goto(`/exam/${exam!.id}?preview=1`);
    await expect(page.getByText(/وضع المعاينة/)).toBeVisible({ timeout: 20_000 });

    // Starting the preview and grading must persist nothing.
    await page.getByRole('button', { name: /ابدأ المعاينة/ }).click();
    for (let i = 0; i < 15; i++) {
      const finish = page.getByRole('button', { name: /إنهاء المعاينة والتصحيح/ });
      if (await finish.isVisible().catch(() => false)) { await finish.click(); break; }
      const next = page.getByRole('button', { name: 'التالي' });
      if (await next.isVisible().catch(() => false)) { await next.click(); await page.waitForTimeout(150); } else break;
    }

    // The attempt count is unchanged after a full preview.
    const after = await page.evaluate(async ({ examId, api }) => {
      const tenant = localStorage.getItem('tenant_id') || '';
      const res = await fetch(`${api}/exams/${examId}/attempts`, {
        headers: { Accept: 'application/json', 'X-Tenant-Id': tenant },
        credentials: 'same-origin',
      });
      const data = await res.json().catch(() => ({}));
      return (data.attempts || data.data || (Array.isArray(data) ? data : [])).length;
    }, { examId: exam!.id, api: process.env.NEXT_PUBLIC_API_URL || '/api/v1' });
    expect(after).toBe(exam!.attempts);
  });

  test('student cannot use the preview link to open an unpublished course', async ({ page }) => {
    test.skip(!STUDENT_EMAIL || !STUDENT_PASSWORD || !DRAFT_COURSE_ID, 'set E2E_STUDENT_* and E2E_DRAFT_COURSE_ID to run');

    await page.goto('/student/login');
    await page.fill('input[type=email]', STUDENT_EMAIL!);
    await page.fill('input[type=password]', STUDENT_PASSWORD!);
    await page.click('button[type=submit]');
    await page.waitForURL((u) => !u.pathname.endsWith('/student/login'), { timeout: 30_000 });

    await page.goto(`/course/${DRAFT_COURSE_ID}/learn?preview=1`);
    // The player refuses to render an unpublished course for a student.
    await expect(page.getByText(/غير موجود|Not found/)).toBeVisible({ timeout: 20_000 });
  });
});
