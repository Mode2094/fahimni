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
import { Select } from '@/components/ui/select';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Alert } from '@/components/ui/alert';
import { CreditCard, Loader2, Search, CalendarPlus, RefreshCcw, Building2, AlertCircle } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const STATUS_META: Record<string, [string, string]> = {
  active: ['نشط', 'text-green-600 bg-green-500/10'],
  trialing: ['تجريبي', 'text-teal-700 bg-teal-500/10'],
  pending: ['قيد الدفع', 'text-amber-600 bg-amber-500/10'],
  canceled: ['ملغي', 'text-red-500 bg-red-500/10'],
  expired: ['منتهي', 'text-gray-500 bg-gray-100'],
  past_due: ['متأخر', 'text-amber-600 bg-amber-500/10'],
};

const statusLabel = (s: string, locale: string) => {
  if (locale === 'ar') return (STATUS_META[s] || ['—'])[0];
  return { active: 'Active', trialing: 'Trialing', pending: 'Pending', canceled: 'Cancelled', expired: 'Expired', past_due: 'Past due' }[s] || s;
};

export default function AdminSubscriptionsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const { formatPrice } = useSettings();
  const t = (ar: string, en: string, _l?: string) => (_l ?? locale) === 'ar' ? ar : en;
  const [subscriptions, setSubscriptions] = useState<any[]>([]);
  const [stats, setStats] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [target, setTarget] = useState<any>(null);
  const [dialog, setDialog] = useState<'extend' | 'plan' | 'status' | null>(null);
  const [days, setDays] = useState('30');
  const [planId, setPlanId] = useState('');
  const [statusVal, setStatusVal] = useState('active');
  const [plans, setPlans] = useState<any[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const headers = () => ({ 'Content-Type': 'application/json', Accept: 'application/json' });

  const load = async () => {
    const q = new URLSearchParams();
    if (search) q.set('search', search);
    if (statusFilter) q.set('status', statusFilter);
    try {
      const d = await fetch(`${API}/admin/subscriptions?${q}`, { headers: headers() }).then(r => r.json());
      setSubscriptions(d.subscriptions || []);
      setStats(d.stats || null);
      setError('');
    } catch { setError(t('تعذر تحميل الاشتراكات', 'Failed to load subscriptions')); }
    setLoading(false);
  };

  useEffect(() => {
    if (!check()) return;
    load();
    fetch(`${API}/admin/plans`, { headers: headers() }).then(r => r.json()).then(d => setPlans(Array.isArray(d) ? d : (d.plans || []))).catch(() => {});
  }, [check()]);

  useEffect(() => { if (check()) load(); }, [statusFilter]);

  const doAction = async (path: string, body: any, msg: string) => {
    setSaving(true);
    try {
      const res = await fetch(`${API}${path}`, { method: 'POST', headers: headers(), body: JSON.stringify(body) });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      toast.success(msg);
      setTarget(null); setDialog(null);
      load();
    } catch { toast.error(t('تعذر الاتصال', 'Cannot connect')); }
    setSaving(false);
  };

  const statCards = [
    { label: t('إجمالي الاشتراكات', 'Total'), value: stats?.total ?? 0 },
    { label: t('نشطة', 'Active'), value: stats?.active ?? 0, color: 'text-green-600' },
    { label: t('تجريبية', 'Trialing'), value: stats?.trialing ?? 0, color: 'text-teal-700' },
    { label: t('ملغاة', 'Cancelled'), value: stats?.cancelled ?? 0, color: 'text-red-500' },
    { label: t('منتهية', 'Expired'), value: stats?.expired ?? 0, color: 'text-gray-500' },
    { label: t('إيراد شهري', 'MRR'), value: formatPrice(stats?.monthly_revenue ?? 0), color: 'text-emerald-600' },
  ];

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#0d948812' }}>
            <CreditCard className="h-6 w-6" style={{ color: '#0d9488' }} />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('إدارة الاشتراكات', 'Subscriptions')}</h1>
            <p className="text-muted-foreground mt-1">{t('جميع اشتراكات المعلمين على المنصة', 'All teacher subscriptions on the platform')}</p>
          </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        {statCards.map((s, i) => (
          <Card key={i} className="group border-0 shadow-sm card-hover overflow-hidden relative">
            <CardContent className="p-5">
              <p className="text-sm text-muted-foreground">{s.label}</p>
              <p className={`text-2xl font-black tracking-tight mt-1.5 ${s.color || ''}`}>{s.value}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="flex flex-wrap gap-3 items-center">
        <div className="relative flex-1 min-w-[220px] max-w-sm">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
          <Input placeholder={t('بحث باسم المعلم...', 'Search teacher name...')} value={search} onChange={(e) => { setSearch(e.target.value); }} onKeyDown={(e) => e.key === 'Enter' && load()} className="ps-9 h-10" />
        </div>
        <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="w-auto" aria-label={t('حالة الاشتراك', 'Subscription status')}>
          <option value="">{t('كل الحالات', 'All statuses')}</option>
          {Object.keys(STATUS_META).map(s => <option key={s} value={s}>{statusLabel(s, locale)}</option>)}
        </Select>
        <Button variant="outline" size="sm" className="gap-2" onClick={load}><RefreshCcw className="h-4 w-4" /> {t('تحديث', 'Refresh')}</Button>
      </div>

      {error && <Alert variant="error" icon={AlertCircle}>{error}</Alert>}

      {loading ? <Card><CardContent className="p-6"><TableSkeleton cols={5} rows={6} /></CardContent></Card> :
        subscriptions.length === 0 ? (
          <EmptyState icon={CreditCard} title={t('لا توجد اشتراكات', 'No subscriptions')} description={t('لم يشترك أي معلم بعد', 'No teacher has subscribed yet')} />
        ) : (
          <Card><div className="divide-y">
            {subscriptions.map((s) => {
              const meta = STATUS_META[s.status] || ['—', 'bg-gray-100 text-gray-500'];
              return (
                <div key={s.id} className="p-4 flex flex-wrap items-center gap-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-50 shrink-0"><Building2 className="h-5 w-5 text-teal-600" /></div>
                  <div className="flex-1 min-w-[180px]">
                  <p className="font-medium">{s.tenant?.name || '—'}</p>
                  <p className="text-xs text-gray-500">{s.plan?.name || '—'} · {t(s.billing_cycle === 'monthly' ? 'شهري' : 'سنوي', s.billing_cycle === 'monthly' ? 'Monthly' : 'Yearly')}</p>
                    <p className="text-[11px] text-gray-400 mt-0.5">
                      {t('يبدأ', 'Starts')}: {s.starts_at?.split('T')[0]} · {t('ينتهي', 'Ends')}: {s.ends_at?.split('T')[0] || '—'}
                      {s.trial_ends_at && <span> · {t('نهاية التجربة', 'Trial ends')}: {s.trial_ends_at.split('T')[0]}</span>}
                    </p>
                  </div>
                  <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${meta[1]}`}>{meta[0]}</span>
                  <div className="flex gap-1.5">
                    <Button variant="outline" size="sm" className="gap-1 text-xs" onClick={() => { setTarget(s); setDays('30'); setDialog('extend'); }}>
                      <CalendarPlus className="h-3.5 w-3.5" /> {t('تمديد', 'Extend')}
                    </Button>
                    <Button variant="outline" size="sm" className="gap-1 text-xs" onClick={() => { setTarget(s); setPlanId(s.plan_id || ''); setDialog('plan'); }}>
                      <RefreshCcw className="h-3.5 w-3.5" /> {t('تغيير الخطة', 'Change plan')}
                    </Button>
                    <Button variant="outline" size="sm" className="gap-1 text-xs" onClick={() => { setTarget(s); setStatusVal(s.status); setDialog('status'); }}>
                      <CreditCard className="h-3.5 w-3.5" /> {t('الحالة', 'Status')}
                    </Button>
                  </div>
                </div>
              );
            })}
          </div></Card>
        )}

      <Dialog open={!!dialog} onOpenChange={(v) => { if (!v) { setTarget(null); setDialog(null); } }}>
        <DialogContent>
          <DialogHeader><DialogTitle>
            {dialog === 'extend' && t('تمديد الاشتراك', 'Extend subscription')}
            {dialog === 'plan' && t('تغيير الخطة', 'Change plan')}
            {dialog === 'status' && t('تغيير الحالة', 'Change status')}
          </DialogTitle></DialogHeader>
          {target && (
            <div className="space-y-4">
              <p className="text-sm text-gray-500">{target.tenant?.name} — {target.plan?.name}</p>
              {dialog === 'extend' && (
                <div className="space-y-2"><Label>{t('عدد الأيام', 'Days')}</Label><Input type="number" min={1} value={days} onChange={(e) => setDays(e.target.value)} className="h-11" /></div>
              )}
              {dialog === 'plan' && (
                <div className="space-y-2"><Label>{t('الخطة', 'Plan')}</Label>
                  <Select value={planId} onChange={(e) => setPlanId(e.target.value)} className="h-11">
                    <option value="">{t('— اختر —', '— Select —')}</option>
                    {plans.map((p: any) => <option key={p.id} value={p.id}>{p.name} — {formatPrice(p.monthly_price)}/{t('شهر', 'mo')}</option>)}
                  </Select>
                </div>
              )}
              {dialog === 'status' && (
                <div className="space-y-2"><Label>{t('الحالة', 'Status')}</Label>
                  <Select value={statusVal} onChange={(e) => setStatusVal(e.target.value)} className="h-11">
                    {['active', 'trialing', 'pending', 'canceled', 'expired', 'past_due'].map(s => <option key={s} value={s}>{statusLabel(s, locale)}</option>)}
                  </Select>
                </div>
              )}
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => { setTarget(null); setDialog(null); }}>{t('إلغاء', 'Cancel')}</Button>
            <Button disabled={saving} className="gap-2" onClick={() => {
              if (dialog === 'extend') doAction(`/admin/subscriptions/${target.id}/extend`, { days: Number(days) }, t('تم تمديد الاشتراك', 'Subscription extended'));
              if (dialog === 'plan') doAction(`/admin/subscriptions/${target.id}/plan`, { plan_id: planId }, t('تم تغيير الخطة', 'Plan changed'));
              if (dialog === 'status') doAction(`/admin/subscriptions/${target.id}/status`, { status: statusVal }, t('تم تحديث الحالة', 'Status updated'));
            }}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}{t('حفظ', 'Save')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
