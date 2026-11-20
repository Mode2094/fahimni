'use client';

import { useCallback, useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import {
  Users, Plus, Trash2, Pencil, Loader2, BookOpen, ClipboardList, BarChart3, Check, UserPlus,
} from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const hdrs = () => ({
  'Content-Type': 'application/json',
  'X-Tenant-Id': localStorage.getItem('tenant_id') || '',
  Accept: 'application/json',
});

type Group = {
  id: string; course_id: string; name: string; description?: string | null;
  is_active: boolean; members_count: number; lessons_count: number; exams_count: number;
  member_ids?: string[]; lesson_ids?: string[]; exam_ids?: string[];
};
type Candidate = { id: string; title: string; is_published?: boolean };
type Student = { id: string; name: string; email?: string };

/**
 * Manage in-course student groups ("Grade 10 — A/B/C") without cloning the
 * course. Separate from community/forum groups and from academic terms.
 * Membership never creates an enrolment, so a student in several groups still
 * uses a single seat.
 */
export default function CourseGroupsManager({
  open, onOpenChange, courseId,
}: {
  open: boolean;
  onOpenChange: (v: boolean) => void;
  courseId: string;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [loading, setLoading] = useState(false);
  const [groups, setGroups] = useState<Group[]>([]);
  const [students, setStudents] = useState<Student[]>([]);
  const [lessons, setLessons] = useState<Candidate[]>([]);
  const [exams, setExams] = useState<Candidate[]>([]);
  const [activeId, setActiveId] = useState<string | null>(null);
  const [tab, setTab] = useState<'members' | 'lessons' | 'exams' | 'results'>('members');
  const [busy, setBusy] = useState(false);

  // create/edit form
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Group | null>(null);
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');

  const [results, setResults] = useState<any>(null);

  const active = groups.find((g) => g.id === activeId) || null;

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch(`${API}/courses/${courseId}/groups`, { headers: hdrs() });
      if (!res.ok) throw new Error();
      const d = await res.json();
      const list: Group[] = d.groups || [];
      setGroups(list);
      setStudents(d.students || []);
      setLessons(d.lessons || []);
      setExams(d.exams || []);
      if (!activeId && list.length) setActiveId(list[0].id);
    } catch {
      toast.error(t('تعذّر تحميل المجموعات', 'Could not load groups'));
    }
    setLoading(false);
  }, [courseId, activeId, t]);

  const loadDetail = useCallback(async (id: string) => {
    try {
      const res = await fetch(`${API}/course-groups/${id}`, { headers: hdrs() });
      if (!res.ok) return;
      const d = await res.json();
      setGroups((gs) => gs.map((g) => (g.id === id ? { ...g, ...d.group } : g)));
    } catch { /* keep summary */ }
  }, []);

  const loadResults = useCallback(async (id: string) => {
    setResults(null);
    try {
      const res = await fetch(`${API}/course-groups/${id}/results`, { headers: hdrs() });
      if (res.ok) setResults(await res.json());
    } catch { /* ignore */ }
  }, []);

  useEffect(() => {
    if (open) load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (activeId) loadDetail(activeId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeId]);

  useEffect(() => {
    if (activeId && tab === 'results') loadResults(activeId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeId, tab]);

  const openCreate = () => { setEditing(null); setName(''); setDescription(''); setFormOpen(true); };
  const openEdit = (g: Group) => { setEditing(g); setName(g.name); setDescription(g.description || ''); setFormOpen(true); };

  const saveGroup = async () => {
    if (!name.trim()) { toast.error(t('أدخل اسم المجموعة', 'Enter a group name')); return; }
    setBusy(true);
    try {
      const body = JSON.stringify({ name: name.trim(), description: description.trim() || null });
      const url = editing ? `${API}/course-groups/${editing.id}` : `${API}/courses/${courseId}/groups`;
      const res = await fetch(url, { method: editing ? 'PUT' : 'POST', headers: hdrs(), body });
      if (!res.ok) throw new Error();
      const d = await res.json();
      toast.success(editing ? t('تم تحديث المجموعة', 'Group updated') : t('تم إنشاء المجموعة', 'Group created'));
      setFormOpen(false);
      if (!editing) setActiveId(d.group.id);
      await load();
    } catch {
      toast.error(t('فشل الحفظ', 'Save failed'));
    }
    setBusy(false);
  };

  const deleteGroup = async (g: Group) => {
    if (!window.confirm(t(`حذف مجموعة «${g.name}»؟ لا يُحذف أي طالب أو درجة — فقط المجموعة وإسناداتها.`, `Delete group "${g.name}"? No student or grade is deleted — only the group and its assignments.`))) return;
    setBusy(true);
    try {
      const res = await fetch(`${API}/course-groups/${g.id}`, { method: 'DELETE', headers: hdrs() });
      if (!res.ok) throw new Error();
      toast.success(t('تم الحذف', 'Deleted'));
      if (activeId === g.id) setActiveId(null);
      await load();
    } catch { toast.error(t('فشل الحذف', 'Delete failed')); }
    setBusy(false);
  };

  const toggle = (list: string[] | undefined, id: string) => {
    const set = new Set(list || []);
    set.has(id) ? set.delete(id) : set.add(id);
    return Array.from(set);
  };

  const saveMembers = async (ids: string[]) => {
    if (!active) return;
    setGroups((gs) => gs.map((g) => (g.id === active.id ? { ...g, member_ids: ids, members_count: ids.length } : g)));
    await fetch(`${API}/course-groups/${active.id}/members`, { method: 'PUT', headers: hdrs(), body: JSON.stringify({ user_ids: ids }) })
      .then((r) => { if (!r.ok) throw new Error(); toast.success(t('تم حفظ الأعضاء', 'Members saved')); })
      .catch(() => { toast.error(t('فشل الحفظ', 'Save failed')); loadDetail(active.id); });
  };

  const saveLessons = async (ids: string[]) => {
    if (!active) return;
    setGroups((gs) => gs.map((g) => (g.id === active.id ? { ...g, lesson_ids: ids, lessons_count: ids.length } : g)));
    await fetch(`${API}/course-groups/${active.id}/lessons`, { method: 'PUT', headers: hdrs(), body: JSON.stringify({ lesson_ids: ids }) })
      .then((r) => { if (!r.ok) throw new Error(); toast.success(t('تم تحديد الدروس المشتركة', 'Shared lessons saved')); })
      .catch(() => { toast.error(t('فشل الحفظ', 'Save failed')); loadDetail(active.id); });
  };

  const saveExams = async (ids: string[]) => {
    if (!active) return;
    setGroups((gs) => gs.map((g) => (g.id === active.id ? { ...g, exam_ids: ids, exams_count: ids.length } : g)));
    await fetch(`${API}/course-groups/${active.id}/exams`, { method: 'PUT', headers: hdrs(), body: JSON.stringify({ exam_ids: ids }) })
      .then((r) => { if (!r.ok) throw new Error(); toast.success(t('تم تحديد الاختبارات المشتركة', 'Shared exams saved')); })
      .catch(() => { toast.error(t('فشل الحفظ', 'Save failed')); loadDetail(active.id); });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange} className="max-w-4xl">
      <DialogContent className="max-w-4xl">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Users className="h-5 w-5 text-teal-600" />
            {t('مجموعات الطلاب داخل الكورس', 'In-course student groups')}
          </DialogTitle>
        </DialogHeader>

        <p className="text-[11px] text-gray-400 -mt-2">
          {t('مجموعات داخل هذا الكورس فقط (مثال: أول ثانوي — أ/ب/ج). لا تنسخ الكورس، ولا تُنشئ فصلاً دراسياً، ولا تستهلك مقعداً إضافياً للطالب.',
             'Groups inside this course only (e.g. Grade 10 — A/B/C). They never clone the course, are not academic terms, and never consume an extra seat.')}
        </p>

        <div className="grid gap-4 md:grid-cols-[240px_1fr]">
          {/* Groups list */}
          <div className="space-y-2 md:border-e md:pe-3">
            <Button size="sm" className="w-full gap-1.5 rounded-full" onClick={openCreate}><Plus className="h-3.5 w-3.5" />{t('مجموعة جديدة', 'New group')}</Button>
            {loading ? <div className="flex justify-center py-6"><Loader2 className="h-5 w-5 animate-spin text-teal-600" /></div> :
              groups.length === 0 ? <p className="text-xs text-gray-400 text-center py-6">{t('لا توجد مجموعات بعد', 'No groups yet')}</p> :
              groups.map((g) => (
                <button key={g.id} onClick={() => { setActiveId(g.id); setTab('members'); }}
                  className={`w-full text-start rounded-xl border p-2.5 transition ${activeId === g.id ? 'border-teal-400 bg-teal-50/60' : 'border-slate-100 hover:bg-slate-50'}`}>
                  <p className="text-sm font-semibold truncate">{g.name}</p>
                  <p className="text-[10px] text-gray-400">{g.members_count} {t('طالب', 'students')} · {g.lessons_count} {t('درس', 'lessons')} · {g.exams_count} {t('اختبار', 'exams')}</p>
                </button>
              ))}
          </div>

          {/* Detail */}
          <div className="min-h-[300px]">
            {!active ? (
              <div className="flex h-full items-center justify-center text-sm text-gray-400 py-16">{t('اختر مجموعة أو أنشئ واحدة', 'Pick a group or create one')}</div>
            ) : (
              <div className="space-y-3">
                <div className="flex items-center justify-between gap-2">
                  <div className="min-w-0">
                    <h3 className="font-bold truncate">{active.name}</h3>
                    {active.description && <p className="text-xs text-gray-500 truncate">{active.description}</p>}
                  </div>
                  <div className="flex items-center gap-1 shrink-0">
                    <Button size="sm" variant="ghost" className="rounded-full" onClick={() => openEdit(active)} aria-label={t('تعديل', 'Edit')} title={t('تعديل', 'Edit')}><Pencil className="h-3.5 w-3.5" /></Button>
                    <Button size="sm" variant="ghost" className="rounded-full text-red-500" onClick={() => deleteGroup(active)} aria-label={t('حذف', 'Delete')} title={t('حذف', 'Delete')}><Trash2 className="h-3.5 w-3.5" /></Button>
                  </div>
                </div>

                <div className="flex flex-wrap gap-1 p-1 bg-muted/50 rounded-full">
                  {([
                    ['members', t('الأعضاء', 'Members'), UserPlus],
                    ['lessons', t('الدروس المشتركة', 'Shared lessons'), BookOpen],
                    ['exams', t('الاختبارات', 'Exams'), ClipboardList],
                    ['results', t('النتائج', 'Results'), BarChart3],
                  ] as const).map(([key, label, Icon]) => (
                    <button key={key} onClick={() => setTab(key)}
                      className={`flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition ${tab === key ? 'bg-teal-600 text-white shadow' : 'text-gray-500 hover:text-gray-800'}`}>
                      <Icon className="h-3.5 w-3.5" />{label}
                    </button>
                  ))}
                </div>

                {tab === 'members' && (
                  <div className="space-y-2">
                    <p className="text-[11px] text-gray-400">{t('الطلاب المسجّلون في هذا الكورس. الانتماء لمجموعة لا يضيف مقعداً.', 'Students enrolled in this course. Group membership adds no seat.')}</p>
                    <div className="max-h-64 overflow-y-auto space-y-1">
                      {students.length === 0 && <p className="text-xs text-gray-400 py-4 text-center">{t('لا يوجد طلاب مسجّلون', 'No enrolled students')}</p>}
                      {students.map((s) => {
                        const on = (active.member_ids || []).includes(s.id);
                        return (
                          <label key={s.id} className="flex items-center gap-2.5 rounded-lg border p-2 cursor-pointer hover:bg-slate-50">
                            <input type="checkbox" checked={on} onChange={() => saveMembers(toggle(active.member_ids, s.id))} />
                            <span className="text-sm flex-1 min-w-0 truncate">{s.name}</span>
                            {s.email && <span className="text-[10px] text-gray-400 truncate max-w-[140px]">{s.email}</span>}
                          </label>
                        );
                      })}
                    </div>
                  </div>
                )}

                {tab === 'lessons' && (
                  <div className="space-y-2">
                    <p className="text-[11px] text-gray-400">{t('درس غير محدد يُعرض لكل الطلاب. تحديد درس يقصره على أعضاء هذه المجموعة فقط.', 'An unselected lesson is shown to everyone. Selecting a lesson restricts it to this group only.')}</p>
                    <div className="max-h-64 overflow-y-auto space-y-1">
                      {lessons.map((l) => {
                        const on = (active.lesson_ids || []).includes(l.id);
                        return (
                          <label key={l.id} className="flex items-center gap-2.5 rounded-lg border p-2 cursor-pointer hover:bg-slate-50">
                            <input type="checkbox" checked={on} onChange={() => saveLessons(toggle(active.lesson_ids, l.id))} />
                            <span className="text-sm flex-1 min-w-0 truncate">{l.title}</span>
                          </label>
                        );
                      })}
                    </div>
                  </div>
                )}

                {tab === 'exams' && (
                  <div className="space-y-2">
                    <p className="text-[11px] text-gray-400">{t('اختبار غير محدد يُعرض لكل الطلاب. تحديد اختبار ينشره لهذه المجموعة فقط.', 'An unselected exam is visible to everyone. Selecting an exam publishes it to this group only.')}</p>
                    <div className="max-h-64 overflow-y-auto space-y-1">
                      {exams.map((e) => {
                        const on = (active.exam_ids || []).includes(e.id);
                        return (
                          <label key={e.id} className="flex items-center gap-2.5 rounded-lg border p-2 cursor-pointer hover:bg-slate-50">
                            <input type="checkbox" checked={on} onChange={() => saveExams(toggle(active.exam_ids, e.id))} />
                            <span className="text-sm flex-1 min-w-0 truncate">{e.title}</span>
                            <Badge className={e.is_published ? 'bg-green-50 text-green-600 border-0' : 'bg-gray-100 text-gray-500 border-0'}>{e.is_published ? t('منشور', 'Published') : t('مسودة', 'Draft')}</Badge>
                          </label>
                        );
                      })}
                    </div>
                  </div>
                )}

                {tab === 'results' && (
                  <div className="space-y-3">
                    {!results ? <div className="flex justify-center py-8"><Loader2 className="h-5 w-5 animate-spin text-teal-600" /></div> : (
                      <>
                        <div className="grid grid-cols-2 gap-2.5">
                          {([['group', t('هذه المجموعة', 'This group')], ['all', t('كل الكورس', 'Whole course')]] as const).map(([key, label]) => (
                            <div key={key} className="rounded-xl border border-slate-100 p-3">
                              <p className="text-[10px] text-gray-500">{label}</p>
                              <p className="text-lg font-bold">{results[key].average_percentage}%</p>
                              <p className="text-[10px] text-gray-400">{results[key].submitted_attempts} {t('محاولة', 'attempts')} · {t('نجاح', 'pass')} {results[key].pass_rate}%</p>
                            </div>
                          ))}
                        </div>
                        <div className="max-h-48 overflow-y-auto space-y-1">
                          {(results.per_student || []).map((s: any) => (
                            <div key={s.user_id} className="flex items-center justify-between rounded-lg border p-2 text-sm">
                              <span className="truncate">{s.name}</span>
                              <span className="text-xs text-gray-500">{s.attempts} · <b>{s.average_percentage}%</b></span>
                            </div>
                          ))}
                          {(results.per_student || []).length === 0 && <p className="text-xs text-gray-400 text-center py-3">{t('لا توجد نتائج بعد', 'No results yet')}</p>}
                        </div>
                      </>
                    )}
                  </div>
                )}
              </div>
            )}
          </div>
        </div>

        {/* Create/edit group dialog-in-dialog (simple inline form) */}
        {formOpen && (
          <div className="rounded-xl border border-teal-100 bg-teal-50/50 p-3 space-y-3">
            <p className="text-sm font-bold flex items-center gap-1.5">{editing ? <Pencil className="h-4 w-4" /> : <Plus className="h-4 w-4" />}{editing ? t('تعديل المجموعة', 'Edit group') : t('مجموعة جديدة', 'New group')}</p>
            <div className="space-y-1.5"><Label className="text-xs">{t('اسم المجموعة', 'Group name')} *</Label><Input value={name} onChange={(e) => setName(e.target.value)} className="h-10" placeholder={t('أول ثانوي — أ', 'Grade 10 — A')} /></div>
            <div className="space-y-1.5"><Label className="text-xs">{t('وصف (اختياري)', 'Description (optional)')}</Label><Textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={2} /></div>
            <div className="flex justify-end gap-2">
              <Button variant="outline" size="sm" className="rounded-full" onClick={() => setFormOpen(false)}>{t('إلغاء', 'Cancel')}</Button>
              <Button size="sm" className="gap-1.5 rounded-full" onClick={saveGroup} disabled={busy}>{busy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Check className="h-3.5 w-3.5" />}{t('حفظ', 'Save')}</Button>
            </div>
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" className="rounded-full" onClick={() => onOpenChange(false)}>{t('تم', 'Done')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
