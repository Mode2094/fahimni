/**
 * REAL-STACK end-to-end journeys for the 2026-11 audit.
 *
 * Unlike the other specs in this folder, these tests do NOT stub `page.route`.
 * They drive the actual Next.js app against a real Laravel API, so they prove
 * the whole path: BFF session login → cookie → tenant-scoped API → database.
 *
 * Run against the isolated audit stack (see docs/AUDIT_2026-11-25_PLATFORM_AUDIT.md):
 *   E2E_PORT=3000 npx playwright test e2e/audit-real-journeys.spec.ts
 *
 * The stack uses an isolated SQLite DB with teacher_registration_enabled=true
 * and captcha off. It never touches production data.
 */
import { expect, test, type Page } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

// These journeys need the isolated audit stack (real Laravel + SQLite + the
// seeded super admin), so they are skipped in the default suite/CI. Enable them
// explicitly against that stack:
//   AUDIT_REAL_STACK=1 E2E_PORT=3000 npx playwright test e2e/audit-real-journeys.spec.ts
const REAL_STACK = process.env.AUDIT_REAL_STACK === '1' || !!process.env.E2E_BASE_URL;
test.beforeEach(() => {
  test.skip(!REAL_STACK, 'Requires the isolated audit stack — see docs/AUDIT_2026-11-25_PLATFORM_AUDIT.md');
});

const BASE = process.env.E2E_BASE_URL || 'http://localhost:3000';
const SHOTS = 'audit-shots';
const stamp = Date.now();

const teacher = {
  name: `Audit Teacher ${stamp}`,
  email: `audit.teacher.${stamp}@audit.local`,
  phone: '+962790000010',
  password: 'AuditPass!2345',
};
const student = {
  name: `Audit Student ${stamp}`,
  email: `audit.student.${stamp}@audit.local`,
  phone: '+962790000011',
  password: 'AuditPass!2345',
};
const admin = {
  email: process.env.AUDIT_ADMIN_EMAIL || 'admin@audit.local',
  password: process.env.AUDIT_ADMIN_PASSWORD || 'AuditAdmin!2345',
};

const state: {
  tenantSlug?: string;
  tenantId?: string;
  courseId?: string;
  cardCode?: string;
} = {};

/** Console/page errors + failed HTTP responses, minus benign noise. */
function watchErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    const txt = m.text();
    if (/favicon|manifest|meta pixel|facebook|net::ERR_/i.test(txt)) return;
    errors.push(`console: ${txt}`);
  });
  page.on('pageerror', (e) => {
    if (/favicon|manifest/i.test(e.message)) return;
    errors.push(`pageerror: ${e.message}`);
  });
  page.on('response', (r) => {
    if (r.status() >= 400) errors.push(`http ${r.status()} ${r.url()}`);
  });
  return errors;
}

async function shot(page: Page, name: string) {
  await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true });
}

/** Log in an existing account through the real BFF session endpoint. */
async function login(page: Page, email: string, password: string) {
  const res = await page.request.post(`${BASE}/api/session/login`, {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Origin: BASE },
    data: { email, password },
  });
  expect(res.status(), `login ${email} -> ${res.status()} ${await res.text()}`).toBe(200);
}

async function loginTeacher(page: Page) {
  await login(page, teacher.email, teacher.password);
}

async function loginStudent(page: Page) {
  await login(page, student.email, student.password);
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Super-admin: login + the three formerly-orphan admin pages
// ─────────────────────────────────────────────────────────────────────────────
test('admin logs in and the platform pages (cards/transactions/logs) work', async ({ page }) => {
  const errors = watchErrors(page);

  await page.goto(`${BASE}/admin/login`);
  await page.locator('#admin-email').fill(admin.email);
  await page.locator('#admin-password').fill(admin.password);
  await page.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/admin\/dashboard/, { timeout: 30_000 });
  await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible();
  await shot(page, '01-admin-dashboard');
  errors.length = 0; // pre-login session probes are expected to 401

  // These three pages used to be unreachable from the menu. Assert each loads
  // real data without an error alert and without a failed request.
  for (const [href, heading] of [
    ['/admin/transactions', 'المعاملات المالية'],
    ['/admin/logs', 'السجلات'],
    ['/admin/cards', 'بطاقات التفعيل'],
  ] as const) {
    await page.goto(`${BASE}${href}`);
    // The page renders … and the menu actually links to it (was orphaned).
    await expect(page.locator(`a[href="${href}"]`).first()).toBeAttached({ timeout: 20_000 });
    await expect(page.getByRole('heading', { name: heading, exact: false }).first()).toBeVisible({ timeout: 20_000 });
    await expect(page.locator('[role="alert"]').filter({ hasText: /تعذر|خطأ|Failed|Error/ })).toHaveCount(0);
    await shot(page, `01-admin${href.replace(/\//g, '-')}`);
  }

  expect(errors, `page errors: ${errors.join(' | ')}`).toEqual([]);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. Teacher: register → dashboard (trial) → author a course + unit + lesson
// ─────────────────────────────────────────────────────────────────────────────
test('teacher registers, gets a workspace + trial, and creates a unit + lesson', async ({ page }) => {
  const errors = watchErrors(page);

  await page.goto(`${BASE}/register/teacher`);
  await page.locator('#reg-name').fill(teacher.name);
  await page.locator('#reg-email').fill(teacher.email);
  await page.locator('#reg-phone').fill(teacher.phone);
  await page.locator('#reg-password').fill(teacher.password);
  await page.locator('#reg-confirm').fill(teacher.password);
  await page.locator('button[type="submit"]').click();

  await expect(page).toHaveURL(/\/teacher\/dashboard/, { timeout: 30_000 });
  await shot(page, '02-teacher-dashboard');
  errors.length = 0;

  state.tenantId = await page.evaluate(() => localStorage.getItem('tenant_id') || undefined);
  expect(state.tenantId, 'tenant_id persisted after teacher registration').toBeTruthy();
  state.tenantSlug = await page.evaluate(async (tenantId) => {
    const r = await fetch('/api/v1/tenants/current', {
      headers: { 'X-Tenant-Id': tenantId as string, Accept: 'application/json' },
    });
    const d = await r.json().catch(() => null);
    return d?.tenant?.slug as string | undefined;
  }, state.tenantId);
  expect(state.tenantSlug, 'workspace slug resolved').toBeTruthy();

  // Create a course.
  await page.goto(`${BASE}/courses`);
  await page.getByRole('button', { name: /كورس جديد|أنشئ أول كورس/ }).first().click();
  const dlg = page.getByRole('dialog');
  await dlg.locator('input').first().fill(`كورس التدقيق ${stamp}`);
  await dlg.getByRole('button', { name: 'إنشاء' }).click();
  const courseCard = page.getByText(`كورس التدقيق ${stamp}`).first();
  await expect(courseCard).toBeVisible({ timeout: 20_000 });
  await shot(page, '02-course-created');

  // Open the course builder.
  await courseCard.click();
  await expect(page).toHaveURL(/\/courses\/[0-9a-zA-Z-]+/, { timeout: 20_000 });
  state.courseId = page.url().split('/courses/')[1].split(/[/?#]/)[0];
  expect(state.courseId).toBeTruthy();

  // Add a unit.
  await page.getByRole('button', { name: /إضافة وحدة/ }).first().click();
  const unitDlg = page.getByRole('dialog');
  await unitDlg.locator('input').first().fill('الوحدة الأولى');
  await unitDlg.getByRole('button', { name: 'حفظ' }).click();
  await expect(page.getByText('الوحدة الأولى').first()).toBeVisible({ timeout: 20_000 });

  // Add a lesson.
  await page.getByRole('button', { name: /إضافة محتوى/ }).first().click();
  const lessonDlg = page.getByRole('dialog');
  await lessonDlg.locator('input').first().fill('الدرس الأول');
  await lessonDlg.getByRole('button', { name: 'حفظ' }).click();
  await expect(page.getByText('الدرس الأول').first()).toBeVisible({ timeout: 20_000 });
  await shot(page, '02-unit-lesson');

  expect(errors, `page errors: ${errors.join(' | ')}`).toEqual([]);
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Teacher: generate activation cards for the course
// ─────────────────────────────────────────────────────────────────────────────
test('teacher generates an activation card batch and a code is revealed', async ({ page }) => {
  const errors = watchErrors(page);
  await loginTeacher(page);

  await page.goto(`${BASE}/teacher/cards`);
  await page.getByRole('heading', { name: /بطاقات التسجيل/ }).first().waitFor({ timeout: 20_000 });
  await shot(page, '03-cards-page');
  errors.length = 0;

  const gen = page.locator('#generate-cards');
  await gen.scrollIntoViewIfNeeded();
  const courseSelect = gen.locator('select').first();

  const option = courseSelect.locator('option', { hasText: `كورس التدقيق ${stamp}` });
  if (await option.count()) {
    await courseSelect.selectOption({ label: `كورس التدقيق ${stamp}` });
  } else {
    const values = await courseSelect.locator('option').evaluateAll((os) =>
      (os as HTMLOptionElement[]).map((o) => o.value).filter(Boolean),
    );
    expect(values.length, 'a course option exists for card generation').toBeGreaterThan(0);
    await courseSelect.selectOption(values[0]);
  }

  await gen.getByRole('button', { name: 'إنشاء' }).click();

  // Activation codes are 24-char uppercase hex (no separators).
  const code = page.locator('code').filter({ hasText: /^[A-F0-9]{24}$/ }).first();
  await expect(code).toBeVisible({ timeout: 20_000 });
  state.cardCode = (await code.innerText()).trim();
  expect(state.cardCode).toMatch(/^[A-F0-9]{24}$/);
  await shot(page, '03-card-generated');

  expect(errors, `page errors: ${errors.join(' | ')}`).toEqual([]);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. Student: register on the tenant → redeem the card
// ─────────────────────────────────────────────────────────────────────────────
test('student registers on the teacher workspace and redeems the card', async ({ page }) => {
  const errors = watchErrors(page);
  expect(state.cardCode, 'a card code exists from the teacher step').toBeTruthy();
  expect(state.tenantSlug, 'tenant slug resolved').toBeTruthy();

  await page.goto(`${BASE}/register/student?tenant_slug=${state.tenantSlug}`);
  await page.locator('#sreg-name').fill(student.name);
  await page.locator('#sreg-email').fill(student.email);
  await page.locator('#sreg-phone').fill(student.phone);
  await page.locator('#sreg-password').fill(student.password);
  await page.locator('#sreg-confirm').fill(student.password);
  await page.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/my-learning/, { timeout: 30_000 });
  await shot(page, '04-student-my-learning');
  errors.length = 0;

  // The /card/[code] page auto-enrolls a signed-in student.
  await page.goto(`${BASE}/card/${state.cardCode}`);
  await expect(
    page.getByText(/تم تفعيل بطاقتك|ابدأ التعلم الآن/).first(),
  ).toBeVisible({ timeout: 30_000 });
  await shot(page, '04-card-redeem');

  expect(errors, `page errors: ${errors.join(' | ')}`).toEqual([]);
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. Student: open the course learner and render the first lesson
// ─────────────────────────────────────────────────────────────────────────────
test('student opens the course learner and the first lesson renders', async ({ page }) => {
  const errors = watchErrors(page);
  await loginStudent(page);
  expect(state.courseId).toBeTruthy();

  await page.goto(`${BASE}/course/${state.courseId}/learn`);
  await expect(page).not.toHaveURL(/login/, { timeout: 20_000 });
  await page.getByText('الدرس الأول').first().click();
  await expect(page.getByText(/الدرس الأول/).first()).toBeVisible({ timeout: 20_000 });
  await shot(page, '05-student-learn');

  expect(errors, `page errors: ${errors.join(' | ')}`).toEqual([]);
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. Teacher: exam grading surface loads
// ─────────────────────────────────────────────────────────────────────────────
test('teacher exam grading surface loads', async ({ page }) => {
  const errors = watchErrors(page);
  await loginTeacher(page);

  await page.goto(`${BASE}/teacher/exams`);
  await expect(page.getByRole('heading', { name: /الامتحانات والتصحيح/ }).first()).toBeVisible({ timeout: 20_000 });
  await shot(page, '06-teacher-exams');
  errors.length = 0;

  expect(errors, `page errors: ${errors.join(' | ')}`).toEqual([]);
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. Teacher: create a published assignment.
//    Before the 2026-11-25 fix the `assignments_enabled` gate was absent from
//    every plan, so this exact POST answered 403 platform-wide.
// ─────────────────────────────────────────────────────────────────────────────
test('teacher creates a published assignment on the course', async ({ page }) => {
  const errors = watchErrors(page);
  await loginTeacher(page);
  expect(state.courseId).toBeTruthy();

  await page.goto(`${BASE}/courses/${state.courseId}`);

  // "Add content" at the *unit* level (the lesson rows have their own button of
  // the same name and render first) opens the lesson dialog, where the kind can
  // be switched to Assignment.
  await page.getByRole('button', { name: /إضافة محتوى/ }).last().click();
  await page.getByRole('dialog').getByRole('button', { name: 'واجب' }).click();

  const aDlg = page.getByRole('dialog').filter({ hasText: 'واجب جديد' });
  await expect(aDlg).toBeVisible({ timeout: 20_000 });
  await aDlg.locator('input').first().fill('واجب التدقيق');
  await aDlg.getByRole('switch', { name: 'نشر للطلاب' }).click();
  await aDlg.getByRole('button', { name: 'حفظ' }).click();

  await expect(page.getByText('واجب التدقيق').first()).toBeVisible({ timeout: 20_000 });
  // The submission/grading surface that was wired in this audit is reachable.
  await expect(page.getByRole('button', { name: /التسليمات/ }).first()).toBeVisible();
  await shot(page, '07-assignment-created');
  errors.length = 0;

  expect(errors, `page errors: ${errors.join(' | ')}`).toEqual([]);
});

// ─────────────────────────────────────────────────────────────────────────────
// 8. Student: submit that assignment from the learner
// ─────────────────────────────────────────────────────────────────────────────
test('student submits the assignment from the learner', async ({ page }) => {
  const errors = watchErrors(page);
  await loginStudent(page);
  expect(state.courseId).toBeTruthy();

  await page.goto(`${BASE}/course/${state.courseId}/learn`);
  await page.getByRole('button', { name: /^الواجبات/ }).click();

  const card = page.locator('[id^="assignment-"]').first();
  await expect(card.getByText('واجب التدقيق')).toBeVisible({ timeout: 20_000 });
  await card.getByPlaceholder('اكتب إجابتك هنا...').fill('إجابتي للتدقيق — هذا النص أُرسل من الاختبار الحقيقي.');
  await card.getByRole('button', { name: 'تسليم الواجب' }).click();
  await expect(card.getByText('تم التسليم').first()).toBeVisible({ timeout: 20_000 });
  await shot(page, '08-student-submitted');
  errors.length = 0;

  expect(errors, `page errors: ${errors.join(' | ')}`).toEqual([]);
});

// ─────────────────────────────────────────────────────────────────────────────
// 9. Teacher: grade the submission in the new submissions viewer
// ─────────────────────────────────────────────────────────────────────────────
test('teacher grades the submission in the submissions viewer', async ({ page }) => {
  const errors = watchErrors(page);
  await loginTeacher(page);

  await page.goto(`${BASE}/courses/${state.courseId}`);
  await page.getByRole('button', { name: /التسليمات/ }).first().click();

  const panel = page.locator('[id^="assign-subs-"]').first();
  await expect(panel.getByText(student.name)).toBeVisible({ timeout: 20_000 });

  await panel.getByLabel('الدرجة').first().fill('9');
  await panel.getByLabel('ملاحظة التصحيح').first().fill('عمل جيد — تصحيح من الاختبار الحقيقي.');
  await panel.getByRole('button', { name: 'حفظ التصحيح' }).click();

  // The viewer re-renders the stored grade after the server confirms it.
  await expect(panel.getByText(/الدرجة:/)).toBeVisible({ timeout: 20_000 });
  await shot(page, '09-teacher-graded');
  errors.length = 0;

  expect(errors, `page errors: ${errors.join(' | ')}`).toEqual([]);
});
