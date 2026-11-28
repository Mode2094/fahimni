import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import TeacherBundlesPage from './page';
import { useLocale } from '@/store/locale';

const BUNDLES = {
  bundles: [
    { id: 'b1', title: 'حزمة الرياضيات', price: 79, currency: 'USD', is_published: true, courses_count: 2, courses: [{ id: 'c1', title: 'جبر' }] },
    { id: 'b2', title: 'مسودة', is_published: false, courses_count: 0, courses: [] },
  ],
};

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
    const method = init?.method || 'GET';
    calls.push({ url, method, body: init?.body || '' });
    if (url.includes('/bundles') && method === 'POST') return json({ bundle: { id: 'b3' } });
    if (url.includes('/bundles')) return json(BUNDLES);
    if (url.includes('/courses')) return json({ courses: [{ id: 'c1', title: 'جبر' }, { id: 'c2', title: 'هندسة' }] });
    if (url.includes('/teacher/students')) return json({ students: [{ id: 'u1', name: 'سارة' }] });
    return json({});
  }) as any;
});

describe('Teacher bundles page', () => {
  it('lists existing bundles', async () => {
    render(<TeacherBundlesPage />);
    expect(await screen.findByRole('heading', { name: 'حزمة الرياضيات' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'مسودة' })).toBeInTheDocument();
  });

  it('creates a bundle with the selected courses', async () => {
    render(<TeacherBundlesPage />);
    await screen.findByRole('heading', { name: 'حزمة الرياضيات' });

    await userEvent.click(screen.getByRole('button', { name: /حزمة جديدة/ }));
    await userEvent.type(screen.getByLabelText('العنوان'), 'حزمة جديدة');
    await userEvent.click(screen.getByLabelText('هندسة'));

    await userEvent.click(screen.getByRole('button', { name: 'حفظ' }));

    await waitFor(() => {
      const post = calls.find((c) => c.method === 'POST' && c.url.endsWith('/bundles'));
      expect(post).toBeTruthy();
      expect(post!.body).toContain('"c2"');
    });
  });
});
