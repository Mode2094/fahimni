import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { PanelShell } from './panel-shell';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { usePlan } from '@/store/plan';
import { useSettings } from '@/store/settings';

vi.mock('next/navigation', () => ({
  usePathname: () => '/my-learning',
}));

vi.mock('next/image', () => ({
  default: (props: React.ImgHTMLAttributes<HTMLImageElement>) => <img {...props} />,
}));

vi.mock('@/components/layout/teacher-notification-bell', () => ({
  TeacherNotificationBell: () => null,
}));

beforeEach(() => {
  localStorage.clear();
  useAuth.setState({ user: { id: 'student-1', name: 'سارة' } as any } as any);
  useLocale.setState({ locale: 'ar' });
  usePlan.setState({
    gates: { appointments_enabled: true, community_enabled: true, certificates_enabled: true },
  } as any);
  useSettings.setState({
    settings: { ...useSettings.getState().settings, student_app_hidden: false, logo_url: null },
  } as any);
  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input);
    const payload = url.includes('unread-count')
      ? { unread_count: 3 }
      : { total: 2 };
    return { ok: true, json: async () => payload } as Response;
  }));
});

describe('PanelShell student mobile navigation', () => {
  it('keeps messages and bookings one tap away and provides a More drawer', async () => {
    const user = userEvent.setup();
    render(<PanelShell variant="student"><div>Student content</div></PanelShell>);

    const bottomNav = screen.getByRole('navigation', { name: 'تنقل سفلي' });
    expect(within(bottomNav).getByRole('link', { name: /الرئيسية/ })).toHaveAttribute('href', '/my-learning');
    expect(within(bottomNav).getByRole('link', { name: /الاختبارات/ })).toHaveAttribute('href', '/exams');
    expect(within(bottomNav).getByRole('link', { name: /الرسائل/ })).toHaveAttribute('href', '/messages');
    expect(within(bottomNav).getByRole('link', { name: /المواعيد/ })).toHaveAttribute('href', '/my-bookings');

    const more = within(bottomNav).getByRole('button', { name: /المزيد/ });
    expect(more).toHaveAttribute('aria-expanded', 'false');
    await user.click(more);
    expect(more).toHaveAttribute('aria-expanded', 'true');
    const drawer = document.getElementById('mobile-navigation-drawer');
    expect(drawer).not.toBeNull();
    expect(within(drawer as HTMLElement).getByRole('link', { name: /الإشعارات/ })).toHaveAttribute('href', '/notifications');
    expect(more).not.toHaveFocus();

    await user.keyboard('{Escape}');
    expect(more).toHaveAttribute('aria-expanded', 'false');
    expect(more).toHaveFocus();
  });

  it('shows real unread counts on the student destinations', async () => {
    render(<PanelShell variant="student"><div>Student content</div></PanelShell>);

    const bottomNav = screen.getByRole('navigation', { name: 'تنقل سفلي' });
    expect(await within(bottomNav).findByRole('link', { name: /الرسائل، 2 غير مقروء/ })).toBeInTheDocument();
    expect(await within(bottomNav).findByRole('button', { name: /المزيد، 3 إشعارات غير مقروءة/ })).toBeInTheDocument();
  });
});

describe('PanelShell teacher navigation', () => {
  beforeEach(() => {
    useAuth.setState({ user: { id: 'teacher-1', name: 'أ. سامي', roles: ['Teacher'], permissions: [] } as any } as any);
    usePlan.setState({
      gates: {
        live_stream_enabled: true,
        scheduled_lessons_enabled: true,
        community_enabled: true,
        blog_enabled: true,
        multi_teacher_enabled: true,
        export_reports: true,
      },
    } as any);
  });

  it('searches localized page names in the mobile teacher drawer', async () => {
    const user = userEvent.setup();
    render(<PanelShell variant="teacher"><div>Teacher content</div></PanelShell>);

    await user.click(screen.getByRole('button', { name: 'فتح القائمة' }));
    const drawer = document.getElementById('mobile-navigation-drawer') as HTMLElement;
    const search = within(drawer).getByRole('searchbox', { name: 'ابحث في صفحات المعلم' });
    await user.type(search, 'الرسائل');

    expect(within(drawer).getByRole('link', { name: /الرسائل/ })).toHaveAttribute('href', '/messages');
    expect(within(drawer).queryByRole('link', { name: /البث المباشر/ })).not.toBeInTheDocument();
  });

  it('collapses and persists teacher navigation sections without removing routes', async () => {
    const user = userEvent.setup();
    render(<PanelShell variant="teacher"><div>Teacher content</div></PanelShell>);

    await user.click(screen.getByRole('button', { name: 'فتح القائمة' }));
    const drawer = document.getElementById('mobile-navigation-drawer') as HTMLElement;
    const teaching = within(drawer).getByRole('button', { name: /التدريس/ });
    await user.click(teaching);

    expect(teaching).toHaveAttribute('aria-expanded', 'false');
    expect(within(drawer).queryByRole('link', { name: /البث المباشر/ })).not.toBeInTheDocument();
    expect(localStorage.getItem('sidebar-collapsed-groups:teacher')).toContain('Teaching');
  });
});
