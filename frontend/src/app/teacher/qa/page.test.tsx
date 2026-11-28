import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import TeacherQaPage from './page';
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
    if (url.includes('/answer')) return json({ question: { id: 'q1', answer: 'ردي' } });
    if (url.includes('/lesson-questions')) {
      return json({ questions: [{ id: 'q1', body: 'كيف أحل؟', student: { name: 'سارة' }, lesson: { title: 'الجبر' } }], unanswered_count: 1 });
    }
    return json({});
  }) as any;
});

describe('Teacher Q&A page', () => {
  it('lists student questions and sends an answer', async () => {
    render(<TeacherQaPage />);
    expect(await screen.findByText('كيف أحل؟')).toBeInTheDocument();
    expect(screen.getByText(/سارة/)).toBeInTheDocument();

    await userEvent.type(screen.getByLabelText('الرد'), 'ردي');
    await userEvent.click(screen.getByRole('button', { name: /إرسال الرد/ }));

    await waitFor(() => {
      const post = calls.find((c) => c.method === 'POST' && c.url.includes('/answer'));
      expect(post).toBeTruthy();
      expect(post!.body).toContain('ردي');
    });
  });
});
