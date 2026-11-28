import { describe, it, expect } from 'vitest';
import { isQuietPath } from './monitoring';

/**
 * Regression: background/best-effort endpoints (unread badges, the ingest
 * endpoint itself) returned 502 during restarts and flooded the «أخطاء
 * الواجهة» page with hundreds of network errors that were not app bugs.
 */
describe('isQuietPath', () => {
  it('treats unread-badge and ingest endpoints as background noise', () => {
    expect(isQuietPath('/api/v1/notifications/unread-count')).toBe(true);
    expect(isQuietPath('/api/v1/conversations/unread-total')).toBe(true);
    expect(isQuietPath('/api/v1/admin-notifications/unread-count')).toBe(true);
    expect(isQuietPath('/api/v1/monitoring/ingest')).toBe(true);
  });

  it('keeps real API failures reportable', () => {
    expect(isQuietPath('/api/v1/student/notes')).toBe(false);
    expect(isQuietPath('/api/v1/courses?page=2')).toBe(false);
    expect(isQuietPath('/api/v1/admin/bi')).toBe(false);
  });
});
