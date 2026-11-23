import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import TeacherQuestionBankPage from './page';

/**
 * The central question bank page: server-side search/filter, bulk selection
 * into an exam, and the guard that a question still used by an exam cannot be
 * deleted (which would strip student results).
 */
const bank = [
  { id: 'q1', question_text: 'سؤال متاح في البنك', question_type: 'multiple_choice', points: 1, difficulty: 'easy', category: 'فيزياء', course_id: 'c1', is_active: true, options: [], metadata: {}, exams: [] },
  { id: 'q2', question_text: 'سؤال مستخدم في اختبار', question_type: 'true_false', points: 2, difficulty: 'hard', category: '', course_id: '', is_active: true, options: [], metadata: {}, exams: [{ id: 'ex-1', title: 'اختبار', sort_order: 0 }] },
];

let calls: { url: string; method: string; body: any }[] = [];

function mockFetch() {
  calls = [];
  global.fetch = vi.fn(async (input: any, init: any = {}) => {
    const url = String(input);
    const method = (init.method || 'GET').toUpperCase();
    calls.push({ url, method, body: init.body ? JSON.parse(init.body) : null });
    const ok = (json: any) => ({ ok: true, status: 200, json: async () => json } as any);

    if (url.includes('/add-questions')) return ok({ message: 'added' });
    if (url.includes('/questions') && method === 'DELETE') return ok({ message: 'deleted' });
    if (url.includes('/questions')) {
      return ok({ questions: bank, pagination: { current_page: 1, last_page: 1, per_page: 12, total: bank.length }, categories: ['فيزياء'] });
    }
    if (url.includes('/courses')) return ok({ courses: [{ id: 'c1', title: 'كورس الفيزياء' }] });
    if (url.includes('/exams')) return ok({ exams: [{ id: 'ex-1', title: 'اختبار الوحدة' }] });
    if (url.includes('/sections')) return ok({ sections: [] });
    return ok({});
  }) as any;
}

beforeEach(() => {
  mockFetch();
  vi.stubGlobal('confirm', vi.fn(() => true));
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('TeacherQuestionBankPage', () => {
  it('lists the teacher\u2019s bank questions with their usage', async () => {
    render(<TeacherQuestionBankPage />);

    expect(await screen.findByText('سؤال متاح في البنك')).toBeTruthy();
    expect(screen.getByText('سؤال مستخدم في اختبار')).toBeTruthy();
    // Usage count is shown per question.
    expect(screen.getByText(/مستخدم في 1/)).toBeTruthy();
  });

  it('sends the server-side search term after debounce', async () => {
    render(<TeacherQuestionBankPage />);
    await screen.findByText('سؤال متاح في البنك');

    fireEvent.change(screen.getByPlaceholderText('ابحث في نص السؤال أو التصنيف...'), { target: { value: 'فيزياء' } });

    await waitFor(() => {
      expect(calls.some((c) => c.method === 'GET' && c.url.includes('/questions?') && c.url.includes('q='))).toBe(true);
    });
  });

  it('bulk-adds selected questions to a chosen exam', async () => {
    render(<TeacherQuestionBankPage />);
    await screen.findByText('سؤال متاح في البنك');

    // Row checkboxes: the first is the "select this page" toggle.
    fireEvent.click(screen.getAllByRole('checkbox')[1]);

    const picker = screen.getByDisplayValue('اختر اختباراً...') as HTMLSelectElement;
    fireEvent.change(picker, { target: { value: 'ex-1' } });

    fireEvent.click(screen.getByText('أضف إلى الاختبار'));

    await waitFor(() => {
      const add = calls.find((c) => c.url.includes('/exams/ex-1/add-questions'));
      expect(add?.method).toBe('POST');
      expect(add?.body).toEqual({ questions: [{ question_id: 'q1' }] });
    });
  });

  it('refuses to delete a question that an exam still uses', async () => {
    render(<TeacherQuestionBankPage />);
    await screen.findByText('سؤال مستخدم في اختبار');

    // The second row's delete button belongs to the exam-linked question.
    const deleteButtons = screen.getAllByLabelText('حذف');
    fireEvent.click(deleteButtons[1]);

    await new Promise((r) => setTimeout(r, 50));
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false);
    expect(window.confirm).not.toHaveBeenCalled();
  });
});
