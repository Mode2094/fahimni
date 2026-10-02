'use client';

import { useEffect, useState } from 'react';
import { use } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { CardSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import {
  Users, Plus, Loader2, Lock, Heart, Eye, MessageSquare, CheckCircle2, HelpCircle,
  Pin, Tag, UserPlus, Clock, Shield, Trash2, ArrowRight, MessagesSquare,
} from 'lucide-react';
import { toast } from 'sonner';
import { communityApi, formatRelativeDate } from '@/services/community';
import type { CommunityGroup, CommunityGroupMember, CommunityThread } from '@/types/api';

export default function GroupDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [group, setGroup] = useState<CommunityGroup | null>(null);
  const [threads, setThreads] = useState<CommunityThread[]>([]);
  const [canManage, setCanManage] = useState(false);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [showThreadForm, setShowThreadForm] = useState(false);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState<Record<string, any>>({ title: '', body: '', type: 'discussion', tags: '' });
  const [members, setMembers] = useState<CommunityGroupMember[]>([]);
  const [showMembers, setShowMembers] = useState(false);
  const [membersLoading, setMembersLoading] = useState(false);

  const load = () => {
    communityApi.group(id)
      .then((d) => { setGroup(d.group); setThreads(d.threads); setCanManage(d.can_manage || d.is_moderator); })
      .catch(() => setGroup(null))
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (check()) load(); }, [id, check()]);

  const loadMembers = () => {
    setMembersLoading(true);
    communityApi.members(id).then(setMembers).catch(() => setMembers([])).finally(() => setMembersLoading(false));
  };

  const openMembers = () => { setShowMembers(true); loadMembers(); };

  const joinOrLeave = async () => {
    if (!group) return;
    setBusy(true);
    try {
      if (group.is_member) {
        await communityApi.leaveGroup(group.id);
        toast.success(t('غادرت المجموعة', 'Left group'));
      } else {
        await communityApi.joinGroup(group.id);
        toast.success(t('تم الانضمام', 'Joined'));
      }
      load();
    } catch (e: any) { toast.error(e.message || 'Failed'); }
    setBusy(false);
  };

  const submitThread = async () => {
    if (!form.title.trim() || !form.body.trim()) { toast.error(t('أدخل العنوان والمحتوى', 'Enter title and content')); return; }
    setSaving(true);
    try {
      const thread = await communityApi.createThread({
        group_id: id,
        title: form.title.trim(),
        body: form.body.trim(),
        type: form.type,
        tags: form.tags.split(',').map((s: string) => s.trim()).filter(Boolean),
      });
      toast.success(t('تم النشر', 'Posted'));
      setShowThreadForm(false);
      window.location.href = `/community/threads/${thread.id}`;
    } catch (e: any) { toast.error(e.message || 'Failed'); }
    setSaving(false);
  };

  const approveMember = async (m: CommunityGroupMember) => {
    try {
      await communityApi.updateMember(id, m.id, { status: 'active' });
      toast.success(t('تمت الموافقة', 'Approved'));
      loadMembers();
    } catch (e: any) { toast.error(e.message || 'Failed'); }
  };

  const toggleRole = async (m: CommunityGroupMember) => {
    try {
      await communityApi.updateMember(id, m.id, { role: m.role === 'moderator' ? 'member' : 'moderator' });
      toast.success(t('تم تحديث الدور', 'Role updated'));
      loadMembers();
    } catch (e: any) { toast.error(e.message || 'Failed'); }
  };

  const removeMember = async (m: CommunityGroupMember) => {
    if (!confirm(t('إزالة هذا العضو من المجموعة؟', 'Remove this member from the group?'))) return;
    try {
      await communityApi.removeMember(id, m.id);
      toast.success(t('تمت الإزالة', 'Removed'));
      loadMembers();
    } catch (e: any) { toast.error(e.message || 'Failed'); }
  };

  const like = async (thread: CommunityThread) => {
    try {
      const d = await communityApi.likeThread(thread.id);
      setThreads((prev) => prev.map((x) => (x.id === thread.id ? { ...x, liked_by_me: d.liked, likes_count: d.likes_count } : x)));
    } catch (e: any) { toast.error(e.message || 'Failed'); }
  };

  if (loading) return <CardSkeleton />;

  if (!group) {
    return (
      <Card className="max-w-md mx-auto mt-16"><CardContent className="flex flex-col items-center gap-4 py-14 text-gray-400">
        <Lock className="h-12 w-12 opacity-40" />
        <p className="text-lg">{t('المجموعة غير موجودة أو خاصة', 'Group not found or private')}</p>
        <Button variant="outline" className="rounded-full" onClick={() => window.location.href = '/community/groups'}>{t('العودة للمجموعات', 'Back to groups')}</Button>
      </CardContent></Card>
    );
  }

  const showLocked = group.visibility === 'private' && !group.is_member && !canManage;

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <Card className="border-0 shadow-sm overflow-hidden">
        <div className="h-28 bg-gradient-to-br from-teal-500 via-teal-400 to-emerald-500" />
        <CardContent className="p-5 sm:p-6 -mt-10 relative">
          <div className="flex flex-wrap items-end justify-between gap-4">
            <div className="flex items-end gap-4">
              <div className="flex h-20 w-20 items-center justify-center rounded-2xl bg-white shadow-xl border">
                <Users className="h-9 w-9 text-teal-600" />
              </div>
              <div className="pb-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <h1 className="text-2xl font-bold">{group.name}</h1>
                  {group.visibility === 'private' && <Badge className="bg-gray-100 text-gray-500 border-0 text-[10px]"><Lock className="h-3 w-3 me-1" />{t('خاصة', 'Private')}</Badge>}
                  {group.my_role === 'moderator' && <Badge className="bg-violet-100 text-violet-700 border-0 text-[10px]">★ {t('مشرف', 'Moderator')}</Badge>}
                </div>
                <p className="text-sm text-gray-500 mt-1">{group.description || t('لا يوجد وصف', 'No description')}</p>
                <div className="flex items-center gap-4 text-xs text-gray-400 mt-2">
                  <span className="flex items-center gap-1"><Users className="h-3.5 w-3.5" />{group.members_count} {t('عضو', 'members')}</span>
                  <span className="flex items-center gap-1"><MessagesSquare className="h-3.5 w-3.5" />{group.threads_count} {t('موضوع', 'topics')}</span>
                </div>
              </div>
            </div>
            <div className="flex items-center gap-2 pb-1">
              {canManage && (
                <Button variant="outline" className="rounded-full gap-2" onClick={openMembers}>
                  <Users className="h-4 w-4" />{t('الأعضاء', 'Members')}
                </Button>
              )}
              {group.membership_status === 'pending' ? (
                <Button variant="outline" className="rounded-full gap-2" disabled><Clock className="h-4 w-4" />{t('بانتظار الموافقة', 'Pending approval')}</Button>
              ) : (
                <Button variant={group.is_member ? 'outline' : 'default'} className="rounded-full gap-2" disabled={busy} onClick={joinOrLeave}>
                  {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : group.is_member ? <ArrowRight className="h-4 w-4" /> : <UserPlus className="h-4 w-4" />}
                  {group.is_member ? t('مغادرة المجموعة', 'Leave group') : t('انضمام', 'Join group')}
                </Button>
              )}
            </div>
          </div>
        </CardContent>
      </Card>

      {showLocked ? (
        <Card><CardContent className="flex flex-col items-center gap-4 py-16 text-gray-400">
          <Lock className="h-12 w-12 opacity-40" />
          <p className="text-lg">{t('هذه مجموعة خاصة', 'This is a private group')}</p>
          <p className="text-sm -mt-2">{t('انضم لتصبح عضواً وتشاهد المواضيع', 'Join to become a member and view topics')}</p>
          <Button onClick={joinOrLeave} disabled={busy} className="mt-2 gap-2 rounded-full">
            {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <UserPlus className="h-4 w-4" />}{t('طلب انضمام', 'Request to join')}
          </Button>
        </CardContent></Card>
      ) : (
        <>
          <div className="flex items-center justify-between flex-wrap gap-3">
            <h2 className="text-lg font-semibold">{t('مواضيع المجموعة', 'Group topics')}</h2>
            <Button onClick={() => setShowThreadForm(true)} className="gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('موضوع جديد', 'New topic')}</Button>
          </div>

          {threads.length === 0 ? (
            <EmptyState icon={MessagesSquare} title={t('لا توجد مواضيع في هذه المجموعة', 'No topics in this group yet')} />
          ) : (
            <div className="space-y-3">
              {threads.map((thread) => (
                <Card key={thread.id} className="border-0 shadow-sm card-hover">
                  <CardContent className="p-4 sm:p-5">
                    <div className="flex flex-wrap items-center gap-2 mb-2">
                      {thread.is_pinned && <Badge className="bg-violet-100 text-violet-700 border-0 text-[10px]"><Pin className="h-3 w-3 me-1" />{t('مثبت', 'Pinned')}</Badge>}
                      {thread.type === 'question'
                        ? <Badge className="bg-amber-100 text-amber-700 border-0 text-[10px]"><HelpCircle className="h-3 w-3 me-1" />{t('سؤال', 'Question')}</Badge>
                        : thread.type === 'announcement'
                          ? <Badge className="bg-red-100 text-red-700 border-0 text-[10px]">{t('إعلان', 'Announcement')}</Badge>
                          : <Badge className="bg-teal-100 text-teal-700 border-0 text-[10px]">{t('نقاش', 'Discussion')}</Badge>}
                      {thread.status === 'closed' && <Badge className="bg-gray-100 text-gray-500 border-0 text-[10px]"><Lock className="h-3 w-3 me-1" />{t('مغلق', 'Closed')}</Badge>}
                    </div>
                    <a href={`/community/threads/${thread.id}`}>
                      <h3 className="font-semibold hover:text-teal-700 transition-colors">{thread.title}</h3>
                    </a>
                    <div className="flex items-center justify-between mt-3 pt-3 border-t text-xs text-gray-400">
                      <span className="flex items-center gap-2">
                        {thread.author?.avatar_url
                          ? <img src={thread.author.avatar_url} className="h-6 w-6 rounded-full object-cover" alt="" />
                          : <span className="flex h-6 w-6 items-center justify-center rounded-full bg-teal-100 text-[10px] font-bold text-teal-700">{initials(thread.author?.name)}</span>}
                        <span>{thread.author?.name}</span>
                        <span>·</span>
                        <span>{formatRelativeDate(thread.last_activity_at || thread.created_at, locale)}</span>
                      </span>
                      <span className="flex items-center gap-3">
                        <button onClick={() => like(thread)} className={thread.liked_by_me ? 'flex items-center gap-1 text-red-500' : 'flex items-center gap-1 hover:text-red-500'}>
                          <Heart className={thread.liked_by_me ? 'h-4 w-4 fill-current' : 'h-4 w-4'} />{thread.likes_count}
                        </button>
                        <span className="flex items-center gap-1">
                          {thread.type === 'question'
                            ? <><CheckCircle2 className="h-4 w-4 text-green-500" />{thread.answers_count}</>
                            : <><MessageSquare className="h-4 w-4" />{thread.comments_count}</>}
                        </span>
                        <span className="flex items-center gap-1"><Eye className="h-4 w-4" />{thread.views_count}</span>
                      </span>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </>
      )}

      <Dialog open={showThreadForm} onOpenChange={setShowThreadForm} className="max-w-2xl">
        <DialogContent><DialogHeader><DialogTitle>{t('موضوع جديد في', 'New topic in')} {group.name}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="grid grid-cols-3 gap-2">
              {[
                { key: 'discussion', label: t('نقاش', 'Discussion') },
                { key: 'question', label: t('سؤال', 'Question') },
                { key: 'announcement', label: t('إعلان', 'Announcement') },
              ].map((opt) => (
                <button key={opt.key} type="button" onClick={() => setForm({ ...form, type: opt.key })}
                  className={`rounded-xl border p-3 text-sm transition-all ${form.type === opt.key ? 'border-teal-300 bg-teal-50 text-teal-700 font-medium' : 'border-gray-200 text-gray-500 hover:border-gray-300'}`}>
                  {opt.label}
                </button>
              ))}
            </div>
            <div className="space-y-2"><Label>{t('العنوان', 'Title')} *</Label><Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className="h-11" /></div>
            <div className="space-y-2"><Label>{t('المحتوى', 'Content')} *</Label><Textarea value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} className="min-h-[160px]" /></div>
            <div className="space-y-2"><Label>{t('الوسوم (مفصولة بفواصل)', 'Tags (comma separated)')}</Label><Input value={form.tags} onChange={(e) => setForm({ ...form, tags: e.target.value })} className="h-11" /></div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowThreadForm(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={submitThread} disabled={saving} className="gap-2 rounded-full">{saving && <Loader2 className="h-4 w-4 animate-spin" />}{t('نشر', 'Post')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={showMembers} onOpenChange={setShowMembers} className="max-w-xl">
        <DialogContent><DialogHeader><DialogTitle>{t('أعضاء المجموعة', 'Group members')} ({group.name})</DialogTitle></DialogHeader>
          {membersLoading ? <div className="flex justify-center py-10"><Loader2 className="h-6 w-6 animate-spin text-teal-600" /></div> :
            members.length === 0 ? <p className="text-center text-gray-400 py-8">{t('لا يوجد أعضاء', 'No members')}</p> : (
              <div className="space-y-2 max-h-[50vh] overflow-y-auto">
                {members.map((m) => (
                  <div key={m.id} className="flex items-center gap-3 rounded-xl border border-gray-100 p-3">
                    {m.avatar_url
                      ? <img src={m.avatar_url} className="h-9 w-9 rounded-full object-cover" alt="" />
                      : <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-teal-100 text-xs font-bold text-teal-700">{initials(m.name)}</span>}
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-medium truncate">{m.name}</p>
                      <p className="text-[11px] text-gray-400">
                        {m.role === 'moderator' ? `★ ${t('مشرف', 'Moderator')}` : t('عضو', 'Member')}
                        {m.status === 'pending' && ` · ${t('بانتظار الموافقة', 'pending')}`}
                      </p>
                    </div>
                    {m.status === 'pending' ? (
                      <Button size="sm" className="rounded-full gap-1.5" onClick={() => approveMember(m)}><CheckCircle2 className="h-3.5 w-3.5" />{t('موافقة', 'Approve')}</Button>
                    ) : (
                      <>
                        <Button size="sm" variant="outline" className="rounded-full" onClick={() => toggleRole(m)}>
                          <Shield className="h-3.5 w-3.5 me-1" />{m.role === 'moderator' ? t('إلغاء الإشراف', 'Unmoderate') : t('مشرف', 'Moderate')}
                        </Button>
                        <Button size="icon" variant="ghost" className="h-8 w-8 text-red-500" onClick={() => removeMember(m)} aria-label={t('إزالة', 'Remove')} title={t('إزالة', 'Remove')}><Trash2 className="h-3.5 w-3.5" /></Button>
                      </>
                    )}
                  </div>
                ))}
              </div>
            )}
        </DialogContent>
      </Dialog>
    </div>
  );
}

function initials(name?: string | null): string {
  if (!name) return '؟';
  return name.trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
}
