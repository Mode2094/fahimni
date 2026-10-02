'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { BookOpen, Plus, Loader2, Trash2, Pencil, Eye, EyeOff, Layers, FileQuestion, ClipboardList, Users, Award } from 'lucide-react';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function CoursesPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const { formatPrice } = useSettings();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [courses, setCourses] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<any>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<any>({ title: '', description: '', short_description: '', price: '0', drip_enabled: false, drip_interval_days: '1', prerequisites_enabled: false, prerequisites: '', certificate_enabled: false, certificate_template: 'classic', certificate_accent_color: '#0d9488', certificate_signature_name: '', certificate_signature_title: '' });

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const load = () => {
    fetch(`${API}/courses`, { headers: headers() })
      .then(r => r.json()).then(d => setCourses(d.courses || [])).catch(() => {})
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (check()) load(); }, [check()]);

  const openAdd = () => { setEditing(null); setForm({ title: '', description: '', short_description: '', price: '0', drip_enabled: false, drip_interval_days: '1', prerequisites_enabled: false, prerequisites: '', certificate_enabled: false, certificate_template: 'classic', certificate_accent_color: '#0d9488', certificate_signature_name: '', certificate_signature_title: '' }); setShowForm(true); };
  const openEdit = (c: any) => { setEditing(c); setForm({ title: c.title || '', description: c.description || '', short_description: c.short_description || '', price: c.price ?? '0', drip_enabled: !!c.drip_enabled, drip_interval_days: String(c.drip_interval_days || '1'), prerequisites_enabled: !!c.prerequisites, prerequisites: c.prerequisites || '', certificate_enabled: !!c.certificate_enabled, certificate_template: c.certificate_template || 'classic', certificate_accent_color: c.certificate_accent_color || '#0d9488', certificate_signature_name: c.certificate_signature_name || '', certificate_signature_title: c.certificate_signature_title || '' }); setShowForm(true); };

  const save = async () => {
    if (!form.title) { toast.error(t('أدخل اسم الكورس', 'Enter course title')); return; }
    try {
      const body = {
        title: form.title,
        description: form.description,
        short_description: form.short_description,
        price: Number(form.price) || 0,
        prerequisites: form.prerequisites_enabled ? form.prerequisites.trim() : null,
        drip_enabled: form.drip_enabled,
        drip_interval_days: Number(form.drip_interval_days) || 1,
        certificate_enabled: form.certificate_enabled,
        certificate_template: form.certificate_template,
        certificate_accent_color: form.certificate_accent_color,
        certificate_signature_name: form.certificate_signature_name.trim() || null,
        certificate_signature_title: form.certificate_signature_title.trim() || null,
        ...(editing ? {} : { slug: form.title.toLowerCase().replace(/[^a-z0-9-]/g, '-') + '-' + Date.now(), is_published: false, sort_order: 0 }),
      };
      const res = await fetch(`${API}/courses${editing ? `/${editing.id}` : ''}`, { method: editing ? 'PUT' : 'POST', headers: headers(), body: JSON.stringify(body) });
      if (!res.ok) throw new Error();
      toast.success(editing ? t('تم تحديث الكورس', 'Course updated') : t('تم إنشاء الكورس', 'Course created'));
      setShowForm(false); load();
    } catch { toast.error('Failed'); }
  };

  const remove = async (id: string) => {
    try {
      await fetch(`${API}/courses/${id}`, { method: 'DELETE', headers: headers() });
      toast.success(t('تم الحذف', 'Deleted')); load();
    } catch { toast.error('Failed'); }
  };

  const togglePublish = async (c: any) => {
    try {
      const res = await fetch(`${API}/courses/${c.id}/${c.is_published ? 'unpublish' : 'publish'}`, { method: 'POST', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(c.is_published ? t('تم إخفاء الكورس', 'Course unpublished') : t('تم نشر الكورس — يظهر في موقعك العام', 'Course published — live on your public site'));
      load();
    } catch { toast.error('Failed'); }
  };

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <BookOpen className="h-6 w-6 text-teal-600" />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('الكورسات', 'Courses')}</h1>
            <p className="text-muted-foreground mt-1">{t('أنشئ وأدر كورساتك: المحتوى، الاختبارات، بنك الأسئلة', 'Create & manage: content, exams, question bank')}</p>
          </div>
        </div>
        <Button onClick={openAdd} className="gap-2 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
          <Plus className="h-4 w-4" /> {t('كورس جديد', 'New Course')}
        </Button>
      </div>
      {loading ? <div className="flex justify-center py-16"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div> :
        courses.length === 0 ? (
          <Card className="border-0 shadow-sm overflow-hidden">
            <CardContent className="flex flex-col items-center gap-4 py-14">
              <div className="flex h-20 w-20 items-center justify-center rounded-3xl bg-gradient-to-br from-teal-50 to-emerald-50 border border-teal-100">
                <BookOpen className="h-10 w-10 text-teal-300" />
              </div>
              <div className="text-center">
                <p className="text-lg font-bold">{t('لا توجد كورسات بعد', 'No courses yet')}</p>
                <p className="text-sm text-muted-foreground mt-1">{t('أنشئ أول كورس وابدأ ببناء علامتك التعليمية', 'Create your first course and start building your brand')}</p>
              </div>
        <Button onClick={openAdd} className="gap-2 rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
                <Plus className="h-4 w-4" /> {t('أنشئ أول كورس', 'Create your first course')}
              </Button>
            </CardContent>
          </Card>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {courses.map((c, i) => (
              <Card key={c.id} className="border-0 shadow-sm card-hover overflow-hidden flex flex-col group">
                <div className="h-28 bg-gradient-to-br from-teal-600 via-teal-500 to-emerald-500 flex items-center justify-center relative">
                  <div className="absolute inset-0 opacity-[0.08]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '20px 20px' }} />
                  <BookOpen className="h-11 w-11 text-white/80 transition-transform group-hover:scale-110 duration-300" />
                  <Badge className={`absolute top-2.5 end-2.5 border-0 text-[10px] ${c.is_published ? 'bg-white/90 text-teal-800' : 'bg-black/30 text-white backdrop-blur'}`}>
                    {c.is_published ? t('منشور', 'Published') : t('مسودة', 'Draft')}
                  </Badge>
                </div>
                <CardContent className="p-5 flex-1 flex flex-col">
                  <a href={`/courses/${c.id}`}><h3 className="font-bold hover:text-teal-700 transition-colors line-clamp-1">{c.title}</h3></a>
                  <p className="text-xs text-muted-foreground mt-1.5 line-clamp-2 min-h-[2rem]">{c.short_description || c.description || t('بدون وصف', 'No description')}</p>
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 mt-3 text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-teal-50 px-2.5 py-1 font-semibold text-teal-700"><Layers className="h-3 w-3" />{c.sections_count ?? 0} {t('أقسام', 'sections')}</span>
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-violet-50 px-2.5 py-1 font-semibold text-violet-700"><ClipboardList className="h-3 w-3" />{c.exams_count ?? 0} {t('اختبارات', 'exams')}</span>
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 font-semibold text-amber-700"><FileQuestion className="h-3 w-3" />{c.questions_count ?? 0} {t('أسئلة', 'questions')}</span>
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-700"><Users className="h-3 w-3" />{c.enrollments_count ?? 0} {t('طلاب', 'students')}</span>
                    {c.prerequisites && <span className="text-[10px] px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 font-medium border border-amber-100">{t('متطلبات مسبقة', 'Prereqs')}</span>}
                    {Number(c.price) > 0 && <span className="font-black text-teal-700 ms-auto">{formatPrice(c.price)}</span>}
                  </div>
                  <div className="flex items-center gap-2 mt-4 pt-4 border-t">
                    <a href={`/courses/${c.id}`} className="flex-1">
                      <Button size="sm" className="w-full rounded-full text-white shadow-brand" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>{t('إدارة', 'Manage')}</Button>
                    </a>
                    <Button size="icon" variant="outline" className="h-8 w-8 rounded-full" onClick={() => togglePublish(c)} title={c.is_published ? t('إخفاء', 'Unpublish') : t('نشر', 'Publish')}>
                      {c.is_published ? <EyeOff className="h-3.5 w-3.5 text-gray-400" /> : <Eye className="h-3.5 w-3.5 text-green-600" />}
                    </Button>
                    <Button size="icon" variant="outline" className="h-8 w-8 rounded-full" onClick={() => openEdit(c)}><Pencil className="h-3.5 w-3.5 text-teal-700" /></Button>
                    <Button size="icon" variant="ghost" className="h-8 w-8 rounded-full text-red-500" onClick={() => remove(c.id)}><Trash2 className="h-3.5 w-3.5" /></Button>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

      <Dialog open={showForm} onOpenChange={setShowForm}>
        <DialogContent><DialogHeader><DialogTitle>{editing ? t('تعديل الكورس', 'Edit course') : t('كورس جديد', 'New course')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2"><Label>{t('اسم الكورس', 'Title')} *</Label><Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className="h-11" /></div>
            <div className="space-y-2"><Label>{t('وصف مختصر', 'Short description')}</Label><Input value={form.short_description} onChange={(e) => setForm({ ...form, short_description: e.target.value })} className="h-11" /></div>
            <div className="space-y-2"><Label>{t('الوصف', 'Description')}</Label><textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} rows={3} className="w-full border border-gray-200 rounded-lg p-3 text-sm focus:outline-none focus:ring-2 focus:ring-teal-600" /></div>
            <div className="space-y-2"><Label>{t('السعر', 'Price')}</Label><Input type="number" min={0} value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} className="h-11" /></div>
            <div className="p-4 rounded-xl border">
              <div className="flex items-center justify-between">
                <div>
                  <p className="font-medium text-sm">{t('فتح تدريجي (Drip Content)', 'Drip content')}</p>
                  <p className="text-xs text-gray-400 mt-0.5">{t('الدروس تفتح تدريجياً بعد التسجيل حسب الفاصل الزمني', 'Lessons unlock gradually after enrollment')}</p>
                </div>
                <button onClick={() => setForm({ ...form, drip_enabled: !form.drip_enabled })}>
                  <div className={`relative w-11 h-6 rounded-full transition-all ${form.drip_enabled ? 'bg-teal-500' : 'bg-gray-200'}`}>
                    <div className={`absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all ${form.drip_enabled ? 'start-5' : 'start-0.5'}`} />
                  </div>
                </button>
              </div>
              {form.drip_enabled && (
                <div className="mt-3">
                  <Label>{t('الفاصل الزمني بين الدروس (أيام)', 'Interval between lessons (days)')}</Label>
                  <Input type="number" min={1} max={365} value={form.drip_interval_days} onChange={(e) => setForm({ ...form, drip_interval_days: e.target.value })} className="h-10 mt-1 max-w-[140px]" />
                  <p className="text-xs text-gray-400 mt-1.5">{t('مثال: 3 = الدرس الأول فوراً، الثاني بعد 3 أيام، الثالث بعد 6...', 'Example: 3 = first lesson now, 2nd after 3 days, 3rd after 6...')}</p>
                </div>
              )}
            </div>
            <div className="p-4 rounded-xl border">
              <div className="flex items-center justify-between">
                <div>
                  <p className="font-medium text-sm">{t('متطلبات قبل بدء الكورس', 'Prerequisites')}</p>
                  <p className="text-xs text-gray-400 mt-0.5">{t('قائمة بالمتطلبات تظهر للطالب قبل البدء (اختياري)', 'Requirements shown to students before they start (optional)')}</p>
                </div>
                <button onClick={() => setForm({ ...form, prerequisites_enabled: !form.prerequisites_enabled })}>
                  <div className={`relative w-11 h-6 rounded-full transition-all ${form.prerequisites_enabled ? 'bg-teal-500' : 'bg-gray-200'}`}>
                    <div className={`absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all ${form.prerequisites_enabled ? 'start-5' : 'start-0.5'}`} />
                  </div>
                </button>
              </div>
              {form.prerequisites_enabled && (
                <div className="mt-3">
                  <Label>{t('المتطلبات (سطر لكل متطلب)', 'Requirements (one per line)')}</Label>
                  <textarea value={form.prerequisites} onChange={(e) => setForm({ ...form, prerequisites: e.target.value })} rows={4} placeholder={t('مثال:\nجهاز كمبيوتر أو لابتوب\nاتصال إنترنت مستقر\nمعرفة أساسية بـ...', 'Example:\nComputer or laptop\nStable internet connection\nBasic knowledge of...')} className="w-full border border-gray-200 rounded-lg p-3 text-sm mt-1 focus:outline-none focus:ring-2 focus:ring-teal-600" />
                  <p className="text-xs text-gray-400 mt-1.5">{t('ستظهر هذه المتطلبات للطالب مع زر تأكيد قبل فتح الكورس', 'Students must confirm these requirements before the course opens')}</p>
                </div>
              )}
            </div>
            <div className="p-4 rounded-xl border">
              <div className="flex items-center justify-between">
                <div>
                  <p className="font-medium text-sm flex items-center gap-1.5"><Award className="h-4 w-4 text-yellow-500" />{t('شهادة إتمام تلقائية', 'Automatic completion certificate')}</p>
                  <p className="text-xs text-gray-400 mt-0.5">{t('شهادة بتصميم مخصص و QR للتحقق تُمنح تلقائياً عند إكمال كل الدروس', 'Custom-designed certificate with QR verification issued automatically when all lessons are completed')}</p>
                </div>
                <button onClick={() => setForm({ ...form, certificate_enabled: !form.certificate_enabled })}>
                  <div className={`relative w-11 h-6 rounded-full transition-all ${form.certificate_enabled ? 'bg-teal-500' : 'bg-gray-200'}`}>
                    <div className={`absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all ${form.certificate_enabled ? 'start-5' : 'start-0.5'}`} />
                  </div>
                </button>
              </div>
              {form.certificate_enabled && (
                <div className="mt-3 space-y-3">
                  <div className="space-y-1.5">
                    <Label>{t('التصميم', 'Design')}</Label>
                    <div className="flex gap-2">
                      {[['classic', t('كلاسيكي', 'Classic')], ['modern', t('عصري', 'Modern')], ['minimal', t('بسيط', 'Minimal')]].map(([v, l]) => (
                        <button key={v} type="button" onClick={() => setForm({ ...form, certificate_template: v })}
                          className={cn('flex-1 px-3 py-2 rounded-lg border text-xs font-medium transition-all', form.certificate_template === v ? 'border-teal-500 bg-teal-50 text-teal-700' : 'border-gray-200 text-gray-500 hover:border-gray-300')}>{l}</button>
                      ))}
                    </div>
                  </div>
                  <div className="space-y-1.5">
                    <Label>{t('اللون المميز', 'Accent color')}</Label>
                    <div className="flex items-center gap-2">
                      <input type="color" value={form.certificate_accent_color} onChange={(e) => setForm({ ...form, certificate_accent_color: e.target.value })} className="h-9 w-12 rounded border border-gray-200 cursor-pointer" />
                      <span className="text-xs text-gray-400" dir="ltr">{form.certificate_accent_color}</span>
                    </div>
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-1.5"><Label>{t('اسم التوقيع', 'Signature name')}</Label><Input value={form.certificate_signature_name} onChange={(e) => setForm({ ...form, certificate_signature_name: e.target.value })} className="h-10" placeholder={t('مثال: أ. محمد', 'e.g. Dr. Name')} /></div>
                    <div className="space-y-1.5"><Label>{t('صفة الموقّع', 'Signature title')}</Label><Input value={form.certificate_signature_title} onChange={(e) => setForm({ ...form, certificate_signature_title: e.target.value })} className="h-10" placeholder={t('مثال: مدرب معتمد', 'e.g. Certified Trainer')} /></div>
                  </div>
                  <p className="text-xs text-gray-400">{t('يمكن للطلاب عرض الشهادة ومشاركتها وطباعتها ومسح QR للتحقق منها', 'Students can view, share, print, and verify the certificate by scanning its QR code')}</p>
                </div>
              )}
            </div>
          </div>
          <DialogFooter><Button variant="outline" onClick={() => setShowForm(false)}>{t('إلغاء', 'Cancel')}</Button><Button onClick={save}>{editing ? t('حفظ', 'Save') : t('إنشاء', 'Create')}</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
