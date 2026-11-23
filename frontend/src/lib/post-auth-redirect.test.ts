import { describe, it, expect, beforeEach } from 'vitest';
import {
  isSafeInternalPath,
  setPendingRedirect,
  consumePendingRedirect,
} from './post-auth-redirect';

beforeEach(() => {
  localStorage.clear();
});

describe('post-auth redirect intent — same-site only', () => {
  it('accepts a normal in-app path', () => {
    expect(isSafeInternalPath('/t/noor/courses/c1')).toBe(true);
  });

  it('rejects external and protocol-relative values (no open redirect)', () => {
    expect(isSafeInternalPath('https://evil.example')).toBe(false);
    expect(isSafeInternalPath('//evil.example')).toBe(false);
    expect(isSafeInternalPath('/\\evil.example')).toBe(false);
    expect(isSafeInternalPath('')).toBe(false);
    expect(isSafeInternalPath(null)).toBe(false);
  });

  it('round-trips a stored destination exactly once', () => {
    setPendingRedirect('/t/noor/courses/c1');
    expect(consumePendingRedirect()).toBe('/t/noor/courses/c1');
    expect(consumePendingRedirect()).toBeNull();
  });

  it('never stores an unsafe value', () => {
    setPendingRedirect('https://evil.example');
    expect(consumePendingRedirect()).toBeNull();
  });
});
