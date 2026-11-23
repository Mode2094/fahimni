/**
 * منطق عرض ملاحظات الطالب — دوال نقية قابلة للاختبار.
 *
 * المصدر الحقيقي للبيانات هو الخادم (يحفظها ويعزلها لكل طالب)، وهذه الدوال
 * تنظّم العرض فقط: التجميع حسب الكورس/الدرس، ووسم المرجع الزمني غير الصالح.
 */

export type LessonNoteType = 'note' | 'bookmark';

export type LessonNote = {
  id: string;
  type: LessonNoteType;
  body: string | null;
  position_seconds: number | null;
  duration_seconds: number | null;
  lesson_id: string;
  lesson_title: string | null;
  course_id: string | null;
  course_title: string | null;
  video_id: string | null;
  has_position: boolean;
  position_valid: boolean;
  position_note: string | null;
  created_at: string | null;
  updated_at: string | null;
};

export type NoteLessonGroup = {
  lesson_id: string;
  lesson_title: string;
  notes: LessonNote[];
};

export type NoteCourseGroup = {
  course_id: string;
  course_title: string;
  notes_count: number;
  lessons: NoteLessonGroup[];
};

/**
 * يجمّع الملاحظات حسب الكورس ثم الدرس، بترتيب ثابت: الكورسات والمواد أبجدياً،
 * والملاحظات داخل كل درس بالأحدث أولاً. لا يعيد ترتيب مصفوفة المدخل.
 */
export function groupNotes(notes: LessonNote[]): NoteCourseGroup[] {
  const courses = new Map<string, NoteCourseGroup>();
  const lessons = new Map<string, NoteLessonGroup>();

  for (const note of notes) {
    const courseId = note.course_id || 'unknown';
    const courseTitle = note.course_title || '—';

    if (!courses.has(courseId)) {
      courses.set(courseId, { course_id: courseId, course_title: courseTitle, notes_count: 0, lessons: [] });
    }
    const course = courses.get(courseId)!;

    const lessonKey = `${courseId}::${note.lesson_id}`;
    if (!lessons.has(lessonKey)) {
      const group: NoteLessonGroup = {
        lesson_id: note.lesson_id,
        lesson_title: note.lesson_title || '—',
        notes: [],
      };
      lessons.set(lessonKey, group);
      course.lessons.push(group);
    }
    const lesson = lessons.get(lessonKey)!;
    lesson.notes.push(note);
    course.notes_count += 1;
  }

  for (const course of courses.values()) {
    for (const lesson of course.lessons) {
      lesson.notes.sort((a, b) => (b.updated_at || '').localeCompare(a.updated_at || ''));
    }
    course.lessons.sort((a, b) => a.lesson_title.localeCompare(b.lesson_title));
  }

  return Array.from(courses.values()).sort((a, b) => a.course_title.localeCompare(b.course_title));
}

/**
 * سبب عدم صلاحية المرجع الزمني — لعرض تنبيه واضح للطالب بدل القفز إلى وقت
 * لم يعد معبّراً عن المحتوى.
 */
export function positionProblemLabel(note: LessonNote, locale: string): string | null {
  if (!note.has_position || note.position_valid) return null;
  const ar = locale === 'ar';
  switch (note.position_note) {
    case 'video_missing':
      return ar ? 'الفيديو المرتبط حُذف — العلامة بقيت كملاحظة' : 'The linked video was removed — kept as a note';
    case 'video_changed':
      return ar ? 'تم استبدال الفيديو — قد لا يطابق الوقت المحتوى الحالي' : 'The video was replaced — the time may no longer match';
    case 'video_unavailable':
      return ar ? 'الفيديو غير متاح حالياً' : 'The video is currently unavailable';
    default:
      return ar ? 'المرجع الزمني غير متاح' : 'The timestamp is unavailable';
  }
}

/** هل يمكن عرض زر «اذهب إلى اللحظة»؟ (مرجع زمني موجود بالفعل) */
export function canJumpToNote(note: LessonNote): boolean {
  return note.has_position && note.position_seconds !== null;
}
