import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

import { RouteShell } from './route-shell';
import { TenantHostProvider } from '@/lib/tenant-host';
import { useAuth } from '@/store';

/**
 * On a teacher's OWN host the public teacher-site paths (`/courses`, `/blog`,
 * `/register`, …) are rewritten server-side to `/t/{slug}` but the browser path
 * stays clean. The shell used to classify by that clean path and blank
 * `/courses` for guests (it looked like the signed-in Course Builder). These
 * tests lock the host-aware behaviour in.
 */

let currentPath = '/courses';

vi.mock('next/navigation', () => ({
  usePathname: () => currentPath,
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
}));

// Keep the shells out of the way: only the gateway decision is under test.
vi.mock('@/components/layout/panel-shell', () => ({ PanelShell: ({ children }: any) => <div data-testid="panel-shell">{children}</div> }));
vi.mock('@/components/layout/public-shell', () => ({ PublicShell: ({ children }: any) => <div data-testid="public-shell">{children}</div> }));
vi.mock('@/components/auth/auth-unavailable', () => ({ AuthUnavailable: () => <div data-testid="auth-unavailable" /> }));

function renderShell(slug: string | null) {
  return render(
    <TenantHostProvider slug={slug} branding={slug ? { slug, name: 'T', logoUrl: null, primary: '#000', accent: '#111' } : null}>
      <RouteShell>
        <div data-testid="page">page</div>
      </RouteShell>
    </TenantHostProvider>,
  );
}

beforeEach(() => {
  localStorage.clear();
  useAuth.setState({ user: null, initialized: true, status: 'unauthenticated' } as any);
});

describe('RouteShell on a teacher host', () => {
  it('renders the public teacher site for /courses (never the blank auth gate)', () => {
    currentPath = '/courses';
    renderShell('ahmad');
    expect(screen.getByTestId('page')).toBeInTheDocument();
  });

  it('still blanks /courses on the platform (it is the signed-in Course Builder)', () => {
    currentPath = '/courses';
    renderShell(null);
    expect(screen.queryByTestId('page')).not.toBeInTheDocument();
  });

  it('does not wrap the tenant /register in the platform public shell', () => {
    currentPath = '/register';
    renderShell('ahmad');
    expect(screen.getByTestId('page')).toBeInTheDocument();
    expect(screen.queryByTestId('public-shell')).not.toBeInTheDocument();
  });

  it('renders /student/login untouched on a teacher host', () => {
    currentPath = '/student/login';
    renderShell('ahmad');
    expect(screen.getByTestId('page')).toBeInTheDocument();
  });

  it('leaves /register/teacher on the platform shell path', () => {
    currentPath = '/register/teacher';
    renderShell('ahmad');
    expect(screen.getByTestId('public-shell')).toBeInTheDocument();
  });
});
