import { describe, it, expect } from 'vitest';

import { isPracticeExam, showsPassMark } from './exam-display';

describe('exam-display — practice vs graded', () => {
  it('flags an ungraded practice', () => {
    expect(isPracticeExam({ assessment_mode: 'practice' })).toBe(true);
    expect(showsPassMark({ assessment_mode: 'practice' })).toBe(false);
  });

  it('keeps normal graded exams intact', () => {
    expect(isPracticeExam({ assessment_mode: 'graded' })).toBe(false);
    expect(showsPassMark({ assessment_mode: 'graded' })).toBe(true);
    // A graded exam with no explicit mode (legacy rows) is still graded.
    expect(showsPassMark({})).toBe(true);
  });

  it('never crashes on a missing exam', () => {
    expect(isPracticeExam(null)).toBe(false);
    expect(isPracticeExam(undefined)).toBe(false);
    expect(showsPassMark(null)).toBe(true);
  });
});
