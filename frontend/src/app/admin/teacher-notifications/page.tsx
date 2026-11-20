'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { useLocale } from '@/store/locale';
import { useAuth } from '@/store';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { TableSkeleton } from '@/components/ui/skeleton';
import { Alert } from '@/components/ui/alert';
import {
  BellRing, Loader2, Plus, Search, Send, Ban, Trash2, Pencil, Users, RefreshCcw,
  CheckCheck, Eye, History, LifeBuoy, ShieldAlert,
} from 'lucide-react';
import { toast } from 'sonner';
import { adminNotificationsApi } from '@/services/endpoints';
import {
  CATEGORY_META, CATEGORY_OPTIONS, STATUS_META, PLATFORM_SENDER,
  categoryLabel, statusLabel, formatDateTime,
} from '@/lib/platform-notifications';
import { cn } from '@/lib/utils';

const EMPTY_FORM = {
  title: '',
  body: '',
  category: 'news',
  audience: 'all' as 'all' | 'selected',
  target_user_ids: [] as string[],
};

function errMsg(e: any, fallback: string): string {
  return e?.response?.data?.message || fallback;
}

/**
 * Super-Admin communication centre for teachers (إشعارات المعلمين).
 *
 * The notification row is the single source of truth: editing it after sending
 * is reflected for every recipient, and retracting / deleting removes it from
 * every recipient list, detail view and counter while a protected audit trail
 * survives.
 */
export default function AdminTeacherNotificationsPage() {
  const { locale } = useLocale();
  const { check } = useAuth();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [items, setItems] = useState<any[]>([]);
  const [stats, setStats] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState<string | null>(null);

  const [filters, setFilters] = useState({ search: '', status: '', category: '', from: '', to: '' });

  // Create / edit
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<any | null>(null);
  const [form, setForm] = useState({ ...EMPTY_FORM });
  const [notifyRecipients, setNotifyRecipients] = useState(false);
  const [saving, setSaving] = useState(false);

  // Recipient picker
  const [teachers, setTeachers] = useState<any[]>([]);
  const [teacherSearch, setTeacherSearch] = useState('');
  const [previewCount, setPreviewCount] = useState<number | null>(null);

  // Detail
  const [detail, setDetail] = useState<any | null>(null);

  // Confirm + contact
  const [confirm, setConfirm] = useState<{ title: string; description: string; destructive?: boolean; action: () => Promise<void> } | null>(null);
  const [confirmBusy, setConfirmBusy] = useState(false);
  const [contactFor, setContactFor] = useState<{ user: any; notificationId?: string } | null>(null);
  const [contactForm, setContactForm] = useState({ subject: '', body: '' });
  const [contactBusy, setContactBusy] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const params: Record<string, string> = {};
      Object.entries(filters).forEach(([k, v]) => { if (v) params[k] = v; });
      const d = await adminNotificationsApi.list(params);
      setItems(d.notifications || []);
      setStats(d.stats || null);
    } catch (e) {
      setError(t('تعذر تحميل الإشعارات', 'Failed to load notifications'));
    } finally {
      setLoading(false);
    }
  }, [filters, locale]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => { if (check()) load(); }, [check, load]);

  const loadTeachers = useCallback(async () => {
    try {
      setTeachers(await adminNotificationsApi.teachers());
    } catch { /* */ }
  }, []);

  // Live recipient count preview.
  useEffect(() => {
    if (!formOpen) return;
    let alive = true;
    adminNotificationsApi
      .previewRecipients({ audience: form.audience, target_user_ids: form.target_user_ids })
      .then((d) => { if (alive) setPreviewCount(d.count); })
      .catch(() => { if (alive) setPreviewCount(null); });
    return () => { alive = false; };
  }, [formOpen, form.audience, form.target_user_ids]); // eslint-disable-line react-hooks/exhaustive-deps

  const openCreate = () => {
    setEditing(null);
    setForm({ ...EMPTY_FORM });
    setNotifyRecipients(false);
    setTeacherSearch('');
    setPreviewCount(null);
    setFormOpen(true);
    if (teachers.length === 0) loadTeachers();
  };

  const openEdit = (n: any) => {
    setEditing(n);
    setForm({
      title: n.title,
      body: n.body,
      category: n.category,
      audience: n.audience,
      target_user_ids: (n.targets || []).map((x: any) => x.user_id),
    });
    setNotifyRecipients(false);
    setTeacherSearch('');
    setPreviewCount(null);
    setFormOpen(true);
    if (teachers.length === 0) loadTeachers();
  };

  const isLocked = !!editing && editing.status !== 'draft';

  const afterWrite = async () => {
    setFormOpen(false);
    setDetail(null);
    await load();
  };

  const doSave = async (sendNow: boolean) => {
    if (!form.title.trim() || !form.body.trim()) {
      toast.error(t('العنوان والنص مطلوبان', 'Title and body are required'));
      return;
    }
    if (!isLocked && form.audience === 'selected' && form.target_user_ids.length === 0) {
      toast.error(t('اختر معلمًا واحدًا على الأقل', 'Pick at least one teacher'));
      return;
    }
    setSaving(true);
    try {
      const payload: any = {
        title: form.title.trim(),
        body: form.body.trim(),
        category: form.category,
      };
      if (!isLocked) {
        payload.audience = form.audience;
        if (form.audience === 'selected') payload.target_user_ids = form.target_user_ids;
      } else {
        payload.notify_recipients = notifyRecipients;
      }

      let id = editing?.id as string | undefined;
      if (editing) {
        await adminNotificationsApi.update(editing.id, payload);
      } else {
        const created = await adminNotificationsApi.create(payload);
        id = created.id;
      }

      if (sendNow && id) {
        await adminNotificationsApi.send(id);
      }
      toast.success(sendNow ? t('تم الإرسال', 'Sent') : t('تم الحفظ', 'Saved'));
      await afterWrite();
    } catch (e) {
      toast.error(errMsg(e, t('تعذر الحفظ', 'Could not save')));
    } finally {
      setSaving(false);
    }
  };

  const requestSend = () => {
    setConfirm({
      title: t('تأكيد الإرسال', 'Confirm sending'),
      description: t(
        `سيُرسل هذا الإشعار إلى ${previewCount ?? 'العدد المحسوب من '} المعلمين. لا يمكن التراجع عن الإرسال إلا بالسحب.`,
        `This will be sent to ${previewCount ?? 'the resolved number of'} teachers. It can only be undone by retracting.`,
      ),
      action: async () => { await doSave(true); },
    });
  };

  const doAction = async (id: string, fn: () => Promise<any>) => {
    setBusyId(id);
    try { await fn(); await load(); } catch (e) { toast.error(errMsg(e, t('تعذر التنفيذ', 'Action failed'))); } finally { setBusyId(null); }
  };

  const requestRetract = (n: any) => setConfirm({
    title: t('سحب الإشعار', 'Retract notification'),
    description: t('سيختفي الإشعار من جميع المعلمين ومن عداداتهم فورًا.', 'It will disappear from every teacher and counter immediately.'),
    destructive: true,
    action: async () => { await doAction(n.id, () => adminNotificationsApi.retract(n.id)); setDetail(null); },
  });

  const requestDelete = (n: any) => setConfirm({
    title: t('حذف الإشعار', 'Delete notification'),
    description: t('سيُحذف نهائيًا من المستلمين، مع الحفاظ على سجل التدقيق الإداري.', 'Permanently removed for recipients; the admin audit trail is preserved.'),
    destructive: true,
    action: async () => { await doAction(n.id, () => adminNotificationsApi.remove(n.id)); setDetail(null); },
  });

  const runConfirm = async () => {
    if (!confirm) return;
    setConfirmBusy(true);
    try { await confirm.action(); setConfirm(null); } finally { setConfirmBusy(false); }
  };

  const toggleTarget = (id: string) => {
    setForm((f) => ({
      ...f,
      target_user_ids: f.target_user_ids.includes(id)
        ? f.target_user_ids.filter((x) => x !== id)
        : [...f.target_user_ids, id],
    }));
  };

  const sendContact = async () => {
    if (!contactFor || !contactForm.subject.trim() || !contactForm.body.trim()) {
      toast.error(t('الموضوع والنص مطلوبان', 'Subject and body are required'));
      return;
    }
    setContactBusy(true);
    try {
      await adminNotificationsApi.contact({
        user_id: contactFor.user.id,
        subject: contactForm.subject.trim(),
        body: contactForm.body.trim(),
        notification_id: contactFor.notificationId,
      });
      toast.success(t('تم إرسال الرسالة عبر نظام الدعم', 'Sent via the support system'));
      setContactFor(null);
      setContactForm({ subject: '', body: '' });
    } catch (e) {
      toast.error(errMsg(e, t('تعذر الإرسال', 'Could not send')));
    } finally {
      setContactBusy(false);
    }
  };

  const filteredTeachers = useMemo(() => {
    const q = teacherSearch.trim().toLowerCase();
    if (!q) return teachers;
    return teachers.filter((x) => (x.name || '').toLowerCase().includes(q) || (x.email || '').toLowerCase().includes(q));
  }, [teachers, teacherSearch]);

  const statCards = [
    { label: t('الإجمالي', 'Total'), value: stats?.total ?? 0, color: '' },
    { label: t('مسودات', 'Drafts'), value: stats?.draft ?? 0, color: 'text-gray-500' },
    { label: t('مُرسلة', 'Sent'), value: stats?.sent ?? 0, color: 'text-teal-700' },
    { label: t('مسحوبة', 'Retracted'), value: stats?.retracted ?? 0, color: 'text-amber-600' },
    { label: t('معلمون مؤهلون', 'Eligible teachers'), value: stats?.teachers ?? 0, color: 'text-sky-600' },
  ];

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <BellRing className="h-6 w-6 text-teal-700" />
          </span>
          <div>
            <h1 className="text-2xl font-bold sm:text-3xl">{t('إشعارات المعلمين', 'Teacher Notifications')}</h1>
            <p className="mt-1 text-sm text-muted-foreground">
              {t('أخبار وتحديثات وتنبيهات من إدارة منصة فهمني إلى معلمي المنصة', 'News, updates and alerts from the Fahimni team to platform teachers')}
            </p>
          </div>
        </div>
        <Button onClick={openCreate} data-testid="create-notification">
          <Plus className="h-4 w-4" /> {t('إشعار جديد', 'New notification')}
        </Button>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        {statCards.map((s, i) => (
          <Card key={i}><CardContent className="p-4">
            <p className="text-xs text-muted-foreground">{s.label}</p>
            <p className={cn('mt-1 text-xl font-bold', s.color)}>{s.value}</p>
          </CardContent></Card>
        ))}
      </div>

      {/* Filters */}
      <Card><CardContent className="flex flex-wrap items-end gap-3 p-4">
        <div className="min-w-[180px] flex-1">
          <Label>{t('بحث', 'Search')}</Label>
          <div className="relative">
            <Search className="pointer-events-none absolute top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground start-3" />
            <Input className="ps-9" value={filters.search} onChange={(e) => setFilters({ ...filters, search: e.target.value })} placeholder={t('عنوان أو نص…', 'Title or body…')} />
          </div>
        </div>
        <div className="w-[150px]">
          <Label>{t('الحالة', 'Status')}</Label>
          <Select value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
            <option value="">{t('الكل', 'All')}</option>
            <option value="draft">{t('مسودة', 'Draft')}</option>
            <option value="sent">{t('مُرسل', 'Sent')}</option>
            <option value="retracted">{t('مسحوب', 'Retracted')}</option>
          </Select>
        </div>
        <div className="w-[150px]">
          <Label>{t('التصنيف', 'Category')}</Label>
          <Select value={filters.category} onChange={(e) => setFilters({ ...filters, category: e.target.value })}>
            <option value="">{t('الكل', 'All')}</option>
            {CATEGORY_OPTIONS.map((c) => <option key={c} value={c}>{categoryLabel(c, locale)}</option>)}
          </Select>
        </div>
        <div className="w-[150px]">
          <Label>{t('من تاريخ', 'From')}</Label>
          <Input type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
        </div>
        <div className="w-[150px]">
          <Label>{t('إلى تاريخ', 'To')}</Label>
          <Input type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
        </div>
        <Button variant="outline" onClick={load} aria-label={t('تحديث', 'Refresh')}>
          <RefreshCcw className="h-4 w-4" />
        </Button>
      </CardContent></Card>

      {error && <Alert variant="error">{error}</Alert>}

      {loading ? (
        <TableSkeleton rows={5} cols={4} />
      ) : items.length === 0 ? (
        <EmptyState
          icon={BellRing}
          title={t('لا توجد إشعارات', 'No notifications')}
          description={t('أنشئ إشعارًا جديدًا لإرساله إلى معلمي المنصة.', 'Create a new notification to send to platform teachers.')}
          action={<Button onClick={openCreate}><Plus className="h-4 w-4" /> {t('إشعار جديد', 'New notification')}</Button>}
        />
      ) : (
        <div className="space-y-3">
          {items.map((n) => (
            <Card key={n.id} data-testid="admin-notification-card">
              <CardContent className="p-4">
                <div className="flex flex-wrap items-start gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-base font-bold">{n.title}</span>
                      <Badge className={CATEGORY_META[n.category]?.className}>{categoryLabel(n.category, locale)}</Badge>
                      <Badge className={STATUS_META[n.status]?.className}>{statusLabel(n.status, locale)}</Badge>
                    </div>
                    <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">{n.body}</p>
                    <div className="mt-2 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                      <span>{n.audience === 'selected' ? t('معلمون محددون', 'Selected teachers') : t('جميع المعلمين', 'All teachers')}</span>
                      <span className="flex items-center gap-1"><Users className="h-3.5 w-3.5" /> {t('المستلمون', 'Recipients')}: <b className="text-foreground">{n.recipient_count ?? 0}</b></span>
                      <span className="flex items-center gap-1"><CheckCheck className="h-3.5 w-3.5" /> {t('قرأوا', 'Read')}: <b className="text-foreground">{n.read_count ?? 0}</b></span>
                      <span>{formatDateTime(n.published_at || n.created_at)}</span>
                    </div>
                  </div>
                  <div className="flex flex-wrap items-center gap-1.5">
                    <Button variant="ghost" size="sm" onClick={() => adminNotificationsApi.get(n.id).then(setDetail)} data-testid="view-notification">
                      <Eye className="h-4 w-4" /> {t('تفاصيل', 'Details')}
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => openEdit(n)} data-testid="edit-notification">
                      <Pencil className="h-4 w-4" /> {t('تعديل', 'Edit')}
                    </Button>
                    {n.status === 'draft' && (
                      <Button size="sm" disabled={busyId === n.id}
                        onClick={() => setConfirm({
                          title: t('تأكيد الإرسال', 'Confirm sending'),
                          description: t('سيُرسل هذا الإشعار إلى المعلمين المستهدفين.', 'This will be sent to the targeted teachers.'),
                          action: async () => { await doAction(n.id, () => adminNotificationsApi.send(n.id)); },
                        })}
                        data-testid="send-notification">
                        {busyId === n.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />} {t('إرسال', 'Send')}
                      </Button>
                    )}
                    {n.status === 'sent' && (
                      <Button variant="outline" size="sm" disabled={busyId === n.id} onClick={() => requestRetract(n)} data-testid="retract-notification">
                        <Ban className="h-4 w-4" /> {t('سحب', 'Retract')}
                      </Button>
                    )}
                    <Button variant="destructive" size="sm" disabled={busyId === n.id} onClick={() => requestDelete(n)} data-testid="delete-notification">
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* ── Create / edit ── */}
      <Dialog open={formOpen} onOpenChange={setFormOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>{editing ? t('تعديل الإشعار', 'Edit notification') : t('إشعار جديد', 'New notification')}</DialogTitle>
            {isLocked && (
              <p className="mt-1 flex items-center gap-1 text-xs text-amber-600">
                <ShieldAlert className="h-3.5 w-3.5" />
                {t('هذا الإشعار مُرسل: التعديل ينعكس فورًا على جميع المستلمين.', 'Already sent: edits are reflected immediately for every recipient.')}
              </p>
            )}
          </DialogHeader>

          <div className="space-y-4">
            <div>
              <Label>{t('العنوان', 'Title')}</Label>
              <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} data-testid="notif-title" />
            </div>
            <div>
              <Label>{t('المحتوى', 'Body')}</Label>
              <Textarea rows={5} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} data-testid="notif-body" />
            </div>
            <div>
              <Label>{t('التصنيف', 'Category')}</Label>
              <Select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} data-testid="notif-category">
                {CATEGORY_OPTIONS.map((c) => <option key={c} value={c}>{categoryLabel(c, locale)}</option>)}
              </Select>
            </div>

            {!isLocked && (
              <>
                <div>
                  <Label>{t('الجمهور', 'Audience')}</Label>
                  <Select value={form.audience} onChange={(e) => setForm({ ...form, audience: e.target.value as 'all' | 'selected' })} data-testid="notif-audience">
                    <option value="all">{t('جميع المعلمين', 'All teachers')}</option>
                    <option value="selected">{t('معلمون محددون', 'Selected teachers')}</option>
                  </Select>
                </div>

                {form.audience === 'selected' && (
                  <div>
                    <Label>{t('اختر المعلمين', 'Choose teachers')}</Label>
                    <div className="relative mt-1">
                      <Search className="pointer-events-none absolute top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground start-3" />
                      <Input className="ps-9" value={teacherSearch} onChange={(e) => setTeacherSearch(e.target.value)} placeholder={t('ابحث بالاسم أو البريد…', 'Search by name or email…')} />
                    </div>
                    <div className="mt-2 max-h-52 overflow-y-auto rounded-xl border">
                      {filteredTeachers.length === 0 ? (
                        <p className="px-3 py-4 text-center text-sm text-muted-foreground">{t('لا يوجد معلمون', 'No teachers')}</p>
                      ) : filteredTeachers.map((tch) => (
                        <label key={tch.id} className="flex cursor-pointer items-center gap-3 border-b px-3 py-2 last:border-b-0 hover:bg-muted/60">
                          <input type="checkbox" checked={form.target_user_ids.includes(tch.id)} onChange={() => toggleTarget(tch.id)} data-testid="teacher-option" />
                          <span className="min-w-0">
                            <span className="block truncate text-sm font-semibold">{tch.name}</span>
                            <span className="block truncate text-xs text-muted-foreground">{tch.email}</span>
                          </span>
                        </label>
                      ))}
                    </div>
                  </div>
                )}
              </>
            )}

            {isLocked && editing?.status === 'sent' && (
              <label className="flex items-center gap-3 rounded-xl border p-3">
                <Switch checked={notifyRecipients} onCheckedChange={setNotifyRecipients} label={t('إشعار المستلمين بالتحديث', 'Notify recipients of the update')} />
                <span className="text-sm">
                  <span className="font-semibold">{t('إشعار المستلمين بالتحديث', 'Notify recipients of the update')}</span>
                  <span className="block text-xs text-muted-foreground">{t('يعيد إظهار الإشعار كغير مقروء عند الجميع.', 'Marks it unread again for everyone.')}</span>
                </span>
              </label>
            )}

            <Alert variant="info" className="text-xs">
              {t('عدد المستلمين المتوقع', 'Estimated recipients')}: <b data-testid="recipient-preview">{previewCount ?? '…'}</b>
            </Alert>
          </div>

          <DialogFooter className="flex-wrap">
            <Button variant="outline" onClick={() => setFormOpen(false)} disabled={saving}>{t('إلغاء', 'Cancel')}</Button>
            {isLocked ? (
              <Button onClick={() => doSave(false)} disabled={saving} data-testid="save-draft">
                {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : null} {t('حفظ التعديل', 'Save changes')}
              </Button>
            ) : (
              <>
                <Button variant="outline" onClick={() => doSave(false)} disabled={saving} data-testid="save-draft">
                  {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : null} {t('حفظ مسودة', 'Save draft')}
                </Button>
                <Button onClick={requestSend} disabled={saving} data-testid="save-send">
                  <Send className="h-4 w-4" /> {t('إرسال الآن', 'Send now')}
                </Button>
              </>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Detail / archive ── */}
      <Dialog open={!!detail} onOpenChange={(o) => { if (!o) setDetail(null); }}>
        <DialogContent className="max-w-3xl">
          {detail && (
            <>
              <DialogHeader>
                <div className="flex flex-wrap items-center gap-2">
                  <Badge className={CATEGORY_META[detail.category]?.className}>{categoryLabel(detail.category, locale)}</Badge>
                  <Badge className={STATUS_META[detail.status]?.className}>{statusLabel(detail.status, locale)}</Badge>
                  <span className="text-xs text-muted-foreground">{formatDateTime(detail.published_at || detail.created_at)}</span>
                </div>
                <DialogTitle className="mt-2">{detail.title}</DialogTitle>
                <p className="text-xs text-muted-foreground">
                  {t('من', 'From')}: {detail.sender?.name || (locale === 'ar' ? PLATFORM_SENDER.ar : PLATFORM_SENDER.en)} ·{' '}
                  {t('المستلمون', 'Recipients')}: {detail.recipient_count ?? 0} · {t('قرأوا', 'Read')}: {detail.read_count ?? 0}
                </p>
              </DialogHeader>

              <p className="whitespace-pre-wrap text-sm leading-relaxed">{detail.body}</p>

              {/* Recipients */}
              <div className="mt-4">
                <p className="mb-2 flex items-center gap-2 text-sm font-bold"><Users className="h-4 w-4" /> {t('المستلمون', 'Recipients')}</p>
                <div className="max-h-52 overflow-y-auto rounded-xl border">
                  {(detail.receipts || []).length === 0 ? (
                    <p className="px-3 py-4 text-center text-sm text-muted-foreground">{t('لا مستلمين', 'No recipients')}</p>
                  ) : (detail.receipts || []).map((r: any) => (
                    <div key={r.id} className="flex items-center gap-3 border-b px-3 py-2 last:border-b-0">
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-semibold">{r.user?.name || '—'}</span>
                        <span className="block truncate text-xs text-muted-foreground">{r.user?.email}</span>
                      </span>
                      <Badge className={r.read_at ? 'bg-teal-500/10 text-teal-700' : 'bg-gray-100 text-gray-500'}>
                        {r.read_at ? t('قرأ', 'Read') : t('لم يقرأ', 'Unread')}
                      </Badge>
                      <Button variant="ghost" size="sm" onClick={() => { setContactFor({ user: r.user, notificationId: detail.id }); setContactForm({ subject: `${t('بخصوص', 'About')}: ${detail.title}`, body: '' }); }}>
                        <LifeBuoy className="h-4 w-4" /> {t('تواصل', 'Contact')}
                      </Button>
                    </div>
                  ))}
                </div>
              </div>

              {/* Audit trail */}
              <div className="mt-4">
                <p className="mb-2 flex items-center gap-2 text-sm font-bold"><History className="h-4 w-4" /> {t('سجل التعديلات', 'Audit trail')}</p>
                <div className="space-y-1.5 rounded-xl border p-3 text-xs">
                  {(detail.revisions || []).length === 0 ? (
                    <p className="text-muted-foreground">{t('لا يوجد سجل', 'No history')}</p>
                  ) : (detail.revisions || []).map((rev: any) => (
                    <div key={rev.id} className="flex flex-wrap items-center gap-2">
                      <Badge variant="outline">{rev.action}</Badge>
                      <span className="font-semibold">{rev.actor?.name || '—'}</span>
                      <span className="text-muted-foreground">{formatDateTime(rev.created_at)}</span>
                    </div>
                  ))}
                </div>
              </div>

              <DialogFooter className="mt-6 flex-wrap justify-between">
                <div className="flex flex-wrap gap-2">
                  <Button variant="outline" onClick={() => openEdit(detail)}><Pencil className="h-4 w-4" /> {t('تعديل', 'Edit')}</Button>
                  {detail.status === 'sent' && (
                    <Button variant="outline" onClick={() => requestRetract(detail)}><Ban className="h-4 w-4" /> {t('سحب', 'Retract')}</Button>
                  )}
                  <Button variant="destructive" onClick={() => requestDelete(detail)}><Trash2 className="h-4 w-4" /> {t('حذف', 'Delete')}</Button>
                </div>
                <Button onClick={() => setDetail(null)}>{t('إغلاق', 'Close')}</Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>

      {/* ── Confirm ── */}
      <Dialog open={!!confirm} onOpenChange={(o) => { if (!o) setConfirm(null); }}>
        <DialogContent>
          {confirm && (
            <>
              <DialogHeader><DialogTitle>{confirm.title}</DialogTitle></DialogHeader>
              <p className="text-sm text-muted-foreground">{confirm.description}</p>
              <DialogFooter>
                <Button variant="outline" onClick={() => setConfirm(null)} disabled={confirmBusy}>{t('إلغاء', 'Cancel')}</Button>
                <Button variant={confirm.destructive ? 'destructive' : 'default'} onClick={runConfirm} disabled={confirmBusy} data-testid="confirm-action">
                  {confirmBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : null} {t('تأكيد', 'Confirm')}
                </Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>

      {/* ── Contact a teacher (reuses support tickets) ── */}
      <Dialog open={!!contactFor} onOpenChange={(o) => { if (!o) setContactFor(null); }}>
        <DialogContent>
          {contactFor && (
            <>
              <DialogHeader><DialogTitle>{t('تواصل مع معلم', 'Contact a teacher')}</DialogTitle></DialogHeader>
              <p className="mb-3 text-sm text-muted-foreground">{contactFor.user?.name} · {contactFor.user?.email}</p>
              <div className="space-y-3">
                <div>
                  <Label>{t('الموضوع', 'Subject')}</Label>
                  <Input value={contactForm.subject} onChange={(e) => setContactForm({ ...contactForm, subject: e.target.value })} />
                </div>
                <div>
                  <Label>{t('الرسالة', 'Message')}</Label>
                  <Textarea rows={4} value={contactForm.body} onChange={(e) => setContactForm({ ...contactForm, body: e.target.value })} />
                </div>
              </div>
              <DialogFooter>
                <Button variant="outline" onClick={() => setContactFor(null)} disabled={contactBusy}>{t('إلغاء', 'Cancel')}</Button>
                <Button onClick={sendContact} disabled={contactBusy}>
                  {contactBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />} {t('إرسال', 'Send')}
                </Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
