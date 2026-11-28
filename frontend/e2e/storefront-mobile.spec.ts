import { test, expect, type Page } from '@playwright/test';

/**
 * Real-browser verification of the student-facing teacher storefront
 * (`/t/[slug]` and `/t/[slug]/courses`).
 *
 * These guarantees cannot be proven by unit tests — they depend on the real
 * layout engine: no horizontal scroll at every phone width, touch targets that
 * clear 44px, a mobile menu that opens/closes without shoving the page sideways,
 * RTL/LTR, and course cards that render the API's real data only (never invented
 * ratings or student counts).
 *
 * It needs a live backend for `/api/v1/public/*`, so it only runs when a tenant
 * slug is provided (CI has no backend) — the structural rules are also covered
 * by Vitest (`storefront.test.tsx`, `landing.test.tsx`).
 *
 *   E2E_TENANT_SLUG=trial-teacher npx playwright test storefront-mobile
 */

const SLUG = process.env.E2E_TENANT_SLUG;
const configured = Boolean(SLUG);

const PHONE_WIDTHS = [320, 360, 390, 430];
/** Anything below this is too small to tap comfortably. */
const MIN_TAP = 44;

/** Horizontal overflow in CSS pixels (0 = no sideways scroll). */
async function overflow(page: Page) {
  return page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
}

/** Bounding boxes of every interactive control inside the given scope. */
async function tapTargets(page: Page, scope: string) {
  return page.locator(`${scope} a, ${scope} button`).evaluateAll((els) =>
    els
      .filter((el) => {
        const rect = el.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
      })
      .map((el) => {
        const rect = el.getBoundingClientRect();
        return { w: rect.width, h: rect.height, text: (el.textContent || '').trim().slice(0, 30) };
      }),
  );
}

test.describe('Student storefront — mobile-first', () => {
  test.skip(!configured, 'set E2E_TENANT_SLUG to run');

  test('phones 320–430: no sideways scroll and the essentials are on screen', async ({ page }) => {
    for (const w of PHONE_WIDTHS) {
      await page.setViewportSize({ width: w, height: 844 });
      await page.goto(`/t/${SLUG}`);
      await expect(page.getByTestId('storefront-header')).toBeVisible();
      await expect(page.locator('#courses')).toBeVisible();

      expect(await overflow(page), `horizontal overflow at ${w}px`).toBeLessThanOrEqual(1);

      // Hero leads with the approved primary call to action.
      await expect(page.getByRole('link', { name: /استكشف الكورسات/ }).first()).toBeVisible();
    }
  });

  test('the catalog shows real course data and no invented social proof', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/t/${SLUG}`);
    await expect(page.getByTestId('storefront-header')).toBeVisible();

    // The real payload the page renders from.
    const api = await page.evaluate(async (slug) => {
      const r = await fetch(`/api/v1/public/${slug}/courses`);
      return r.json();
    }, SLUG);
    const courses: any[] = Array.isArray(api?.courses) ? api.courses : [];

    if (courses.length === 0) {
      await expect(page.getByText('لا توجد كورسات منشورة بعد')).toBeVisible();
    } else {
      const first = courses[0];
      const card = page.getByTestId('course-card').first();
      await expect(card).toBeVisible();
      await expect(card).toContainText(first.title);

      // Price is shown only when the course actually has one.
      const priced = Number(first.price) > 0;
      await expect(page.getByTestId('course-price')).toHaveCount(
        courses.filter((c) => Number(c.price) > 0).length,
      );
      if (priced) await expect(page.getByTestId('course-price').first()).toContainText(String(first.price));
    }

    // No fabricated ratings, star icons or marketing numbers anywhere.
    const body = (await page.locator('body').innerText()).replace(/[\u200f\u200e]/g, '');
    expect(body).not.toMatch(/4\.(8|9)\s*تقييم/);
    expect(body).not.toContain('+500');
    expect(await page.locator('.lucide-star').count()).toBe(0);
  });

  test('phone: the menu opens and closes without pushing the page sideways', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/t/${SLUG}`);
    await expect(page.getByTestId('storefront-header')).toBeVisible();

    await page.getByRole('button', { name: 'فتح القائمة' }).click();
    const menu = page.getByTestId('storefront-mobile-menu');
    await expect(menu).toBeVisible();
    expect(await overflow(page), 'overflow with menu open').toBeLessThanOrEqual(1);

    // Every control in the drawer is comfortably tappable.
    for (const t of await tapTargets(page, '#storefront-mobile-menu')) {
      expect(t.h, `tap height for "${t.text}"`).toBeGreaterThanOrEqual(MIN_TAP);
    }

    await page.getByRole('button', { name: 'إغلاق القائمة' }).click();
    await expect(menu).toBeHidden();
    expect(await overflow(page), 'overflow after closing').toBeLessThanOrEqual(1);
  });

  test('tablet + desktop: nav is present and the page stays scroll-free', async ({ page }) => {
    for (const [w, h] of [[768, 1024], [1024, 900], [1366, 900]] as const) {
      await page.setViewportSize({ width: w, height: h });
      await page.goto(`/t/${SLUG}`);
      await expect(page.getByTestId('storefront-header')).toBeVisible();
      expect(await overflow(page), `horizontal overflow at ${w}px`).toBeLessThanOrEqual(1);
    }
  });

  test('the public catalogue page reuses the same cards without overflow', async ({ page }) => {
    for (const w of [360, 768, 1366]) {
      await page.setViewportSize({ width: w, height: 900 });
      await page.goto(`/t/${SLUG}/courses`);
      await expect(page.getByRole('heading', { name: /جميع الكورسات/ })).toBeVisible();
      expect(await overflow(page), `catalogue overflow at ${w}px`).toBeLessThanOrEqual(1);
    }
  });
});

const STUDENT_EMAIL = process.env.E2E_STUDENT_EMAIL;
const STUDENT_PASSWORD = process.env.E2E_STUDENT_PASSWORD;
const studentConfigured = Boolean(STUDENT_EMAIL && STUDENT_PASSWORD);

test.describe('Student storefront — signed-in student', () => {
  test.skip(!configured || !studentConfigured, 'set E2E_TENANT_SLUG + E2E_STUDENT_* to run');

  test('an enrolled student sees continue-learning (not enroll) and no dashboard link', async ({
    page,
    context,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/student/login?tenant_slug=${SLUG}`);
    await page.fill('#email', STUDENT_EMAIL!);
    await page.fill('#password', STUDENT_PASSWORD!);
    await page.click('button[type=submit]');
    await page.waitForURL('**/my-learning', { timeout: 30_000 });

    // The session travels with the public storefront too.
    await page.goto(`/t/${SLUG}`);
    await expect(page.getByTestId('storefront-header')).toBeVisible();

    // The API's real enrollments drive the card action.
    const api = await page.evaluate(async () => {
      const r = await fetch('/api/v1/my-courses', { headers: { Accept: 'application/json' } });
      return r.json();
    });
    const enrolled = (Array.isArray(api?.enrollments) ? api.enrollments : []).map((e: any) =>
      String(e.course_id),
    );

    if (enrolled.length > 0) {
      const card = page.getByTestId('course-card').first();
      await expect(card.getByRole('link', { name: /تابع التعلم/ })).toHaveAttribute(
        'href',
        new RegExp(`/course/${enrolled[0]}/learn$`),
      );
      await expect(card.getByRole('link', { name: /التفاصيل والتسجيل/ })).toHaveCount(0);
    }

    // A visitor/student must never be offered the teacher dashboard.
    expect(await page.getByRole('link', { name: /لوحة التحكم|لوحة المعلم/ }).count()).toBe(0);
    await context.close();
  });
});
