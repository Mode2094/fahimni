import { Suspense } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';

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

function renderPage() {
  return render(
    <Suspense fallback={<div>loading</div>}>
      <CourseLearnPage params={PARAMS} />
    </Suspense>,
  );
}

describe('Course learn page — smoke', () => {
  it('renders the course title and its curriculum sidebar', async () => {
    renderPage();

    expect(await screen.findByRole('heading', { name: 'كورس الفيزياء' })).toBeInTheDocument();
    expect(await screen.findByText('الوحدة الأولى')).toBeInTheDocument();
  });
});
