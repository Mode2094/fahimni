import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ExamEditor } from './exam-editor';

/**
 * The unified exam editor is the single place a teacher manages one exam. These
 * tests lock in the behaviours the rework is about:
 *  - questions are read from the `exams[]` pivot link (the old code read a
 *    non-existent `exam_id` and always showed zero questions);
 *  - a saved question can be attached from the picker (with search/filter);
 *  - removing a question DETACHES it from the exam (never deletes the question);
 *  - ordering persists through the reorder endpoint;
 *  - settings are saved through the exam update endpoint.
 */
const exam = {
  id: 'exam-1', course_id: 'course-1', section_id: 'sec-1', title: 'اختبار الوحدة',
  description: '', duration_minutes: 30, pass_mark_percent: 50, max_attempts: 3,
  shuffle_questions: true, is_published: false,
};

const questions = [
  { id: 'q1', question_text: 'سؤال الكورس الأول', question_type: 'multiple_choice', points: 1, options: [], metadata: {}, created_at: '2026-01-01', exams: [{ id: 'exam-1', title: 'اختبار الوحدة', sort_order: 0 }] },
  { id: 'q2', question_text: 'سؤال الكورس الثاني', question_type: 'true_false', points: 2, options: [], metadata: {}, created_at: '2026-01-02', exams: [{ id: 'exam-1', title: 'اختبار الوحدة', sort_order: 1 }] },
  // Saved in the course bank but attached to a different exam.
  { id: 'q3', question_text: 'سؤال محفوظ بلا اختبار', question_type: 'interactive_html', points: 5, options: [], metadata: {}, created_at: '2026-01-03', exams: [] },
];

let calls: { url: string; method: string; body: any }[] = [];

function mockFetch() {
  calls = [];
  global.fetch = vi.fn(async (input: any, init: any = {}) => {
    const url = String(input);
    const method = (init.method || 'GET').toUpperCase();
    calls.push({ url, method, body: init.body ? JSON.parse(init.body) : null });
    const ok = (json: any) => ({ ok: true, status: 200, json: async () => json, headers: new Map() } as any);
    if (url.includes('/questions/reorder')) return ok({ message: 'reordered' });
    if (url.includes('/add-questions')) return ok({ message: 'added', exam });
    if (url.includes('/draw-questions')) return ok({ message: 'drawn', added_count: 2, exam });
    if (method === 'DELETE') return ok({ message: 'ok' });
    if (method === 'PUT') return ok({ exam });
    return ok({});
  }) as any;
}

const onClose = vi.fn();
const onChanged = vi.fn();

beforeEach(() => {
  mockFetch();
  vi.stubGlobal('confirm', vi.fn(() => true));
  onClose.mockClear();
  onChanged.mockClear();
});

afterEach(() => {
  vi.restoreAllMocks();
});

function renderEditor() {
  return render(
    <ExamEditor
      exam={exam}
      sections={[{ id: 'sec-1', title: 'الوحدة الأولى' }]}
      questions={questions}
      onClose={onClose}
      onChanged={onChanged}
    />,
  );
}

describe('ExamEditor', () => {
  it('lists the questions attached to the exam via the exams[] pivot link', () => {
    renderEditor();
    expect(screen.getByText('سؤال الكورس الأول')).toBeTruthy();
    expect(screen.getByText('سؤال الكورس الثاني')).toBeTruthy();
    // A question attached to another exam / no exam must not appear here.
    expect(screen.queryByText('سؤال محفوظ بلا اختبار')).toBeNull();
  });

  it('removes a question from the exam (detach) instead of deleting it', async () => {
    renderEditor();
    fireEvent.click(screen.getAllByLabelText('إزالة من الاختبار')[0]);

    await waitFor(() => expect(onChanged).toHaveBeenCalled());
    const remove = calls.find((c) => c.method === 'DELETE');
    expect(remove?.url).toBe('/api/v1/exams/exam-1/questions/q1');
    // Never the destructive question delete.
    expect(calls.some((c) => c.method === 'DELETE' && /\/questions\/q1$/.test(c.url) && !c.url.includes('/exams/'))).toBe(false);
  });

  it('persists ordering through the reorder endpoint', async () => {
    renderEditor();
    // First row (q1) moved down → [q2, q1].
    fireEvent.click(screen.getAllByLabelText('أسفل')[0]);

    await waitFor(() => expect(onChanged).toHaveBeenCalled());
    const reorder = calls.find((c) => c.url.includes('/questions/reorder'));
    expect(reorder?.method).toBe('POST');
    expect(reorder?.body).toEqual({ question_ids: ['q2', 'q1'] });
  });

  it('attaches a saved question from the picker (with search)', async () => {
    renderEditor();
    fireEvent.click(screen.getByText('اختيار سؤال محفوظ'));

    // The bank question is offered…
    expect(screen.getByText('سؤال محفوظ بلا اختبار')).toBeTruthy();
    // …and the ones already in the exam show as "Added".
    expect(screen.getAllByText('مضاف').length).toBe(2);

    // Search narrows the picker to the bank question (the attached ones show
    // no "Added" badge anymore because they are filtered out).
    fireEvent.change(screen.getByPlaceholderText('بحث في أسئلة الكورس...'), { target: { value: 'محفوظ بلا' } });
    expect(screen.queryAllByText('مضاف').length).toBe(0);

    fireEvent.click(screen.getByText('إضافة'));
    await waitFor(() => expect(onChanged).toHaveBeenCalled());
    const add = calls.find((c) => c.url.includes('/add-questions'));
    expect(add?.body).toEqual({ questions: [{ question_id: 'q3' }] });
  });

  it('saves settings through the exam update endpoint', async () => {
    renderEditor();
    fireEvent.click(screen.getByText('الإعدادات'));
    fireEvent.click(screen.getByText('حفظ الإعدادات'));

    await waitFor(() => expect(onChanged).toHaveBeenCalled());
    const put = calls.find((c) => c.method === 'PUT' && c.url.endsWith('/exams/exam-1'));
    expect(put?.body.title).toBe('اختبار الوحدة');
    expect(put?.body.is_published).toBe(false);
  });

  it('persists option shuffling and a random per-attempt question count', async () => {
    renderEditor();
    fireEvent.click(screen.getByText('الإعدادات'));

    fireEvent.change(screen.getByPlaceholderText('0'), { target: { value: '4' } });
    fireEvent.click(screen.getByLabelText('خلط ترتيب الخيارات داخل كل سؤال'));
    fireEvent.click(screen.getByText('حفظ الإعدادات'));

    await waitFor(() => expect(onChanged).toHaveBeenCalled());
    const put = calls.find((c) => c.method === 'PUT' && c.url.endsWith('/exams/exam-1'));
    expect(put?.body.random_questions_count).toBe(4);
    expect(put?.body.shuffle_options).toBe(true);
  });

  it('draws a random set of the bank questions into the exam', async () => {
    renderEditor();
    fireEvent.click(screen.getByText('سحب عشوائي من البنك'));

    fireEvent.change(screen.getByDisplayValue('5'), { target: { value: '3' } });
    fireEvent.click(screen.getByText('اسحب وأضف'));

    await waitFor(() => expect(onChanged).toHaveBeenCalled());
    const draw = calls.find((c) => c.url.includes('/draw-questions'));
    expect(draw?.method).toBe('POST');
    expect(draw?.body.count).toBe(3);
  });

  it('renders inside the course content flow, never as a full-screen overlay', () => {
    // A regression guard: the editor used to be `fixed inset-0 z-50` and hid the
    // whole platform (sidebar included). It must stay in the normal flow.
    const { container } = renderEditor();
    const root = container.firstElementChild as HTMLElement;

    expect(root.className).not.toContain('fixed');
    expect(root.className).not.toContain('inset-0');
    expect(root.className).not.toContain('z-50');
    // The sidebar lives outside this subtree in PanelShell; with the editor in
    // normal flow it can no longer be covered.
    expect(root.className).toContain('space-y-4');
  });

  it('creates a new HTML question with its own type (custom_html) and sandbox payload', async () => {
    const { container } = renderEditor();
    fireEvent.click(screen.getByText('سؤال جديد'));

    // The HTML option is independent from the interactive one.
    fireEvent.click(await screen.findByText('سؤال HTML'));

    // Question text (first textarea) + the HTML/CSS/JS code area.
    fireEvent.change(container.querySelector('textarea') as HTMLTextAreaElement, { target: { value: 'اكتب مستند HTML' } });
    fireEvent.change(screen.getByPlaceholderText('الصق وسم HTML هنا…'), { target: { value: '<h1>مرحبا</h1>' } });

    fireEvent.click(screen.getByText('حفظ السؤال'));

    await waitFor(() => expect(onChanged).toHaveBeenCalled());
    const post = calls.find((c) => c.method === 'POST' && c.url.endsWith('/questions'));
    expect(post?.body.question_type).toBe('custom_html');
    expect(post?.body.exam_id).toBe('exam-1');
    expect(post?.body.metadata.interactive.html).toBe('<h1>مرحبا</h1>');
  });

  it('still creates an interactive question with its original type (interactive_html)', async () => {
    const { container } = renderEditor();
    fireEvent.click(screen.getByText('سؤال جديد'));
    fireEvent.click(await screen.findByText('سؤال تفاعلي'));

    fireEvent.change(container.querySelector('textarea') as HTMLTextAreaElement, { target: { value: 'عنصر تفاعلي' } });
    fireEvent.change(screen.getByPlaceholderText('الصق وسم HTML هنا…'), { target: { value: '<button>x</button>' } });

    fireEvent.click(screen.getByText('حفظ السؤال'));

    await waitFor(() => expect(onChanged).toHaveBeenCalled());
    const post = calls.find((c) => c.method === 'POST' && c.url.endsWith('/questions'));
    expect(post?.body.question_type).toBe('interactive_html');
    expect(post?.body.metadata.interactive.html).toBe('<button>x</button>');
  });
});
