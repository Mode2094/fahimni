import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import ProfilePage from './page';
import { useLocale } from '@/store/locale';

function json(body: unknown) {
  return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) } as any);
}

beforeEach(() => {
  localStorage.clear();
  useLocale.setState({ locale: 'ar' });
  global.fetch = vi.fn((input: any) => {
    const url = String(input);
    if (url.includes('/student/profile')) {
      return json({
        profile: {
          id: 'u1', name: 'سارة', email: 's@example.com',
          stats: {
            points: 120, rank: 2, courses_count: 3, courses_completed: 1,
            avg_progress: 40, lessons_completed: 12, exams_passed: 2, favorites_count: 0,
            streak: { current: 5, longest: 7, last_activity_date: '2026-11-24', active_today: true },
          },
          enrollments: [], favorites: [],
        },
      });
    }
    if (url.includes('/student/achievements')) return json({ achievements: [], unlocked_count: 0, total_count: 0 });
    if (url.includes('/student/activity')) return json({ activity: [] });
    if (url.includes('/student/points')) return json({ total_points: 120, history: [] });
    if (url.includes('/student/leaderboard')) return json({ leaderboard: [], my_rank: 2 });
    return json({});
  }) as any;
});

describe('Profile streak', () => {
  it('shows the daily streak badge and stat card', async () => {
    render(<ProfilePage />);

    expect(await screen.findByTestId('streak-badge')).toHaveTextContent('5');
    // stat card label is present too
    expect(screen.getAllByText(/أيام متتالية/).length).toBeGreaterThan(0);
  });
});
