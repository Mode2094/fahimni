import { describe, it, expect } from 'vitest';
import { plural, formatDuration, formatClock, arabicPluralCategory } from './ar-format';

describe('arabicPluralCategory', () => {
  it.each([
    [0, 'zero'],
    [1, 'one'],
    [2, 'two'],
    [3, 'few'],
    [10, 'few'],
    [11, 'many'],
    [99, 'many'],
    [100, 'many'],
    [103, 'few'],
  ] as const)('%i → %s', (n, cat) => {
    expect(arabicPluralCategory(n)).toBe(cat);
  });
});

describe('plural (Arabic)', () => {
  it('renders singular, dual and plural categories for lessons', () => {
    expect(plural.lessons(0)).toBe('لا دروس');
    expect(plural.lessons(1)).toBe('درس واحد');
    expect(plural.lessons(2)).toBe('درسان');
    expect(plural.lessons(3)).toBe('3 دروس');
    expect(plural.lessons(5)).toBe('5 دروس');
    expect(plural.lessons(11)).toBe('11 درساً');
  });

  it('formats minutes and hours', () => {
    expect(plural.minutes(1)).toBe('دقيقة واحدة');
    expect(plural.minutes(2)).toBe('دقيقتان');
    expect(plural.minutes(5)).toBe('5 دقائق');
    expect(plural.hours(1)).toBe('ساعة واحدة');
    expect(plural.hours(2)).toBe('ساعتان');
    expect(plural.hours(5)).toBe('5 ساعات');
  });

  it('never invents a plural for English (basic singular/plural)', () => {
    expect(plural.lessons(1, 'en')).toBe('1 lesson');
    expect(plural.lessons(3, 'en')).toBe('3 lessons');
  });
});

describe('formatDuration', () => {
  it('never renders the old "0 min" bug', () => {
    expect(formatDuration(0)).toBe('بدون مدة');
    expect(formatDuration(null)).toBe('بدون مدة');
    expect(formatDuration(undefined)).toBe('بدون مدة');
    expect(formatDuration(0, 'en')).toBe('No duration');
  });

  it('uses Arabic durations', () => {
    expect(formatDuration(5)).toBe('5 دقائق');
    expect(formatDuration(60)).toBe('ساعة واحدة');
    expect(formatDuration(90)).toBe('ساعة واحدة و30 دقيقة');
  });
});

describe('formatClock', () => {
  it('formats seconds as m:ss or h:mm:ss', () => {
    expect(formatClock(0)).toBe('0:00');
    expect(formatClock(75)).toBe('1:15');
    expect(formatClock(3930)).toBe('1:05:30');
  });
});
