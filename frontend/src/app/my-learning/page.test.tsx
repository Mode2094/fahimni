import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import MyLearningPage from './page';
import { useLocale } from '@/store/locale';
import { useAuth } from '@/store';
import { toast } from 'sonner';
import { enrollments, dashboardApi, favoritesApi, cardsApi, tenants } from '@/services/endpoints';

vi.mock('@/services/endpoints', () => ({
  enrollments: { myCourses: vi.fn() },
  dashboardApi: { studentLessons: vi.fn() },
  favoritesApi: { list: vi.fn(), toggle: vi.fn() },
  cardsApi: { redeem: vi.fn() },
  tenants: { list: vi.fn() },
}));

vi.mock('@/services/api', () => ({
  getApiErrorMessage: (_err: unknown, fallback: string) => fallback,
}));

const COURSES = [
  { course_id: 'c1', course: { title: 'كورس الفيزياء' }, progress_percent: 40, status: 'active' },
  { course_id: 'c2', course: { title: 'كورس الكيمياء' }, progress_percent: 100, status: 'completed' },
];

const LESSONS = [
  {
    instance_key: 'k1',
    title: 'درس اليوم',
    instance_date: '2099-12-01',
    start_time: '10:00:00',
    meeting_url: 'https://meet.google.com/abc-defg-hij',
    is_today: true,
  },
];

beforeEach(() => {
  localStorage.clear();
  useLocale.setState({ locale: 'ar' });
  useAuth.setState({ user: { name: 'سارة الطالبة' } as any } as any);
  (enrollments.myCourses as any).mockResolvedValue([]);
  (dashboardApi.studentLessons as any).mockResolvedValue([]);
  (favoritesApi.list as any).mockResolvedValue([]);
  (cardsApi.redeem as any).mockResolvedValue({});
  (tenants.list as any).mockResolvedValue([{ id: 't1' }]);
});

describe('Student dashboard (my-learning)', () => {
  it('shows course skeletons while the enrollments are loading', () => {
    (enrollments.myCourses as any).mockReturnValue(new Promise(() => {}));

    const { container } = render(<MyLearningPage />);

    expect(container.querySelectorAll('.animate-pulse').length).toBeGreaterThan(0);
    // The greeting/hero is not gated on loading, so it is present immediately.
    expect(screen.getByText('رحلتك التعليمية')).toBeInTheDocument();
  });

  it('renders the real enrollments, progress and scheduled lessons', async () => {
    (enrollments.myCourses as any).mockResolvedValue(COURSES);
    (dashboardApi.studentLessons as any).mockResolvedValue(LESSONS);
    (favoritesApi.list as any).mockResolvedValue([{ id: 'c1' }]);

    render(<MyLearningPage />);

    expect(await screen.findByText('كورس الفيزياء')).toBeInTheDocument();
    expect(screen.getByText('كورس الكيمياء')).toBeInTheDocument();
    expect(screen.getByText('40%')).toBeInTheDocument();
    expect(screen.getByText('دروسي المجدولة')).toBeInTheDocument();
    expect(screen.getByText('درس اليوم')).toBeInTheDocument();

    // The join button is a real, working link to the live room.
    const join = screen.getByRole('link', { name: /الانضمام للدرس/ });
    expect(join).toHaveAttribute('href', 'https://meet.google.com/abc-defg-hij');

    // Favourites are reflected from real data (c1 only).
    expect(document.querySelector('.fill-rose-500')).not.toBeNull();
  });

  it('renders the empty state when the student has no enrollments', async () => {
    render(<MyLearningPage />);

    expect(await screen.findByText('لم تسجل في أي كورس بعد')).toBeInTheDocument();
  });

  it('falls back to the empty state (never hangs) when the API fails', async () => {
    (enrollments.myCourses as any).mockRejectedValue(new Error('boom'));
    (dashboardApi.studentLessons as any).mockRejectedValue(new Error('boom'));
    (favoritesApi.list as any).mockRejectedValue(new Error('boom'));

    const { container } = render(<MyLearningPage />);

    expect(await screen.findByText('لم تسجل في أي كورس بعد')).toBeInTheDocument();
    await waitFor(() => expect(container.querySelectorAll('.animate-pulse').length).toBe(0));
  });

  it('redeems a registration card code and confirms success', async () => {
    const user = userEvent.setup();
    render(<MyLearningPage />);
    await screen.findByText('لم تسجل في أي كورس بعد');

    await user.type(screen.getByPlaceholderText('XXXX-XXXX-XXXX'), 'ABC-123');
    await user.click(screen.getByRole('button', { name: /تسجيل/ }));

    await waitFor(() => expect(cardsApi.redeem).toHaveBeenCalledWith('ABC-123'));
    expect(toast.success).toHaveBeenCalled();
    expect(await screen.findByText(/تم التسجيل بنجاح/)).toBeInTheDocument();
  });

  it('surfaces an error (and keeps the form) when redemption fails', async () => {
    (cardsApi.redeem as any).mockRejectedValue(new Error('invalid code'));
    const user = userEvent.setup();
    render(<MyLearningPage />);
    await screen.findByText('لم تسجل في أي كورس بعد');

    await user.type(screen.getByPlaceholderText('XXXX-XXXX-XXXX'), 'BAD-CODE');
    await user.click(screen.getByRole('button', { name: /تسجيل/ }));

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('فشل'));
    expect(screen.getByPlaceholderText('XXXX-XXXX-XXXX')).toBeInTheDocument();
  });

  it('uses English labels when the locale is English', async () => {
    useLocale.setState({ locale: 'en' });
    render(<MyLearningPage />);

    expect(await screen.findByText('My courses')).toBeInTheDocument();
    expect(screen.getByText('Your learning journey')).toBeInTheDocument();
    expect(screen.getByText('No enrollments yet')).toBeInTheDocument();
  });
});
