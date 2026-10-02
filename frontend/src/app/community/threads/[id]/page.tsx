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
  Loader2, Heart, Eye, Pin, Lock, CheckCircle2, HelpCircle, Tag,
  Pencil, Trash2, MessageSquare, Reply, MessagesSquare, LockOpen, Send,
} from 'lucide-react';
import { toast } from 'sonner';
import { communityApi, formatRelativeDate } from '@/services/community';
import type { CommunityComment, CommunityThread } from '@/types/api';

export default function ThreadDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { check, user } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [thread, setThread] = useState<CommunityThread | null>(null);
  const [comments, setComments] = useState<CommunityComment[]>([]);
  const [canManage, setCanManage] = useState(false);
  const [isTeacher, setIsTeacher] = useState(false);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [comment, setComment] = useState('');
  const [sending, setSending] = useState(false);
  const [replyingTo, setReplyingTo] = useState<CommunityComment | null>(null);
  const [replyText, setReplyText] = useState('');
  const [showEdit, setShowEdit] = useState(false);
  const [saving, setSaving] = useState(false);
  const [editForm, setEditForm] = useState<Record<string, any>>({ title: '', body: '', tags: '' });

  const load = () => {
    communityApi.thread(id)
      .then((d) => {
        setThread(d.thread);
        setComments(d.comments || []);
        setCanManage(d.can_manage);
        setIsTeacher(d.is_teacher);
      })
      .catch(() => setThread(null))
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (check()) load(); }, [id, check()]);

  const openEdit = () => {
    if (!thread) return;
    setEditForm({ title: thread.title, body: thread.body, tags: thread.tags.join(', ') });
    setShowEdit(true);
  };

  const submitEdit = async () => {
    if (!editForm.title.trim() || !editForm.body.trim()) return;
    setSaving(true);
    try {
      await communityApi.updateThread(id, {
        title: editForm.title.trim(),
        body: editForm.body.trim(),
        tags: editForm.tags.split(',').map((s: string) => s.trim()).filter(Boolean),
      });
      toast.success(t('تم التحديث', 'Updated'));
      setShowEdit(false);
      load();
    } catch (e: any) { toast.error(e.message || 'Failed'); }
    setSaving(false);
  };

  const removeThread = async () => {
    if (!confirm(t('حذف هذا الموضوع نهائياً؟', 'Delete this thread permanently?'))) return;
    try {
      await communityApi.deleteThread(id);
      toast.success(t('تم الحذف', 'Deleted'));
      window.location.href = '/community';
    } catch (e: any) { toast.error(e.message || 'Failed'); }
  };

  const pin = async () => {
    setBusy(true);
    try {
      const d = await communityApi.pinThread(id);
      setThread((prev) => prev ? { ...prev, is_pinned: d.is_pinned } : prev);
      toast.success(d.is_pinned ? t('تم التثبيت', 'Pinned') : t('تم إلغاء التثبيت', 'Unpinned'));
    } catch (e: any) { toast.error(e.message || 'Failed'); }
    setBusy(false);
  };

  const close = async () => {
    setBusy(true);
    try {
      const d = await communityApi.toggleThreadStatus(id);
      setThread((prev) => prev ? { ...prev, status: d.status as CommunityThread['status'] } : prev);
      toast.success(d.status === 'open' ? t('تم فتح الموضوع', 'Thread reopened') : t('تم إغلاق الموضوع', 'Thread closed'));
    } catch (e: any) { toast.error(e.message || 'Failed'); }
    setBusy(false);
  };

  const likeThread = async () => {
    try {
      const d = await communityApi.likeThread(id);
      setThread((prev) => prev ? { ...prev, liked_by_me: d.liked, likes_count: d.likes_count } : prev);
    } catch (e: any) { toast.error(e.message || 'Failed'); }
  };

  const sendComment = async (parentId: string | null = null, text: string = '') => {
    const body = text || comment;
    if (!body.trim()) return;
    setSending(true);
    try {
      await communityApi.createComment(id, { body: body.trim(), parent_id: parentId });
      toast.success(t('تم النشر', 'Posted'));
      setComment('');
      setReplyText('');
      setReplyingTo(null);
      load();
    } catch (e: any) { toast.error(e.message || 'Failed'); }
    setSending(false);
  };

  const likeComment = async (c: CommunityComment) => {
    try {
      const d = await communityApi.likeComment(id, c.id);
      const update = (list: CommunityComment[]): CommunityComment[] =>
        list.map((x) => x.id === c.id ? { ...x, liked_by_me: d.liked, likes_count: d.likes_count } : { ...x, replies: x.replies ? update(x.replies) : x.replies });
      setComments(update);
    } catch (e: any) { toast.error(e.message || 'Failed'); }
  };

  const acceptAnswer = async (c: CommunityComment) => {
    try {
      const d = await communityApi.acceptAnswer(id, c.id);
      const update = (list: CommunityComment[]): CommunityComment[] =>
        list.map((x) => ({ ...x, is_answer: x.id === c.id ? d.is_answer : false, replies: x.replies }));
      setComments(update);
      toast.success(d.is_answer ? t('تم اعتماد الإجابة', 'Answer accepted') : t('تم إلغاء الاعتماد', 'Answer unaccepted'));
    } catch (e: any) { toast.error(e.message || 'Failed'); }
  };

  const removeComment = async (c: CommunityComment) => {
    if (!confirm(t('حذف هذا التعليق؟', 'Delete this comment?'))) return;
    try {
      await communityApi.deleteComment(id, c.id);
      toast.success(t('تم الحذف', 'Deleted'));
      load();
    } catch (e: any) { toast.error(e.message || 'Failed'); }
  };

  const likeCommentReply = async (replyId: string) => {
    try {
      const d = await communityApi.likeComment(id, replyId);
      const update = (list: CommunityComment[]): CommunityComment[] =>
        list.map((x) => x.replies
          ? { ...x, replies: x.replies.map((r) => r.id === replyId ? { ...r, liked_by_me: d.liked, likes_count: d.likes_count } : r) }
          : x);
      setComments(update);
    } catch (e: any) { toast.error(e.message || 'Failed'); }
  };

  const removeReply = async (parentId: string, replyId: string) => {
    if (!confirm(t('حذف هذا الرد؟', 'Delete this reply?'))) return;
    try {
      await communityApi.deleteComment(id, replyId);
      toast.success(t('تم الحذف', 'Deleted'));
      load();
    } catch (e: any) { toast.error(e.message || 'Failed'); }
  };

  const canActOn = (c: CommunityComment) => isTeacher || canManage || c.author?.id === user?.id;
  const canAccept = (c: CommunityComment) => (thread?.author?.id === user?.id || isTeacher || canManage) && thread?.type === 'question';
  const canReply = () => thread?.status === 'open';

  if (loading) return <CardSkeleton />;

  if (!thread) {
    return (
      <Card className="max-w-md mx-auto mt-16"><CardContent className="flex flex-col items-center gap-4 py-14 text-gray-400">
        <MessagesSquare className="h-12 w-12 opacity-40" />
        <p className="text-lg">{t('الموضوع غير موجود أو محذوف', 'Thread not found or deleted')}</p>
        <Button variant="outline" className="rounded-full" onClick={() => window.location.href = '/community'}>{t('العودة للمجتمع', 'Back to community')}</Button>
      </CardContent></Card>
    );
  }

  const answers = comments;
  const isQuestion = thread.type === 'question';

  return (
    <div className="space-y-6 max-w-4xl mx-auto">
      <Card className="border-0 shadow-sm">
        <CardContent className="p-5 sm:p-7">
          <div className="flex flex-wrap items-center gap-2 mb-3">
            {thread.is_pinned && <Badge className="bg-violet-100 text-violet-700 border-0 text-[10px]"><Pin className="h-3 w-3 me-1" />{t('مثبت', 'Pinned')}</Badge>}
            {isQuestion
              ? <Badge className="bg-amber-100 text-amber-700 border-0 text-[10px]"><HelpCircle className="h-3 w-3 me-1" />{t('سؤال', 'Question')}</Badge>
              : thread.type === 'announcement'
                ? <Badge className="bg-red-100 text-red-700 border-0 text-[10px]">{t('إعلان', 'Announcement')}</Badge>
                : <Badge className="bg-teal-100 text-teal-700 border-0 text-[10px]">{t('نقاش', 'Discussion')}</Badge>}
            {thread.group && (
              <a href={`/community/groups/${thread.group.id}`}>
                <Badge variant="outline" className="text-[10px] hover:border-teal-300 hover:text-teal-700 transition-colors">{thread.group.name}</Badge>
              </a>
            )}
            {thread.status === 'closed'
              ? <Badge className="bg-gray-100 text-gray-500 border-0 text-[10px]"><Lock className="h-3 w-3 me-1" />{t('مغلق', 'Closed')}</Badge>
              : <Badge className="bg-green-100 text-green-700 border-0 text-[10px]"><LockOpen className="h-3 w-3 me-1" />{t('مفتوح', 'Open')}</Badge>}
          </div>

          <h1 className="text-2xl font-bold leading-snug">{thread.title}</h1>

          <div className="flex items-center gap-2 mt-3 text-xs text-gray-400 flex-wrap">
            {thread.author?.avatar_url
              ? <img src={thread.author.avatar_url} className="h-7 w-7 rounded-full object-cover" alt="" />
              : <span className="flex h-7 w-7 items-center justify-center rounded-full bg-teal-100 text-[10px] font-bold text-teal-700">{initials(thread.author?.name)}</span>}
            <span className="font-medium text-gray-600">{thread.author?.name}</span>
            <span>·</span>
            <span>{new Date(thread.created_at).toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</span>
            <span className="flex items-center gap-1 ms-1"><Eye className="h-3.5 w-3.5" />{thread.views_count} {t('مشاهدة', 'views')}</span>
          </div>

          <div className="mt-5 whitespace-pre-wrap text-[15px] leading-7 text-gray-800">{thread.body}</div>

          {thread.tags.length > 0 && (
            <div className="flex flex-wrap gap-1.5 mt-5">
              {thread.tags.map((tag) => (
                <span key={tag} className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 text-[11px] text-gray-500">
                  <Tag className="h-3 w-3" />{tag}
                </span>
              ))}
            </div>
          )}

          <div className="flex items-center justify-between gap-2 mt-6 pt-4 border-t flex-wrap">
            <button onClick={likeThread} className={thread.liked_by_me
              ? 'flex items-center gap-2 rounded-full bg-red-50 px-4 py-2 text-sm font-medium text-red-500'
              : 'flex items-center gap-2 rounded-full bg-gray-50 px-4 py-2 text-sm text-gray-600 hover:bg-red-50 hover:text-red-500 transition-colors'}>
              <Heart className={thread.liked_by_me ? 'h-4 w-4 fill-current' : 'h-4 w-4'} />
              {thread.likes_count} {t('إعجاب', 'Likes')}
            </button>
            <div className="flex items-center gap-1 flex-wrap">
              {isTeacher && (
                <Button variant="ghost" size="sm" className="rounded-full text-xs gap-1.5" onClick={pin} disabled={busy}>
                  <Pin className="h-3.5 w-3.5" />{thread.is_pinned ? t('إلغاء التثبيت', 'Unpin') : t('تثبيت', 'Pin')}
                </Button>
              )}
              {(thread.author?.id === user?.id || canManage) && (
                <>
                  <Button variant="ghost" size="sm" className="rounded-full text-xs gap-1.5" onClick={close} disabled={busy}>
                    <Lock className="h-3.5 w-3.5" />{thread.status === 'open' ? t('إغلاق', 'Close') : t('فتح', 'Reopen')}
                  </Button>
                  <Button variant="ghost" size="sm" className="rounded-full text-xs gap-1.5" onClick={openEdit}><Pencil className="h-3.5 w-3.5" />{t('تعديل', 'Edit')}</Button>
                  <Button variant="ghost" size="sm" className="rounded-full text-xs gap-1.5 text-red-500" onClick={removeThread}><Trash2 className="h-3.5 w-3.5" />{t('حذف', 'Delete')}</Button>
                </>
              )}
            </div>
          </div>
        </CardContent>
      </Card>

      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold flex items-center gap-2">
          {isQuestion ? <CheckCircle2 className="h-5 w-5 text-green-500" /> : <MessageSquare className="h-5 w-5 text-teal-600" />}
          {isQuestion
            ? `${t('الإجابات', 'Answers')} (${thread.answers_count})`
            : `${t('التعليقات', 'Comments')} (${thread.comments_count})`}
        </h2>
      </div>

      {thread.status === 'closed' && (
        <div className="flex items-center gap-2 rounded-xl bg-gray-50 p-4 text-sm text-gray-500">
          <Lock className="h-4 w-4" />{t('هذا الموضوع مغلق ولا يمكن إضافة تعليقات جديدة', 'This thread is closed and no new comments can be added')}
        </div>
      )}

      <div className="space-y-3">
        {answers.length === 0 ? (
          <EmptyState icon={MessageSquare} title={isQuestion ? t('لا توجد إجابات بعد — كن أول من يجيب', 'No answers yet — be the first to answer') : t('لا توجد تعليقات بعد', 'No comments yet')} />
        ) : (
          answers.map((c) => (
            <CommentItem key={c.id} comment={c} isQuestion={isQuestion}
              canActOn={canActOn(c)} canAccept={canAccept(c)} canReply={canReply()}
              replyingTo={replyingTo} setReplyingTo={setReplyingTo} replyText={replyText} setReplyText={setReplyText}
              sending={sending}
              onLike={() => likeComment(c)} onAccept={() => acceptAnswer(c)}
              onReply={(text) => sendComment(c.id, text)} onDelete={() => removeComment(c)}
              onLikeReply={likeCommentReply} onDeleteReply={removeReply} />
          ))
        )}
      </div>

      {thread.status === 'open' && (
        <Card className="border-0 shadow-sm">
          <CardContent className="p-4 sm:p-5">
            <Label className="mb-2 block">{isQuestion ? t('أضف إجابتك', 'Add your answer') : t('أضف تعليقاً', 'Add a comment')}</Label>
            <Textarea value={comment} onChange={(e) => setComment(e.target.value)} className="min-h-[100px]" placeholder={t('اكتب هنا...', 'Write here...')} />
            <div className="flex justify-end mt-3">
              <Button onClick={() => sendComment()} disabled={sending || !comment.trim()} className="gap-2 rounded-full">
                {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}{t('نشر', 'Post')}
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      <Dialog open={showEdit} onOpenChange={setShowEdit} className="max-w-2xl">
        <DialogContent><DialogHeader><DialogTitle>{t('تعديل الموضوع', 'Edit thread')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2"><Label>{t('العنوان', 'Title')} *</Label><Input value={editForm.title} onChange={(e) => setEditForm({ ...editForm, title: e.target.value })} className="h-11" /></div>
            <div className="space-y-2"><Label>{t('المحتوى', 'Content')} *</Label><Textarea value={editForm.body} onChange={(e) => setEditForm({ ...editForm, body: e.target.value })} className="min-h-[160px]" /></div>
            <div className="space-y-2"><Label>{t('الوسوم (مفصولة بفواصل)', 'Tags (comma separated)')}</Label><Input value={editForm.tags} onChange={(e) => setEditForm({ ...editForm, tags: e.target.value })} className="h-11" /></div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowEdit(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={submitEdit} disabled={saving} className="gap-2 rounded-full">{saving && <Loader2 className="h-4 w-4 animate-spin" />}{t('حفظ', 'Save')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function CommentItem({ comment, isQuestion, canActOn, canAccept, canReply, replyingTo, setReplyingTo, replyText, setReplyText, sending, onLike, onAccept, onReply, onDelete, onLikeReply, onDeleteReply }: {
  comment: CommunityComment;
  isQuestion: boolean;
  canActOn: boolean;
  canAccept: boolean;
  canReply: boolean;
  replyingTo: CommunityComment | null;
  setReplyingTo: (c: CommunityComment | null) => void;
  replyText: string;
  setReplyText: (v: string) => void;
  sending: boolean;
  onLike: () => void;
  onAccept: () => void;
  onReply: (text: string) => void;
  onDelete: () => void;
  onLikeReply: (replyId: string) => void;
  onDeleteReply: (parentId: string, replyId: string) => void;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const isReplying = replyingTo?.id === comment.id;

  return (
    <Card className={comment.is_answer ? 'border-2 border-green-400 shadow-sm' : 'border-0 shadow-sm'}>
      <CardContent className="p-4 sm:p-5">
        {comment.is_answer && (
          <div className="flex items-center gap-1.5 rounded-full bg-green-50 text-green-700 text-[11px] font-semibold px-3 py-1 w-fit mb-3">
            <CheckCircle2 className="h-3.5 w-3.5" />{t('الإجابة المعتمدة', 'Accepted answer')}
          </div>
        )}
        <div className="flex items-center gap-2 text-xs text-gray-400">
          {comment.author?.avatar_url
            ? <img src={comment.author.avatar_url} className="h-7 w-7 rounded-full object-cover" alt="" />
            : <span className="flex h-7 w-7 items-center justify-center rounded-full bg-teal-100 text-[10px] font-bold text-teal-700">{initials(comment.author?.name)}</span>}
          <span className="font-medium text-gray-600">{comment.author?.name}</span>
          <span>·</span>
          <span>{formatRelativeDate(comment.created_at, locale)}</span>
        </div>

        <p className="mt-3 whitespace-pre-wrap text-sm leading-7 text-gray-800">{comment.body}</p>

        <div className="flex items-center gap-2 mt-4 pt-3 border-t text-xs flex-wrap">
          <button onClick={onLike} className={comment.liked_by_me
            ? 'flex items-center gap-1 text-red-500 font-medium'
            : 'flex items-center gap-1 text-gray-500 hover:text-red-500 transition-colors'}>
            <Heart className={comment.liked_by_me ? 'h-3.5 w-3.5 fill-current' : 'h-3.5 w-3.5'} />{comment.likes_count}
          </button>
          {canReply && (
            <button onClick={() => setReplyingTo(isReplying ? null : comment)} className="flex items-center gap-1 text-gray-500 hover:text-teal-700 transition-colors">
              <Reply className="h-3.5 w-3.5" />{t('رد', 'Reply')}
            </button>
          )}
          {isQuestion && !comment.is_answer && canAccept && (
            <button onClick={onAccept} className="flex items-center gap-1 text-green-600 hover:text-green-700 transition-colors">
              <CheckCircle2 className="h-3.5 w-3.5" />{t('اعتماد كإجابة', 'Accept as answer')}
            </button>
          )}
          {isQuestion && comment.is_answer && canAccept && (
            <button onClick={onAccept} className="flex items-center gap-1 text-gray-400 hover:text-gray-600 transition-colors">
              {t('إلغاء الاعتماد', 'Unaccept')}
            </button>
          )}
          {canActOn && (
            <button onClick={onDelete} className="flex items-center gap-1 text-gray-400 hover:text-red-500 transition-colors ms-auto">
              <Trash2 className="h-3.5 w-3.5" />{t('حذف', 'Delete')}
            </button>
          )}
        </div>

        {isReplying && (
          <div className="mt-3 flex gap-2">
            <Input value={replyText} onChange={(e) => setReplyText(e.target.value)} placeholder={t('اكتب رداً...', 'Write a reply...')} className="h-10 flex-1 min-w-0" />
            <Button size="sm" className="rounded-full shrink-0" onClick={() => onReply(replyText)} disabled={sending || !replyText.trim()}>
              {sending ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Send className="h-3.5 w-3.5" />}
            </Button>
          </div>
        )}

        {comment.replies && comment.replies.length > 0 && (
          <div className="mt-4 space-y-2 border-s-2 border-gray-100 ps-4">
            {comment.replies.map((r) => (
              <div key={r.id} className="rounded-xl bg-gray-50 p-3">
                <div className="flex items-center gap-2 text-[11px] text-gray-400">
                  <span className="flex h-5 w-5 items-center justify-center rounded-full bg-teal-100 text-[9px] font-bold text-teal-700">{initials(r.author?.name)}</span>
                  <span className="font-medium text-gray-600">{r.author?.name}</span>
                  <span>·</span>
                  <span>{formatRelativeDate(r.created_at, locale)}</span>
                </div>
                <p className="mt-1.5 text-sm whitespace-pre-wrap text-gray-700">{r.body}</p>
                <div className="flex items-center gap-2 mt-2">
                  <button onClick={() => onLikeReply(r.id)} className={r.liked_by_me ? 'flex items-center gap-1 text-[11px] text-red-500' : 'flex items-center gap-1 text-[11px] text-gray-400 hover:text-red-500'}>
                    <Heart className={r.liked_by_me ? 'h-3 w-3 fill-current' : 'h-3 w-3'} />{r.likes_count}
                  </button>
                  {canActOn && (
                    <button onClick={() => onDeleteReply(comment.id, r.id)} className="flex items-center gap-1 text-[11px] text-gray-400 hover:text-red-500" aria-label={t('حذف', 'Delete')} title={t('حذف', 'Delete')}>
                      <Trash2 className="h-3 w-3" />
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function initials(name?: string | null): string {
  if (!name) return '؟';
  return name.trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
}
