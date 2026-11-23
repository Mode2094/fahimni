import { describe, it, expect } from 'vitest';
import { canResume, shouldPersist, formatClock } from './watch-position';

describe('canResume', () => {
  it('offers resume for a meaningful mid-video position', () => {
    expect(canResume({ position_seconds: 120, duration_seconds: 600 })).toBe(true);
  });

  it('ignores trivial positions near the start', () => {
    expect(canResume({ position_seconds: 3, duration_seconds: 600 })).toBe(false);
    expect(canResume({ position_seconds: 0, duration_seconds: 600 })).toBe(false);
    expect(canResume(null)).toBe(false);
    expect(canResume(undefined)).toBe(false);
  });

  it('does not offer resume at the very end of a finished video', () => {
    expect(canResume({ position_seconds: 600, duration_seconds: 600 })).toBe(false);
    expect(canResume({ position_seconds: 592, duration_seconds: 600 })).toBe(false);
  });
});

describe('shouldPersist', () => {
  it('writes only when the position advanced enough', () => {
    expect(shouldPersist(100, 100)).toBe(false);
    expect(shouldPersist(101, 100)).toBe(false);
    expect(shouldPersist(102, 100)).toBe(true);
  });

  it('never writes a zero/negative position', () => {
    expect(shouldPersist(0, 0)).toBe(false);
    expect(shouldPersist(-5, 0)).toBe(false);
    expect(shouldPersist(NaN, 0)).toBe(false);
  });
});

describe('formatClock', () => {
  it('formats minutes/seconds and hours', () => {
    expect(formatClock(0)).toBe('0:00');
    expect(formatClock(65)).toBe('1:05');
    expect(formatClock(3725)).toBe('1:02:05');
  });
});
