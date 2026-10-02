'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import {
  Users, Loader2, MonitorSmartphone, RotateCcw, Settings2, Search, Mail, Calendar,
  BookOpen, Award, TrendingUp, XCircle, CheckCircle2, UserX, Clock, Smartphone, Star,
  Coins, Trophy, Activity as ActivityIcon, History,
} from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function StudentsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [students, setStudents] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [profile, setProfile] = useState<any>(null);
  const [profileLoading, setProfileLoading] = useState(false);
  const [limitTarget, setLimitTarget] = useState<any>(null);
  const [limitVal, setLimitVal] = useState('1');
  const [busyId, setBusyId] = useState('');

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const load = () => {
    fetch(`${API}/teacher/students`, { headers: headers() })
      .then(r => r.json()).then(d => setStudents(d.students || [])).catch(() => {})
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (check()) load(); }, [check()]);

  const openProfile = async (s: any) => {
    setProfileLoading(true);
    try {
      const res = await fetch(`${API}/teacher/students/${s.id}`, { headers: headers() });
      if (!res.ok) throw new Error();
      setProfile((await res.json()).student);
    } catch { toast.error('Failed'); }
    setProfileLoading(false);
  };

  const saveLimit = async () => {
    if (!limitTarget) return;
    setBusyId(limitTarget.id);
    try {
      const res = await fetch(`${API}/teacher/students/${limitTarget.id}/device-limit`, { method: 'POST', headers: headers(), body: JSON.stringify({ max_devices: Math.max(1, Number(limitVal) || 1), notes: 'override' }) });
      if (!res.ok) throw new Error();
      toast.success(t(`تم منح ${limitTarget.name} الدخول من ${limitVal} أجهزة`, `Granted ${limitTarget.name} ${limitVal} device(s)`));
      setLimitTarget(null); load();
    } catch { toast.error('Failed'); }
    setBusyId('');
  };

  const resetDevices = async (s: any) => {
    setBusyId(s.id);
    try {
      const res = await fetch(`${API}/teacher/students/${s.id}/reset-devices`, { method: 'POST', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t(`تم إعادة تعيين أجهزة ${s.name} — يمكنه الدخول من جهاز جديد الآن`, `Devices reset for ${s.name} — they can log in from a new device now`));
      if (profile?.id === s.id) openProfile(s);
      load();
    } catch { toast.error('Failed'); }
    setBusyId('');
  };

  const clearOverride = async (s: any) => {
    if (!confirm(t('إزالة الصلاحية الخاصة عن هذا الطالب؟', 'Remove the special grant from this student?'))) return;
    setBusyId(s.id);
    try {
      const res = await fetch(`${API}/teacher/students/${s.id}/device-limit`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تمت إزالة الصلاحية — عاد للحد الافتراضي', 'Override removed — back to default'));
      if (profile?.id === s.id) openProfile(s);
      load();
    } catch { toast.error('Failed'); }
    setBusyId('');
  };

  const openLimit = (s: any) => { setLimitTarget(s); setLimitVal(String(s.max_devices)); };

  const filtered = students.filter((s) => !search || s.name?.toLowerCase().includes(search.toLowerCase()) || s.email?.toLowerCase().includes(search.toLowerCase()));
  const totalDevices = students.reduce((a, s) => a + s.devices_count, 0);
  const atLimit = students.filter((s) => s.devices_count >= s.max_devices).length;
  const passedTotal = students.reduce((a, s) => a + s.passed_count, 0);
  const totalPoints = students.reduce((a, s) => a + (s.points || 0), 0);

  const statCards = [
    { label: t('الطلاب', 'Students'), value: students.length, icon: Users, color: '#0d9488' },
    { label: t('إجمالي النقاط', 'Total points'), value: totalPoints, icon: Coins, color: '#d97706' },
    { label: t('إنجازات مفتوحة', 'Achievements'), value: students.reduce((a, s) => a + (s.achievements_count || 0), 0), icon: Trophy, color: '#7c3aed' },
    { label: t('اختبارات ناجحة', 'Exams passed'), value: passedTotal, icon: Award, color: '#059669' },
  ];

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex flex-wrap items-center gap-3">
        <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
          <Users className="h-6 w-6 text-teal-600" />
        </span>
        <div>
          <h1 className="text-2xl sm:text-3xl font-bold">{t('الطلاب', 'Students')}</h1>
          <p className="text-muted-foreground mt-1">{t('إدارة كاملة: الأداء، الأجهزة، الحدود، وإعادة التعيين', 'Full control: performance, devices, limits & resets')}</p>
        </div>
      </div>

      <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {statCards.map((s, i) => (
          <Card key={i} className="group border-0 shadow-sm card-hover overflow-hidden relative">
            <div className="absolute -top-8 -end-8 h-24 w-24 rounded-full opacity-10 transition-opacity group-hover:opacity-20" style={{ background: s.color }} />
            <CardContent className="p-5">
              <div className="flex h-11 w-11 items-center justify-center rounded-2xl mb-4" style={{ backgroundColor: `${s.color}15` }}><s.icon className="h-5 w-5" style={{ color: s.color }} /></div>
              <div className="text-3xl font-black tracking-tight">{s.value}</div>
              <div className="text-sm text-muted-foreground mt-1">{s.label}</div>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="relative max-w-md">
        <Search className="h-4 w-4 absolute start-3.5 top-1/2 -translate-y-1/2 text-gray-400" />
        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('ابحث بالاسم أو الإيميل...', 'Search by name or email...')} className="h-11 ps-10 rounded-full border-teal-100 bg-white shadow-sm focus-visible:ring-teal-500" />
      </div>

      {loading ? <TableSkeleton rows={6} cols={4} /> :
        filtered.length === 0 ? (
          <EmptyState icon={search ? UserX : Users} title={search ? t('لا نتائج مطابقة', 'No matching students') : t('لا يوجد طلاب بعد', 'No students yet')} />
        ) : (
          <div className="grid gap-4">
            {filtered.map((s) => (
              <Card key={s.id} className="border-0 shadow-sm overflow-hidden transition-all hover:shadow-md hover:border-teal-100">
                <CardContent className="p-5">
                  <div className="flex flex-wrap items-center gap-4">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-gradient-to-br from-teal-600 to-emerald-500 text-white font-bold shrink-0 ring-2 ring-teal-100">{s.name?.[0]?.toUpperCase() || '?'}</div>
                    <div className="flex-1 min-w-[160px]">
                      <p className="font-bold">{s.name}</p>
                      <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-gray-500 mt-0.5">
                        <span className="flex items-center gap-1"><Mail className="h-3 w-3 text-teal-500" />{s.email}</span>
                        <span className="flex items-center gap-1"><Calendar className="h-3 w-3 text-amber-500" />{s.joined_at ? new Date(s.joined_at).toLocaleDateString() : '—'}</span>
                      </div>
                      <div className="flex flex-wrap items-center gap-x-4 gap-y-0.5 mt-1.5 text-xs">
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-teal-50 px-2.5 py-1 font-semibold text-teal-700"><BookOpen className="h-3 w-3" />{s.courses_count} {t('كورس', 'courses')}</span>
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-violet-50 px-2.5 py-1 font-semibold text-violet-700"><TrendingUp className="h-3 w-3" />{s.avg_percentage}% {t('متوسط', 'avg')}</span>
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-700"><Award className="h-3 w-3" />{s.passed_count}/{s.attempts_count} {t('ناجح', 'passed')}</span>
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 font-semibold text-amber-700"><Coins className="h-3 w-3" />{s.points || 0} {t('نقطة', 'pts')}</span>
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-fuchsia-50 px-2.5 py-1 font-semibold text-fuchsia-700"><Trophy className="h-3 w-3" />{s.achievements_count || 0} {t('إنجاز', 'badges')}</span>
                      </div>
                    </div>
                    <div className="flex items-center gap-1.5 px-3.5 py-1.5 rounded-full bg-teal-50 border border-teal-100 text-teal-700 text-sm shrink-0">
                      <MonitorSmartphone className="h-4 w-4" /><b>{s.devices_count}</b> / {s.max_devices}
                    </div>
                    {s.override ? (
                      <Badge className="bg-violet-100 text-violet-700 border-0 shrink-0 rounded-full">{t('صلاحية خاصة', 'Special grant')}</Badge>
                    ) : s.devices_count >= s.max_devices ? (
                      <Badge className="bg-amber-100 text-amber-700 border-0 shrink-0 rounded-full">{t('الحد مكتمل', 'Limit reached')}</Badge>
                    ) : (
                      <Badge variant="outline" className="text-xs shrink-0 rounded-full border-teal-200 text-teal-600">{t('متاح', 'Available')}</Badge>
                    )}
                    <div className="flex gap-1.5 flex-wrap">
                      <Button size="sm" variant="outline" className="gap-1 rounded-full text-xs" onClick={() => openProfile(s)}><Users className="h-3.5 w-3.5" />{t('الملف', 'Profile')}</Button>
                      <Button size="sm" variant="outline" className="gap-1 rounded-full text-xs" onClick={() => openLimit(s)} disabled={busyId === s.id}><Settings2 className="h-3.5 w-3.5" />{t('الحد', 'Limit')}</Button>
                      <Button size="sm" variant="outline" className="gap-1 rounded-full text-xs text-red-600 border-red-100 hover:bg-red-50" onClick={() => resetDevices(s)} disabled={busyId === s.id}><RotateCcw className="h-3.5 w-3.5" />{t('إعادة الأجهزة', 'Reset')}</Button>
                      {s.override && <Button size="sm" variant="ghost" className="gap-1 rounded-full text-xs text-gray-500" onClick={() => clearOverride(s)} disabled={busyId === s.id}><XCircle className="h-3.5 w-3.5" />{t('إزالة', 'Remove')}</Button>}
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

      {/* ─── Limit dialog ───────────────────────── */}
      <Dialog open={!!limitTarget} onOpenChange={(v) => { if (!v) setLimitTarget(null); }}>
        <DialogContent><DialogHeader><DialogTitle>{t('تحديد حد الأجهزة', 'Set device limit')}</DialogTitle></DialogHeader>
          {limitTarget && (
            <div className="space-y-4">
              <div className="flex items-center gap-3 p-3 rounded-xl bg-teal-50">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-teal-600 text-white font-bold">{limitTarget.name?.[0]?.toUpperCase()}</div>
                <div><p className="font-medium text-sm">{limitTarget.name}</p><p className="text-xs text-gray-500">{limitTarget.email}</p></div>
              </div>
              <div className="space-y-2">
                <Label>{t('عدد الأجهزة المسموحة', 'Allowed devices')}</Label>
                <Input type="number" min={1} max={20} value={limitVal} onChange={(e) => setLimitVal(e.target.value)} className="h-11" />
                <p className="text-xs text-gray-400">{t('1 = جهاز واحد فقط، 3 = ثلاثة أجهزة', '1 = one device only, 3 = three devices')}</p>
              </div>
              <div className="flex items-center gap-2 p-3 rounded-xl bg-gray-50 text-sm text-gray-600">
                <Smartphone className="h-4 w-4 text-green-600 shrink-0" />
                {t('مسجل حالياً من', 'Currently on')} <b>{limitTarget.devices_count}</b> {t('أجهزة من أصل', 'of')} {limitTarget.max_devices}
              </div>
            </div>
          )}
          <DialogFooter><Button variant="outline" onClick={() => setLimitTarget(null)}>{t('إلغاء', 'Cancel')}</Button><Button onClick={saveLimit} className="gap-1.5 rounded-full"><Settings2 className="h-4 w-4" />{t('حفظ الصلاحية', 'Save grant')}</Button></DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ─── Profile dialog ──────────────────────── */}
      <Dialog open={!!profile} onOpenChange={(v) => { if (!v) setProfile(null); }} className="max-w-2xl">
        <DialogContent>
          <DialogHeader><DialogTitle>{t('ملف الطالب', 'Student profile')}</DialogTitle></DialogHeader>
          {profileLoading ? <div className="flex justify-center py-12"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div> : profile && (
            <div className="space-y-5">
              <div className="flex items-center gap-4">
                <div className="flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-teal-500 to-emerald-400 text-white font-bold text-lg">{profile.name?.[0]?.toUpperCase()}</div>
                <div className="flex-1">
                  <p className="font-bold text-lg">{profile.name}</p>
                  <p className="text-sm text-gray-500">{profile.email}</p>
                </div>
                <div className="text-start">
                  <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-teal-50 text-teal-700 text-sm"><MonitorSmartphone className="h-4 w-4" /><b>{profile.devices?.length || 0}</b> / {profile.max_devices}</div>
                  {profile.override && <Badge className="bg-violet-100 text-violet-700 border-0 mt-1.5">{t('صلاحية خاصة', 'Special grant')}</Badge>}
                </div>
              </div>

              <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center">
                <div className="p-3 rounded-xl bg-teal-50"><p className="text-xl font-bold text-teal-700">{profile.enrollments?.length || 0}</p><p className="text-xs text-gray-500">{t('كورسات', 'Courses')}</p></div>
                <div className="p-3 rounded-xl bg-violet-50"><p className="text-xl font-bold text-violet-600">{profile.attempts?.length || 0}</p><p className="text-xs text-gray-500">{t('محاولات', 'Attempts')}</p></div>
                <div className="p-3 rounded-xl bg-amber-50"><p className="text-xl font-bold text-amber-600">{profile.stats?.points || 0}</p><p className="text-xs text-gray-500">{t('نقاط', 'Points')}{profile.stats?.rank ? ` · #${profile.stats.rank}` : ''}</p></div>
                <div className="p-3 rounded-xl bg-emerald-50"><p className="text-xl font-bold text-emerald-600">{profile.stats?.unlocked_count || 0}</p><p className="text-xs text-gray-500">{t('إنجازات', 'Badges')}</p></div>
              </div>

              {profile.activity?.length > 0 && (
                <div>
                  <h3 className="font-semibold text-sm mb-2 flex items-center gap-1.5"><ActivityIcon className="h-4 w-4 text-teal-600" />{t('سجل النشاط', 'Activity log')}</h3>
                  <div className="max-h-48 overflow-y-auto space-y-1.5 pe-1">
                    {(profile.activity || []).map((a: any) => (
                      <div key={a.id} className="flex items-center gap-2.5 p-2.5 rounded-xl bg-gray-50">
                        <div className="flex h-7 w-7 items-center justify-center rounded-full bg-teal-100 shrink-0"><History className="h-3.5 w-3.5 text-teal-700" /></div>
                        <div className="flex-1 min-w-0">
                          <p className="text-xs text-gray-700 truncate">{a.description}</p>
                          <p className="text-[10px] text-gray-400">{new Date(a.created_at).toLocaleString()}</p>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {profile.stats?.achievements?.filter((x: any) => x.unlocked).length > 0 && (
                <div>
                  <h3 className="font-semibold text-sm mb-2 flex items-center gap-1.5"><Trophy className="h-4 w-4 text-amber-500" />{t('الإنجازات المحققة', 'Earned achievements')}</h3>
                  <div className="flex flex-wrap gap-1.5">
                    {profile.stats.achievements.filter((x: any) => x.unlocked).map((a: any) => (
                      <span key={a.id} className="flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 text-[11px] font-medium"><Star className="h-3 w-3 fill-amber-500 text-amber-500" />{locale === 'ar' ? (a.title_ar || a.title) : a.title}</span>
                    ))}
                  </div>
                </div>
              )}

              <div>
                <h3 className="font-semibold text-sm mb-2 flex items-center gap-1.5"><BookOpen className="h-4 w-4 text-teal-600" />{t('الكورسات المسجل بها', 'Enrolled courses')}</h3>
                <div className="space-y-2">
                  {(profile.enrollments || []).length === 0 && <p className="text-sm text-gray-400">{t('لا يوجد', 'None')}</p>}
                  {(profile.enrollments || []).map((e: any) => (
                    <div key={e.id} className="flex items-center gap-3 p-3 rounded-xl border">
                      <div className="flex-1"><p className="text-sm font-medium">{e.course_title}</p><p className="text-[11px] text-gray-400">{new Date(e.enrolled_at).toLocaleDateString()} · {t('المصدر', 'source')}: {e.source}</p></div>
                      <div className="w-28"><div className="flex justify-between text-[10px] text-gray-400 mb-1"><span>{t('التقدم', 'progress')}</span><span>{e.progress_percent}%</span></div><div className="h-1.5 rounded-full bg-gray-100"><div className="h-1.5 rounded-full bg-teal-500" style={{ width: `${e.progress_percent}%` }} /></div></div>
                    </div>
                  ))}
                </div>
              </div>

              <div>
                <h3 className="font-semibold text-sm mb-2 flex items-center gap-1.5"><Award className="h-4 w-4 text-violet-500" />{t('نتائج الاختبارات', 'Exam results')}</h3>
                <div className="space-y-2">
                  {(profile.attempts || []).length === 0 && <p className="text-sm text-gray-400">{t('لم يقدم أي اختبار', 'No attempts yet')}</p>}
                  {(profile.attempts || []).map((a: any) => (
                    <div key={a.id} className="flex items-center gap-3 p-3 rounded-xl border">
                      {a.passed ? <CheckCircle2 className="h-5 w-5 text-emerald-500 shrink-0" /> : <XCircle className="h-5 w-5 text-red-400 shrink-0" />}
                      <div className="flex-1"><p className="text-sm font-medium">{a.exam_title}</p><p className="text-[11px] text-gray-400">{a.score} / {a.total_points} · {new Date(a.submitted_at).toLocaleDateString()}</p></div>
                      <span className={`text-sm font-bold ${a.passed ? 'text-emerald-600' : 'text-red-500'}`}>{a.percentage}%</span>
                    </div>
                  ))}
                </div>
              </div>

              <div>
                <h3 className="font-semibold text-sm mb-2 flex items-center gap-1.5"><MonitorSmartphone className="h-4 w-4 text-amber-500" />{t('الأجهزة المسجلة', 'Registered devices')}</h3>
                <div className="space-y-2">
                  {(profile.devices || []).length === 0 && <p className="text-sm text-gray-400">{t('لا أجهزة مسجلة', 'No devices')}</p>}
                  {(profile.devices || []).map((dv: any) => (
                    <div key={dv.id} className="flex items-center gap-3 p-3 rounded-xl bg-gray-50">
                      <Smartphone className="h-4 w-4 text-gray-500 shrink-0" />
                      <div className="flex-1"><p className="text-sm font-medium" dir="ltr">{dv.device_id}</p><p className="text-[11px] text-gray-400">{dv.device_name} · {dv.ip_address} · {dv.last_seen_at ? new Date(dv.last_seen_at).toLocaleString() : '—'}</p></div>
                    </div>
                  ))}
                </div>
              </div>

              <div className="flex gap-2 pt-2 border-t flex-wrap">
                <Button variant="outline" size="sm" className="gap-1.5 rounded-full" onClick={() => { setLimitTarget({ id: profile.id, name: profile.name, email: profile.email, devices_count: profile.devices?.length || 0, max_devices: profile.max_devices }); setLimitVal(String(profile.max_devices)); setProfile(null); }}><Settings2 className="h-3.5 w-3.5" />{t('تحديد الحد', 'Set limit')}</Button>
                <Button variant="outline" size="sm" className="gap-1.5 rounded-full text-red-600 border-red-100 hover:bg-red-50" onClick={() => resetDevices(profile)} disabled={busyId === profile.id}><RotateCcw className="h-3.5 w-3.5" />{t('إعادة تعيين الأجهزة', 'Reset devices')}</Button>
                {profile.override && <Button variant="ghost" size="sm" className="gap-1.5 rounded-full" onClick={() => clearOverride(profile)} disabled={busyId === profile.id}><XCircle className="h-3.5 w-3.5" />{t('إزالة الصلاحية', 'Remove grant')}</Button>}
                <Button variant="ghost" size="sm" className="gap-1.5 rounded-full text-gray-400" onClick={() => setProfile(null)}><Clock className="h-3.5 w-3.5" />{t('آخر دخول', 'Last login')}: {profile.last_login_at ? new Date(profile.last_login_at).toLocaleString() : '—'}</Button>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
