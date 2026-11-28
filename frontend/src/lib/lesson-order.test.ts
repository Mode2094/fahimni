import { describe, expect, it } from 'vitest';
import { moveLessonBy, moveLessonTo } from './lesson-order';

describe('lesson ordering helpers', () => {
  const ids = ['lesson-a', 'lesson-b', 'lesson-c'];

  it('moves a lesson to a drop target without losing any ids', () => {
    expect(moveLessonTo(ids, 'lesson-c', 'lesson-a')).toEqual(['lesson-c', 'lesson-a', 'lesson-b']);
  });

  it('moves a lesson by one position for keyboard/touch controls', () => {
    expect(moveLessonBy(ids, 'lesson-b', 1)).toEqual(['lesson-a', 'lesson-c', 'lesson-b']);
  });

  it('keeps the order unchanged when the source or target is invalid or out of range', () => {
    expect(moveLessonTo(ids, 'missing', 'lesson-a')).toBeNull();
    expect(moveLessonBy(ids, 'lesson-a', -1)).toBeNull();
  });
});
