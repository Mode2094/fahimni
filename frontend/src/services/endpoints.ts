import api from './api';
import type { LessonNote } from '@/lib/lesson-notes';
import type { LoginResponse, User, Tenant, Course, Section, Lesson, Exam, Question, SubscriptionPlan, Payment, Invoice, Enrollment, RecommenderQuestion, PlanRecommendationResponse } from '@/types/api';

export const auth = {
  login: (e: string, p: string) => api.post<LoginResponse>('/auth/login', { email: e, password: p }).then(r => r.data),
  logout: () => api.post('/auth/logout'),
  me: () => api.get<{ user: User }>('/auth/me').then(r => r.data.user),
};

export const tenants = {
  list: () => api.get<{ tenants: Tenant[] }>('/tenants').then(r => r.data.tenants),
  create: (d: { name: string; slug: string }) => api.post<{ tenant: Tenant }>('/tenants', d).then(r => r.data.tenant),
  get: (id: string) => api.get<{ tenant: Tenant }>(`/tenants/${id}`).then(r => r.data.tenant),
  update: (id: string, d: any) => api.put<{ tenant: Tenant }>(`/tenants/${id}`, d).then(r => r.data.tenant),
  remove: (id: string) => api.delete(`/tenants/${id}`),
};

export const courses = {
  list: () => api.get<{ courses: Course[] }>('/courses').then(r => r.data.courses),
  get: (id: string) => api.get<{ course: Course }>(`/courses/${id}`).then(r => r.data.course),
  create: (d: any) => api.post<{ course: Course }>('/courses', d).then(r => r.data.course),
  remove: (id: string) => api.delete(`/courses/${id}`),
};

export const sectionsApi = {
  list: (courseId: string) => api.get<{ sections: Section[] }>(`/sections?course_id=${courseId}`).then(r => r.data.sections),
  create: (d: any) => api.post<{ section: Section }>('/sections', d).then(r => r.data.section),
  update: (id: string, d: any) => api.put<{ section: Section }>(`/sections/${id}`, d).then(r => r.data.section),
  remove: (id: string) => api.delete(`/sections/${id}`),
};

export const lessonsApi = {
  create: (d: any) => api.post<{ lesson: Lesson }>('/lessons', d).then(r => r.data.lesson),
  update: (id: string, d: any) => api.put<{ lesson: Lesson }>(`/lessons/${id}`, d).then(r => r.data.lesson),
  remove: (id: string) => api.delete(`/lessons/${id}`),
};

export const exams = {
  list: () => api.get<{ exams: Exam[] }>('/exams').then(r => r.data.exams),
  get: (id: string) => api.get<{ exam: Exam }>(`/exams/${id}`).then(r => r.data.exam),
};

export const questions = {
  list: (examId: string) => api.get<{ questions: Question[] }>(`/exams/${examId}/questions`).then(r => r.data.questions),
  create: (d: any) => api.post('/questions', d),
  update: (id: string, d: any) => api.put(`/questions/${id}`, d),
  remove: (id: string) => api.delete(`/questions/${id}`),
};

export const plans = {
  list: () => api.get<{ plans: SubscriptionPlan[] }>('/plans').then(r => r.data.plans),
  publicList: () => api.get<{ plans: SubscriptionPlan[] }>('/public/plans').then(r => r.data.plans),
  create: (d: any) => api.post('/admin/plans', d),
  update: (id: string, d: any) => api.put(`/admin/plans/${id}`, d),
  remove: (id: string) => api.delete(`/admin/plans/${id}`),
};

export const payments = {
  list: () => api.get<{ payments: Payment[] }>('/payments').then(r => r.data.payments),
};

export const invoices = {
  list: () => api.get<{ invoices: Invoice[] }>('/invoices').then(r => r.data.invoices),
};

export const enrollments = {
  myCourses: () => api.get<{ enrollments: Enrollment[] }>('/my-courses').then(r => r.data.enrollments),
  list: () => api.get<{ enrollments: Enrollment[] }>('/enrollments').then(r => r.data.enrollments),
};

export const cardsApi = {
  list: () => api.get<{ cards: any[] }>('/cards').then(r => r.data.cards),
  generate: (courseId: string, count: number) => api.post('/cards/generate', { course_id: courseId, count }),
  redeem: (code: string) => api.post('/cards/redeem', { code }),
};

export const liveSessions = {
  list: () => api.get<{ sessions: any[] }>('/teacher/live-sessions').then(r => r.data.sessions),
  create: (d: any) => api.post('/teacher/live-sessions', d),
  remove: (id: string) => api.delete(`/teacher/live-sessions/${id}`),
};

export const attemptsApi = {
  list: () => api.get<{ attempts: any[] }>('/student/exam-attempts').then(r => r.data.attempts),
  certificates: () => api.get<{ certificates: any[] }>('/student/certificates').then(r => r.data.certificates),
};

export const notificationsApi = {
  list: (perPage = 20) => api.get<{ notifications: any[]; meta: any }>(`/notifications?per_page=${perPage}`).then(r => r.data),
  unreadCount: () => api.get<{ unread_count: number }>('/notifications/unread-count').then(r => r.data.unread_count),
  markAsRead: (id: string) => api.post(`/notifications/${id}/read`),
  markAllAsRead: () => api.post('/notifications/read-all'),
};

/* Platform notifications from the Super Admin ("إدارة منصة فهمني") — a system
 * kept separate from tenant announcements/notifications. Teachers may read and
 * mark-read only; there is no delete method here on purpose. */
export const teacherNotificationsApi = {
  list: (perPage = 20) => api.get<{ notifications: any[]; meta: any }>(`/admin-notifications?per_page=${perPage}`).then(r => r.data),
  unreadCount: () => api.get<{ unread_count: number }>('/admin-notifications/unread-count').then(r => r.data.unread_count),
  get: (id: string) => api.get<{ notification: any }>(`/admin-notifications/${id}`).then(r => r.data.notification),
  markRead: (id: string) => api.post<{ unread_count: number }>(`/admin-notifications/${id}/read`).then(r => r.data),
  markAllRead: () => api.post<{ unread_count: number }>('/admin-notifications/read-all').then(r => r.data),
  contact: (id: string, body: string) => api.post(`/admin-notifications/${id}/contact`, { body }).then(r => r.data),
};

/* Super-Admin management of platform notifications to teachers. */
export const adminNotificationsApi = {
  list: (params: Record<string, any> = {}) => api.get<{ notifications: any[]; meta: any; stats: any }>('/admin/teacher-notifications', { params }).then(r => r.data),
  get: (id: string) => api.get<{ notification: any }>(`/admin/teacher-notifications/${id}`).then(r => r.data.notification),
  create: (d: any) => api.post<{ notification: any }>('/admin/teacher-notifications', d).then(r => r.data.notification),
  update: (id: string, d: any) => api.put<{ notification: any }>(`/admin/teacher-notifications/${id}`, d).then(r => r.data.notification),
  send: (id: string) => api.post<{ recipient_count: number; notification: any }>(`/admin/teacher-notifications/${id}/send`).then(r => r.data),
  retract: (id: string) => api.post<{ notification: any }>(`/admin/teacher-notifications/${id}/retract`).then(r => r.data),
  remove: (id: string) => api.delete(`/admin/teacher-notifications/${id}`),
  teachers: () => api.get<{ teachers: any[] }>('/admin/teacher-notifications/teachers').then(r => r.data.teachers),
  previewRecipients: (d: { audience: 'all' | 'selected'; target_user_ids?: string[] }) =>
    api.post<{ count: number; recipients: any[] }>('/admin/teacher-notifications/recipients', d).then(r => r.data),
  contact: (d: { user_id: string; subject: string; body: string; notification_id?: string }) =>
    api.post('/admin/teacher-notifications/contact', d).then(r => r.data),
};

export const preferencesApi = {
  list: () => api.get<{ preferences: any[] }>('/preferences').then(r => r.data.preferences),
  update: (d: { notification_type: string; is_enabled: boolean; channels: string[] }) => api.put('/preferences', d),
};

export const pushTokens = {
  register: (d: { token: string; platform: 'web' | 'android' | 'ios'; device_id?: string }) => api.post('/push-tokens', d),
  remove: (token: string) => api.delete('/push-tokens', { data: { token } }),
};

export const announcementsApi = {
  list: (perPage = 20) => api.get<{ announcements: any[]; meta: any }>(`/announcements?per_page=${perPage}`).then(r => r.data),
  get: (id: string) => api.get<{ announcement: any }>(`/announcements/${id}`).then(r => r.data.announcement),
  create: (d: any) => api.post('/announcements', d),
  update: (id: string, d: any) => api.put(`/announcements/${id}`, d),
  remove: (id: string) => api.delete(`/announcements/${id}`),
  send: (id: string) => api.post<{ recipient_count: number }>(`/announcements/${id}/send`).then(r => r.data),
};

export const chatApi = {
  conversations: () => api.get<{ conversations: any[] }>('/conversations').then(r => r.data.conversations),
  contacts: () => api.get<{ contacts: any[] }>('/conversations/contacts').then(r => r.data.contacts),
  start: (userId: string) => api.post<{ conversation: any }>('/conversations', { user_id: userId }).then(r => r.data.conversation),
  messages: (id: string) => api.get<{ messages: any[] }>(`/conversations/${id}/messages`).then(r => r.data.messages),
  send: (id: string, body: string) => api.post(`/conversations/${id}/messages`, { body }),
  markRead: (id: string) => api.post(`/conversations/${id}/read`),
  unreadTotal: () => api.get<{ unread_count: number }>('/conversations/unread-total').then(r => r.data.unread_count),
};

export const dashboardApi = {
  teacherStats: () => api.get<any>('/dashboard').then(r => r.data),
  teacherUpcomingLessons: () => api.get<{ lessons: any[] }>('/teacher/scheduled-lessons', { params: { upcoming: 1 } }).then(r => r.data.lessons),
  studentLessons: () => api.get<{ lessons: any[] }>('/scheduled-lessons').then(r => r.data.lessons),
};

export const favoritesApi = {
  list: () => api.get<{ favorites: any[] }>('/student/favorites').then(r => r.data.favorites),
  toggle: (courseId: string) => api.post<{ favorited: boolean }>(`/student/favorites/${courseId}`).then(r => r.data),
};

/**
 * ملاحظات الطالب وعلاماتهم المرجعية — خاصة بالطالب نفسه؛ لا مشاركة.
 * الخادم يتحقّق من ملكية الدرس وصلاحية الوصول في كل عملية.
 */
export const notesApi = {
  list: (params: { course_id?: string; lesson_id?: string; type?: 'note' | 'bookmark'; q?: string; page?: number; per_page?: number } = {}) =>
    api.get<{ data: LessonNote[]; meta: any }>('/student/notes', { params }).then(r => r.data),
  summary: () => api.get<{ courses: any[] }>('/student/notes/summary').then(r => r.data.courses),
  create: (payload: { lesson_id: string; type?: 'note' | 'bookmark'; body?: string | null; position_seconds?: number | null; duration_seconds?: number | null; video_id?: string | null }) =>
    api.post<{ note: LessonNote }>('/student/notes', payload).then(r => r.data.note),
  update: (id: string, payload: { body?: string | null; position_seconds?: number | null }) =>
    api.put<{ note: LessonNote }>(`/student/notes/${id}`, payload).then(r => r.data.note),
  remove: (id: string) => api.delete<{ ok: boolean }>(`/student/notes/${id}`).then(r => r.data),
};

export const planRecommender = {
  questions: () => api.get<{ questions: RecommenderQuestion[] }>('/teacher/plan-recommender/questions').then(r => r.data.questions),
  recommend: (answers: Record<string, string>) => api.post<PlanRecommendationResponse>('/teacher/plan-recommender/recommend', { answers }).then(r => r.data),
};

export const assistant = {
  ask: (question: string, page = '') =>
    api.post<{ answer: string; mode: 'llm' | 'rules' | 'disabled'; matched: string | null }>(
      '/teacher/assistant/ask',
      { question, page },
      { timeout: 45000 },
    ).then(r => r.data),
};
