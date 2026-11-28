import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import MyNotesPage from './page';
import { useLocale } from '@/store/locale';
import { useAuth } from '@/store';
import { notesApi } from '@/services/endpoints';

vi.mock('@/services/endpoints', () => ({
  notesApi: { list: vi.fn(), summary: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn() },
}));

vi.mock('@/services/api', () => ({
  getApiErrorMessage: (_err: unknown, fallback: string) => fallback,
}));

const NOTE = {
  id: 'n1', type: 'note', body: 'قانون الجذب', position_seconds: null, duration_seconds: null,
  lesson_id: 'l1', lesson_title: 'الدرس الأول', course_id: 'c1', course_title: 'الفيزياء',
  video_id: null, has_position: false, position_valid: false, position_note: null,
  created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-05T00:00:00Z',
};

const BOOKMARK = {
  id: 'b1', type: 'bookmark', body: null, position_seconds: 90, duration_seconds: 600,
  lesson_id: 'l1', lesson_title: 'الدرس الأول', course_id: 'c1', course_title: 'الفيزياء',
  video_id: 'v1', has_position: true, position_valid: false, position_note: 'video_changed',
  created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-03T00:00:00Z',
};

const SUMMARY = [{
  course_id: 'c1', course_title: 'الفيزياء', notes_count: 2,
  lessons: [{ lesson_id: 'l1', lesson_title: 'الدرس الأول', notes_count: 2 }],
}];

beforeEach(() => {
  localStorage.clear();
  useLocale.setState({ locale: 'ar' });
  useAuth.setState({ user: { name: 'سارة' } as any } as any);
  (notesApi.list as any).mockResolvedValue({ data: [NOTE, BOOKMARK], meta: {} });
  (notesApi.summary as any).mockResolvedValue(SUMMARY);
  (notesApi.update as any).mockResolvedValue({ ...NOTE, body: 'محدّث' });
  (notesApi.remove as any).mockResolvedValue({ ok: true });
});

describe('My Notes page', () => {
  it('groups notes by course and lesson and links back to the lesson', async () => {
    render(<MyNotesPage />);

    expect(await screen.findByText('الفيزياء')).toBeInTheDocument();
    expect(screen.getByText('الدرس الأول')).toBeInTheDocument();
    expect(screen.getByText('قانون الجذب')).toBeInTheDocument();
    expect(screen.getByText('خاصة بك وحدك — لا يراها غيرك')).toBeInTheDocument();

    const open = screen.getAllByRole('link', { name: /افتح الدرس/ })[0] as HTMLAnchorElement;
    expect(open.getAttribute('href')).toBe('/course/c1/learn?lesson=l1');
  });

  it('flags a timestamp whose video was replaced', async () => {
    render(<MyNotesPage />);
    await screen.findByText('قانون الجذب');

    expect(screen.getByText(/استبدال/)).toBeInTheDocument();
  });

  it('searches server-side (debounced) when typing', async () => {
    const user = userEvent.setup();
    render(<MyNotesPage />);
    await screen.findByText('قانون الجذب');

    await user.type(screen.getByPlaceholderText(/ابحث في نص ملاحظاتك/), 'جذب');

    await waitFor(() => expect(notesApi.list).toHaveBeenCalledWith(expect.objectContaining({ q: 'جذب' })), { timeout: 2000 });
  });

  it('shows the empty state when the student has no notes', async () => {
    (notesApi.list as any).mockResolvedValue({ data: [], meta: {} });
    (notesApi.summary as any).mockResolvedValue([]);

    render(<MyNotesPage />);

    expect(await screen.findByText('لا توجد ملاحظات بعد')).toBeInTheDocument();
  });

  it('edits a note inline', async () => {
    const user = userEvent.setup();
    render(<MyNotesPage />);
    await screen.findByText('قانون الجذب');

    await user.click(screen.getAllByLabelText('تعديل الملاحظة')[0]);
    const box = screen.getByDisplayValue('قانون الجذب');
    await user.clear(box);
    await user.type(box, 'محدّث');
    await user.click(screen.getByRole('button', { name: /^حفظ$/ }));

    await waitFor(() => expect(notesApi.update).toHaveBeenCalledWith('n1', { body: 'محدّث' }));
  });

  it('deletes a note after confirmation and refreshes the summary', async () => {
    const user = userEvent.setup();
    vi.stubGlobal('confirm', vi.fn(() => true));
    render(<MyNotesPage />);
    await screen.findByText('قانون الجذب');

    await user.click(screen.getAllByLabelText('حذف الملاحظة')[0]);

    await waitFor(() => expect(notesApi.remove).toHaveBeenCalledWith('n1'));
    await waitFor(() => expect(notesApi.summary).toHaveBeenCalledTimes(2));
  });
});
