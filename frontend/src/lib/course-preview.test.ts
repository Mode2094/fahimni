import { describe, it, expect } from 'vitest';
import { parsePreviewQuery, parseLearnTaskQuery, buildLearnUrl, buildLearnAssignmentUrl, buildExamUrl } from './course-preview';

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

describe('parseLearnTaskQuery', () => {
  it('reads a supported tab and its focus target', () => {
    expect(parseLearnTaskQuery('?tab=assignments&assignment=a1')).toEqual({
      tab: 'assignments',
      assignmentId: 'a1',
      examId: null,
    });
    expect(parseLearnTaskQuery('?tab=exams&exam=e1')).toEqual({
      tab: 'exams',
      assignmentId: null,
      examId: 'e1',
    });
  });

  it('ignores unknown tabs and missing targets', () => {
    expect(parseLearnTaskQuery('').tab).toBeNull();
    expect(parseLearnTaskQuery('?tab=lessons').tab).toBeNull();
    expect(parseLearnTaskQuery('?tab=anything').tab).toBeNull();
    expect(parseLearnTaskQuery('').assignmentId).toBeNull();
    expect(parseLearnTaskQuery('').examId).toBeNull();
  });

  it('accepts a search string without a leading question mark', () => {
    expect(parseLearnTaskQuery('tab=assignments&assignment=a2').assignmentId).toBe('a2');
  });
});

describe('buildLearnAssignmentUrl', () => {
  it('matches what parseLearnTaskQuery reads back', () => {
    const url = buildLearnAssignmentUrl('c1', 'a1');
    expect(url).toBe('/course/c1/learn?tab=assignments&assignment=a1');
    const query = url.slice(url.indexOf('?'));
    expect(parseLearnTaskQuery(query)).toEqual({
      tab: 'assignments',
      assignmentId: 'a1',
      examId: null,
    });
  });

  it('encodes ids so they survive the round trip', () => {
    const url = buildLearnAssignmentUrl('c 1', 'a&b');
    const query = url.slice(url.indexOf('?'));
    expect(url.startsWith('/course/c%201/learn?')).toBe(true);
    expect(parseLearnTaskQuery(query).assignmentId).toBe('a&b');
  });
});
