'use client';

import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { EmptyState } from '@/components/ui/empty-state';
import { TableSkeleton } from '@/components/ui/skeleton';
import { Eye, Loader2, Search, ShieldAlert, GraduationCap, Users } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const headers = () => ({ 'Content-Type': 'application/json', Accept: 'application/json' });

/**
 * Super-admin entry point for the read-only "View-As" mode.
 *
 * Starting a preview switches the browser session to the chosen teacher or
 * student. The backend then refuses every write until the admin exits — this
 * page only lists accounts and starts the switch, and always shows the
 * read-only warning up front.
 */
export default function AdminViewAsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [tab, setTab] = useState<'teachers' | 'students'>('teachers');
  const [teachers, setTeachers] = useState<any[]>([]);
  const [students, setStudents] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [busyId, setBusyId] = useState('');

  const load = useCallback(() => {
    setLoading(true);
    const q = search ? `?search=${encodeURIComponent(search)}` : '';
    Promise.all([
      fetch(`${API}/admin/teachers${q}`, { headers: headers() }).then((r) => (r.ok ? r.json() : { teachers: [] })),
      fetch(`${API}/admin/students${q}`, { headers: headers() }).then((r) => (r.ok ? r.json() : { students: [] })),
    ]).then(([te, st]) => {
      setTeachers(te.teachers || []);
      setStudents(st.students || []);
    }).catch(() => {}).finally(() => setLoading(false));
  }, [search]);

  useEffect(() => { if (check()) load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [load]);

  const start = async (id: string, kind: 'teacher' | 'student') => {
    setBusyId(id);
    try {
      const res = await fetch(`${API}/admin/view-as/${id}`, { method: 'POST', headers: headers() });
      const d = await res.json().catch(() => null);
      if (!res.ok) {
        toast.error(d?.message || t('تعذّر بدء المعاينة', 'Could not start the preview'));
        return;
      }
      // Full navigation so every panel re-resolves the impersonated session.
      window.location.href = kind === 'teacher' ? '/teacher/dashboard' : '/my-learning';
    } catch {
      toast.error(t('تعذّر بدء المعاينة', 'Could not start the preview'));
    } finally {
      setBusyId('');
    }
  };

  const rows = tab === 'teachers' ? teachers : students;

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <div className="flex items-center gap-3">
        <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-amber-50 text-amber-600">
          <Eye className="h-6 w-6" />
        </span>
        <div>
          <h1 className="text-xl font-bold">{t('معاينة حساب (قراءة فقط)', 'Preview an account (read-only)')}</h1>
          <p className="text-sm text-gray-500">{t('اعرض المنصة كما يراها هذا الحساب دون إمكانية أي تعديل.', 'See the platform as this account sees it — with no ability to change anything.')}</p>
        </div>
      </div>

      <div className="flex items-start gap-2 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
        <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" />
        <p>{t('أثناء المعاينة يُمنع أي إنشاء أو تعديل أو حذف أو دفع على مستوى الخادم، ويمكنك الخروج من المعاينة في أي وقت من الشريط العلوي.', 'While previewing, every create, update, delete and payment is refused server-side; you can exit the preview at any time from the top bar.')}</p>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <div className="flex gap-1.5 rounded-full bg-slate-100/80 p-1.5">
          <button
            onClick={() => setTab('teachers')}
            className={`flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-bold ${tab === 'teachers' ? 'bg-white text-teal-700 shadow-sm' : 'text-gray-500'}`}
          >
            <Users className="h-4 w-4" /> {t('المعلمون', 'Teachers')}
          </button>
          <button
            onClick={() => setTab('students')}
            className={`flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-bold ${tab === 'students' ? 'bg-white text-teal-700 shadow-sm' : 'text-gray-500'}`}
          >
            <GraduationCap className="h-4 w-4" /> {t('الطلاب', 'Students')}
          </button>
        </div>
        <div className="relative ms-auto w-full sm:w-72">
          <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('ابحث بالاسم أو البريد…', 'Search by name or email…')}
            className="ps-9"
            aria-label={t('بحث', 'Search')}
          />
        </div>
      </div>

      {loading ? (
        <TableSkeleton />
      ) : rows.length === 0 ? (
        <EmptyState icon={Eye} title={t('لا نتائج', 'No results')} description={t('جرّب بحثاً آخر.', 'Try another search.')} />
      ) : (
        <Card>
          <CardContent className="divide-y p-0">
            {rows.map((u: any) => (
              <div key={u.id} className="flex items-center gap-3 p-3 sm:p-4">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-teal-50 text-xs font-bold text-teal-700">
                  {(u.name || '?').slice(0, 1).toUpperCase()}
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate font-medium">{u.name}</p>
                  <p className="truncate text-xs text-gray-500">{u.email}</p>
                </div>
                <Button
                  size="sm"
                  variant="outline"
                  className="gap-1.5 rounded-full"
                  disabled={busyId === u.id}
                  onClick={() => start(u.id, tab === 'teachers' ? 'teacher' : 'student')}
                >
                  {busyId === u.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Eye className="h-3.5 w-3.5" />}
                  {t('معاينة', 'Preview')}
                </Button>
              </div>
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
