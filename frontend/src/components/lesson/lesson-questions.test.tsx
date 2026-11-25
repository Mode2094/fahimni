import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import LessonQuestions from './lesson-questions';
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
    if (url.includes('/questions') && (init?.method === 'POST')) return json({ question: { id: 'q9' } });
    if (url.includes('/questions')) return json({ questions: [{ id: 'q1', body: 'كيف أحل؟', answer: 'بهذه الطريقة', mine: true }] });
    return json({});
  }) as any;
});

describe('LessonQuestions', () => {
  it('renders the teacher answer for the lesson Q&A', async () => {
    render(<LessonQuestions lessonId="l1" />);
    expect(await screen.findByText('كيف أحل؟')).toBeInTheDocument();
    expect(screen.getByText('بهذه الطريقة')).toBeInTheDocument();
  });

  it('posts a new question to the lesson', async () => {
    render(<LessonQuestions lessonId="l1" />);
    await screen.findByText('كيف أحل؟');

    await userEvent.type(screen.getByLabelText('نص السؤال'), 'سؤال جديد');
    await userEvent.click(screen.getByRole('button', { name: 'إرسال' }));

    const post = calls.find((c) => c.method === 'POST' && c.url.includes('/lessons/l1/questions'));
    expect(post).toBeTruthy();
    expect(post!.body).toContain('سؤال جديد');
  });

  it('does not delete a question until the confirmation is accepted', async () => {
    // No <ConfirmDialogHost> is mounted here, so confirmDialog falls back to the
    // native window.confirm, which jsdom does not confirm (returns falsy).
    global.fetch = vi.fn((input: any, init?: any) => {
      const url = String(input);
      calls.push({ url, method: init?.method || 'GET', body: init?.body || '' });
      if (url.includes('/questions')) return json({ questions: [{ id: 'q1', body: 'سؤالي', mine: true, answered_at: null }] });
      return json({});
    }) as any;

    render(<LessonQuestions lessonId="l1" />);
    await screen.findByText('سؤالي');

    await userEvent.click(screen.getByRole('button', { name: 'حذف' }));

    expect(calls.some((c) => c.method === 'DELETE')).toBe(false);
  });
});
