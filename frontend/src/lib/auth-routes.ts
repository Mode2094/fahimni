import type { User } from '@/types/api';

/**
 * Where an authenticated account belongs after login / PWA launch.
 * Single source of truth for every redirect (login pages, /app, shells).
 */
export function dashboardPathFor(user: Pick<User, 'is_super_admin' | 'roles'> & Partial<Pick<User, 'requires_profile_completion'>> | null): string {
  if (!user) return '/student/login';
  if (user.is_super_admin) return '/admin/dashboard';
  // A teacher who has not supplied a phone yet must finish their profile first.
  if (user.requires_profile_completion) return '/teacher/complete-profile';
  if (user.roles?.includes('Teacher')) return '/teacher/dashboard';

  return '/my-learning';
}

/** Only allow same-origin absolute paths as a post-login destination. */
export function safeInternalPath(path: string | null | undefined): string | null {
  if (!path || !path.startsWith('/') || path.startsWith('//') || path.startsWith('/\\')) {
    return null;
  }

  return path;
}

/**
 * Post-login destination. Staff always land on their dashboard; students may
 * return to the protected page they originally requested.
 */
export function postLoginPath(
  user: Pick<User, 'is_super_admin' | 'roles'> | null,
  from?: string | null,
): string {
  if (!user) return '/student/login';
  if (user.is_super_admin || user.roles?.includes('Teacher')) return dashboardPathFor(user);

  const target = safeInternalPath(from);

  // Never bounce back to a login screen (or the PWA entry point, which would
  // just redirect again).
  if (!target || target.startsWith('/student/login') || target === '/app') {
    return dashboardPathFor(user);
  }

  return target;
}
