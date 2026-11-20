import { test, expect, type Page, type BrowserContext } from '@playwright/test';

/**
 * The AI assistant must live in the teacher sidebar — «اسألني عن المنصة» —
 * and NOT as a floating button/bubble that overlaps the navigation or the
 * logout control. This drives the real product: teacher login → sidebar item →
 * in-panel chat → ask a question → real answer.
 *
 * Skipped unless a teacher account is provided (CI has no backend).
 *
 *   E2E_TEACHER_EMAIL=... E2E_TEACHER_PASSWORD=... npx playwright test assistant-sidebar
 */

const EMAIL = process.env.E2E_TEACHER_EMAIL;
const PASSWORD = process.env.E2E_TEACHER_PASSWORD;
const configured = Boolean(EMAIL && PASSWORD);

async function login(page: Page) {
  await page.goto('/teacher/login');
  await page.fill('input[type=email]', EMAIL!);
  await page.fill('input[type=password]', PASSWORD!);
  await page.click('button[type=submit]');
  await page.waitForURL('**/teacher/dashboard', { timeout: 30_000 });
}

let session: Awaited<ReturnType<BrowserContext['storageState']>> | undefined;

test.describe('Assistant — sidebar entry (no floating button)', () => {
  test.skip(!configured, 'set E2E_TEACHER_EMAIL / E2E_TEACHER_PASSWORD to run');

  test.beforeAll(async ({ browser }) => {
    if (!configured) return;
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await login(page);
    session = await ctx.storageState();
    await ctx.close();
  });

  test('desktop: exactly one sidebar item opens the in-panel assistant and it answers', async ({ browser }, testInfo) => {
    const ctx = await browser.newContext({ storageState: session });
    const page = await ctx.newPage();
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/teacher/dashboard');
    await expect(page.getByTestId('stats-grid')).toBeVisible({ timeout: 20_000 });

    // The old floating assistant is completely gone.
    await expect(page.getByTestId('askme-fab')).toHaveCount(0);
    await expect(page.getByText('اسألني عن منصتك')).toHaveCount(0);

    // The sidebar exposes exactly one assistant entry.
    const nav = page.getByRole('navigation', { name: 'Main navigation' }).first();
    const item = nav.getByRole('link', { name: 'اسألني عن المنصة' });
    await expect(item).toHaveCount(1);
    await item.click();

    await page.waitForURL('**/teacher/assistant');
    // It renders INSIDE the teacher panel — the sidebar is still there.
    await expect(page.getByRole('navigation', { name: 'Main navigation' }).first()).toBeVisible();
    await expect(page.getByRole('main').getByRole('heading', { name: 'اسألني عن المنصة' })).toBeVisible();
    // Still no floating assistant on the assistant page itself.
    await expect(page.getByTestId('askme-fab')).toHaveCount(0);

    // Ask a real question → a real answer arrives.
    const botMessages = page.locator('[data-testid="assistant-msg"][data-from="bot"]');
    await expect(botMessages).toHaveCount(1); // welcome message

    const input = page.getByPlaceholder('اكتب سؤالك عن المنصة...');
    await input.fill('كيف أعمل كورس؟');
    await input.press('Enter');

    // The user's question is echoed and the assistant replies (no error text).
    await expect(page.locator('[data-testid="assistant-msg"][data-from="user"]')).toHaveCount(1);
    await expect(botMessages).toHaveCount(2, { timeout: 30_000 });
    const answer = (await botMessages.last().innerText()).trim();
    expect(answer.length).toBeGreaterThan(0);
    expect(answer).not.toContain('حدث خطأ أثناء معالجة سؤالك');
    await page.screenshot({ path: testInfo.outputPath('assistant-desktop.png') });

    // The floating bubble text never reappears.
    await expect(page.getByTestId('askme-fab')).toHaveCount(0);
    await expect(page.getByText('اسألني عن منصتك')).toHaveCount(0);

    await ctx.close();
  });

  test('phone: the assistant opens from the drawer without covering the nav/logout', async ({ browser }, testInfo) => {
    const ctx = await browser.newContext({ storageState: session });
    const page = await ctx.newPage();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/teacher/dashboard');
    await expect(page.getByTestId('stats-grid')).toBeVisible({ timeout: 20_000 });

    // No floating assistant on mobile either.
    await expect(page.getByTestId('askme-fab')).toHaveCount(0);

    // Open the drawer and use the assistant entry.
    await page.getByRole('button', { name: 'فتح القائمة' }).click();
    const drawer = page.getByRole('dialog', { name: 'قائمة التنقل' });
    await expect(drawer).toBeVisible();
    await drawer.getByRole('link', { name: 'اسألني عن المنصة' }).click();

    await page.waitForURL('**/teacher/assistant');
    await expect(page.getByRole('main').getByRole('heading', { name: 'اسألني عن المنصة' })).toBeVisible();
    // The drawer closed on navigation, so nothing covers the page controls.
    await expect(page.getByRole('button', { name: 'فتح القائمة' })).toBeVisible();
    await expect(page.getByTestId('askme-fab')).toHaveCount(0);

    // No horizontal overflow introduced by the assistant layout.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    await page.screenshot({ path: testInfo.outputPath('assistant-phone.png') });

    await ctx.close();
  });
});
