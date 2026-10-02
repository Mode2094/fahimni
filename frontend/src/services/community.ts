import type {
  CommunityComment,
  CommunityGroup,
  CommunityGroupMember,
  CommunityOverview,
  CommunityThread,
} from '@/types/api';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export const communityHeaders = (): Record<string, string> => {
  const tid = localStorage.getItem('tenant_id');
  return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
};

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${API}${path}`, { headers: communityHeaders(), ...init });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error((data as any)?.message || 'Request failed');
  }
  return data as T;
}

export const communityApi = {
  overview: () => request<CommunityOverview>('/community/overview'),

  threads: (params: Record<string, string> = {}) => {
    const qs = new URLSearchParams(params).toString();
    return request<{ threads: CommunityThread[] }>(`/community/threads${qs ? `?${qs}` : ''}`).then((d) => d.threads);
  },
  thread: (id: string) =>
    request<{ thread: CommunityThread; comments: CommunityComment[]; can_manage: boolean; is_teacher: boolean }>(`/community/threads/${id}`),
  createThread: (body: Record<string, unknown>) =>
    request<{ thread: CommunityThread }>('/community/threads', { method: 'POST', body: JSON.stringify(body) }).then((d) => d.thread),
  updateThread: (id: string, body: Record<string, unknown>) =>
    request<{ thread: CommunityThread }>(`/community/threads/${id}`, { method: 'PUT', body: JSON.stringify(body) }).then((d) => d.thread),
  deleteThread: (id: string) => request<{ message: string }>(`/community/threads/${id}`, { method: 'DELETE' }),
  pinThread: (id: string) => request<{ is_pinned: boolean }>(`/community/threads/${id}/pin`, { method: 'POST' }),
  toggleThreadStatus: (id: string) => request<{ status: string }>(`/community/threads/${id}/status`, { method: 'POST' }),

  groups: (params: Record<string, string> = {}) => {
    const qs = new URLSearchParams(params).toString();
    return request<{ groups: CommunityGroup[] }>(`/community/groups${qs ? `?${qs}` : ''}`).then((d) => d.groups);
  },
  group: (id: string) =>
    request<{ group: CommunityGroup; threads: CommunityThread[]; can_manage: boolean; is_moderator: boolean }>(`/community/groups/${id}`),
  createGroup: (body: Record<string, unknown>) =>
    request<{ group: CommunityGroup }>('/community/groups', { method: 'POST', body: JSON.stringify(body) }).then((d) => d.group),
  updateGroup: (id: string, body: Record<string, unknown>) =>
    request<{ group: CommunityGroup }>(`/community/groups/${id}`, { method: 'PUT', body: JSON.stringify(body) }).then((d) => d.group),
  deleteGroup: (id: string) => request<{ message: string }>(`/community/groups/${id}`, { method: 'DELETE' }),
  joinGroup: (id: string) => request<{ message: string; group: CommunityGroup }>(`/community/groups/${id}/join`, { method: 'POST' }),
  leaveGroup: (id: string) => request<{ message: string; group: CommunityGroup }>(`/community/groups/${id}/leave`, { method: 'POST' }),
  members: (id: string) => request<{ members: CommunityGroupMember[] }>(`/community/groups/${id}/members`).then((d) => d.members),
  updateMember: (id: string, memberId: string, body: Record<string, unknown>) =>
    request<{ member: CommunityGroupMember }>(`/community/groups/${id}/members/${memberId}`, { method: 'PUT', body: JSON.stringify(body) }),
  removeMember: (id: string, memberId: string) =>
    request<{ message: string }>(`/community/groups/${id}/members/${memberId}`, { method: 'DELETE' }),

  createComment: (threadId: string, body: { body: string; parent_id?: string | null }) =>
    request<{ comment: CommunityComment }>(`/community/threads/${threadId}/comments`, { method: 'POST', body: JSON.stringify(body) }).then((d) => d.comment),
  updateComment: (threadId: string, commentId: string, body: { body: string }) =>
    request<{ comment: CommunityComment }>(`/community/threads/${threadId}/comments/${commentId}`, { method: 'PUT', body: JSON.stringify(body) }).then((d) => d.comment),
  deleteComment: (threadId: string, commentId: string) =>
    request<{ message: string }>(`/community/threads/${threadId}/comments/${commentId}`, { method: 'DELETE' }),
  acceptAnswer: (threadId: string, commentId: string) =>
    request<{ is_answer: boolean }>(`/community/threads/${threadId}/comments/${commentId}/answer`, { method: 'POST' }),

  likeThread: (id: string) => request<{ liked: boolean; likes_count: number }>(`/community/threads/${id}/like`, { method: 'POST' }),
  likeComment: (threadId: string, commentId: string) =>
    request<{ liked: boolean; likes_count: number }>(`/community/threads/${threadId}/comments/${commentId}/like`, { method: 'POST' }),
};

export const formatRelativeDate = (iso: string | null, locale: string): string => {
  if (!iso) return '';
  const date = new Date(iso);
  const diff = Date.now() - date.getTime();
  const minutes = Math.floor(diff / 60000);
  if (minutes < 1) return locale === 'ar' ? 'الآن' : 'now';
  if (minutes < 60) return locale === 'ar' ? `منذ ${minutes} دقيقة` : `${minutes} min ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return locale === 'ar' ? `منذ ${hours} ساعة` : `${hours} h ago`;
  const days = Math.floor(hours / 24);
  if (days < 7) return locale === 'ar' ? `منذ ${days} يوم` : `${days} d ago`;
  return date.toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US');
};
