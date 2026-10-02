'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { BarChart3, RefreshCcw, CircleDollarSign, TrendingUp, TrendingDown, HardDrive, Loader2, Users } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminBiPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [bi, setBi] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [recalc, setRecalc] = useState(false);

  const headers = () => ({ Accept: 'application/json' });

  const load = async () => {
    try {
      const res = await fetch(`${API}/admin/bi`, { headers: headers() });
      const d = await res.json();
      setBi(d.bi);
    } catch { toast.error(t('فشل التحميل', 'Failed to load')); }
    finally { setLoading(false); }
  };

  useEffect(() => { if (check()) load(); }, [check()]);

  const recalculate = async () => {
    setRecalc(true);
    try {
      const d = await fetch(`${API}/admin/bi/recalculate`, { method: 'POST', headers: headers() }).then(r => r.json());
      if (d.bi) setBi(d.bi);
      toast.success(d.message || t('تمت إعادة الحساب', 'Recalculated'));
      load();
    } catch { toast.error(t('فشلت إعادة الحساب', 'Recalculation failed')); }
    finally { setRecalc(false); }
  };

  const monthlyRev = Number(bi?.monthly_revenue ?? 0);
  const monthlyCost = Number(bi?.monthly_infra_cost ?? 0);
  const margin = Number(bi?.platform_margin ?? 0);
  const totalSubs = (bi?.plans || []).reduce((a: number, p: any) => a + Number(p.subscribers ?? 0), 0);

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#0d948812' }}>
            <BarChart3 className="h-6 w-6" style={{ color: '#0d9488' }} />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('ذكاء الأعمال', 'Business Intelligence')}</h1>
            <p className="text-muted-foreground mt-1">{t('أداء المنصة وربحية الخطط', 'Platform performance & plan profitability')}</p>
          </div>
        </div>
        <Button onClick={recalculate} disabled={recalc} className="gap-2 rounded-full">
          {recalc ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCcw className="h-4 w-4" />}
          {t('إعادة الحساب', 'Recalculate')}
        </Button>
      </div>

      {loading ? <Card><CardContent className="p-6"><TableSkeleton cols={4} rows={5} /></CardContent></Card> : !bi ? <EmptyState icon={BarChart3} title={t('لا توجد بيانات', 'No data')} /> : (
        <>
          {/* ─── ملخص سريع ─────────────────────────── */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <Card className="border-0 shadow-sm"><CardContent className="p-5">
              <p className="text-xs text-gray-400 flex items-center gap-1"><CircleDollarSign className="h-3.5 w-3.5" />{t('إيراد الشهر', 'Monthly revenue')}</p>
              <p className="text-2xl font-bold mt-1">{monthlyRev} {bi.revenue_currency || 'JOD'}</p>
            </CardContent></Card>
            <Card className="border-0 shadow-sm"><CardContent className="p-5">
              <p className="text-xs text-gray-400 flex items-center gap-1"><Users className="h-3.5 w-3.5" />{t('المشتركون', 'Subscribers')}</p>
              <p className="text-2xl font-bold mt-1">{totalSubs}</p>
            </CardContent></Card>
            <Card className="border-0 shadow-sm"><CardContent className="p-5">
              <p className="text-xs text-gray-400 flex items-center gap-1"><HardDrive className="h-3.5 w-3.5" />{t('تكلفة البنية', 'Infra cost')}</p>
              <p className="text-2xl font-bold mt-1 text-red-600">{monthlyCost} USD</p>
            </CardContent></Card>
            <Card className="border-0 shadow-sm"><CardContent className="p-5">
              <p className="text-xs text-gray-400 flex items-center gap-1">{margin >= 0 ? <TrendingUp className="h-3.5 w-3.5" /> : <TrendingDown className="h-3.5 w-3.5" />}{t('هامش الربح', 'Margin')}</p>
              <p className={`text-2xl font-bold mt-1 ${margin >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>{margin} USD</p>
            </CardContent></Card>
          </div>

          {/* ─── ربحية الخطط (مبسطة) ─────────────────── */}
          <Card className="border-0 shadow-sm"><CardContent className="p-6">
            <h3 className="font-bold mb-4">{t('ربحية الخطط', 'Plan profitability')}</h3>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-slate-50 text-xs text-gray-500">
                    {[t('الخطة', 'Plan'), t('السعر', 'Price'), t('مشتركون', 'Subscribers'), t('ربح متوقع', 'Expected profit'), t('الحالة', 'Status')].map((h, i) => (
                      <th key={i} className="px-4 py-3 text-start font-semibold">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {(bi.plans || []).map((p: any) => (
                    <tr key={p.plan_id} className="hover:bg-gray-50">
                      <td className="px-4 py-3 font-medium">{p.plan_name}</td>
                      <td className="px-4 py-3">{p.price} {p.currency}</td>
                      <td className="px-4 py-3">{p.subscribers}</td>
                      <td className={`px-4 py-3 font-semibold ${p.expected_profit >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>{p.expected_profit} USD</td>
                      <td className="px-4 py-3">
                        {p.loss ? <span className="text-red-600 font-medium">{t('خاسرة', 'Loss')}</span> : p.margin_percentage < 40 ? <span className="text-amber-600 font-medium">{t('هامش ضعيف', 'Thin')}</span> : <span className="text-emerald-600 font-medium">{t('مربحة', 'Profitable')}</span>}
                      </td>
                    </tr>
                  ))}
                  {(bi.plans || []).length === 0 && (
                    <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-400">{t('لا خطط نشطة', 'No active plans')}</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </CardContent></Card>

          {/* ─── تكاليف البنية (مبسطة) ───────────────── */}
          {(bi.infrastructure || []).length > 0 && (
            <Card className="border-0 shadow-sm"><CardContent className="p-6">
              <h3 className="font-bold mb-4">{t('تكاليف البنية التحتية (هذا الشهر)', 'Infrastructure costs (this month)')}</h3>
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {(bi.infrastructure || []).map((p: any) => (
                  <div key={p.provider} className="rounded-xl border border-slate-100 p-4">
                    <div className="flex items-center justify-between mb-2">
                      <span className="font-semibold capitalize">{p.provider}</span>
                      <span className="font-bold">{p.total} {p.currency}</span>
                    </div>
                    <div className="grid grid-cols-2 gap-2 text-xs text-gray-500">
                      {[['storage', t('تخزين', 'Storage')], ['delivery', t('بث', 'Delivery')], ['live', t('مباشر', 'Live')]].map(([k, label]) => (
                        <div key={k} className="rounded-lg bg-slate-50 px-3 py-2">
                          <p className="text-[10px] text-gray-400">{label}</p>
                          <p className="font-semibold text-slate-700">{Number(p[k] ?? 0).toFixed(4)} {p.currency}</p>
                        </div>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </CardContent></Card>
          )}
        </>
      )}
    </div>
  );
}
