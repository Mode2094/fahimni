'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select } from '@/components/ui/select';
import { CardSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import {
  MessagesSquare, Plus, Loader2, Heart, Eye, MessageSquare, Pin, Lock,
  Search, Users, HelpCircle, UsersRound, Tag, CheckCircle2,
} from 'lucide-react';
import { toast } from 'sonner';
import { communityApi, formatRelativeDate } from '@/services/community';
import type { CommunityOverview, CommunityThread } from '@/types/api';

export default function CommunityPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [overview, setOverview] = useState<CommunityOverview | null>(null);
  const [threads, setThreads] = useState<CommunityThread[]>([]);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState('all');
  const [sort, setSort] = useState('active');
  const [search, setSearch] = useState('');
  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState<Record<string, any>>({ title: '', body: '', type: 'discussion', group_id: '', tags: '' });

  const loadThreads = () => {
    const params: Record<string, string> = { sort };
    if (tab === 'questions') params.type = 'question';
    if (tab === 'discussions') params.type = 'discussion';
    if (tab === 'unanswered') params.sort = 'unanswered';
    if (search.trim()) params.search = search.trim();
    communityApi.threads(params)
      .then(setThreads)
      .catch(() => setThreads([]));
  };

  useEffect(() => {
    if (!check()) return;
    communityApi.overview().then(setOverview).catch(() => setOverview(null));
    loadThreads();
    setLoading(false);
  }, [tab, sort, search]);

  const openNew = () => {
    setForm({ title: '', body: '', type: 'discussion', group_id: '', tags: '' });
    setShowForm(true);
  };

  const submit = async () => {
    if (!form.title.trim() || !form.body.trim()) { toast.error(t('أدخل العنوان والمحتوى', 'Enter title and content')); return; }
    setSaving(true);
    try {
      const thread = await communityApi.createThread({
        title: form.title.trim(),
        body: form.body.trim(),
        type: form.type,
        group_id: form.group_id || undefined,
        tags: form.tags.split(',').map((s: string) => s.trim()).filter(Boolean),
      });
      toast.success(t('تم النشر', 'Posted'));
      setShowForm(false);
      setTab('all');
      loadThreads();
      communityApi.overview().then(setOverview).catch(() => {});
      window.location.href = `/community/threads/${thread.id}`;
    } catch (e: any) {
      toast.error(e.message || 'Failed');
    }
    setSaving(false);
  };

  const like = async (thread: CommunityThread) => {
    try {
      const d = await communityApi.likeThread(thread.id);
      setThreads((prev) => prev.map((x) => (x.id === thread.id ? { ...x, liked_by_me: d.liked, likes_count: d.likes_count } : x)));
    } catch (e: any) { toast.error(e.message || 'Failed'); }
  };

  const tabs = [
    { key: 'all', label: t('الكل', 'All') },
    { key: 'questions', label: t('الأسئلة', 'Questions') },
    { key: 'unanswered', label: t('بدون إجابة', 'Unanswered') },
    { key: 'discussions', label: t('النقاشات', 'Discussions') },
  ];

  const typeBadge = (type: string) => {
    if (type === 'question') return <Badge className="bg-amber-100 text-amber-700 border-0 text-[10px]"><HelpCircle className="h-3 w-3 me-1" />{t('سؤال', 'Question')}</Badge>;
    if (type === 'announcement') return <Badge className="bg-red-100 text-red-700 border-0 text-[10px]">📢 {t('إعلان', 'Announcement')}</Badge>;
    return <Badge className="bg-teal-100 text-teal-700 border-0 text-[10px]">{t('نقاش', 'Discussion')}</Badge>;
  };

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <MessagesSquare className="h-6 w-6 text-teal-600" />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('مجتمع المعلم', 'Teacher Community')}</h1>
            <p className="text-muted-foreground mt-1">{t('منتدى خاص بمعلمك: اسأل، ناقش، وساعد زملاءك', 'A private forum for your teacher: ask, discuss and help classmates')}</p>
          </div>
        </div>
        <Button onClick={openNew} className="gap-2 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}><Plus className="h-4 w-4" /> {t('نشر موضوع', 'New topic')}</Button>
      </div>

      <div className="grid gap-6 lg:grid-cols-[1fr_300px]">
        <div className="space-y-4">
          <Card className="border-0 shadow-sm">
            <CardContent className="p-3 sm:p-4">
              <div className="flex flex-wrap items-center gap-2">
                <div className="relative flex-1 min-w-[180px]">
                  <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                  <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('ابحث في المواضيع...', 'Search topics...')} className="h-10 ps-9" />
                </div>
                <div className="flex flex-wrap items-center gap-1 bg-gray-100 rounded-full p-1">
                  {tabs.map((tabItem) => (
                    <button key={tabItem.key} onClick={() => setTab(tabItem.key)}
                      className={cnTab(tab === tabItem.key)}>{tabItem.label}</button>
                  ))}
                </div>
                <Select value={sort} onChange={(e) => setSort(e.target.value)} className="w-auto rounded-full bg-white border-gray-200">
                  <option value="active">{t('آخر نشاط', 'Recent activity')}</option>
                  <option value="latest">{t('الأحدث', 'Newest')}</option>
                  <option value="popular">{t('الأكثر تفاعلاً', 'Most liked')}</option>
                </Select>
              </div>
            </CardContent>
          </Card>

          {loading ? <CardSkeleton /> :
            threads.length === 0 ? (
              <EmptyState icon={MessagesSquare} title={t('لا توجد مواضيع بعد', 'No topics yet')} description={t('كن أول من يبدأ نقاشاً أو يطرح سؤالاً', 'Be the first to start a discussion or ask a question')} action={<Button onClick={openNew} className="mt-2 gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('نشر موضوع', 'New topic')}</Button>} />
            ) : (
              <div className="space-y-3">
                {threads.map((thread) => (
                  <Card key={thread.id} className="border-0 shadow-sm card-hover">
                    <CardContent className="p-4 sm:p-5">
                      <div className="flex flex-wrap items-center gap-2 mb-2">
                        {thread.is_pinned && <Badge className="bg-violet-100 text-violet-700 border-0 text-[10px]"><Pin className="h-3 w-3 me-1" />{t('مثبت', 'Pinned')}</Badge>}
                        {typeBadge(thread.type)}
                        {thread.group && <Badge variant="outline" className="text-[10px]">{thread.group.name}</Badge>}
                        {thread.status === 'closed' && <Badge className="bg-gray-100 text-gray-500 border-0 text-[10px]"><Lock className="h-3 w-3 me-1" />{t('مغلق', 'Closed')}</Badge>}
                      </div>
                      <a href={`/community/threads/${thread.id}`}>
                        <h3 className="font-semibold text-lg hover:text-teal-700 transition-colors leading-snug">{thread.title}</h3>
                      </a>
                      <p className="text-sm text-gray-500 mt-1 line-clamp-2 whitespace-pre-wrap">{thread.body.slice(0, 200)}</p>
                      {thread.tags.length > 0 && (
                        <div className="flex flex-wrap gap-1.5 mt-3">
                          {thread.tags.slice(0, 5).map((tag) => (
                            <span key={tag} className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 text-[11px] text-gray-500">
                              <Tag className="h-3 w-3" />{tag}
                            </span>
                          ))}
                        </div>
                      )}
                      <div className="flex items-center justify-between mt-4 pt-3 border-t text-xs text-gray-400">
                        <span className="flex items-center gap-2 min-w-0">
                          {thread.author?.avatar_url
                            ? <img src={thread.author.avatar_url} className="h-6 w-6 rounded-full object-cover" alt="" />
                            : <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-teal-100 text-[10px] font-bold text-teal-700">{initials(thread.author?.name)}</span>}
                          <span className="truncate">{thread.author?.name}</span>
                          <span>·</span>
                          <span className="shrink-0">{formatRelativeDate(thread.last_activity_at || thread.created_at, locale)}</span>
                        </span>
                        <span className="flex items-center gap-3 shrink-0">
                          <button onClick={() => like(thread)} className={cnLike(thread.liked_by_me)}>
                            <Heart className={thread.liked_by_me ? 'h-4 w-4 fill-current' : 'h-4 w-4'} />{thread.likes_count}
                          </button>
                          <span className="flex items-center gap-1">
                            {thread.type === 'question'
                              ? <><CheckCircle2 className="h-4 w-4 text-green-500" />{thread.answers_count} {t('إجابة', 'answers')}</>
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
        </div>

        <div className="space-y-4">
          {overview && (
            <>
              <Card className="border-0 shadow-sm">
                <CardContent className="p-4">
                  <h4 className="font-semibold text-sm mb-3">{t('إحصائيات المجتمع', 'Community stats')}</h4>
                  <div className="grid grid-cols-2 gap-2">
                    {[
                      { label: t('مواضيع', 'Topics'), value: overview.stats.threads },
                      { label: t('أسئلة', 'Questions'), value: overview.stats.questions },
                      { label: t('بدون إجابة', 'Unanswered'), value: overview.stats.unanswered },
                      { label: t('تعليقات', 'Comments'), value: overview.stats.comments },
                      { label: t('مجموعات', 'Groups'), value: overview.stats.groups },
                      { label: t('أعضاء', 'Members'), value: overview.stats.members },
                    ].map((s) => (
                      <div key={s.label} className="rounded-xl bg-gray-50 p-2.5 text-center">
                        <p className="text-lg font-bold text-teal-700">{s.value}</p>
                        <p className="text-[11px] text-gray-500">{s.label}</p>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>

              <Card className="border-0 shadow-sm">
                <CardContent className="p-4">
                  <div className="flex items-center justify-between mb-3">
                    <h4 className="font-semibold text-sm flex items-center gap-1.5"><UsersRound className="h-4 w-4 text-teal-600" />{t('مجموعاتي', 'My groups')}</h4>
                    <a href="/community/groups" className="text-xs text-teal-700 hover:underline">{t('كل المجموعات', 'All groups')}</a>
                  </div>
                  {overview.my_groups.length === 0 ? (
                    <p className="text-xs text-gray-400">{t('لم تنضم لأي مجموعة بعد', 'You have not joined any group yet')}</p>
                  ) : (
                    <div className="space-y-2">
                      {overview.my_groups.map((g) => (
                        <a key={g.id} href={`/community/groups/${g.id}`}
                          className="flex items-center gap-3 rounded-xl border border-gray-100 p-2.5 hover:border-teal-200 hover:bg-teal-50/40 transition-all">
                          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-teal-600 to-teal-500 text-white">
                            <Users className="h-4 w-4" />
                          </div>
                          <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium truncate">{g.name}</p>
                            <p className="text-[11px] text-gray-400">{g.members_count} {t('عضو', 'members')}</p>
                          </div>
                          {g.visibility === 'private' && <Lock className="h-3.5 w-3.5 text-gray-300" />}
                        </a>
                      ))}
                    </div>
                  )}
                </CardContent>
              </Card>

              {overview.tags.length > 0 && (
                <Card className="border-0 shadow-sm">
                  <CardContent className="p-4">
                    <h4 className="font-semibold text-sm mb-3">{t('الوسوم الشائعة', 'Popular tags')}</h4>
                    <div className="flex flex-wrap gap-2">
                      {overview.tags.map((tag) => (
                        <button key={tag.name} onClick={() => setSearch(tag.name)}
                          className="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600 hover:bg-teal-50 hover:text-teal-700 transition-colors">
                          #{tag.name}
                        </button>
                      ))}
                    </div>
                  </CardContent>
                </Card>
              )}
            </>
          )}
        </div>
      </div>

      <Dialog open={showForm} onOpenChange={setShowForm} className="max-w-2xl">
        <DialogContent><DialogHeader><DialogTitle>{t('نشر موضوع جديد', 'New topic')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2"><Label>{t('نوع الموضوع', 'Topic type')} *</Label>
              <div className="grid grid-cols-3 gap-2">
                {[
                  { key: 'discussion', label: t('نقاش', 'Discussion'), icon: MessagesSquare },
                  { key: 'question', label: t('سؤال', 'Question'), icon: HelpCircle },
                  { key: 'announcement', label: t('إعلان', 'Announcement'), icon: Pin },
                ].map((opt) => (
                  <button key={opt.key} type="button" onClick={() => setForm({ ...form, type: opt.key })}
                    className={cnType(form.type === opt.key)}>
                    <opt.icon className="h-4 w-4" />
                    <span>{opt.label}</span>
                  </button>
                ))}
              </div>
            </div>
            <div className="space-y-2">
              <Label>{t('المجموعة', 'Group')}</Label>
              <Select value={form.group_id} onChange={(e) => setForm({ ...form, group_id: e.target.value })} className="h-11 border-gray-200">
                <option value="">{t('المنتدى العام', 'Main forum')}</option>
                {overview?.my_groups.map((g) => (
                  <option key={g.id} value={g.id}>{g.name}</option>
                ))}
              </Select>
            </div>
            <div className="space-y-2"><Label>{t('العنوان', 'Title')} *</Label><Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className="h-11" /></div>
            <div className="space-y-2"><Label>{t('المحتوى', 'Content')} *</Label><Textarea value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} className="min-h-[160px]" /></div>
            <div className="space-y-2"><Label>{t('الوسوم (مفصولة بفواصل)', 'Tags (comma separated)')}</Label><Input value={form.tags} onChange={(e) => setForm({ ...form, tags: e.target.value })} className="h-11" placeholder={t('مثال: رياضيات، جبر', 'e.g. Math, Algebra')} /></div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowForm(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={submit} disabled={saving} className="gap-2 rounded-full">{saving && <Loader2 className="h-4 w-4 animate-spin" />}{t('نشر', 'Post')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function cnTab(active: boolean): string {
  return `rounded-full px-3.5 py-1.5 text-sm font-medium transition-all ${active ? 'bg-white shadow-sm text-teal-700' : 'text-gray-500 hover:text-gray-700'}`;
}

function cnType(active: boolean): string {
  return `flex items-center justify-center gap-2 rounded-xl border p-3 text-sm transition-all ${active ? 'border-teal-300 bg-teal-50 text-teal-700 font-medium' : 'border-gray-200 text-gray-500 hover:border-gray-300'}`;
}

function cnLike(liked: boolean | undefined): string {
  return `flex items-center gap-1 transition-colors ${liked ? 'text-red-500' : 'hover:text-red-500'}`;
}

function initials(name?: string | null): string {
  if (!name) return '؟';
  return name.trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
}
