import { expect, test, type Page } from '@playwright/test';

const TENANT_ID = 'teacher-e2e-tenant';
const COURSE_ID = 'course-e2e';
const SECTION_ID = 'section-e2e';
const LESSON_A = 'lesson-a';
const LESSON_B = 'lesson-b';

const teacher = {
  id: 'teacher-e2e',
  name: 'Teacher E2E',
  email: 'teacher.e2e@example.test',
  phone: '+962790000000',
  avatar_url: null,
  is_super_admin: false,
  is_active: true,
  locale: 'ar',
  email_verified_at: null,
  last_login_at: null,
  roles: ['Teacher'],
  permissions: ['media.manage'],
  created_at: '2026-11-24T00:00:00Z',
  tenants: [{
    id: TENANT_ID,
    name: 'Teacher E2E Workspace',
    slug: 'teacher-e2e',
    status: 'active',
    role: 'teacher',
  }],
};

function lesson(id: string, title: string, sort_order: number) {
  return {
    id,
    section_id: SECTION_ID,
    title,
    lesson_type: 'video',
    is_free_preview: false,
    sort_order,
    is_published: true,
    contents: [{ content_type: 'video' }],
    metadata: {},
  };
}

async function stubTeacherCourse(page: Page) {
  let order = [LESSON_A, LESSON_B];
  const savedRequests: Array<{ lesson_ids: string[]; expected_lesson_ids: string[] }> = [];

  await page.addInitScript(({ user, tenantId }) => {
    localStorage.setItem('user', JSON.stringify(user));
    localStorage.setItem('lms_authed', '1');
    localStorage.setItem('tenant_id', tenantId);
  }, { user: teacher, tenantId: TENANT_ID });

  await page.route('**/api/session/me', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ user: teacher }),
  }));

  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;

    if (path === '/api/v1/sections') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          sections: [{
            id: SECTION_ID,
            title: 'Unit 1',
            sort_order: 1,
            lessons: order.map((id, index) => lesson(id, id === LESSON_A ? 'Lesson A' : 'Lesson B', index + 1)),
          }],
        }),
      });
      return;
    }
    if (path === '/api/v1/exams') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ exams: [] }) });
      return;
    }
    if (path === '/api/v1/questions') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ questions: [] }) });
      return;
    }
    if (path === '/api/v1/assignments') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ assignments: [] }) });
      return;
    }
    if (path === `/api/v1/courses/${COURSE_ID}`) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ course: { id: COURSE_ID, title: 'Physics' } }),
      });
      return;
    }
    if (path === '/api/v1/lessons/reorder' && request.method() === 'POST') {
      const payload = request.postDataJSON();
      expect(request.headers()['x-tenant-id']).toBe(TENANT_ID);
      savedRequests.push(payload);
      if (payload.expected_lesson_ids.join(',') !== order.join(',')) {
        await route.fulfill({
          status: 409,
          contentType: 'application/json',
          body: JSON.stringify({ code: 'lesson_order_conflict', message: 'Stale order.' }),
        });
        return;
      }
      order = payload.lesson_ids;
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ message: 'Saved.' }) });
      return;
    }
    if (path === `/api/v1/lessons/${LESSON_A}/contents`) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          contents: [{
            id: 'video-content-a',
            content_type: 'video',
            title: 'Lesson A video',
            video: { provider: 'bunny', provider_video_id: 'old-video-guid', title: 'Old video', status: 'ready' },
          }],
        }),
      });
      return;
    }

    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({}) });
  });

  return {
    getOrder: () => order,
    getSavedRequests: () => savedRequests,
  };
}

test('desktop drag reorders lessons transactionally and the teacher can replace the video', async ({ page }) => {
  const api = await stubTeacherCourse(page);
  await page.goto(`/courses/${COURSE_ID}`);

  await expect(page.getByText('Lesson A')).toBeVisible();
  await expect(page.getByText('Lesson B')).toBeVisible();

  const desktopHandle = page.locator(`[data-lesson-drag-handle="${LESSON_A}"]`);
  const desktopTarget = page.locator(`[data-lesson-row="${LESSON_B}"]`);
  const desktopHandleBox = await desktopHandle.boundingBox();
  const desktopTargetBox = await desktopTarget.boundingBox();
  expect(desktopHandleBox).not.toBeNull();
  expect(desktopTargetBox).not.toBeNull();
  await page.mouse.move(
    desktopHandleBox!.x + desktopHandleBox!.width / 2,
    desktopHandleBox!.y + desktopHandleBox!.height / 2,
  );
  await page.mouse.down();
  await expect(page.locator(`[data-lesson-row="${LESSON_A}"]`)).toHaveClass(/opacity-50/);
  await page.mouse.move(
    desktopTargetBox!.x + desktopTargetBox!.width / 2,
    desktopTargetBox!.y + desktopTargetBox!.height / 2,
    { steps: 8 },
  );
  await page.mouse.up();
  await expect.poll(api.getOrder).toEqual([LESSON_B, LESSON_A]);
  expect(api.getSavedRequests()[0]).toEqual({
    section_id: SECTION_ID,
    lesson_ids: [LESSON_B, LESSON_A],
    expected_lesson_ids: [LESSON_A, LESSON_B],
  });

  await page.reload();
  await expect(page.locator('[data-lesson-row]')).toHaveCount(2);
  const rowIds = await page.locator('[data-lesson-row]').evaluateAll((rows) =>
    rows.map((row) => row.getAttribute('data-lesson-row')),
  );
  expect(rowIds).toEqual([LESSON_B, LESSON_A]);

  const lessonRow = page.locator(`[data-lesson-row="${LESSON_A}"]`);
  await lessonRow.getByRole('button', { name: /إدارة المحتوى|Manage content|إضافة محتوى|Add content/ }).click();
  await expect(page.getByRole('button', { name: /استبدال الفيديو|Replace video/ })).toBeVisible();
});

test.describe('touch ordering', () => {
  test.use({ isMobile: true, hasTouch: true, viewport: { width: 390, height: 844 } });

  test('a touch pointer drag reorders lessons and retains the keyboard/tap fallback', async ({ page }) => {
    const api = await stubTeacherCourse(page);
    await page.goto(`/courses/${COURSE_ID}`);
    await expect(page.locator(`[data-lesson-row="${LESSON_A}"]`)).toBeVisible();

    const handle = page.locator(`[data-lesson-drag-handle="${LESSON_B}"]`);
    const target = page.locator(`[data-lesson-row="${LESSON_A}"]`);
    const handleBox = await handle.boundingBox();
    const targetBox = await target.boundingBox();
    expect(handleBox).not.toBeNull();
    expect(targetBox).not.toBeNull();

    const startX = handleBox!.x + handleBox!.width / 2;
    const startY = handleBox!.y + handleBox!.height / 2;
    const targetX = targetBox!.x + targetBox!.width / 2;
    const targetY = targetBox!.y + targetBox!.height / 2;
    const pointer = { pointerId: 21, pointerType: 'touch', isPrimary: true, button: 0 };
    await handle.dispatchEvent('pointerdown', { ...pointer, clientX: startX, clientY: startY });
    await expect(page.locator(`[data-lesson-row="${LESSON_B}"]`)).toHaveClass(/opacity-50/);
    await target.dispatchEvent('pointermove', { ...pointer, clientX: targetX, clientY: targetY });
    await target.dispatchEvent('pointerup', { ...pointer, clientX: targetX, clientY: targetY });

    await expect.poll(api.getOrder).toEqual([LESSON_B, LESSON_A]);
    await expect(target.getByRole('button', { name: /تحريك الدرس لأعلى|Move lesson up/ })).toBeEnabled();
  });
});
