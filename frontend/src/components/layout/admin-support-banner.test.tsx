import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AdminSupportBanner from './admin-support-banner';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';

beforeEach(() => {
  useLocale.setState({ locale: 'ar' });
  useAuth.setState({ adminSupport: null });
});

describe('AdminSupportBanner', () => {
  it('renders nothing when no support session is active', () => {
    const { container } = render(<AdminSupportBanner />);
    expect(container.firstChild).toBeNull();
  });

  it('shows the support warning with the teacher name and ends on click', async () => {
    const stopAdminSupport = vi.fn();
    useAuth.setState({
      adminSupport: {
        active: true,
        mode: 'support',
        actor_id: 'a1',
        actor_name: 'المدير',
        user_id: 'u1',
        user_name: 'أستاذ أحمد',
        tenant_id: 't1',
      },
      stopAdminSupport,
    });

    render(<AdminSupportBanner />);

    const banner = screen.getByTestId('admin-support-banner');
    expect(banner).toHaveTextContent('أستاذ أحمد');
    expect(banner).toHaveTextContent('بصلاحية السوبر أدمن');

    await userEvent.click(screen.getByTestId('admin-support-exit'));
    expect(stopAdminSupport).toHaveBeenCalledTimes(1);
  });
});
