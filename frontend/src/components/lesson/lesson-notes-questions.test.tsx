import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import LessonNotesQuestions from './lesson-notes-questions';
import { useLocale } from '@/store/locale';

function json(body: unknown) {
  return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) } as any);
}

let calls: { url: string; method: string; body: string }[];

beforeEach(() => {
  localStorage.clear();
  useLocale.setState({ locale: 'ar' });
  calls = [];
  global.fetch = vi.fn((input: any, init?: any) => {
    const url = String(input);
    calls.push({ url, method: init?.method || 'GET', body: init?.body || '' });
    if (url.includes('/lesson-notes') && (init?.method === 'POST')) return json({ note: { id: 'n9' } });
    if (url.includes('/lesson-notes')) return json({ notes: [{ id: 'n1', body: 'نقطة مهمة', timestamp_seconds: 30, is_shared: false }] });
    if (url.includes('/questions')) return json({ questions: [{ id: 'q1', body: 'كيف أحل؟', answer: 'بهذه الطريقة', mine: true }] });
    return json({});
  }) as any;
});

describe('LessonNotesQuestions', () => {
  it('lists the student notes', async () => {
    render(<LessonNotesQuestions lessonId="l1" />);
    expect(await screen.findByText('نقطة مهمة')).toBeInTheDocument();
    expect(screen.getByText('0:30')).toBeInTheDocument();
  });

  it('pins a new note to the current video moment', async () => {
    render(<LessonNotesQuestions lessonId="l1" getTimestamp={() => 75} />);
    await screen.findByText('نقطة مهمة');

    await userEvent.type(screen.getByLabelText('نص الملاحظة'), 'ملاحظة جديدة');
    await userEvent.click(screen.getByLabelText(/ربط بلحظة الفيديو/));
    await userEvent.click(screen.getByRole('button', { name: 'حفظ' }));

    await waitFor(() => {
      const post = calls.find((c) => c.method === 'POST' && c.url.includes('/lessons/l1/notes'));
      expect(post).toBeTruthy();
      expect(post!.body).toContain('"timestamp_seconds":75');
      expect(post!.body).toContain('ملاحظة جديدة');
    });
  });

  it('shows the teacher answer in the Q&A tab', async () => {
    render(<LessonNotesQuestions lessonId="l1" />);
    await userEvent.click(screen.getByRole('button', { name: /اسأل المعلم/ }));
    expect(await screen.findByText('كيف أحل؟')).toBeInTheDocument();
    expect(screen.getByText('بهذه الطريقة')).toBeInTheDocument();
  });
});
