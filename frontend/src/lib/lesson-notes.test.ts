import { describe, it, expect } from 'vitest';
import { groupNotes, positionProblemLabel, canJumpToNote, type LessonNote } from './lesson-notes';

function note(over: Partial<LessonNote>): LessonNote {
  return {
    id: 'n1',
    type: 'note',
    body: 'x',
    position_seconds: null,
    duration_seconds: null,
    lesson_id: 'l1',
    lesson_title: 'L1',
    course_id: 'c1',
    course_title: 'C1',
    video_id: null,
    has_position: false,
    position_valid: false,
    position_note: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-02T00:00:00Z',
    ...over,
  };
}

describe('groupNotes', () => {
  it('groups by course then lesson with counts', () => {
    const groups = groupNotes([
      note({ id: 'a', course_id: 'c1', lesson_id: 'l1', updated_at: '2026-01-02T00:00:00Z' }),
      note({ id: 'b', course_id: 'c1', lesson_id: 'l1', updated_at: '2026-01-03T00:00:00Z' }),
      note({ id: 'c', course_id: 'c1', lesson_id: 'l2' }),
      note({ id: 'd', course_id: 'c2', lesson_id: 'l3' }),
    ]);

    expect(groups).toHaveLength(2);
    const c1 = groups.find((g) => g.course_id === 'c1')!;
    expect(c1.notes_count).toBe(3);
    expect(c1.lessons).toHaveLength(2);
    // داخل كل درس: الأحدث أولاً.
    const l1 = c1.lessons.find((l) => l.lesson_id === 'l1')!;
    expect(l1.notes.map((n) => n.id)).toEqual(['b', 'a']);
  });

  it('returns an empty array for no notes', () => {
    expect(groupNotes([])).toEqual([]);
  });
});

describe('positionProblemLabel', () => {
  it('explains a removed video', () => {
    const n = note({ type: 'bookmark', has_position: true, position_valid: false, position_note: 'video_missing' });
    expect(positionProblemLabel(n, 'ar')).toMatch(/حُذف/);
    expect(positionProblemLabel(n, 'en')).toMatch(/removed/);
  });

  it('explains a replaced video', () => {
    const n = note({ type: 'bookmark', has_position: true, position_valid: false, position_note: 'video_changed' });
    expect(positionProblemLabel(n, 'ar')).toMatch(/استبدال/);
  });

  it('returns null for a valid timestamp', () => {
    expect(positionProblemLabel(note({ has_position: true, position_valid: true }), 'ar')).toBeNull();
  });
});

describe('canJumpToNote', () => {
  it('is true only when there is a real timestamp', () => {
    expect(canJumpToNote(note({ has_position: true, position_seconds: 30 }))).toBe(true);
    expect(canJumpToNote(note({ has_position: false, position_seconds: null }))).toBe(false);
  });
});
