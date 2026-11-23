'use client';

import { useEffect, useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState } from '@/components/ui/empty-state';
import { CardSkeleton } from '@/components/ui/skeleton';
import { useLocale } from '@/store/locale';
import {
  Package, Plus, Pencil, Trash2, Loader2, BookOpen, UserPlus, EyeOff, Eye,
} from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

type Course = { id: string; title: string; is_published?: boolean };
type Bundle = {
  id: string;
  title: string;
  slug?: string;
  description?: string | null;
  short_description?: string | null;
  price?: number | string | null;
  currency?: string;
  is_published?: boolean;
  courses_count?: number;
  courses?: Course[];
};

const headers = () => ({
  'Content-Type': 'application/json',
  Accept: 'application/json',
  'X-Tenant-Id': typeof window !== 'undefined' ? localStorage.getItem('tenant_id') || '' : '',
});

type FormState = {
  id: string | null;
  title: string;
  short_description: string;
  price: string;
  is_published: boolean;
  courseIds: string[];
};

const EMPTY_FORM: FormState = {
  id: null, title: '', short_description: '', price: '', is_published: false, courseIds: [],
};

export default function TeacherBundlesPage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [bundles, setBundles] = useState<Bundle[]>([]);
  const [courses, setCourses] = useState<Course[]>([]);
  const [students, setStudents] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [busyId, setBusyId] = useState('');
  const [form, setForm] = useState<FormState | null>(null);
  const [enrollBundle, setEnrollBundle] = useState('');
  const [enrollStudent, setEnrollStudent] = useState('');
  const [enrolling, setEnrolling] = useState(false);

  const load = async () => {
    setLoading(true);
    try {
      const [b, c, s] = await Promise.all([
        fetch(`${API}/bundles`, { headers: headers() }).then((r) => (r.ok ? r.json() : { bundles: [] })),
        fetch(`${API}/courses?per_page=100`, { headers: headers() }).then((r) => (r.ok ? r.json() : { courses: [] })),
        fetch(`${API}/teacher/students`, { headers: headers() }).then((r) => (r.ok ? r.json() : { students: [] })),
      ]);
      setBundles(b.bundles || []);
      setCourses(Array.isArray(c.courses) ? c.courses : (c.courses?.data || []));
      setStudents(s.students || []);
    } catch {
      toast.error(t('تعذّر تحميل الحزم', 'Could not load bundles'));
    }
    setLoading(false);
  };

  useEffect(() => { void load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, []);

  const openCreate = () => setForm({ ...EMPTY_FORM });

  const openEdit = (b: Bundle) => setForm({
    id: b.id,
    title: b.title,
    short_description: b.short_description || '',
    price: b.price != null ? String(b.price) : '',
    is_published: !!b.is_published,
    courseIds: (b.courses || []).map((c) => c.id),
  });

  const toggleCourse = (id: string) => {
    if (!form) return;
    setForm({
      ...form,
      courseIds: form.courseIds.includes(id)
        ? form.courseIds.filter((x) => x !== id)
        : [...form.courseIds, id],
    });
  };

  const save = async () => {
    if (!form) return;
    if (!form.title.trim()) { toast.error(t('أدخل عنوان الحزمة', 'Enter a bundle title')); return; }
    setSaving(true);
    const payload = {
      title: form.title.trim(),
      short_description: form.short_description || null,
      price: form.price === '' ? null : Number(form.price),
      is_published: form.is_published,
      course_ids: form.courseIds,
    };
    try {
      const res = await fetch(form.id ? `${API}/bundles/${form.id}` : `${API}/bundles`, {
        method: form.id ? 'PUT' : 'POST',
        headers: headers(),
        body: JSON.stringify(payload),
      });
      if (!res.ok) {
        const d = await res.json().catch(() => null);
        toast.error(d?.message || t('فشل الحفظ', 'Save failed'));
        return;
      }
      toast.success(form.id ? t('تم تحديث الحزمة', 'Bundle updated') : t('تم إنشاء الحزمة', 'Bundle created'));
      setForm(null);
      await load();
    } catch {
      toast.error(t('فشل الحفظ', 'Save failed'));
    } finally {
      setSaving(false);
    }
  };

  const togglePublish = async (b: Bundle) => {
    setBusyId(b.id);
    try {
      const res = await fetch(`${API}/bundles/${b.id}`, {
        method: 'PUT',
        headers: headers(),
        body: JSON.stringify({ is_published: !b.is_published }),
      });
      if (res.ok) { toast.success(t('تم التحديث', 'Updated')); await load(); }
      else toast.error(t('تعذّر التحديث', 'Update failed'));
    } finally { setBusyId(''); }
  };

  const remove = async (b: Bundle) => {
    if (typeof window !== 'undefined' && !window.confirm(t('حذف الحزمة؟ لن تُحذف الكورسات.', 'Delete bundle? Its courses stay.'))) return;
    setBusyId(b.id);
    try {
      const res = await fetch(`${API}/bundles/${b.id}`, { method: 'DELETE', headers: headers() });
      if (res.ok) { toast.success(t('تم الحذف', 'Deleted')); await load(); }
      else toast.error(t('تعذّر الحذف', 'Delete failed'));
    } finally { setBusyId(''); }
  };

  const enroll = async () => {
    if (!enrollBundle || !enrollStudent) { toast.error(t('اختر الحزمة والطالب', 'Pick a bundle and a student')); return; }
    setEnrolling(true);
    try {
      const res = await fetch(`${API}/bundles/${enrollBundle}/enroll`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ user_id: enrollStudent }),
      });
      const d = await res.json().catch(() => null);
      if (!res.ok) { toast.error(d?.message || t('تعذّر التسجيل', 'Enrollment failed')); return; }
      toast.success(d?.message || t('تم التسجيل', 'Enrolled'));
    } catch {
      toast.error(t('تعذّر التسجيل', 'Enrollment failed'));
    } finally { setEnrolling(false); }
  };

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-teal-50 text-teal-600">
            <Package className="h-6 w-6" />
          </span>
          <div>
            <h1 className="text-xl font-bold">{t('حزم الكورسات', 'Course Bundles')}</h1>
            <p className="text-sm text-gray-500">{t('اجمع عدة كورسات في حزمة واحدة', 'Group several courses into one package')}</p>
          </div>
        </div>
        <Button onClick={openCreate} className="gap-2 rounded-full" style={{ background: '#0d9488' }}>
          <Plus className="h-4 w-4" /> {t('حزمة جديدة', 'New bundle')}
        </Button>
      </div>

      {/* Enroll a student into every course of a bundle */}
      <Card>
        <CardContent className="flex flex-wrap items-end gap-3 p-4">
          <div className="flex-1 min-w-[180px]">
            <Label>{t('تسجيل طالب في الحزمة', 'Enroll a student in a bundle')}</Label>
            <select
              aria-label={t('الحزمة', 'Bundle')}
              className="mt-1 w-full rounded-xl border bg-white px-3 py-2 text-sm"
              value={enrollBundle}
              onChange={(e) => setEnrollBundle(e.target.value)}
            >
              <option value="">{t('اختر حزمة…', 'Choose a bundle…')}</option>
              {bundles.map((b) => <option key={b.id} value={b.id}>{b.title}</option>)}
            </select>
          </div>
          <div className="flex-1 min-w-[180px]">
            <Label>{t('الطالب', 'Student')}</Label>
            <select
              aria-label={t('الطالب', 'Student')}
              className="mt-1 w-full rounded-xl border bg-white px-3 py-2 text-sm"
              value={enrollStudent}
              onChange={(e) => setEnrollStudent(e.target.value)}
            >
              <option value="">{t('اختر طالباً…', 'Choose a student…')}</option>
              {students.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </div>
          <Button onClick={enroll} disabled={enrolling} variant="outline" className="gap-2 rounded-full">
            {enrolling ? <Loader2 className="h-4 w-4 animate-spin" /> : <UserPlus className="h-4 w-4" />}
            {t('تسجيل', 'Enroll')}
          </Button>
        </CardContent>
      </Card>

      {form && (
        <Card data-testid="bundle-form">
          <CardContent className="space-y-4 p-4">
            <h2 className="font-semibold">{form.id ? t('تعديل الحزمة', 'Edit bundle') : t('حزمة جديدة', 'New bundle')}</h2>
            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <Label htmlFor="bundle-title">{t('العنوان', 'Title')}</Label>
                <Input id="bundle-title" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
              </div>
              <div>
                <Label htmlFor="bundle-price">{t('السعر (اختياري)', 'Price (optional)')}</Label>
                <Input id="bundle-price" type="number" min="0" value={form.price}
                  onChange={(e) => setForm({ ...form, price: e.target.value })} />
              </div>
            </div>
            <div>
              <Label htmlFor="bundle-desc">{t('وصف مختصر', 'Short description')}</Label>
              <Input id="bundle-desc" value={form.short_description}
                onChange={(e) => setForm({ ...form, short_description: e.target.value })} />
            </div>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={form.is_published}
                onChange={(e) => setForm({ ...form, is_published: e.target.checked })} />
              {t('نشر الحزمة على الصفحة العامة', 'Publish on the public page')}
            </label>
            <div>
              <Label>{t('الكورسات', 'Courses')}</Label>
              <div className="mt-2 grid max-h-56 gap-2 overflow-y-auto rounded-xl border p-3 sm:grid-cols-2">
                {courses.length === 0 && <p className="text-sm text-gray-400">{t('لا توجد كورسات', 'No courses')}</p>}
                {courses.map((c) => (
                  <label key={c.id} className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.courseIds.includes(c.id)} onChange={() => toggleCourse(c.id)} />
                    <span className="truncate">{c.title}</span>
                  </label>
                ))}
              </div>
            </div>
            <div className="flex gap-2">
              <Button onClick={save} disabled={saving} className="gap-2 rounded-full" style={{ background: '#0d9488' }}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : null} {t('حفظ', 'Save')}
              </Button>
              <Button variant="outline" onClick={() => setForm(null)} className="rounded-full">{t('إلغاء', 'Cancel')}</Button>
            </div>
          </CardContent>
        </Card>
      )}

      {loading ? (
        <CardSkeleton count={3} />
      ) : bundles.length === 0 ? (
        <EmptyState
          icon={Package}
          title={t('لا توجد حزم بعد', 'No bundles yet')}
          description={t('أنشئ حزمة واجمع فيها عدة كورسات.', 'Create a bundle and group several courses in it.')}
        />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {bundles.map((b) => (
            <Card key={b.id} data-testid="bundle-card">
              <CardContent className="space-y-3 p-4">
                <div className="flex items-start justify-between gap-2">
                  <h3 className="font-semibold">{b.title}</h3>
                  <span className={`rounded-full px-2 py-0.5 text-xs ${b.is_published ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500'}`}>
                    {b.is_published ? t('منشورة', 'Published') : t('مسودة', 'Draft')}
                  </span>
                </div>
                <p className="flex items-center gap-1.5 text-sm text-gray-500">
                  <BookOpen className="h-4 w-4" /> {b.courses_count ?? (b.courses || []).length} {t('كورس', 'courses')}
                </p>
                {b.price != null && b.price !== '' && (
                  <p className="text-sm font-medium text-teal-700">{b.price} {b.currency || ''}</p>
                )}
                <div className="flex flex-wrap gap-2">
                  <Button size="sm" variant="outline" className="gap-1 rounded-full" onClick={() => openEdit(b)}>
                    <Pencil className="h-3.5 w-3.5" /> {t('تعديل', 'Edit')}
                  </Button>
                  <Button size="sm" variant="outline" className="gap-1 rounded-full" disabled={busyId === b.id} onClick={() => togglePublish(b)}>
                    {b.is_published ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
                    {b.is_published ? t('إخفاء', 'Unpublish') : t('نشر', 'Publish')}
                  </Button>
                  <Button size="sm" variant="outline" className="gap-1 rounded-full text-red-600" disabled={busyId === b.id} onClick={() => remove(b)}>
                    <Trash2 className="h-3.5 w-3.5" /> {t('حذف', 'Delete')}
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
