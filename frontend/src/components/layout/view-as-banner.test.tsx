import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ViewAsBanner from './view-as-banner';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';

beforeEach(() => {
  useLocale.setState({ locale: 'ar' });
  useAuth.setState({ viewAs: null });
});

describe('ViewAsBanner', () => {
  it('renders nothing when no preview is active', () => {
    const { container } = render(<ViewAsBanner />);
    expect(container.firstChild).toBeNull();
  });

  it('shows the read-only warning and exits on click', async () => {
    const stopViewAs = vi.fn();
    useAuth.setState({
      viewAs: { active: true, read_only: true, user_id: 'u1', user_name: 'سارة' },
      stopViewAs,
    });

    render(<ViewAsBanner />);

    expect(screen.getByTestId('view-as-banner')).toHaveTextContent('سارة');
    expect(screen.getByTestId('view-as-banner')).toHaveTextContent('قراءة فقط');

    await userEvent.click(screen.getByRole('button', { name: /الخروج من المعاينة/ }));
    expect(stopViewAs).toHaveBeenCalledTimes(1);
  });
});
