import { describe, it, expect } from 'vitest';
import {
  CATEGORY_META, CATEGORY_OPTIONS, STATUS_META,
  categoryLabel, statusLabel, formatDateTime,
} from './platform-notifications';

describe('platform notification presentation metadata', () => {
  it('labels every category in both locales', () => {
    for (const key of CATEGORY_OPTIONS) {
      expect(CATEGORY_META[key]).toBeTruthy();
      expect(categoryLabel(key, 'ar')).not.toBe('');
      expect(categoryLabel(key, 'en')).not.toBe('');
    }
  });

  it('labels every status in both locales', () => {
    for (const key of ['draft', 'sent', 'retracted']) {
      expect(STATUS_META[key]).toBeTruthy();
      expect(statusLabel(key, 'ar')).not.toBe('');
      expect(statusLabel(key, 'en')).not.toBe('');
    }
  });

  it('falls back to the raw key for unknown values', () => {
    expect(categoryLabel('weird', 'ar')).toBe('weird');
    expect(statusLabel('weird', 'en')).toBe('weird');
  });

  it('formats dates safely, including empty input', () => {
    expect(formatDateTime(null)).toBe('—');
    expect(formatDateTime('not-a-date')).toBe('—');
    expect(formatDateTime('2026-01-02T10:30:00Z')).not.toBe('—');
  });
});
