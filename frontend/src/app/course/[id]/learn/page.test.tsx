import { Suspense } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import CourseLearnPage from './page';
import { useLocale } from '@/store/locale';

/**
 * A pre-resolved thenable. React's `use()` short-circuits when a thenable is
 * already marked `fulfilled`, so the page renders without a Suspense round-trip.
 */
const PARAMS = {
  status: 'fulfilled',
  value: { id: 'c1' },
  then: (onFulfilled: (v: { id: string }) => unknown) => Promise.resolve(onFulfilled({ id: 'c1' })),
} as unknown as Promise<{ id: string }>;

const SECTIONS = {
  course: { id: 'c1', title: 'كورس الفيزياء', prerequisites: null },
  sections: [
    {
      id: 's1',
      title: 'الوحدة الأولى',
      lessons: [
        { id: 'l1', title: 'درس 1', locked: false, lesson_type: 'text', completed: false },
        { id: 'l2', title: 'درس 2', locked: false, lesson_type: 'text', completed: true },
      ],
    },
  ],
  enrollment_id: 'e1',
  file_downloads_enabled: true,
};

function json(body: unknown) {
  return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) } as any);
}

let progressCalls: string[];

beforeEach(() => {
  localStorage.clear();
  useLocale.setState({ locale: 'ar' });
  window.scrollTo = vi.fn() as any;
  progressCalls = [];

  global.fetch = vi.fn((input: any, init?: any) => {
    const url = String(input);
    if (url.includes('/progress/')) {
      progressCalls.push(`${init?.method || 'GET'} ${url} ${init?.body || ''}`);
      return json({ progress: { id: 'p1', status: 'completed' } });
    }
    if (url.includes('/sections')) return json(SECTIONS);
    if (url.includes('/lessons/')) {
      return json({ contents: [{ id: 'ct1', content_type: 'text', text: { body: 'محتوى الدرس' } }] });
    }
    return json({});
  }) as any;
});

function renderPage() {
  return render(
    <Suspense fallback={<div>loading</div>}>
      <CourseLearnPage params={PARAMS} />
    </Suspense>,
  );
}

describe('Course learn page — navigation & progress', () => {
  it('moves to the next lesson from the navigation bar', async () => {
    renderPage();

    // The first unlocked lesson becomes active.
    expect(await screen.findByRole('heading', { name: 'درس 1' })).toBeInTheDocument();

    await userEvent.click(await screen.findByRole('button', { name: /التالي/ }));

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'درس 2' })).toBeInTheDocument();
    });
  });

  it('marks the current lesson complete and persists it for the enrollment', async () => {
    renderPage();
    await screen.findByRole('heading', { name: 'درس 1' });

    await userEvent.click(await screen.findByRole('button', { name: 'أكملت هذا الدرس' }));

    expect(await screen.findByText('تم إكمال الدرس')).toBeInTheDocument();
    expect(progressCalls.some((c) => c.includes('PUT') && c.includes('/progress/l1') && c.includes('completed'))).toBe(true);
  });

  it('shows the completion state, not the action, for an already-completed lesson', async () => {
    renderPage();
    await screen.findByRole('heading', { name: 'درس 1' });

    await userEvent.click(await screen.findByRole('button', { name: /التالي/ }));

    expect(await screen.findByRole('heading', { name: 'درس 2' })).toBeInTheDocument();
    expect(screen.getByText('تم إكمال الدرس')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'أكملت هذا الدرس' })).toBeNull();
  });
});
