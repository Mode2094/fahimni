'use client';

import { useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Users, BookOpen, HardDrive, Film, ClipboardList, HelpCircle, Clock, Video, GraduationCap, Loader2, AlertTriangle, BarChart3 } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const fmtBytes = (mb: number) => {
  if (mb >= 1024) return `${(mb / 1024).toFixed(1)} GB`;
  return `${mb} MB`;
};

export default function TeacherUsageOverview() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    if (!token) return;
    fetch(`${API}/teacher/plan`, {
      headers: { 'X-Tenant-Id': tid || '', Accept: 'application/json' },
    })
      .then((r) => r.json())
      .then((d) => setData(d))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <Card className="border-0 shadow-sm"><CardContent className="p-5 flex items-center justify-center gap-2 text-sm text-gray-400">
        <Loader2 className="h-4 w-4 animate-spin" /> {t('جاري تحميل الاستخدام...', 'Loading usage...')}
      </CardContent></Card>
    );
  }

  if (!data?.usage) return null;

  const u = data.usage;

  const items: { key: string; label: string; icon: any; color: string }[] = [
    { key: 'students', label: t('الطلاب', 'Students'), icon: GraduationCap, color: '#7c3aed' },
    { key: 'courses', label: t('الكورسات', 'Courses'), icon: BookOpen, color: '#0d9488' },
    { key: 'storage', label: t('التخزين', 'Storage'), icon: HardDrive, color: '#059669' },
    { key: 'videos', label: t('الفيديوهات', 'Videos'), icon: Film, color: '#0891b2' },
    { key: 'delivery_minutes', label: t('دقائق المشاهدة (شهرياً)', 'Watch minutes (month)'), icon: Clock, color: '#d97706' },
    { key: 'exams', label: t('الاختبارات', 'Exams'), icon: ClipboardList, color: '#dc2626' },
    { key: 'questions', label: t('الأسئلة', 'Questions'), icon: HelpCircle, color: '#16a34a' },
    { key: 'live_minutes', label: t('دقائق البث (شهرياً)', 'Live minutes (month)'), icon: Video, color: '#0d9488' },
  ];

  return (
    <Card className="border-0 shadow-sm">
      <CardContent className="p-6">
        <div className="flex items-center justify-between gap-2 mb-5 flex-wrap">
          <div className="flex items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-teal-500/10">
              <BarChart3 className="h-4.5 w-4.5 text-teal-600" />
            </span>
            <div>
              <h3 className="font-bold text-sm">{t('حدود خطتك', 'Your plan limits')}</h3>
              <p className="text-[11px] text-muted-foreground">{t('الاستخدام مقابل الخطة الحالية', 'Usage vs current plan')}</p>
            </div>
          </div>
          {data.plan && <Badge className="bg-teal-100 text-teal-700 border-0">{data.plan.plan?.name || data.plan.name}</Badge>}
        </div>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          {items.map((it) => {
            const e = u[it.key];
            if (!e) return null;
            const used = e.unit === 'bytes' ? fmtBytes(e.used / (1024 * 1024)) : String(e.used);
            const limit = e.unlimited ? t('غير محدود', 'Unlimited') : e.unit === 'bytes' ? fmtBytes(e.limit / (1024 * 1024)) : String(e.limit);
            const pct = e.unlimited ? 0 : (e.pct ?? 0);
            const barColor = pct >= 100 ? 'bg-rose-500' : pct >= 90 ? 'bg-amber-500' : pct >= 80 ? 'bg-amber-400' : 'bg-teal-500';
            return (
              <div key={it.key} className="rounded-2xl border border-slate-100 bg-slate-50/50 p-3.5 transition-colors hover:border-teal-200 hover:bg-teal-50/30">
                <div className="flex items-center gap-1.5 text-[10px] text-gray-500 mb-1.5">
                  <span className="flex h-6 w-6 items-center justify-center rounded-lg" style={{ backgroundColor: `${it.color}12` }}>
                    <it.icon className="h-3.5 w-3.5" style={{ color: it.color }} />
                  </span>
                  <span className="truncate font-semibold">{it.label}</span>
                </div>
                <p className="text-sm font-bold">{used} <span className="text-[10px] font-medium text-gray-400">/ {limit}</span></p>
                <div className="mt-2 h-1.5 rounded-full bg-slate-200/70">
                  {!e.unlimited && <div className={`h-full rounded-full ${barColor} transition-all duration-500`} style={{ width: `${Math.min(100, pct)}%` }} />}
                </div>
                {!e.unlimited && pct >= 80 && (
                  <p className="mt-1 text-[10px] text-amber-600 flex items-center gap-0.5">
                    <AlertTriangle className="h-3 w-3" />{pct}%
                  </p>
                )}
              </div>
            );
          })}
        </div>
      </CardContent>
    </Card>
  );
}
