import { expect, test } from '@playwright/test';

const CARD_CODE = 'QR1234567890ABCD';
const TENANT_ID = 'tenant-student-workspace';
const COURSE_ID = 'course-student-workspace';
const LESSON_ID = 'lesson-student-workspace';

const student = {
  id: 'student-e2e',
  name: 'Sara Student',
  email: 'sara.e2e@example.test',
  phone: null,
  avatar_url: null,
  is_super_admin: false,
  is_active: true,
  locale: 'ar',
  email_verified_at: null,
  last_login_at: null,
  roles: [],
  permissions: [],
  created_at: '2026-11-24T00:00:00Z',
  tenants: [{
    id: TENANT_ID,
    name: 'Teacher Workspace',
    slug: 'teacher-one',
    status: 'active',
    role: 'student',
  }],
};

test('student can register from a QR card, redeem it, and load assignments with the teacher context', async ({ page }) => {
  let registered = false;
  let redeemed = false;
  let assignmentTenantHeader = '';

  await page.route('**/api/session/csrf', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/session/me', (route) => route.fulfill({
    status: registered ? 200 : 401,
    contentType: 'application/json',
    body: JSON.stringify(registered ? { user: student } : { message: 'Unauthenticated.' }),
  }));
  await page.route('**/api/v1/auth/captcha', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ enabled: false }),
  }));
  await page.route('**/api/session/register', async (route) => {
    const payload = route.request().postDataJSON();
    expect(payload.tenant_slug).toBe('teacher-one');
    registered = true;
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ user: student, tenant_id: TENANT_ID }),
    });
  });
  await page.route(`**/api/v1/public/cards/${CARD_CODE}`, (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      card: {
        code: CARD_CODE,
        status: 'generated',
        course: { id: COURSE_ID, title: 'Physics' },
        tenant: { id: TENANT_ID, slug: 'teacher-one', name: 'Teacher Workspace' },
      },
    }),
  }));
  await page.route('**/api/v1/cards/redeem', async (route) => {
    expect(route.request().headers()['x-tenant-id']).toBe(TENANT_ID);
    expect(route.request().postDataJSON().code).toBe(CARD_CODE);
    redeemed = true;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'Card redeemed successfully.' }),
    });
  });
  await page.route(`**/api/v1/public/courses/${COURSE_ID}/sections`, (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      course: { id: COURSE_ID, title: 'Physics' },
      sections: [{
        id: 'section-student-workspace',
        title: 'Unit 1',
        lessons: [{
          id: LESSON_ID,
          section_id: 'section-student-workspace',
          title: 'Lesson 1',
          lesson_type: 'video',
          duration_minutes: 4,
          is_free_preview: false,
          locked: false,
        }],
      }],
      is_enrolled: true,
      preview: false,
      drip: { enabled: false },
      file_downloads_enabled: true,
    }),
  }));
  await page.route(`**/api/v1/public/courses/${COURSE_ID}/lessons/${LESSON_ID}`, (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      course: { id: COURSE_ID, title: 'Physics' },
      lesson: { id: LESSON_ID, title: 'Lesson 1', enrolled: true },
      preview: false,
      contents: [],
      exercise: null,
    }),
  }));
  await page.route(`**/api/v1/assignments?course_id=${COURSE_ID}`, async (route) => {
    assignmentTenantHeader = route.request().headers()['x-tenant-id'] || '';
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ assignments: [] }),
    });
  });
  await page.route(`**/api/v1/exams?course_id=${COURSE_ID}`, (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ exams: [] }),
  }));

  await page.goto(`/card/${CARD_CODE}`);
  await expect(page.getByRole('link', { name: /إنشاء حساب|Sign up/ })).toBeVisible();
  await page.getByRole('link', { name: /إنشاء حساب|Sign up/ }).click();

  await page.locator('#sreg-name').fill(student.name);
  await page.locator('#sreg-email').fill(student.email);
  await page.locator('#sreg-password').fill('test-password-123');
  await page.locator('#sreg-confirm').fill('test-password-123');
  await page.getByRole('button', { name: /إنشاء حساب الطالب|Create Student Account/ }).click();

  await expect(page).toHaveURL(new RegExp(`/card/${CARD_CODE}$`));
  await expect(page.getByRole('heading', { name: 'تم تفعيل بطاقتك!' })).toBeVisible();
  expect(redeemed).toBe(true);

  await page.getByRole('link', { name: 'ابدأ التعلم الآن' }).click();
  await expect(page).toHaveURL(new RegExp(`/course/${COURSE_ID}/learn`));
  await page.getByRole('button', { name: 'الواجبات' }).click();
  await expect.poll(() => assignmentTenantHeader).toBe(TENANT_ID);
});
