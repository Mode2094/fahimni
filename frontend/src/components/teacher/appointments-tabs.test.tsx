import { describe, it, expect, afterEach, vi } from 'vitest';
import { render } from '@testing-library/react';
import { usePlan } from '@/store/plan';
import AppointmentsTabs from './appointments-tabs';

// usePathname needs an app-router context that jsdom lacks.
vi.mock('next/navigation', () => ({
  __esModule: true,
  usePathname: () => '/teacher/appointments',
}));

const SERVICES_LINK = 'a[href="/teacher/services"]';

afterEach(() => {
  // Reset the shared store so tests don't leak gate state into each other.
  usePlan.setState({ gates: {} });
});

/**
 * Regression: the "Paid Sessions" tab linked to /teacher/services even when the
 * plan did not include `paid_services_enabled`, so the destination page 403'd.
 */
describe('AppointmentsTabs plan gating', () => {
  it('shows the paid sessions tab when the plan allows it', () => {
    usePlan.setState({ gates: { paid_services_enabled: true } });
    const { container } = render(<AppointmentsTabs />);
    expect(container.querySelector(SERVICES_LINK)).toBeTruthy();
  });

  it('hides the paid sessions tab when the plan forbids it', () => {
    usePlan.setState({ gates: { paid_services_enabled: false } });
    const { container } = render(<AppointmentsTabs />);
    expect(container.querySelector(SERVICES_LINK)).toBeNull();
    // The appointments tab itself is always available.
    expect(container.querySelector('a[href="/teacher/appointments"]')).toBeTruthy();
  });
});
