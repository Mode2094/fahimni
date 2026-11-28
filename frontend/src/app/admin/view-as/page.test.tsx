import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AdminViewAsPage from './page';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';

function json(body: unknown) {
  return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) } as any);
}

let calls: { url: string; method: string }[];

beforeEach(() => {
  useLocale.setState({ locale: 'ar' });
  useAuth.setState({ user: { id: 'a1', is_super_admin: true } as any });
  calls = [];
  global.fetch = vi.fn((input: any, init?: any) => {
    const url = String(input);
    calls.push({ url, method: init?.method || 'GET' });
    if (url.includes('/admin/teachers')) return json({ teachers: [{ id: 't1', name: 'أستاذ علي', email: 'ali@example.com' }] });
    if (url.includes('/admin/students')) return json({ students: [{ id: 's1', name: 'سارة', email: 'sara@example.com' }] });
    if (url.includes('/admin/view-as/')) return json({ view_as: { active: true, read_only: true, user_id: 't1' } });
    return json({});
  }) as any;
});

describe('Admin view-as page', () => {
  it('lists teachers and students, and starts a preview', async () => {
    render(<AdminViewAsPage />);

    expect(await screen.findByText('أستاذ علي')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /الطلاب/ }));
    expect(await screen.findByText('سارة')).toBeInTheDocument();

    const previewButtons = screen.getAllByRole('button', { name: /معاينة/ });
    await userEvent.click(previewButtons[0]);

    await waitFor(() => {
      const post = calls.find((c) => c.method === 'POST' && c.url.includes('/admin/view-as/'));
      expect(post).toBeTruthy();
    });
  });
});
