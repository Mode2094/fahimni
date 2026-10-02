'use client';

import { useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { HardDrive, Film, Radio, Clock, Video, AlertTriangle, XCircle, Loader2, CircleDollarSign } from 'lucide-react';
import { useRouter } from 'next/navigation';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const fmt = (bytes: number) => {
  if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
  if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
  if (bytes >= 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${bytes} B`;
};

export default function TeacherMediaUsage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [usage, setUsage] = useState<any>(null);
  const [cost, setCost] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const router = useRouter();

  useEffect(() => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    if (!token) return;
    fetch(`${API}/teacher/media/usage`, {
      headers: { 'X-Tenant-Id': tid || '', Accept: 'application/json' },
    })
      .then(r => r.json())
      .then(d => { setUsage(d.usage); setCost(d.estimated_cost || null); })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <Card className="border-0 shadow-sm"><CardContent className="p-5 flex items-center justify-center gap-2 text-sm text-gray-400">
        <Loader2 className="h-4 w-4 animate-spin" /> {t('جاري تحميل استخدام الوسائط...', 'Loading media usage...')}
      </CardContent></Card>
    );
  }

  if (!usage) return null;

  const storage = usage.storage;
  const pct = storage.pct ?? 0;
  const barColor = pct >= 100 ? 'bg-rose-500' : pct >= 90 ? 'bg-amber-500' : 'bg-teal-500';

  const rows = [
    { key: 'videos', label: t('الفيديوهات', 'Videos'), icon: Film, color: '#7c3aed' },
    { key: 'cost', label: t('التكلفة التقديرية (الشهر)', 'Estimated cost (month)'), icon: CircleDollarSign, color: '#dc2626', custom: cost ? `${cost.total} ${cost.currency}` : '—' },
    { key: 'live_sessions', label: t('البث المباشر', 'Live sessions'), icon: Radio, color: '#059669' },
    { key: 'live_minutes', label: t('دقائق البث (الشهر)', 'Live minutes (month)'), icon: Clock, color: '#0891b2' },
    { key: 'recordings', label: t('التسجيلات', 'Recordings'), icon: Video, color: '#d97706' },
  ];

  return (
    <Card className="border-0 shadow-sm overflow-hidden">
      <CardContent className="p-6 space-y-5">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-teal-500/10">
              <HardDrive className="h-4.5 w-4.5 text-teal-600" />
            </span>
            <div>
              <h3 className="font-bold text-sm">{t('استخدام الوسائط', 'Media usage')}</h3>
              <p className="text-[11px] text-muted-foreground">{t('التخزين والبث والتسجيلات', 'Storage, live & recordings')}</p>
            </div>
          </div>
          <button onClick={() => router.push('/courses')} className="shrink-0 rounded-full border border-teal-200 bg-teal-50 px-3.5 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100">
            {t('إدارة المحتوى', 'Manage content')}
          </button>
        </div>

        {usage.warnings?.length > 0 && (
          <div className="space-y-1.5">
            {usage.warnings.map((w: any, i: number) => (
              <div key={i} className={`flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold ${w.level === 'blocked' ? 'bg-rose-50 text-rose-700' : w.level === 'critical' ? 'bg-amber-50 text-amber-700' : 'bg-yellow-50 text-yellow-700'}`}>
                {w.level === 'blocked' ? <XCircle className="h-4 w-4 shrink-0" /> : <AlertTriangle className="h-4 w-4 shrink-0" />}
                {w.message}
              </div>
            ))}
          </div>
        )}

        {/* التخزين */}
        <div className="rounded-2xl border border-slate-100 bg-slate-50/60 p-4">
          <div className="mb-2 flex items-center justify-between text-sm">
            <span className="font-semibold text-slate-700">{t('مساحة التخزين', 'Storage')}</span>
            <span className="font-black text-slate-800">{fmt(storage.used)} <span className="font-medium text-slate-400">/ {storage.limit ? fmt(storage.limit) : '∞'}</span></span>
          </div>
          <div className="h-3 overflow-hidden rounded-full bg-slate-200/70">
            <div className={`h-full rounded-full ${barColor} transition-all duration-500`} style={{ width: `${Math.min(100, pct)}%` }} />
          </div>
          <div className="mt-1.5 flex justify-between text-[11px] text-gray-400">
            <span>{Math.min(100, pct)}%</span>
            {storage.exceeded && <span className="text-rose-500 font-bold">{t('متجاوز الحد — قم بالترقية أو احذف ملفات', 'Over limit — upgrade or delete files')}</span>}
          </div>
        </div>

        {/* عدادات */}
        <div className="grid grid-cols-2 gap-2.5">
          {rows.map((r) => {
            const e = usage[r.key];
            if (!e && !r.custom) return null;
            const value = r.custom ?? (r.key === 'live_minutes' ? String(e.used ?? 0) : e.unlimited ? `${e.used} ∞` : `${e.used} / ${e.limit}`);
            return (
              <div key={r.key} className="flex items-center gap-2.5 rounded-xl border border-slate-100 bg-white px-3 py-2.5 shadow-sm transition-colors hover:border-teal-200">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl" style={{ backgroundColor: `${r.color}12` }}>
                  <r.icon className="h-4 w-4 shrink-0" style={{ color: r.color }} />
                </span>
                <div className="min-w-0">
                  <p className="text-[10px] text-gray-500 truncate">{r.label}</p>
                  <p className="text-sm font-bold">{value}</p>
                </div>
              </div>
            );
          })}
        </div>

        {usage.storage.exceeded && (
          <button
            onClick={() => router.push('/pricing')}
            className="w-full rounded-full bg-gradient-to-r from-teal-600 to-teal-500 py-2.5 text-sm font-bold text-white shadow-md transition hover:opacity-90"
          >
            {t('ترقية الخطة', 'Upgrade plan')}
          </button>
        )}
      </CardContent>
    </Card>
  );
}
