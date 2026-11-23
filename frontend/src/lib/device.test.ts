import { describe, it, expect, beforeEach } from 'vitest';
import { getDeviceId } from './device';

/** The cookie helper the module writes with; jsdom keeps it as a plain string. */
function cookieValue(name: string): string {
  const row = document.cookie.split('; ').find((entry) => entry.startsWith(name + '='));
  return row ? decodeURIComponent(row.slice(name.length + 1)) : '';
}

beforeEach(() => {
  localStorage.clear();
  document.cookie = 'lms_device_id=; Max-Age=0; Path=/';
});

describe('getDeviceId — stable device identity', () => {
  it('returns the same id on repeated calls and mirrors it to a cookie', () => {
    const first = getDeviceId();
    expect(first).toMatch(/^dev-/);
    expect(getDeviceId()).toBe(first);
    expect(cookieValue('lms_device_id')).toBe(first);
  });

  it('recovers the same id from the cookie after localStorage is cleared', () => {
    const first = getDeviceId();
    localStorage.clear();
    expect(getDeviceId()).toBe(first);
  });

  it('reuses an id already present in localStorage without regenerating', () => {
    localStorage.setItem('lms_device_id', 'dev-existing');
    expect(getDeviceId()).toBe('dev-existing');
  });
});
