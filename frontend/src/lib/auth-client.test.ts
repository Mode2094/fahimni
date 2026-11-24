import { beforeEach, describe, expect, it } from 'vitest';
import { enrichApiInit, getStoredTenantId } from './auth-client';

describe('tenant context for browser API requests', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('adds the stored workspace to same-origin Laravel API requests', () => {
    localStorage.setItem('tenant_id', 'tenant-a');

    const init = enrichApiInit('/api/v1/assignments?course_id=course-a');

    expect(new Headers(init.headers).get('X-Tenant-Id')).toBe('tenant-a');
  });

  it('preserves an explicit tenant header and does not add one to BFF session calls', () => {
    localStorage.setItem('tenant_id', 'tenant-a');

    const explicit = enrichApiInit('/api/v1/dashboard', { headers: { 'X-Tenant-Id': 'tenant-b' } });
    const session = enrichApiInit('/api/session/login');

    expect(new Headers(explicit.headers).get('X-Tenant-Id')).toBe('tenant-b');
    expect(new Headers(session.headers).has('X-Tenant-Id')).toBe(false);
  });

  it('returns no selection when storage is unavailable or empty', () => {
    expect(getStoredTenantId()).toBeNull();
  });
});
