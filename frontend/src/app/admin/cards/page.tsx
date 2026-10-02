'use client';

import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { CreditCard, Layers, Users, Ban, CalendarDays, Package, Building2, RefreshCcw, Ticket } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminCardsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [batches, setBatches] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [selected, setSelected] = useState('');

  const headers = () => ({ Accept: 'application/json' });

  const load = () => {
    fetch(`${API}/cards/batches`, { headers: headers() })
      .then(r => r.json()).then(d => {
        setBatches(d.batches || []);
        setSelected(prev => prev || d.batches?.[0]?.batch_id || '');
      }).catch(() => {})
      .finally(() => setLoading(false));
  };

  useEffect(() => { if (check()) load(); }, [check()]);

  const totals = useMemo(() => batches.reduce((acc, b) => ({
    total: acc.total + (b.total || 0),
    redeemed: acc.redeemed + (b.redeemed || 0),
    generated: acc.generated + (b.generated || 0),
    cancelled: acc.cancelled + (b.cancelled || 0),
    expired: acc.expired + (b.expired || 0),
  }), { total: 0, redeemed: 0, generated: 0, cancelled: 0, expired: 0 }), [batches]);

  const batch = batches.find((b: any) => b.batch_id === selected);
  const cards: any[] = batch?.cards || [];

  const statCards = [
    { label: t('إجمالي البطاقات', 'Total cards'), value: totals.total, icon: Package, color: '#0d9488' },
    { label: t('مستخدمة', 'Redeemed'), value: totals.redeemed, icon: Users, color: '#059669' },
    { label: t('جاهزة', 'Ready'), value: totals.generated, icon: CreditCard, color: '#0891b2' },
    { label: t('ملغاة', 'Cancelled'), value: totals.cancelled, icon: Ban, color: '#dc2626' },
  ];

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#0d948812' }}>
            <Ticket className="h-6 w-6" style={{ color: '#0d9488' }} />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('بطاقات التفعيل', 'Activation Cards')}</h1>
            <p className="text-muted-foreground mt-1">{t('مراقبة بطاقات جميع المعلمين على المنصة', 'Monitor cards of all teachers on the platform')}</p>
          </div>
        </div>
        <Button variant="outline" className="gap-2" onClick={load}><RefreshCcw className="h-4 w-4" /> {t('تحديث', 'Refresh')}</Button>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {statCards.map((s, i) => (
          <Card key={i}><CardContent className="p-5">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-xl" style={{ backgroundColor: `${s.color}12` }}><s.icon className="h-5 w-5" style={{ color: s.color }} /></div>
              <div><p className="text-xl font-bold">{s.value}</p><p className="text-xs text-gray-500">{s.label}</p></div>
            </div>
          </CardContent></Card>
        ))}
      </div>

      {loading ? <Card><CardContent className="p-4"><TableSkeleton rows={5} cols={4} /></CardContent></Card> :
        batches.length === 0 ? (
          <EmptyState icon={Layers} title={t('لا توجد دفعات بطاقات بعد', 'No card batches yet')} description={t('الدفعات تظهر هنا تلقائياً عندما ينشئ المعلمون بطاقات تفعيل', 'Batches appear here automatically when teachers generate activation cards')} />
        ) : (
          <>
            <Card><CardContent className="p-4">
              <h3 className="font-semibold flex items-center gap-2 mb-3"><Layers className="h-4 w-4 text-teal-600" /> {t('الدفعات', 'Batches')}</h3>
              <div className="flex flex-wrap gap-2">
                {batches.map((b: any) => (
                  <button key={b.batch_id} onClick={() => setSelected(b.batch_id)}
                    className={`px-4 py-2 rounded-full text-sm border transition-colors ${selected === b.batch_id ? 'bg-teal-500 text-white border-teal-500' : 'bg-white border-gray-200 hover:border-teal-300'}`}>
                    {b.course?.title || '—'} · <span className="font-mono text-xs">{b.batch_id.slice(0, 8)}</span>
                    <span className="ms-1 opacity-70">({b.total})</span>
                  </button>
                ))}
              </div>
            </CardContent></Card>

            {batch && (
              <Card>
                <div className="p-4 border-b">
                  <div className="flex items-center gap-2 text-sm text-gray-500">
                    <Building2 className="h-4 w-4" /> {batch.course?.tenant?.name || t('منصة', 'Platform')} · {batch.course?.title || ''}
                  </div>
                  <div className="flex flex-wrap gap-2 mt-2 text-xs">
                    <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 font-medium"><Package className="h-3 w-3" /> {t('إجمالي', 'Total')}: {batch.total}</span>
                    <span className="inline-flex items-center gap-1 rounded-full bg-teal-500/10 px-2.5 py-0.5 font-medium text-teal-700"><CreditCard className="h-3 w-3" /> {t('جاهزة', 'Ready')}: {batch.generated}</span>
                    <span className="inline-flex items-center gap-1 rounded-full bg-green-500/10 px-2.5 py-0.5 font-medium text-green-600"><Users className="h-3 w-3" /> {t('مستخدمة', 'Used')}: {batch.redeemed}</span>
                    <span className="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2.5 py-0.5 font-medium text-red-500"><Ban className="h-3 w-3" /> {t('ملغاة', 'Cancelled')}: {batch.cancelled}</span>
                    {batch.expires_at && <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-0.5 font-medium text-amber-600"><CalendarDays className="h-3 w-3" /> {t('تنتهي', 'Expires')}: {batch.expires_at.split('T')[0]}</span>}
                  </div>
                </div>
                <div className="divide-y max-h-[480px] overflow-y-auto">
                  {cards.map((c: any) => (
                    <div key={c.id} className="flex flex-wrap items-center gap-3 p-3.5">
                      <CreditCard className="h-5 w-5 text-teal-600 shrink-0" />
                      <div className="flex-1 min-w-[140px]">
                        <code className="px-2 py-1 rounded bg-gray-100 text-sm font-mono">{c.code}</code>
                        <p className="text-[11px] text-gray-400 mt-1">{c.course?.title || '—'} · {c.created_at?.split('T')[0]}</p>
                      </div>
                      <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                        c.status === 'generated' ? 'bg-teal-500/10 text-teal-700' :
                        c.status === 'redeemed' ? 'bg-green-500/10 text-green-600' :
                        c.status === 'cancelled' ? 'bg-red-500/10 text-red-500' : 'bg-gray-100 text-gray-500'
                      }`}>
                        {c.status === 'generated' ? t('جاهزة', 'Ready') : c.status === 'redeemed' ? t('مستخدمة', 'Used') : c.status === 'cancelled' ? t('ملغاة', 'Cancelled') : t('منتهية', 'Expired')}
                      </span>
                      {c.status === 'redeemed' && (
                        <span className="text-xs text-green-600">{c.student?.name || c.student?.full_name || c.student_id}</span>
                      )}
                    </div>
                  ))}
                </div>
              </Card>
            )}
          </>
        )}
    </div>
  );
}
