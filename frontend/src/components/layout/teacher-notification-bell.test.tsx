import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

import { TeacherNotificationBell } from './teacher-notification-bell';
import { useLocale } from '@/store/locale';
import { teacherNotificationsApi } from '@/services/endpoints';

const push = vi.fn();
vi.mock('next/navigation', () => ({
  useRouter: () => ({ push }),
  usePathname: () => '/teacher/dashboard',
}));

vi.mock('@/services/endpoints', () => ({
  teacherNotificationsApi: {
    list: vi.fn(),
    unreadCount: vi.fn(),
    get: vi.fn(),
    markRead: vi.fn(),
    markAllRead: vi.fn(),
    contact: vi.fn(),
  },
}));

beforeEach(() => {
  useLocale.setState({ locale: 'ar' });
  vi.clearAllMocks();
  (teacherNotificationsApi.unreadCount as any).mockResolvedValue(2);
  (teacherNotificationsApi.list as any).mockResolvedValue({
    notifications: [
      { id: 'n1', title: 'تنبيه الصيانة', body: 'اقرأ هذا.', category: 'alert', published_at: '2026-01-02T10:00:00Z', read_at: null },
    ],
    meta: { unread_count: 1 },
  });
  (teacherNotificationsApi.markRead as any).mockResolvedValue({ unread_count: 0 });
  (teacherNotificationsApi.markAllRead as any).mockResolvedValue({ unread_count: 0 });
});

describe('TeacherNotificationBell', () => {
  it('shows the unread count badge from the platform endpoint', async () => {
    render(<TeacherNotificationBell />);
    await waitFor(() => expect(teacherNotificationsApi.unreadCount).toHaveBeenCalled());
    expect(await screen.findByTestId('teacher-notif-bell')).toBeInTheDocument();
    // Badge text for 2 unread.
    expect(screen.getByText('2')).toBeInTheDocument();
  });

  it('opens the dropdown, lists items and opens a notification', async () => {
    render(<TeacherNotificationBell />);
    fireEvent.click(screen.getByTestId('teacher-notif-bell'));

    expect(await screen.findByText('تنبيه الصيانة')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('teacher-notif-item'));

    await waitFor(() => expect(teacherNotificationsApi.markRead).toHaveBeenCalledWith('n1'));
    expect(push).toHaveBeenCalledWith('/teacher/notifications?open=n1');
  });

  it('marks all as read from the dropdown', async () => {
    render(<TeacherNotificationBell />);
    fireEvent.click(screen.getByTestId('teacher-notif-bell'));
    await screen.findByText('تنبيه الصيانة');

    fireEvent.click(screen.getByText('تحديد الكل كمقروء'));
    await waitFor(() => expect(teacherNotificationsApi.markAllRead).toHaveBeenCalled());
  });
});
