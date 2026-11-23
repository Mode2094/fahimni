import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { DuplicateDialog } from './duplicate-dialog';
import { useLocale } from '@/store/locale';
import { toast } from 'sonner';

const ok = (data: unknown) => Promise.resolve({ ok: true, json: async () => data } as any);

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem('tenant_id', 't1');
  useLocale.setState({ locale: 'ar' });
});

/**
 * «إنشاء نسخة» يعرض النتيجة بوضوح: ما نُسخ، وما أُعيد استخدامه (بلا نسخ
 * تخزين إضافي) — ويستدعي نقطة النهاية الصحيحة حسب نوع المحتوى.
 */
describe('DuplicateDialog', () => {
  it('pre-fills a copy name and posts to the course duplicate endpoint', async () => {
    const fetchMock = vi.fn((_url: unknown) => ok({
      course: { id: 'new-course' },
      report: { sections: 1, lessons: 2, contents: 3, videos_reused: 1, files_reused: 0, exams: 0, questions_reused: 0, assignments: 0, skipped: [] },
    }));
    vi.stubGlobal('fetch', fetchMock);

    const onDuplicated = vi.fn();
    render(
      <DuplicateDialog open onOpenChange={() => {}} kind="course" id="c1" originalTitle="رياضيات" onDuplicated={onDuplicated} />,
    );

    const input = screen.getByRole('textbox') as HTMLInputElement;
    expect(input.value).toBe('رياضيات — نسخة');

    await userEvent.click(screen.getByRole('button', { name: /إنشاء النسخة/ }));

    await waitFor(() => expect(onDuplicated).toHaveBeenCalledWith('new-course'));
    expect(String(fetchMock.mock.calls[0][0])).toContain('/courses/c1/duplicate');

    // نتيجة واضحة للعملية (لا رسالة غامضة).
    expect(await screen.findByText(/New copy|نسخة جديدة/)).toBeInTheDocument();
    expect(screen.getByText(/أُعيد استخدام: 1 فيديو/)).toBeInTheDocument();
    expect(toast.success).toHaveBeenCalled();
  });

  it('shows what was NOT copied when the backend skips an item', async () => {
    vi.stubGlobal('fetch', vi.fn(() => ok({
      lesson: { id: 'l2' },
      report: { sections: 0, lessons: 1, contents: 0, videos_reused: 0, files_reused: 0, exams: 0, questions_reused: 0, assignments: 0, skipped: [{ type: 'live', title: 'حصة مباشرة' }] },
    })));

    render(<DuplicateDialog open onOpenChange={() => {}} kind="lesson" id="l1" originalTitle="درس" />);
    await userEvent.click(screen.getByRole('button', { name: /إنشاء النسخة/ }));

    expect(await screen.findByText(/لم يُنسخ/)).toBeInTheDocument();
    expect(screen.getByText(/البث المباشر: حصة مباشرة/)).toBeInTheDocument();
    expect(screen.getByText(/بعض العناصر لم تُنسخ/)).toBeInTheDocument();
  });

  it('surfaces the backend quota message instead of faking success', async () => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
      ok: false,
      json: async () => ({ message: 'لقد وصلت إلى الحد الأقصى من الكورسات' }),
    } as any)));

    render(<DuplicateDialog open onOpenChange={() => {}} kind="exam" id="e1" originalTitle="اختبار" />);
    await userEvent.click(screen.getByRole('button', { name: /إنشاء النسخة/ }));

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('لقد وصلت إلى الحد الأقصى من الكورسات'));
    expect(screen.queryByText(/New copy|نسخة جديدة/)).not.toBeInTheDocument();
  });
});
