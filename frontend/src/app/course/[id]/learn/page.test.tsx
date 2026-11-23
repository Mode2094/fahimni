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
        { id: 'l1', title: 'درس 1', locked: false, lesson_type: 'text' },
        { id: 'l2', title: 'درس 2', locked: false, lesson_type: 'text' },
      ],
    },
  ],
  file_downloads_enabled: true,
};

function json(body: unknown) {
  return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) } as any);
}

beforeEach(() => {
  localStorage.clear();
  useLocale.setState({ locale: 'ar' });
  // jsdom does not implement smooth scrolling.
  window.scrollTo = vi.fn() as any;

  global.fetch = vi.fn((input: any) => {
    const url = String(input);
    if (url.includes('/sections')) return json(SECTIONS);
    if (url.includes('/lessons/')) {
      return json({ contents: [{ id: 'ct1', content_type: 'text', text: { body: 'محتوى الدرس' } }] });
    }
    return json({});
  }) as any;
});

describe('Course learn page — prev/next lesson navigation', () => {
  it('moves to the next lesson from the navigation bar', async () => {
    render(
      <Suspense fallback={<div>loading</div>}>
        <CourseLearnPage params={PARAMS} />
      </Suspense>,
    );

    // The first unlocked lesson becomes active.
    expect(await screen.findByRole('heading', { name: 'درس 1' })).toBeInTheDocument();

    const next = await screen.findByRole('button', { name: /التالي/ });
    await userEvent.click(next);

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'درس 2' })).toBeInTheDocument();
    });
  });
});
