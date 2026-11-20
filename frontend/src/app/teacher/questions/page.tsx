import { redirect } from 'next/navigation';

/**
 * The standalone "Question Bank" page was removed when exam and question
 * management were unified inside the course builder: questions now live with
 * their exam (course → unit → exam → questions). This route only exists so old
 * links and bookmarks keep working — it forwards to the courses list, where the
 * teacher drills into a course and opens the exam editor. No data or API was
 * removed.
 */
export default function TeacherQuestionsPage() {
  redirect('/courses');
}
