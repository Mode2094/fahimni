/** Return a reordered copy, or null when the lesson cannot be moved. */
export function moveLessonTo(ids: string[], sourceId: string, targetId: string): string[] | null {
  const source = ids.indexOf(sourceId);
  const target = ids.indexOf(targetId);
  if (source < 0 || target < 0) return null;

  const reordered = [...ids];
  const [moved] = reordered.splice(source, 1);
  reordered.splice(target, 0, moved);
  return reordered;
}

/** Keyboard/touch alternative: move one item by one adjacent position. */
export function moveLessonBy(ids: string[], lessonId: string, direction: -1 | 1): string[] | null {
  const source = ids.indexOf(lessonId);
  const target = source + direction;
  if (source < 0 || target < 0 || target >= ids.length) return null;

  return moveLessonTo(ids, lessonId, ids[target]);
}
