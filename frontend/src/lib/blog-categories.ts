/**
 * Blog architecture — categories only. No articles are generated automatically.
 * Future posts should be tagged with one category and target a distinct intent.
 */
export const BLOG_CATEGORIES = [
  { id: 'online-teaching', ar: 'التدريس أونلاين', en: 'Online Teaching' },
  { id: 'course-creation', ar: 'إنشاء الكورسات', en: 'Course Creation' },
  { id: 'teacher-business', ar: 'عمل المعلم', en: 'Teacher Business' },
  { id: 'student-management', ar: 'إدارة الطلاب', en: 'Student Management' },
  { id: 'teaching-tools', ar: 'أدوات التدريس', en: 'Teaching Tools' },
] as const;

export type BlogCategoryId = (typeof BLOG_CATEGORIES)[number]['id'];