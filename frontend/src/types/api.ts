export interface User {
  id: string; name: string; email: string; phone: string | null;
  avatar_url: string | null; is_super_admin: boolean; is_active: boolean;
  locale: string; email_verified_at: string | null; last_login_at: string | null;
  roles: string[]; permissions: string[]; created_at: string;
  tenants?: Tenant[];
}
export interface LoginResponse { user: User; token: string; }
export interface Tenant { id: string; name: string; slug: string; email?: string; status?: string; }
export interface Course { id: string; tenant_id: string; title: string; slug: string; description: string; short_description: string; thumbnail_url?: string; is_published: boolean; sort_order: number; created_at: string; }
export interface Section { id: string; course_id: string; tenant_id: string; title: string; description?: string; sort_order: number; is_published: boolean; lessons: Lesson[]; }
export interface Lesson { id: string; section_id: string; tenant_id: string; title: string; description?: string; content?: string; video_url?: string; file_url?: string; duration_minutes: number; sort_order: number; is_free_preview: boolean; lesson_type: string; }
export interface Exam { id: string; course_id: string; tenant_id: string; title: string; description: string; duration_minutes: number; pass_mark_percent: number; max_attempts: number; is_published: boolean; }
export interface Question { id: string; exam_question_id?: string; question_text: string; question_type: string; points: number; options: { id: string; option_text: string; is_correct: boolean }[]; }
export interface SubscriptionPlan { id: string; name: string; slug: string; description: string; monthly_price: number; yearly_price: number; currency: string; trial_days: number; is_active: boolean; features: any[]; }
export interface Payment { id: string; tenant_name?: string; tenant_id: string; transaction_id: string; gateway: string; amount: number; currency: string; status: string; created_at: string; }
export interface Invoice { id: string; tenant_name?: string; invoice_number: string; total_amount: number; currency: string; status: string; created_at: string; }
export interface Enrollment { id: string; course_id: string; user_id: string; status: string; progress_percent: number; enrolled_at: string; course?: Course; }

export interface CommunityAuthor { id: string; name: string; avatar_url: string | null; }
export interface CommunityGroup {
  id: string; name: string; slug: string; description: string | null;
  cover_image: string | null; visibility: 'public' | 'private';
  join_policy: 'open' | 'request'; status: string;
  members_count: number; threads_count: number;
  created_at: string; is_member: boolean;
  membership_status: 'active' | 'pending' | null; my_role: 'member' | 'moderator' | null;
}
export interface CommunityComment {
  id: string; thread_id: string; parent_id: string | null; body: string;
  is_answer: boolean; likes_count: number; created_at: string; updated_at: string;
  author: CommunityAuthor | null; liked_by_me?: boolean;
  replies_count?: number; replies?: CommunityComment[];
}
export interface CommunityThread {
  id: string; group_id: string | null;
  group: { id: string; name: string; slug: string; visibility: string } | null;
  title: string; body: string; type: 'discussion' | 'question' | 'announcement';
  tags: string[]; status: 'open' | 'closed' | 'hidden'; is_pinned: boolean;
  views_count: number; comments_count: number; answers_count: number; likes_count: number;
  last_activity_at: string | null; created_at: string; updated_at: string;
  author: CommunityAuthor | null; liked_by_me?: boolean;
}
export interface CommunityStats {
  threads: number; questions: number; unanswered: number;
  groups: number; members: number; comments: number;
}
export interface CommunityOverview {
  is_teacher: boolean; stats: CommunityStats;
  tags: { name: string; count: number }[];
  my: { threads: number; comments: number; likes: number };
  my_groups: CommunityGroup[];
}
export interface CommunityGroupMember {
  id: string; group_id: string; user_id: string; name: string; email: string;
  avatar_url: string | null; role: 'member' | 'moderator'; status: 'active' | 'pending';
  joined_at: string | null;
}

export interface RecommenderOption { key: string; label: { ar: string; en: string }; }
export interface RecommenderQuestion {
  key: string;
  question: { ar: string; en: string };
  options: RecommenderOption[];
}
export interface PlanHighlight {
  key: string; label_ar: string; label_en: string; type: string;
  value: string; display_ar: string; display_en: string;
}
export interface RecommendedPlan {
  id: string; name: string; slug: string; description: string;
  monthly_price: number; yearly_price: number | null; currency: string; trial_days: number;
  score: number; max_score: number;
  reasons: { ar: string; en: string }[];
  highlights: PlanHighlight[];
  features: any[];
  is_current: boolean;
}
export interface PlanRecommendationResponse {
  recommended: RecommendedPlan;
  alternatives: RecommendedPlan[];
}
