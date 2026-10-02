'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { EmptyState } from '@/components/ui/empty-state';
import { CardSkeleton } from '@/components/ui/skeleton';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { UsersRound, Plus, Loader2, Trash2, Crown, Shield, Mail, XCircle, UserRound } from 'lucide-react';
import { toast } from 'sonner';
import { usePlan } from '@/store/plan';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function TeacherStaffPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const { plan, usage } = usePlan();

  const [teachers, setTeachers] = useState<any[]>([]);
  const [invitations, setInvitations] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const PERMS: { key: string; ar: string; en: string; desc: string }[] = [
    { key: 'courses.manage', ar: 'إدارة الكورسات والمحتوى', en: 'Manage courses & content', desc: 'إضافة وتعديل الدروس والأقسام والمحتوى' },
    { key: 'students.manage', ar: 'إدارة الطلاب', en: 'Manage students', desc: 'إضافة الطلاب، التسجيل، وحذفهم' },
    { key: 'exams.manage', ar: 'إدارة الاختبارات', en: 'Manage exams', desc: 'إنشاء وتعديل الاختبارات' },
    { key: 'questions.manage', ar: 'بنك الأسئلة', en: 'Question bank', desc: 'إضافة وتعديل الأسئلة' },
    { key: 'cards.manage', ar: 'بطاقات التسجيل', en: 'Registration cards', desc: 'إنشاء وإدارة بطاقات التسجيل' },
    { key: 'live.manage', ar: 'البث المباشر', en: 'Live streaming', desc: 'إنشاء وإدارة جلسات البث' },
    { key: 'announcements.manage', ar: 'الإعلانات', en: 'Announcements', desc: 'نشر وإدارة الإعلانات' },
    { key: 'schedule.manage', ar: 'الجدولة والمواعيد', en: 'Schedule & appointments', desc: 'إدارة الدروس المجدولة والمواعيد' },
    { key: 'blog.manage', ar: 'المدونة', en: 'Blog', desc: 'كتابة وإدارة المقالات' },
    { key: 'community.manage', ar: 'المجتمع', en: 'Community', desc: 'إدارة مجموعات ونقاشات المجتمع' },
    { key: 'analytics.view', ar: 'مشاهدة التحليلات', en: 'View analytics', desc: 'الاطلاع على التقارير فقط' },
    { key: 'settings.manage', ar: 'الإعدادات العامة', en: 'General settings', desc: 'تعديل إعدادات المنصة (بدون المالية)' },
  ];

  const [form, setForm] = useState({ email: '', role: 'teacher', permissions: [] as string[] });
  const togglePerm = (key: string) => setForm((f) => ({
    ...f,
    permissions: f.permissions.includes(key) ? f.permissions.filter((x) => x !== key) : [...f.permissions, key],
  }));
  const [busyId, setBusyId] = useState('');
  const [removingId, setRemovingId] = useState('');

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const load = () => {
    fetch(`${API}/teacher/staff`, { headers: headers() })
      .then(r => r.json()).then(d => { setTeachers(d.teachers || []); setInvitations(d.invitations || []); }).catch(() => {})
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (check()) load(); }, [check()]);

  const invite = async () => {
    if (!form.email.trim()) { toast.error(t('أدخل البريد الإلكتروني', 'Enter email')); return; }
    setBusyId('save');
    try {
      const res = await fetch(`${API}/teacher/staff/invitations`, { method: 'POST', headers: headers(), body: JSON.stringify(form) });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(t('تم إرسال الدعوة — وصل المعلم بريداً برابط القبول', 'Invitation sent — the teacher received an email with an accept link'));
      setShowForm(false); setForm({ email: '', role: 'teacher', permissions: [] }); load();
    } catch { toast.error('Failed'); }
    setBusyId('');
  };

  const cancelInvitation = async (id: string) => {
    if (!window.confirm(t('إلغاء هذه الدعوة؟', 'Cancel this invitation?'))) return;
    setRemovingId(id);
    try {
      const res = await fetch(`${API}/teacher/staff/invitations/${id}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم إلغاء الدعوة', 'Invitation cancelled')); load();
    } catch { toast.error('Failed'); }
    setRemovingId('');
  };

  const removeMember = async (member: any) => {
    if (!window.confirm(t('إزالة هذا المعلم من المؤسسة؟', 'Remove this teacher from the institution?'))) return;
    setRemovingId(member.id);
    try {
      const res = await fetch(`${API}/teacher/staff/members/${member.id}`, { method: 'DELETE', headers: headers() });
      if (!res.ok) { const d = await res.json().catch(() => null); toast.error(d?.message || 'Failed'); return; }
      toast.success(t('تمت الإزالة', 'Member removed')); load();
    } catch { toast.error('Failed'); }
    setRemovingId('');
  };

  const limited = usage?.teachers && !usage.teachers.unlimited;
  const atLimit = limited && teachers.length >= usage.teachers.limit;

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <UsersRound className="h-6 w-6 text-teal-600" />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('فريق العمل', 'Team')}</h1>
            <p className="text-muted-foreground mt-1">{t('أعضاء يساعدونك في إدارة نفس المحتوى بصلاحيات تحددها — لا خطط ولا كورسات خاصة بهم', 'Members who help you manage the same content with roles you control — no separate plans or courses')}</p>
          </div>
        </div>
        <Button onClick={() => setShowForm(true)} disabled={atLimit} className="gap-2 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}><Plus className="h-4 w-4" /> {t('دعوة عضو', 'Invite member')}</Button>
      </div>

      <div className="rounded-2xl border border-teal-100 bg-teal-50/50 p-5 space-y-3">
        <p className="font-bold text-teal-800 text-sm flex items-center gap-2"><Shield className="h-4 w-4" />{t('ماذا يعني فريق العمل؟', 'What does Team mean?')}</p>
        <ul className="text-[13px] text-slate-600 space-y-1.5 leading-relaxed">
          <li>• {t('يعمل أعضاء فريقك على نفس منصتك وحسابك — نفس الكورسات والطلاب والامتحانات والمحتوى.', 'Team members work on YOUR platform and account — the same courses, students, exams and content.')}</li>
          <li>• {t('لا يملكون خطة خاصة بهم ولا كورسات منفصلة — كل شيء يبقى باسمك وتحت إدارتك.', 'They do not get their own plan or separate courses — everything stays under your ownership.')}</li>
          <li>• {t('ماذا يستطيع العضو فعله: إضافة/تعديل الدروس والمحتوى، رفع الفيديوهات، إنشاء الاختبارات والأسئلة، إدارة الطلاب ونتائجهم.', 'What a member CAN do: add/edit lessons and content, upload videos, create exams and questions, manage students and their results.')}</li>
          <li>• {t('ما لا يستطيع فعله: تغيير خطتك أو الأسعار أو الإعدادات المالية أو حذف الحساب الرئيسي.', 'What they CANNOT do: change your plan, pricing, financial settings, or delete the main account.')}</li>
          <li>• {t('لا توجد أدوار — كل عضو يحصل على الصلاحيات التي تحددها أنت عند دعوته (أو تفارغها لاحقاً).', 'No roles — each member gets exactly the permissions you set when inviting them (or change them later).')}</li>
        </ul>
      </div>

      {atLimit && (
        <div className="rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-700">
          {t('وصلت حد المعلمين في خطتك الحالية', 'You reached the teacher limit of your current plan')} ({usage.teachers.used}/{usage.teachers.limit}) — {t('رقِّ خطتك لاستيعاب المزيد', 'upgrade your plan to add more')}.
        </div>
      )}

      {loading ? <CardSkeleton count={3} /> : (
        <div className="space-y-6">
          {teachers.length === 0 && invitations.length === 0 ? (
            <EmptyState icon={UsersRound} title={t('لا يوجد معلمون بعد', 'No teachers yet')} description={t('ادعُ أول معلم للانضمام إلى مؤسستك', 'Invite your first teacher to join your institution')}
              action={<Button onClick={() => setShowForm(true)} className="mt-2 gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('دعوة عضو', 'Invite member')}</Button>} />
          ) : (
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {teachers.map(m => (
              <Card key={m.id} className="border-0 shadow-sm">
                <CardContent className="p-5">
                  <div className="flex items-start gap-3">
                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-50 shrink-0">
                      {m.role === 'owner' ? <Crown className="h-5 w-5 text-amber-500" /> : <UserRound className="h-5 w-5 text-teal-700" />}
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="font-semibold truncate">{m.name}</p>
                      <p className="text-xs text-gray-500 truncate" dir="ltr">{m.email}</p>
                      <div className="flex items-center gap-2 mt-2 flex-wrap">
                        <Badge className={m.role === 'owner' ? 'bg-amber-50 text-amber-600 border-0' : 'bg-teal-50 text-teal-700 border-0'}>
                          {m.role === 'owner' ? t('المالك', 'Owner') : t('عضو', 'Member')}
                        </Badge>
                        <Badge className={m.status === 'active' ? 'bg-emerald-50 text-emerald-600 border-0' : 'bg-gray-100 text-gray-500 border-0'}>
                          {m.status === 'active' ? t('نشط', 'Active') : t('موقوف', 'Inactive')}
                        </Badge>
                      </div>
                      {(m.permissions || []).length > 0 && (
                        <div className="flex flex-wrap gap-1 mt-2">
                          {(m.permissions || []).map((pk: string) => {
                            const pm = PERMS.find((x) => x.key === pk);
                            return pm ? <Badge key={pk} className="bg-teal-50 text-teal-700 border-0 text-[10px]">{t(pm.ar, pm.en)}</Badge> : null;
                          })}
                        </div>
                      )}
                    </div>
                    {m.role !== 'owner' && (
                      <Button size="sm" variant="ghost" className="rounded-full text-xs text-red-500 shrink-0" onClick={() => removeMember(m)} disabled={removingId === m.id} title={t('إزالة', 'Remove')} aria-label={t('إزالة', 'Remove')}><Trash2 className="h-3.5 w-3.5" /></Button>
                    )}
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
          )}

          {invitations.length > 0 && (
            <>
              <h3 className="font-semibold flex items-center gap-2"><Mail className="h-4 w-4 text-teal-600" />{t('دعوات معلقة', 'Pending invitations')}</h3>
              <div className="space-y-3">
                {invitations.map(inv => (
                  <Card key={inv.id} className="border-0 shadow-sm bg-amber-50/40">
                    <CardContent className="p-4 flex items-center gap-3 flex-wrap">
                      <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 shrink-0"><Mail className="h-4 w-4 text-amber-600" /></div>
                      <div className="flex-1 min-w-[200px]">
                        <p className="font-semibold" dir="ltr">{inv.email}</p>
                        <p className="text-xs text-gray-500">{t('دور', 'Role')}: {inv.role} · {t('تنتهي في', 'Expires')} {new Date(inv.expires_at).toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US')}</p>
                      </div>
                      <Badge className="bg-amber-100 text-amber-700 border-0">{t('قيد الانتظار', 'Pending')}</Badge>
                      <Button size="sm" variant="outline" className="gap-1.5 rounded-full text-xs text-amber-600" onClick={() => cancelInvitation(inv.id)} disabled={removingId === inv.id}><XCircle className="h-3.5 w-3.5" />{t('إلغاء', 'Cancel')}</Button>
                    </CardContent>
                  </Card>
                ))}
              </div>
            </>
          )}
        </div>
      )}

      <Dialog open={showForm} onOpenChange={setShowForm}>
        <DialogContent><DialogHeader><DialogTitle>{t('دعوة عضو جديد', 'Invite a new member')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2"><Label>{t('البريد الإلكتروني', 'Email')} *</Label><Input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="h-11" dir="ltr" placeholder="teacher@example.com" /></div>
            <div className="space-y-2">
              <Label>{t('الصلاحيات — حدد ما يستطيع العضو إدارته', 'Permissions — set what the member can manage')}</Label>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-64 overflow-y-auto pe-1">
                {PERMS.map((p) => (
                  <label key={p.key} className={`flex items-start gap-2 rounded-xl border p-2.5 cursor-pointer transition ${form.permissions.includes(p.key) ? 'border-teal-400 bg-teal-50' : 'border-slate-200 hover:border-teal-200'}`}>
                    <input type="checkbox" checked={form.permissions.includes(p.key)} onChange={() => togglePerm(p.key)} className="mt-0.5 h-4 w-4 accent-teal-600" />
                    <span className="min-w-0">
                      <span className="block text-xs font-semibold text-slate-700">{t(p.ar, p.en)}</span>
                      <span className="block text-[10px] text-gray-400 leading-snug">{t(p.desc, p.en)}</span>
                    </span>
                  </label>
                ))}
              </div>
              <p className="text-[11px] text-gray-400">{t('اترك الكل فارغاً = صلاحية افتراضية (إدارة المحتوى والطلاب)', 'Leave empty = default access (content & students)')}</p>
            </div>
            <p className="text-xs text-gray-400">{t('سيصلك رابط قبول عبر البريد — بعد القبول يمكن للمعلم تسجيل الدخول وإدارة منصته', 'The invited teacher receives an email with an accept link — after accepting they can log in and manage their own platform')}</p>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowForm(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={invite} disabled={busyId === 'save'} className="gap-2 rounded-full">{busyId === 'save' && <Loader2 className="h-4 w-4 animate-spin" />}{t('إرسال الدعوة', 'Send invite')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
