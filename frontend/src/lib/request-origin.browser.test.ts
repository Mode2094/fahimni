import { describe, it, expect, afterEach, vi } from 'vitest';
import { isPlatformBrowserHost } from '@/lib/request-origin';

/**
 * Google OAuth is only supported on a platform host (apex/www/api) or loopback
 * — the central callback can hand back a session cookie there. On a teacher's
 * own domain / subdomain it must be reported unavailable so the UI keeps the
 * email/password path instead of a broken cross-domain redirect.
 */
describe('isPlatformBrowserHost', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  function onHost(hostname: string) {
    vi.stubGlobal('window', { location: { hostname } });
  }

  it('is true for the platform apex, www and api hosts', () => {
    onHost('fahimni.me');
    expect(isPlatformBrowserHost()).toBe(true);

    onHost('www.fahimni.me');
    expect(isPlatformBrowserHost()).toBe(true);

    onHost('api.fahimni.me');
    expect(isPlatformBrowserHost()).toBe(true);
  });

  it('is true for loopback dev hosts', () => {
    onHost('localhost');
    expect(isPlatformBrowserHost()).toBe(true);

    onHost('127.0.0.1');
    expect(isPlatformBrowserHost()).toBe(true);
  });

  it('is false for a teacher custom domain or subdomain', () => {
    onHost('teacher-ahmad.com');
    expect(isPlatformBrowserHost()).toBe(false);

    onHost('www.teacher-ahmad.com');
    expect(isPlatformBrowserHost()).toBe(false);

    onHost('ahmad.fahimni.me');
    expect(isPlatformBrowserHost()).toBe(false);

    // Suffix look-alike is not a platform host.
    onHost('fahimni.me.evil.com');
    expect(isPlatformBrowserHost()).toBe(false);
  });
});
