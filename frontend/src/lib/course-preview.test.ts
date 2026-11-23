import { describe, it, expect } from 'vitest';
import { parsePreviewQuery, buildLearnUrl, buildExamUrl } from './course-preview';

describe('parsePreviewQuery', () => {
  it('detects preview mode only for preview=1', () => {
    expect(parsePreviewQuery('?preview=1').preview).toBe(true);
    expect(parsePreviewQuery('preview=1').preview).toBe(true);
    expect(parsePreviewQuery('?preview=0').preview).toBe(false);
    expect(parsePreviewQuery('?preview=true').preview).toBe(false);
    expect(parsePreviewQuery('').preview).toBe(false);
  });

  it('reads the lesson hint, defaulting to null', () => {
    expect(parsePreviewQuery('?preview=1&lesson=abc').lessonId).toBe('abc');
    expect(parsePreviewQuery('?lesson=abc').lessonId).toBe('abc');
    expect(parsePreviewQuery('?preview=1').lessonId).toBeNull();
    expect(parsePreviewQuery('').lessonId).toBeNull();
  });
});

describe('buildLearnUrl', () => {
  it('returns the plain student URL for a normal open', () => {
    expect(buildLearnUrl('c1')).toBe('/course/c1/learn');
  });

  it('adds preview and lesson hints', () => {
    expect(buildLearnUrl('c1', { preview: true })).toBe('/course/c1/learn?preview=1');
    expect(buildLearnUrl('c1', { preview: true, lessonId: 'l9' })).toBe(
      '/course/c1/learn?preview=1&lesson=l9',
    );
    expect(buildLearnUrl('c1', { lessonId: 'l9' })).toBe('/course/c1/learn?lesson=l9');
  });
});

describe('buildExamUrl', () => {
  it('keeps preview mode when launched from a previewed course', () => {
    expect(buildExamUrl('e1')).toBe('/exam/e1');
    expect(buildExamUrl('e1', { preview: true })).toBe('/exam/e1?preview=1');
  });
});
