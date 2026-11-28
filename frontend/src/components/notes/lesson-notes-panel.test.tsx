import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import LessonNotesPanel from './lesson-notes-panel';
import { useLocale } from '@/store/locale';
import { toast } from 'sonner';
import { notesApi } from '@/services/endpoints';

vi.mock('@/services/endpoints', () => ({
  notesApi: { list: vi.fn(), summary: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn() },
}));

vi.mock('@/services/api', () => ({
  getApiErrorMessage: (_err: unknown, fallback: string) => fallback,
}));

const NOTE = {
  id: 'n1', type: 'note', body: 'راجع القانون', position_seconds: null, duration_seconds: null,
  lesson_id: 'l1', lesson_title: 'Intro', course_id: 'c1', course_title: 'Algebra',
  video_id: null, has_position: false, position_valid: false, position_note: null,
  created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-02T00:00:00Z',
};

const BOOKMARK = {
  id: 'b1', type: 'bookmark', body: null, position_seconds: 142, duration_seconds: 600,
  lesson_id: 'l1', lesson_title: 'Intro', course_id: 'c1', course_title: 'Algebra',
  video_id: 'v1', has_position: true, position_valid: true, position_note: null,
  created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-03T00:00:00Z',
};

function renderPanel(over: Partial<React.ComponentProps<typeof LessonNotesPanel>> = {}) {
  const props = {
    lessonId: 'l1',
    videoId: 'v1',
    canUsePlayer: true,
    getCurrentTime: () => 142.9,
    getDuration: () => 600,
    onSeek: vi.fn(),
    ...over,
  };
  return { ...render(<LessonNotesPanel {...props} />), props };
}

beforeEach(() => {
  useLocale.setState({ locale: 'ar' });
  (notesApi.list as any).mockResolvedValue({ data: [NOTE, BOOKMARK], meta: {} });
  (notesApi.create as any).mockResolvedValue({ ...NOTE, id: 'new' });
  (notesApi.update as any).mockResolvedValue({ ...NOTE, body: 'محدّث' });
  (notesApi.remove as any).mockResolvedValue({ ok: true });
});

describe('LessonNotesPanel', () => {
  it('lists the student own notes and bookmarks with a private hint', async () => {
    renderPanel();

    expect(await screen.findByText('راجع القانون')).toBeInTheDocument();
    expect(screen.getByText('علامة مرجعية بدون تعليق')).toBeInTheDocument();
    expect(screen.getByText('خاصة بك وحدك')).toBeInTheDocument();
    // A bookmark shows its timestamp as a jump control.
    expect(screen.getByRole('button', { name: /2:22|142/ })).toBeInTheDocument();
  });

  it('jumps to the saved moment when the timestamp is clicked', async () => {
    const user = userEvent.setup();
    const { props } = renderPanel();
    await screen.findByText('راجع القانون');

    await user.click(screen.getByTitle('اذهب إلى هذه اللحظة'));

    expect(props.onSeek).toHaveBeenCalledWith(142);
  });

  it('saves the current moment as a bookmark with the video position', async () => {
    const user = userEvent.setup();
    renderPanel();
    await screen.findByText('راجع القانون');

    await user.click(screen.getByRole('button', { name: /احفظ هذه اللحظة/ }));

    await waitFor(() => expect(notesApi.create).toHaveBeenCalledWith(expect.objectContaining({
      lesson_id: 'l1', video_id: 'v1', type: 'bookmark', position_seconds: 142, duration_seconds: 600,
    })));
    expect(toast.success).toHaveBeenCalled();
  });

  it('saves a text note', async () => {
    const user = userEvent.setup();
    renderPanel();
    await screen.findByText('راجع القانون');

    await user.type(screen.getByPlaceholderText(/اكتب ملاحظة/), 'ملاحظة جديدة');
    await user.click(screen.getByRole('button', { name: /حفظ الملاحظة/ }));

    await waitFor(() => expect(notesApi.create).toHaveBeenCalledWith(expect.objectContaining({
      lesson_id: 'l1', type: 'note', body: 'ملاحظة جديدة', position_seconds: null,
    })));
  });

  it('edits an existing note', async () => {
    const user = userEvent.setup();
    renderPanel();
    await screen.findByText('راجع القانون');

    await user.click(screen.getAllByLabelText('تعديل الملاحظة')[0]);
    const box = screen.getByDisplayValue('راجع القانون');
    await user.clear(box);
    await user.type(box, 'نص محدّث');
    await user.click(screen.getByRole('button', { name: /^حفظ$/ }));

    await waitFor(() => expect(notesApi.update).toHaveBeenCalledWith('n1', { body: 'نص محدّث' }));
  });

  it('deletes a note after confirmation', async () => {
    const user = userEvent.setup();
    vi.stubGlobal('confirm', vi.fn(() => true));
    renderPanel();
    await screen.findByText('راجع القانون');

    await user.click(screen.getAllByLabelText('حذف الملاحظة')[0]);

    await waitFor(() => expect(notesApi.remove).toHaveBeenCalled());
  });

  it('renders nothing in teacher preview mode', () => {
    const { container } = renderPanel({ disabled: true });
    expect(container).toBeEmptyDOMElement();
    expect(notesApi.list).not.toHaveBeenCalled();
  });

  it('offers no bookmark control for a text-only lesson', async () => {
    renderPanel({ canUsePlayer: false, videoId: null });
    await screen.findByText('راجع القانون');

    expect(screen.queryByRole('button', { name: /احفظ هذه اللحظة/ })).toBeNull();
    // Writing a plain note is still available.
    expect(screen.getByRole('button', { name: /حفظ الملاحظة/ })).toBeInTheDocument();
  });
});
