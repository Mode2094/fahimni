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
import { Users, Plus, Loader2, Lock, Search, UserPlus, Check, Clock, MessagesSquare, Trash2, Pencil } from 'lucide-react';
import { toast } from 'sonner';
import { communityApi } from '@/services/community';
import type { CommunityGroup } from '@/types/api';

export default function GroupsPage() {
  const { check, user } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [groups, setGroups] = useState<CommunityGroup[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [isTeacher, setIsTeacher] = useState(false);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<CommunityGroup | null>(null);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState<Record<string, any>>({ name: '', description: '', visibility: 'public', join_policy: 'open' });
  const [busy, setBusy] = useState<string | null>(null);

  const load = () => {
    communityApi.groups(search.trim() ? { search: search.trim() } : {})
      .then(setGroups)
      .catch(() => setGroups([]))
      .finally(() => setLoading(false));
  };
  useEffect(() => {
    if (!check()) return;
    communityApi.overview().then((o) => setIsTeacher(o.is_teacher)).catch(() => {});
    load();
  }, []);

  const openAdd = () => { setEditing(null); setForm({ name: '', description: '', visibility: 'public', join_policy: 'open' }); setShowForm(true); };
  const openEdit = (g: CommunityGroup) => { setEditing(g); setForm({ name: g.name, description: g.description || '', visibility: g.visibility, join_policy: g.join_policy }); setShowForm(true); };

  const save = async () => {
    if (!form.name.trim()) { toast.error(t('أدخل اسم المجموعة', 'Enter a group name')); return; }
    setSaving(true);
    try {
      if (editing) {
        await communityApi.updateGroup(editing.id, { name: form.name.trim(), description: form.description.trim(), visibility: form.visibility, join_policy: form.join_policy });
        toast.success(t('تم تحديث المجموعة', 'Group updated'));
      } else {
        await communityApi.createGroup({ name: form.name.trim(), description: form.description.trim(), visibility: form.visibility, join_policy: form.join_policy });
        toast.success(t('تم إنشاء المجموعة', 'Group created'));
      }
      setShowForm(false);
      load();
    } catch (e: any) { toast.error(e.message || 'Failed'); }
    setSaving(false);
  };

  const remove = async (g: CommunityGroup) => {
    if (!confirm(t('حذف هذه المجموعة نهائياً؟', 'Delete this group permanently?'))) return;
    setBusy(g.id);
    try { await communityApi.deleteGroup(g.id); toast.success(t('تم الحذف', 'Deleted')); load(); } catch (e: any) { toast.error(e.message || 'Failed'); }
    setBusy(null);
  };

  const joinOrLeave = async (g: CommunityGroup) => {
    setBusy(g.id);
    try {
      if (g.is_member) {
        await communityApi.leaveGroup(g.id);
        toast.success(t('غادرت المجموعة', 'Left group'));
      } else {
        await communityApi.joinGroup(g.id);
        toast.success(t('تم الانضمام', 'Joined'));
      }
      load();
    } catch (e: any) { toast.error(e.message || 'Failed'); }
    setBusy(null);
  };

  const canManageGroup = (g: CommunityGroup) => isTeacher || g.my_role === 'moderator';

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl sm:text-3xl font-bold">{t('المجموعات', 'Groups')}</h1>
          <p className="text-gray-500 mt-1">{t('انضم لمجموعات زملائك حسب الاهتمامات والمواد', 'Join classmates groups by subject and interest')}</p>
        </div>
        {isTeacher && <Button onClick={openAdd} className="gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('مجموعة جديدة', 'New group')}</Button>}
      </div>

      <Card className="border-0 shadow-sm">
        <CardContent className="p-3 sm:p-4">
          <div className="relative max-w-md">
            <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
            <Input value={search} onChange={(e) => { setSearch(e.target.value); }} placeholder={t('ابحث عن مجموعة...', 'Search groups...')}
              className="h-10 ps-9"
              onKeyDown={(e) => { if (e.key === 'Enter') load(); }} />
          </div>
        </CardContent>
      </Card>

      {loading ? <CardSkeleton /> :
        groups.length === 0 ? (
          <EmptyState icon={Users} title={t('لا توجد مجموعات بعد', 'No groups yet')} description={t('انضم لمجموعة أو أنشئ واحدة لطلابك', 'Join a group or create one for your students')} action={isTeacher ? <Button onClick={openAdd} className="mt-2 gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('مجموعة جديدة', 'New group')}</Button> : undefined} />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {groups.map((g) => (
              <Card key={g.id} className="border-0 shadow-sm card-hover overflow-hidden flex flex-col">
                <div className="h-20 bg-gradient-to-br from-teal-500/15 via-teal-500/10 to-teal-500/5 flex items-center justify-center relative">
                  <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white shadow-lg">
                    <Users className="h-6 w-6 text-teal-600" />
                  </div>
                  <div className="absolute top-2 end-2 flex gap-1.5">
                    {g.visibility === 'private' && <Badge className="bg-black/50 text-white border-0 text-[10px]"><Lock className="h-3 w-3 me-1" />{t('خاصة', 'Private')}</Badge>}
                    {g.my_role === 'moderator' && <Badge className="bg-violet-500/80 text-white border-0 text-[10px]">★ {t('مشرف', 'Moderator')}</Badge>}
                  </div>
                </div>
                <CardContent className="p-5 flex-1 flex flex-col">
                  <h3 className="font-semibold text-lg">{g.name}</h3>
                  <p className="text-xs text-gray-400 mt-1 line-clamp-2 flex-1">{g.description || t('لا يوجد وصف', 'No description')}</p>
                  <div className="flex items-center gap-3 text-[11px] text-gray-400 mt-3">
                    <span className="flex items-center gap-1"><Users className="h-3 w-3" />{g.members_count}</span>
                    <span className="flex items-center gap-1"><MessagesSquare className="h-3 w-3" />{g.threads_count} {t('موضوع', 'topics')}</span>
                  </div>
                  <div className="flex items-center gap-2 mt-4 pt-3 border-t">
                    <Button size="sm" variant={g.is_member ? 'outline' : 'default'}
                      className="flex-1 gap-1.5 rounded-full" disabled={busy === g.id}
                      onClick={() => joinOrLeave(g)}>
                      {busy === g.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> :
                        g.membership_status === 'pending' ? <Clock className="h-3.5 w-3.5" /> :
                        g.is_member ? <Check className="h-3.5 w-3.5" /> : <UserPlus className="h-3.5 w-3.5" />}
                      {g.membership_status === 'pending' ? t('قيد الانتظار', 'Pending') : g.is_member ? t('غادر', 'Leave') : t('انضم', 'Join')}
                    </Button>
                    <Button size="sm" variant="outline" className="rounded-full" onClick={() => window.location.href = `/community/groups/${g.id}`}>
                      {t('تصفح', 'Browse')}
                    </Button>
                    {canManageGroup(g) && (
                      <>
                        <Button size="icon" variant="ghost" className="h-8 w-8 text-teal-700" onClick={() => openEdit(g)} aria-label={t('تعديل', 'Edit')} title={t('تعديل', 'Edit')}><Pencil className="h-3.5 w-3.5" /></Button>
                        <Button size="icon" variant="ghost" className="h-8 w-8 text-red-500" disabled={busy === g.id} onClick={() => remove(g)} aria-label={t('حذف', 'Delete')} title={t('حذف', 'Delete')}><Trash2 className="h-3.5 w-3.5" /></Button>
                      </>
                    )}
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

      <Dialog open={showForm} onOpenChange={setShowForm} className="max-w-xl">
        <DialogContent><DialogHeader><DialogTitle>{editing ? t('تعديل المجموعة', 'Edit group') : t('مجموعة جديدة', 'New group')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2"><Label>{t('الاسم', 'Name')} *</Label><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="h-11" /></div>
            <div className="space-y-2"><Label>{t('الوصف', 'Description')}</Label><Textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="min-h-[100px]" /></div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-2"><Label>{t('الرؤية', 'Visibility')}</Label>
                <Select value={form.visibility} onChange={(e) => setForm({ ...form, visibility: e.target.value })} className="h-11 border-gray-200">
                  <option value="public">{t('عامة — يراها الجميع', 'Public — visible to all')}</option>
                  <option value="private">{t('خاصة — للأعضاء فقط', 'Private — members only')}</option>
                </Select>
              </div>
              <div className="space-y-2"><Label>{t('الانضمام', 'Join policy')}</Label>
                <Select value={form.join_policy} onChange={(e) => setForm({ ...form, join_policy: e.target.value })} className="h-11 border-gray-200">
                  <option value="open">{t('انضمام مباشر', 'Open join')}</option>
                  <option value="request">{t('بطلب وموافقة', 'Request approval')}</option>
                </Select>
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowForm(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={save} disabled={saving} className="gap-2 rounded-full">{saving && <Loader2 className="h-4 w-4 animate-spin" />}{editing ? t('حفظ', 'Save') : t('إنشاء', 'Create')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
