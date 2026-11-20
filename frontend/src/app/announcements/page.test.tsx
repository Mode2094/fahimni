import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';

import AnnouncementsPage from './page';
import { useLocale } from '@/store/locale';
import { announcementsApi } from '@/services/endpoints';

vi.mock('@/services/endpoints', () => ({
  announcementsApi: {
    list: vi.fn(),
    get: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    send: vi.fn(),
  },
}));

const EMPTY = { announcements: [], meta: { can_manage: false } };

beforeEach(() => {
  localStorage.clear();
  useLocale.setState({ locale: 'ar' });
  (announcementsApi.list as any).mockResolvedValue(EMPTY);
  // The page loads the course list for the "course" audience picker.
  (globalThis as any).fetch = vi.fn().mockResolvedValue({ json: async () => ({ courses: [] }) });
});

describe('Announcements — staff capability from the server, not localStorage', () => {
  it('shows the create control when the API reports can_manage', async () => {
    (announcementsApi.list as any).mockResolvedValue({ announcements: [], meta: { can_manage: true } });

    render(<AnnouncementsPage />);

    expect(await screen.findByText('إعلان جديد')).toBeInTheDocument();
  });

  it('hides the create control for a student even if localStorage still says Teacher', async () => {
    // Stale client snapshot — the exact bug that let students see admin
    // controls. The server says can_manage=false, so the control must be gone.
    localStorage.setItem('user', JSON.stringify({ roles: ['Teacher'] }));
    (announcementsApi.list as any).mockResolvedValue({ announcements: [], meta: { can_manage: false } });

    render(<AnnouncementsPage />);

    await screen.findByText('لا توجد إعلانات بعد');
    expect(screen.queryByText('إعلان جديد')).toBeNull();
  });

  it('falls back to localStorage only when the API omits the flag', async () => {
    localStorage.setItem('user', JSON.stringify({ roles: ['Teacher'] }));
    (announcementsApi.list as any).mockResolvedValue({ announcements: [], meta: {} });

    render(<AnnouncementsPage />);

    expect(await screen.findByText('إعلان جديد')).toBeInTheDocument();
  });
});
