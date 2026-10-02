'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { CardSkeleton } from '@/components/ui/skeleton';
import { toast } from 'sonner';
import { Loader2, Users, BookOpen, ClipboardList, TrendingUp, Target, Wallet, Clock, FileText, FileSpreadsheet, BarChart3 } from 'lucide-react';
import { BarChart, Bar, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend } from 'recharts';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AnalyticsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const { formatPrice } = useSettings();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [days, setDays] = useState(30);
  const [exporting, setExporting] = useState<'pdf' | 'excel' | null>(null);

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const load = () => {
    setLoading(true);
    fetch(`${API}/teacher/analytics?days=${days}`, { headers: headers() })
      .then(r => r.json()).then(d => setData(d)).catch(() => {})
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (check()) load(); }, [check(), days]);

  const download = async (fmt: 'pdf' | 'excel') => {
    setExporting(fmt);
    try {
      const res = await fetch(`${API}/teacher/analytics/report/${fmt}?days=${days}`, { headers: headers() });
      if (!res.ok) throw new Error();
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `analytics-report-${new Date().toISOString().slice(0, 10)}.${fmt === 'excel' ? 'xlsx' : 'pdf'}`;
      a.click();
      URL.revokeObjectURL(url);
    } catch { toast.error(t('فشل إنشاء التقرير', 'Failed to generate report')); }
    finally { setExporting(null); }
  };

  const fmtDuration = (sec: number) => {
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    if (h > 0) return `${h} ${t('س', 'h')} ${m} ${t('د', 'm')}`;
    return `${m} ${t('دقيقة', 'min')}`;
  };

  if (loading) return <CardSkeleton count={6} />;
  if (!data) return null;

  const ov = data.overview;
  const dailyData = (data.daily || []).map((d: any) => ({ ...d, date: d.date.slice(5) }));
  const courses = data.courses || [];
  const exams = data.exams || [];
  const topStudents = data.top_students || [];

  const stats = [
    { label: t('الطلاب', 'Students'), value: ov.total_students, icon: Users },
    { label: t('التسجيلات', 'Enrollments'), value: ov.total_enrollments, icon: BookOpen },
    { label: t('الكورسات', 'Courses'), value: ov.total_courses, icon: BookOpen },
    { label: t('الاختبارات', 'Exams'), value: ov.total_exams, icon: ClipboardList },
    { label: t('المحاولات', 'Attempts'), value: `${ov.total_attempts} · ${ov.pass_rate}% ${t('نجاح', 'pass')}`, icon: TrendingUp },
    { label: t('متوسط الدرجات', 'Avg score'), value: `${ov.avg_score}%`, icon: Target },
    { label: t('نسبة الإكمال', 'Completion'), value: `${ov.completion_rate}%`, icon: Target },
    { label: t('الإيراد', 'Revenue'), value: formatPrice(ov.revenue), icon: Wallet },
    { label: t('الربح', 'Profit'), value: formatPrice(ov.profit), icon: Wallet },
    { label: t('مدة المشاهدة', 'Watch time'), value: fmtDuration(ov.watch_seconds), icon: Clock },
  ];

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <BarChart3 className="h-6 w-6 text-teal-600" />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('التحليلات والتقارير', 'Analytics & Reports')}</h1>
            <p className="text-muted-foreground mt-1">{t('أداء منصتك من قاعدة البيانات مباشرة', 'Your platform performance, straight from the database')}</p>
          </div>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <div className="flex items-center gap-1 p-1 bg-white rounded-xl border">
            {[7, 30, 90].map((d) => (
              <button key={d} onClick={() => setDays(d)} className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-all ${days === d ? 'bg-teal-600 text-white' : 'text-gray-500 hover:bg-gray-50'}`}>{d} {t('يوم', 'd')}</button>
            ))}
          </div>
          <Button size="sm" variant="outline" className="gap-2 rounded-xl" disabled={!!exporting} onClick={() => download('pdf')}>
            {exporting === 'pdf' ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileText className="h-4 w-4 text-red-500" />}
            {t('PDF', 'PDF')}
          </Button>
          <Button size="sm" variant="outline" className="gap-2 rounded-xl" disabled={!!exporting} onClick={() => download('excel')}>
            {exporting === 'excel' ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileSpreadsheet className="h-4 w-4 text-green-600" />}
            {t('Excel', 'Excel')}
          </Button>
        </div>
      </div>

      {/* ─── بطاقات أساسية ─────────────────────────── */}
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
        {stats.map((s, i) => (
          <Card key={i} className="border-0 shadow-sm">
            <CardContent className="p-4">
              <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-teal-500/10 mb-2"><s.icon className="h-4.5 w-4.5 text-teal-600" /></div>
              <div className="text-lg font-bold truncate">{s.value}</div>
              <div className="text-xs text-gray-500 mt-0.5">{s.label}</div>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* ─── الرسوم البيانية ───────────────────────── */}
      <div className="grid lg:grid-cols-2 gap-6">
        <Card className="border-0 shadow-sm">
          <div className="p-5 border-b"><h2 className="font-semibold">{t('الإيرادات خلال الفترة', 'Revenue over time')}</h2></div>
          <CardContent className="p-5">
            <div className="h-56" dir="ltr">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={dailyData} margin={{ top: 5, right: 5, left: -10, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                  <XAxis dataKey="date" tick={{ fontSize: 10, fill: '#94a3b8' }} tickLine={false} axisLine={{ stroke: '#e2e8f0' }} />
                  <YAxis tick={{ fontSize: 10, fill: '#94a3b8' }} tickLine={false} axisLine={false} />
                  <Tooltip formatter={(v: any) => [formatPrice(v), t('الإيراد', 'Revenue')]} />
                  <Bar dataKey="revenue" name={t('الإيراد', 'Revenue')} fill="#0d9488" radius={[5, 5, 0, 0]} maxBarSize={26} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>

        <Card className="border-0 shadow-sm">
          <div className="p-5 border-b"><h2 className="font-semibold">{t('التسجيلات والمحاولات', 'Enrollments & attempts')}</h2></div>
          <CardContent className="p-5">
            <div className="h-56" dir="ltr">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={dailyData} margin={{ top: 5, right: 5, left: -10, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                  <XAxis dataKey="date" tick={{ fontSize: 10, fill: '#94a3b8' }} tickLine={false} axisLine={{ stroke: '#e2e8f0' }} />
                  <YAxis tick={{ fontSize: 10, fill: '#94a3b8' }} tickLine={false} axisLine={false} />
                  <Tooltip />
                  <Legend wrapperStyle={{ fontSize: 11 }} />
                  <Line type="monotone" dataKey="enrollments" name={t('تسجيلات', 'Enrollments')} stroke="#0d9488" strokeWidth={2} dot={false} />
                  <Line type="monotone" dataKey="attempts" name={t('محاولات', 'Attempts')} stroke="#7c3aed" strokeWidth={2} dot={false} />
                </LineChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* ─── أداء الكورسات ─────────────────────────── */}
      <Card className="border-0 shadow-sm overflow-hidden">
        <div className="p-5 border-b"><h2 className="font-semibold">{t('أداء الكورسات', 'Course performance')}</h2></div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm min-w-[560px]">
            <thead>
              <tr className="text-start text-xs text-gray-400 border-b bg-gray-50/60">
                <th className="p-3 font-medium">{t('الكورس', 'Course')}</th>
                <th className="p-3 font-medium">{t('التسجيلات', 'Enrollments')}</th>
                <th className="p-3 font-medium">{t('الإكمال', 'Completion')}</th>
                <th className="p-3 font-medium">{t('متوسط التقدم', 'Avg progress')}</th>
                <th className="p-3 font-medium">{t('المحاولات', 'Attempts')}</th>
                <th className="p-3 font-medium">{t('المشاهدة', 'Watch')}</th>
                <th className="p-3 font-medium">{t('الإيراد', 'Revenue')}</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {courses.length === 0 && (
                <tr><td colSpan={7} className="p-6 text-center text-gray-400">{t('لا كورسات بعد', 'No courses yet')}</td></tr>
              )}
              {courses.map((c: any) => (
                <tr key={c.id} className="hover:bg-gray-50/60">
                  <td className="p-3 font-medium">{c.title}</td>
                  <td className="p-3">{c.enrollments}</td>
                  <td className="p-3">{c.completion_rate}%</td>
                  <td className="p-3">{c.avg_progress}%</td>
                  <td className="p-3">{c.attempts}</td>
                  <td className="p-3">{fmtDuration(c.watch_seconds)}</td>
                  <td className="p-3 font-semibold">{formatPrice(c.revenue)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      {/* ─── أداء الاختبارات + أفضل الطلاب ─────────── */}
      <div className="grid lg:grid-cols-2 gap-6">
        <Card className="border-0 shadow-sm overflow-hidden">
          <div className="p-5 border-b"><h2 className="font-semibold">{t('أداء الاختبارات', 'Exam performance')}</h2></div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm min-w-[400px]">
              <thead>
                <tr className="text-start text-xs text-gray-400 border-b bg-gray-50/60">
                  <th className="p-3 font-medium">{t('الاختبار', 'Exam')}</th>
                  <th className="p-3 font-medium">{t('محاولات', 'Attempts')}</th>
                  <th className="p-3 font-medium">{t('متوسط', 'Avg')}</th>
                  <th className="p-3 font-medium">{t('نجاح', 'Pass')}</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {exams.length === 0 && (
                  <tr><td colSpan={4} className="p-6 text-center text-gray-400">{t('لا اختبارات بعد', 'No exams yet')}</td></tr>
                )}
                {exams.map((e: any) => (
                  <tr key={e.id} className="hover:bg-gray-50/60">
                    <td className="p-3 font-medium">{e.title}</td>
                    <td className="p-3">{e.attempts}</td>
                    <td className="p-3">{e.avg_score}%</td>
                    <td className="p-3">{e.pass_rate}%</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>

        <Card className="border-0 shadow-sm overflow-hidden">
          <div className="p-5 border-b"><h2 className="font-semibold">{t('أفضل الطلاب', 'Top students')}</h2></div>
          <div className="divide-y">
            {topStudents.length === 0 && (
              <p className="p-6 text-center text-sm text-gray-400">{t('لا نتائج اختبارات بعد', 'No exam results yet')}</p>
            )}
            {topStudents.map((s: any, i: number) => (
              <div key={s.id} className="flex items-center gap-3 p-3">
                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-teal-50 text-teal-700 text-xs font-bold shrink-0">{i + 1}</span>
                <div className="flex-1 min-w-0">
                  <p className="font-medium text-sm truncate">{s.name}</p>
                  <p className="text-[11px] text-gray-400" dir="ltr">{s.email}</p>
                </div>
                <span className="text-sm font-bold text-teal-700">{s.avg_score}%</span>
              </div>
            ))}
          </div>
        </Card>
      </div>
    </div>
  );
}
