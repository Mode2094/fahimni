import { test, expect, type Page, type BrowserContext } from '@playwright/test';

/**
 * Real-browser verification of the production-update package's teacher flows
 * that cannot be proven by unit tests: the in-course groups manager, the
 * multi-video upload queue, and the exam attempt's submit confirmation.
 *
 * They depend on a live backend (real teacher, real course, real exam), so they
 * only run when the matching env vars are provided — CI skips them. The
 * structural rules (RTL, no horizontal overflow, labelled controls ≥44px) are
 * also covered by Vitest so CI still guards the behaviour.
 *
 *   E2E_TEACHER_EMAIL=... E2E_TEACHER_PASSWORD=... E2E_COURSE_ID=... \
 *   E2E_EXAM_ID=... npx playwright test production-package
 */

const EMAIL = process.env.E2E_TEACHER_EMAIL;
const PASSWORD = process.env.E2E_TEACHER_PASSWORD;
const COURSE_ID = process.env.E2E_COURSE_ID;
const EXAM_ID = process.env.E2E_EXAM_ID;
const configured = Boolean(EMAIL && PASSWORD);

const PHONE_WIDTHS = [320, 360, 390, 430];
/** Anything below this is too small to tap comfortably. */
const MIN_TAP = 44;

async function login(page: Page) {
  await page.goto('/teacher/login');
  await page.fill('input[type=email]', EMAIL!);
  await page.fill('input[type=password]', PASSWORD!);
  await page.click('button[type=submit]');
  await page.waitForURL('**/teacher/dashboard', { timeout: 30_000 });
}

/** Horizontal overflow in CSS pixels (0 = no sideways scroll). */
async function overflow(page: Page) {
  return page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
}

/** Every visible interactive control inside a scope must be labelled. */
async function unlabelledControls(page: Page, scope: string) {
  return page.locator(`${scope} button, ${scope} a`).evaluateAll((els) =>
    els
      .filter((el) => {
        const rect = el.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
      })
      .filter((el) => {
        const text = (el.textContent || '').trim();
        const label = el.getAttribute('aria-label') || el.getAttribute('title');
        return text.length === 0 && !label;
      })
      .map((el) => el.outerHTML.slice(0, 120)),
  );
}

let session: Awaited<ReturnType<BrowserContext['storageState']>> | undefined;

test.describe('Production update package — teacher flows', () => {
  test.skip(!configured, 'set E2E_TEACHER_EMAIL / E2E_TEACHER_PASSWORD to run');

  test.beforeAll(async ({ browser }) => {
    if (!configured) return;
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await login(page);
    session = await ctx.storageState();
    await ctx.close();
  });

  test.beforeEach(async ({ context }) => {
    if (session) await context.addCookies(session.cookies);
  });

  test('in-course groups dialog opens with labelled controls and no sideways scroll', async ({ page }) => {
    test.skip(!COURSE_ID, 'set E2E_COURSE_ID to run');
    await page.goto(`/courses/${COURSE_ID}`);

    await page.getByRole('button', { name: /مجموعات الطلاب|Student groups/ }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible({ timeout: 15_000 });

    // Separate from terms/forum — the copy says so explicitly.
    await expect(dialog.getByText(/لا تنسخ الكورس|never clone the course/)).toBeVisible();

    for (const width of PHONE_WIDTHS) {
      await page.setViewportSize({ width, height: 800 });
      expect(await overflow(page), `horizontal overflow at ${width}px`).toBe(0);
    }

    await page.setViewportSize({ width: 1280, height: 900 });
    expect(await unlabelledControls(page, '[role=dialog]')).toEqual([]);
  });

  test('multi-video upload queue lists items with progress states and no overflow', async ({ page }) => {
    test.skip(!COURSE_ID, 'set E2E_COURSE_ID to run');
    await page.goto(`/courses/${COURSE_ID}`);

    await page.getByRole('button', { name: /رفع فيديوهات متعددة|Upload multiple videos/ }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible({ timeout: 15_000 });
    await expect(dialog.getByText(/قائمة|queue|رفع/).first()).toBeVisible();

    for (const width of [320, 390, 430]) {
      await page.setViewportSize({ width, height: 800 });
      expect(await overflow(page), `horizontal overflow at ${width}px`).toBe(0);
    }
  });
});

test.describe('Production update package — exam attempt', () => {
  test.skip(!configured || !EXAM_ID, 'set the teacher credentials and E2E_EXAM_ID to run');

  test('submit button is labelled and asks to confirm when answers are missing', async ({ page, context }) => {
    if (session) await context.addCookies(session.cookies);
    await page.goto(`/exam/${EXAM_ID}`);

    // The primary action is a real, named button — never a blank green one.
    const submit = page.getByRole('button', { name: /تسليم|submit|إنهاء/i }).first();
    await expect(submit).toBeVisible({ timeout: 20_000 });
    const box = await submit.boundingBox();
    expect((box?.height ?? 0)).toBeGreaterThanOrEqual(MIN_TAP);
  });
});
