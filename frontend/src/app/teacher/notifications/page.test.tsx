import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

import TeacherNotificationsPage from './page';
import { useLocale } from '@/store/locale';
import { teacherNotificationsApi } from '@/services/endpoints';

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

const FEED = {
  notifications: [
    { id: 'n1', title: 'صيانة مجدولة', body: 'سيتوقف النظام قليلاً.', category: 'maintenance', published_at: '2026-01-02T10:00:00Z', read_at: null },
    { id: 'n2', title: 'تحديث جديد', body: 'ميزات جديدة.', category: 'update', published_at: '2026-01-01T10:00:00Z', read_at: '2026-01-01T11:00:00Z' },
  ],
  meta: { unread_count: 1, total: 2 },
};

beforeEach(() => {
  localStorage.clear();
  useLocale.setState({ locale: 'ar' });
  vi.clearAllMocks();
  (teacherNotificationsApi.list as any).mockResolvedValue(FEED);
  (teacherNotificationsApi.markAllRead as any).mockResolvedValue({ unread_count: 0 });
  (teacherNotificationsApi.markRead as any).mockResolvedValue({ unread_count: 0 });
});

describe('teacher platform-notification archive', () => {
  it('renders notifications with category labels and the platform sender', async () => {
    render(<TeacherNotificationsPage />);

    expect(await screen.findByText('صيانة مجدولة')).toBeInTheDocument();
    expect(screen.getByText('تحديث جديد')).toBeInTheDocument();
    // Category chip + one sender line per item.
    expect(screen.getAllByText('صيانة').length).toBeGreaterThan(0);
    expect(screen.getAllByText('إدارة منصة فهمني').length).toBe(2);
  });

  it('offers mark-all-read but never a delete control', async () => {
    render(<TeacherNotificationsPage />);
    await screen.findByText('صيانة مجدولة');

    const markAll = await screen.findByTestId('mark-all-read');
    fireEvent.click(markAll);
    await waitFor(() => expect(teacherNotificationsApi.markAllRead).toHaveBeenCalled());

    // A teacher can never delete a platform notification from the UI.
    expect(screen.queryByText('حذف')).toBeNull();
  });

  it('shows an empty state when there is nothing to read', async () => {
    (teacherNotificationsApi.list as any).mockResolvedValue({ notifications: [], meta: { unread_count: 0, total: 0 } });
    render(<TeacherNotificationsPage />);

    expect(await screen.findByText('لا توجد إشعارات بعد')).toBeInTheDocument();
  });

  it('opens a notification, marks it read and lets the teacher contact the team', async () => {
    (teacherNotificationsApi.contact as any).mockResolvedValue({ ticket: { id: 't1' } });
    render(<TeacherNotificationsPage />);
    await screen.findByText('صيانة مجدولة');

    fireEvent.click(screen.getByText('صيانة مجدولة'));
    await waitFor(() => expect(teacherNotificationsApi.markRead).toHaveBeenCalledWith('n1'));

    fireEvent.click(await screen.findByTestId('contact-admin'));
    fireEvent.change(screen.getByTestId('contact-body'), { target: { value: 'لدي استفسار' } });
    fireEvent.click(screen.getByTestId('contact-send'));

    await waitFor(() => expect(teacherNotificationsApi.contact).toHaveBeenCalledWith('n1', 'لدي استفسار'));
  });
});
