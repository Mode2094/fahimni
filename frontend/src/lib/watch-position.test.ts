import { describe, it, expect } from 'vitest';
import {
  canResume,
  shouldPersist,
  formatClock,
  addWatchedBucket,
  coverageFraction,
  reachedWatchRequirement,
} from './watch-position';

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

describe('addWatchedBucket', () => {
  it('counts playback as real coverage, once per second', () => {
    const covered = new Set<number>();
    expect(addWatchedBucket(covered, 0, 3)).toBe(true);
    expect(covered.size).toBe(4); // 0,1,2,3
    // Re-watching the same span adds nothing.
    expect(addWatchedBucket(covered, 0, 3)).toBe(false);
    expect(covered.size).toBe(4);
  });

  it('ignores a seek/jump larger than the step limit', () => {
    const covered = new Set<number>();
    // Jumping from the start straight to the end must not mark the video
    // watched — that is the whole point of tracking coverage.
    expect(addWatchedBucket(covered, 0, 600)).toBe(false);
    expect(covered.size).toBe(0);
  });

  it('handles a backwards playhead (rewind) in either direction', () => {
    const covered = new Set<number>();
    expect(addWatchedBucket(covered, 10, 8)).toBe(true);
    expect(covered.size).toBe(3); // 8,9,10
  });
});

describe('coverageFraction', () => {
  it('reports the watched share of the duration, capped at 1', () => {
    const covered = new Set<number>([0, 1, 2, 3, 4]);
    expect(coverageFraction(covered, 10)).toBe(0.5);
    expect(coverageFraction(covered, 5)).toBe(1);
  });

  it('is zero for an unknown duration', () => {
    expect(coverageFraction(new Set(), 0)).toBe(0);
    expect(coverageFraction(new Set([1, 2]), NaN)).toBe(0);
  });
});

describe('reachedWatchRequirement', () => {
  it('passes when the required seconds are met', () => {
    expect(reachedWatchRequirement(new Set([1, 2, 3]), 3)).toBe(true);
    expect(reachedWatchRequirement(new Set([1, 2]), 3)).toBe(false);
  });

  it('treats a non-positive requirement as no condition (non-video lessons)', () => {
    expect(reachedWatchRequirement(new Set(), 0)).toBe(true);
    expect(reachedWatchRequirement(new Set(), -5)).toBe(true);
  });
});
