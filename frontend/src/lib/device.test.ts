import { describe, it, expect } from 'vitest';
import { parseRawHeaders } from './device';

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
