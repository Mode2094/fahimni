import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import TeacherStudentFollowUpPage from './page';

/**
 * صفحة «متابعة الطلاب»: تعرض الطلاب ذوي الدلائل الحقيقية، ولا تفبرك أرقاماً،
 * وتتيح التصفية والترقيم وإظهار أسباب الاستحقاق ومصادر البيانات.
 */
const report = {
  data: [
    {
      id: 's1', name: 'ليلى', email: 'layla@x.com', avatar_url: null,
      last_activity_at: '2026-11-01T10:00:00+00:00', last_activity_source: 'login',
      last_activity_sources: ['login'], days_inactive: 9, data_available: true,
      status: 'inactive', at_risk: true, reasons: [{ code: 'inactive', days: 9 }],
      progress_percent: 12.5, courses_count: 2, courses_completed: 0, lessons_completed: 1,
      overdue_assignments: 0, overdue_exams: 0,
    },
    {
      id: 's2', name: 'سامي', email: null, avatar_url: null,
      last_activity_at: '2026-11-10T10:00:00+00:00', last_activity_source: 'exam_attempt',
      last_activity_sources: ['exam_attempt'], days_inactive: 0, data_available: true,
      status: 'overdue', at_risk: true, reasons: [{ code: 'overdue_assignments', count: 2 }],
      progress_percent: 55, courses_count: 1, courses_completed: 0, lessons_completed: 4,
      overdue_assignments: 2, overdue_exams: 0,
    },
  ],
  meta: {
    generated_at: '2026-11-23T18:00:00+00:00',
    thresholds: { inactive_days: 7, low_progress_percent: 30, min_enrolled_days: 3 },
    counts: { total: 2, at_risk: 2, active: 0, new: 0, inactive: 1, inactive_7: 1, inactive_14: 0, low_progress: 0, overdue: 1, no_activity_data: 0, never_signed_in: 0 },
    signals: { login: 'تسجيل الدخول' },
    courses: [{ id: 'c1', title: 'كورس الجبر' }],
    groups: [{ id: 'g1', name: 'مجموعة أ', course_id: 'c1' }],
    data_quality: { note: 'المؤشرات مبنية على سجلات حقيقية فقط.', sources: { last_login_at: 'users.last_login_at' }, missing_activity_is_not_inactivity: true },
    pagination: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
  },
};

let calls: string[] = [];

function mockFetch(payload: any = report) {
  calls = [];
  global.fetch = vi.fn(async (input: any) => {
    calls.push(String(input));
    return { ok: true, status: 200, json: async () => payload } as any;
  }) as any;
}

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem('lms_authed', '1');
  mockFetch();
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('TeacherStudentFollowUpPage', () => {
  it('lists students with their real reasons and status', async () => {
    render(<TeacherStudentFollowUpPage />);

    expect(await screen.findByText('ليلى')).toBeTruthy();
    expect(screen.getByText('سامي')).toBeTruthy();
    // سبب حقيقي بالرقم، وليس تصنيفاً غامضاً.
    expect(screen.getByText('متغيّب منذ 9 يوم')).toBeTruthy();
    expect(screen.getByText('2 واجب فات موعده')).toBeTruthy();
    // حالة كل طالب.
    const badges = screen.getAllByTestId('status-badge').map((b) => b.textContent);
    expect(badges).toContain('غير نشط');
    expect(badges).toContain('متأخر عن الاستحقاق');
  });

  it('shows the honest data-source note on demand', async () => {
    render(<TeacherStudentFollowUpPage />);
    await screen.findByText('ليلى');

    fireEvent.click(screen.getByText('مصادر البيانات'));

    expect(await screen.findByText('المؤشرات مبنية على سجلات حقيقية فقط.')).toBeTruthy();
    expect(screen.getByText('users.last_login_at')).toBeTruthy();
  });

  it('filters by status when a stat card is clicked', async () => {
    render(<TeacherStudentFollowUpPage />);
    await screen.findByText('ليلى');

    fireEvent.click(screen.getByText('غير نشط ٧ أيام'));

    await waitFor(() => {
      expect(calls.some((u) => u.includes('status=inactive') && u.includes('inactive_days=7'))).toBe(true);
    }, { timeout: 1500 });
  });

  it('links each student to their profile and email', async () => {
    render(<TeacherStudentFollowUpPage />);
    await screen.findByText('ليلى');

    const profile = screen.getAllByRole('link', { name: /الملف/ })[0] as HTMLAnchorElement;
    expect(profile.getAttribute('href')).toBe('/students?student=s1');
    expect(screen.getByTitle('مراسلة الطالب').getAttribute('href')).toBe('mailto:layla@x.com');
  });

  it('shows an empty state and never invents rows', async () => {
    mockFetch({
      data: [],
      meta: { ...report.meta, counts: { total: 0, at_risk: 0 }, pagination: { current_page: 1, per_page: 25, total: 0, last_page: 1 } },
    });
    render(<TeacherStudentFollowUpPage />);

    expect(await screen.findByText('لا يوجد من يحتاج متابعة الآن')).toBeTruthy();
  });

  it('paginates when there are more rows than one page', async () => {
    mockFetch({
      data: report.data,
      meta: { ...report.meta, pagination: { current_page: 1, per_page: 2, total: 60, last_page: 30 } },
    });
    render(<TeacherStudentFollowUpPage />);
    await screen.findByText('ليلى');

    expect(screen.getByText('صفحة 1 من 30')).toBeTruthy();
    fireEvent.click(screen.getByText('التالي'));
    await waitFor(() => {
      expect(calls.some((u) => u.includes('page=2'))).toBe(true);
    }, { timeout: 1500 });
  });
});
