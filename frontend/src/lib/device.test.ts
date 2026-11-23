import { describe, it, expect, beforeEach } from 'vitest';
import { getDeviceId, parseRawHeaders } from './device';

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

/**
 * Regression: `line.split(': ')` produced a 3-element tuple whenever a header
 * value itself contained ': ', and `new Headers(...)` then threw
 * "Failed to construct 'Headers': Invalid value" (reported at /app).
 */
describe('parseRawHeaders', () => {
  it('parses ordinary CRLF/ LF response headers', () => {
    expect(parseRawHeaders('content-type: text/html\r\ncache-control: no-store')).toEqual([
      ['content-type', 'text/html'],
      ['cache-control', 'no-store'],
    ]);
  });

  it('keeps the full value when it contains a colon', () => {
    expect(parseRawHeaders('content-disposition: attachment; filename="a: b.pdf"')).toEqual([
      ['content-disposition', 'attachment; filename="a: b.pdf"'],
    ]);
  });

  it('drops blank and malformed lines instead of throwing', () => {
    expect(parseRawHeaders('\r\nnot-a-header\r\nx-ok: 1\r\n')).toEqual([['x-ok', '1']]);
  });

  it('never throws when fed to the Headers constructor', () => {
    const raw = 'date: Mon, 23 Nov 2026 22:30:05 GMT\r\nlink: </a>; rel="x: y"\r\nalt-svc: h3=":443"';
    expect(() => new Headers(parseRawHeaders(raw))).not.toThrow();
  });
});
