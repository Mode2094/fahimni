import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/react';

import ResultsPage from './page';
import { useLocale } from '@/store/locale';

/**
 * The student results list must never turn an HTML/written submission that is
 * still with the teacher, or an ungraded practice, into a grade or a failure.
 * Assertions are scoped to the attempt card, since the page header carries its
 * own passed/average/best tiles.
 */
function mockAttempts(attempts: unknown[]) {
  (globalThis as any).fetch = vi.fn().mockResolvedValue({
    ok: true,
    status: 200,
    json: async () => ({ attempts }),
  });
}

function cardFor(title: string): HTMLElement | null {
  const node = screen.queryByText(title)?.closest('.shadow-sm');
  return (node as HTMLElement) ?? null;
}

async function awaitCardFor(title: string): Promise<HTMLElement> {
  await screen.findByText(title);
  const card = cardFor(title);
  if (!card) throw new Error(`card for ${title} not found`);
  return card;
}

beforeEach(() => {
  localStorage.clear();
  useLocale.setState({ locale: 'ar' });
});

describe('Results page — submitted HTML practice/attempts', () => {
  it('shows «تم التسليم» and no percentage for a submission awaiting the teacher', async () => {
    mockAttempts([
      {
        id: 'a1',
        exam_title: 'تمرين HTML',
        status: 'submitted',
        is_practice: false,
        needs_review: true,
        percentage: 0,
        score: 0,
        total_points: 10,
        passed: false,
        submitted_at: '2026-11-20T10:00:00Z',
      },
    ]);

    render(<ResultsPage />);

    const card = await awaitCardFor('تمرين HTML');
    expect(within(card).getAllByText('تم التسليم').length).toBeGreaterThan(0);
    expect(card).toHaveTextContent('تم استلام تسليمك');
    expect(card).not.toHaveTextContent('بانتظار مراجعة المعلم');
    expect(card).not.toHaveTextContent('راسب');
    // No grade of any kind leaks onto the card.
    expect(card).not.toHaveTextContent('%');
  });

  it('shows an ungraded practice as completed without pass/fail', async () => {
    mockAttempts([
      {
        id: 'a2',
        exam_title: 'تمرين السرعة',
        status: 'submitted',
        is_practice: true,
        needs_review: false,
        percentage: null,
        score: null,
        total_points: null,
        passed: null,
        submitted_at: '2026-11-20T10:00:00Z',
      },
    ]);

    render(<ResultsPage />);

    const card = await awaitCardFor('تمرين السرعة');
    expect(within(card).getAllByText('مكتمل').length).toBeGreaterThan(0);
    expect(card).not.toHaveTextContent('ناجح');
    expect(card).not.toHaveTextContent('راسب');
    expect(card).not.toHaveTextContent('%');
  });

  it('still shows the real grade for a normal graded exam', async () => {
    mockAttempts([
      {
        id: 'a3',
        exam_title: 'اختبار الوحدة',
        status: 'submitted',
        is_practice: false,
        needs_review: false,
        percentage: 80,
        score: 8,
        total_points: 10,
        passed: true,
        submitted_at: '2026-11-20T10:00:00Z',
      },
    ]);

    render(<ResultsPage />);

    const card = await awaitCardFor('اختبار الوحدة');
    expect(within(card).getAllByText('ناجح').length).toBeGreaterThan(0);
    expect(card).toHaveTextContent('80%');
    expect(card).toHaveTextContent('8 / 10');
  });
});
