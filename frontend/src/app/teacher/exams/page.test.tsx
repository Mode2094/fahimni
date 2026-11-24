import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import TeacherExamsPage from './page';
import { useLocale } from '@/store/locale';

/** A resolved fetch-like response. */
const ok = (data: unknown) => Promise.resolve({ ok: true, json: async () => data } as any);

function routeFetch(routes: Record<string, () => Promise<any>>) {
  return vi.fn((url: unknown) => {
    const u = String(url);
    const key = Object.keys(routes).find((k) => u.includes(k));
    return key ? routes[key]() : Promise.resolve({ ok: false, json: async () => ({}) } as any);
  });
}

const ATTEMPT = {
  id: 'a1',
  exam_id: 'e1',
  attempt_number: 2,
  status: 'submitted',
  needs_review: true,
  percentage: 40,
  passed: false,
  time_spent_seconds: 125, // 2:05
  submitted_at: '2026-11-20T10:00:00Z',
  manual_review_count: 1,
  student: { id: 'u1', name: 'سارة أحمد' },
  course: { id: 'c1', title: 'فيزياء' },
  section: { id: 's1', title: 'الوحدة الأولى' },
  skills: [
    { name: 'الكثافة', points_earned: 2, points_max: 5, accuracy: 40, level: 'needs_support' },
  ],
  answers: [
    {
      id: 'an1',
      is_correct: null,
      points_earned: 0,
      points_max: 5,
      given_answer: 'شرح الطالب هنا',
      question: { question_type: 'writing', question_text: 'اشرح قانون الكثافة' },
    },
  ],
};

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem('tenant_id', 't1');
  useLocale.setState({ locale: 'ar' });
});

describe('Teacher exams review page', () => {
  it('lists every attempt of the exam (not only the pending ones) with the real duration', async () => {
    vi.stubGlobal('fetch', routeFetch({
      '/exams/e1/attempts': () => ok({
        context: { course: { title: 'فيزياء' }, lesson: { title: 'درس الكثافة' } },
        attempts: [ATTEMPT],
      }),
      '/exams': () => ok({ exams: [{ id: 'e1', title: 'اختبار الوحدة' }] }),
    }));

    render(<TeacherExamsPage />);

    // The student's name and attempt number come through.
    expect(await screen.findByText('سارة أحمد')).toBeInTheDocument();
    expect(screen.getByText(/#2/)).toBeInTheDocument();
    // The actual duration (125s) is shown as 2:05, not the remaining time.
    expect(screen.getByText(/المدة الفعلية: 2:05/)).toBeInTheDocument();
    expect(screen.getByText('فيزياء · درس الكثافة')).toBeInTheDocument();
    expect(screen.getByText('بانتظار التصحيح')).toBeInTheDocument();
  });

  it('opens a review that renders the student answer and the skill performance', async () => {
    vi.stubGlobal('fetch', routeFetch({
      '/exams/e1/attempts': () => ok({ attempts: [ATTEMPT] }),
      '/exams': () => ok({ exams: [{ id: 'e1', title: 'اختبار الوحدة' }] }),
      '/exam-attempts/a1': () => ok({
        attempt: ATTEMPT,
        context: { course: { title: 'فيزياء' }, lesson: { title: 'درس الكثافة' } },
      }),
    }));

    render(<TeacherExamsPage />);
    await screen.findByText('سارة أحمد');

    await userEvent.click(screen.getByRole('button', { name: 'مراجعة' }));

    await waitFor(() => expect(screen.getByText('اشرح قانون الكثافة')).toBeInTheDocument());
    expect(screen.getByText('شرح الطالب هنا')).toBeInTheDocument();
    expect(screen.getByText(/درس الكثافة/)).toBeInTheDocument();
    // Skill performance derived from the real graded answers.
    expect(screen.getByText('الكثافة')).toBeInTheDocument();
    expect(screen.getAllByText(/40%/).length).toBeGreaterThan(0);
  });

  it('labels completed manual grading separately from automatic grading and shows result context', async () => {
    const autoAttempt = { ...ATTEMPT, needs_review: false, reviewed_at: null };
    vi.stubGlobal('fetch', routeFetch({
      '/exams/e1/attempts': () => ok({
        context: { course: { title: 'فيزياء' }, lesson: { title: 'درس الكثافة' } },
        attempts: [autoAttempt],
      }),
      '/exams': () => ok({ exams: [{ id: 'e1', title: 'تمرين الكثافة' }] }),
    }));

    render(<TeacherExamsPage />);

    expect(await screen.findByText('فيزياء · درس الكثافة')).toBeInTheDocument();
    expect(screen.getByText('تصحيح تلقائي')).toBeInTheDocument();
  });
});
