import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';

import TeacherShell from './teacher-shell';
import { useAuth } from '@/store';
import { usePlan } from '@/store/plan';

// vi.mock factories are hoisted above the module body — `vi.hoisted` keeps the
// shared router spy in a spot the factory can safely reference.
const { replace, push } = vi.hoisted(() => ({ replace: vi.fn(), push: vi.fn() }));

vi.mock('next/navigation', () => ({
  useRouter: () => ({ replace, push }),
  usePathname: () => '/teacher/dashboard',
}));

// The shell's chrome is irrelevant to the gate — keep the test focused.
vi.mock('@/components/layout/panel-shell', () => ({
  PanelShell: ({ children }: any) => <div data-testid="panel">{children}</div>,
}));
vi.mock('@/components/assistant/ask-me', () => ({ default: () => null }));

function seedTeacher({ superAdmin = false } = {}) {
  useAuth.setState({
    user: { roles: ['Teacher'], is_super_admin: superAdmin } as any,
    check: () => true,
  } as any);
}

beforeEach(() => {
  replace.mockReset();
  push.mockReset();
  localStorage.clear();
  localStorage.setItem('lms_authed', '1');
  localStorage.setItem('tenant_id', 't1');
  usePlan.setState({ plan: null, active: null, features: {}, usage: null, gates: {}, loaded: false });
});

describe('TeacherShell — forced plan selection', () => {
  it('redirects a teacher with no active plan to /pricing (never renders the panel)', async () => {
    seedTeacher();
    usePlan.setState({ loaded: true, active: false });

    render(
      <TeacherShell>
        <div>SECRET PANEL</div>
      </TeacherShell>,
    );

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/pricing'));
    expect(screen.queryByText('SECRET PANEL')).not.toBeInTheDocument();
  });

  it('renders the panel for a teacher with an active plan', async () => {
    seedTeacher();
    usePlan.setState({ loaded: true, active: true, plan: { plan_name: 'الاحترافية' } });

    render(
      <TeacherShell>
        <div>SECRET PANEL</div>
      </TeacherShell>,
    );

    expect(await screen.findByText('SECRET PANEL')).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });

  it('never redirects a super admin, even without a plan', async () => {
    seedTeacher({ superAdmin: true });
    usePlan.setState({ loaded: true, active: false });

    render(
      <TeacherShell>
        <div>SECRET PANEL</div>
      </TeacherShell>,
    );

    expect(await screen.findByText('SECRET PANEL')).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });

  it('does not send a non-teacher to /pricing (they are routed by the auth guard)', async () => {
    useAuth.setState({
      user: { roles: ['Student'], is_super_admin: false } as any,
      check: () => true,
    } as any);
    usePlan.setState({ loaded: true, active: false });

    render(
      <TeacherShell>
        <div>SECRET PANEL</div>
      </TeacherShell>,
    );

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/student/login'));
    expect(replace).not.toHaveBeenCalledWith('/pricing');
  });

  it('does not bounce a teacher when the plan status is unknown (fetch failed)', async () => {
    seedTeacher();
    // Plan load fails → `active` stays null → must fail safe (no redirect).
    vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new Error('network down'))));

    render(
      <TeacherShell>
        <div>SECRET PANEL</div>
      </TeacherShell>,
    );

    expect(await screen.findByText('SECRET PANEL')).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });
});
