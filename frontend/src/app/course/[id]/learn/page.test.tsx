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

describe('Course learn page — practice exercise card', () => {
  function mockLesson(exercise: Record<string, unknown>) {
    global.fetch = vi.fn((input: any) => {
      const url = String(input);
      if (url.includes('/sections')) return json(SECTIONS);
      if (url.includes('/lessons/')) {
        return json({ contents: [{ id: 'ct1', content_type: 'text', text: { body: 'محتوى الدرس' } }], exercise });
      }
      return json({});
    }) as any;
  }

  it('hides the pass mark on an ungraded practice card', async () => {
    mockLesson({
      id: 'ex1', exam_id: 'e1', title: 'تمرين HTML', questions_count: 3,
      duration_minutes: 0, pass_mark_percent: 0, attempts_used: 0, attempts_left: 3,
      assessment_mode: 'practice', position: 'after_content',
    });

    renderPage();

    expect(await screen.findByText('تمرين HTML')).toBeInTheDocument();
    expect(screen.getByText('غير مُقيَّم')).toBeInTheDocument();
    expect(screen.queryByText(/نجاح 0%/)).toBeNull();
  });

  it('keeps the pass mark on a normal graded exercise card', async () => {
    mockLesson({
      id: 'ex2', exam_id: 'e2', title: 'اختبار قصير', questions_count: 5,
      duration_minutes: 15, pass_mark_percent: 60, attempts_used: 0, attempts_left: 2,
      assessment_mode: 'graded', position: 'after_content',
    });

    renderPage();

    expect(await screen.findByText('اختبار قصير')).toBeInTheDocument();
    expect(screen.getByText(/نجاح 60%/)).toBeInTheDocument();
    expect(screen.queryByText('غير مُقيَّم')).toBeNull();
  });
});
