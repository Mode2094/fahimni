'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import AdminSubscriptionMetrics from '@/components/admin/subscription-metrics';
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
  cancelled: ['ملغي', 'text-red-500 bg-red-500/10'],
  expired: ['منتهي', 'text-gray-500 bg-gray-100'],
  past_due: ['متأخر', 'text-amber-600 bg-amber-500/10'],
};

const statusLabel = (s: string, locale: string) => {
  const normalized = s === 'canceled' ? 'cancelled' : s;
  if (locale === 'ar') return (STATUS_META[normalized] || ['—'])[0];
  return { active: 'Active', trialing: 'Trialing', pending: 'Pending', cancelled: 'Cancelled', expired: 'Expired', past_due: 'Past due' }[normalized] || normalized;
};

export default function AdminSubscriptionsPanel() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const { formatPrice } = useSettings();
  const t = (ar: string, en: string, _l?: string) => (_l ?? locale) === 'ar' ? ar : en;
  const [subscriptions, setSubscriptions] = useState<any[]>([]);
  const [subscriptionMetrics, setSubscriptionMetrics] = useState<any>(null);
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
  const [requestId, setRequestId] = useState('');

  const headers = () => ({ 'Content-Type': 'application/json', 'X-Tenant-Id': '', Accept: 'application/json' });

  // Stable per-extend-dialog id: a retry/double submit can never add the days
  // twice, because the server treats a repeated request_id as a no-op.
  const newRequestId = () => (globalThis.crypto?.randomUUID?.() ?? `req_${Date.now()}_${Math.random().toString(36).slice(2)}`);

  const load = async () => {
    const q = new URLSearchParams();
    if (search) q.set('search', search);
    if (statusFilter) q.set('status', statusFilter);
    try {
      const d = await fetch(`${API}/admin/subscriptions?${q}`, { headers: headers(), cache: 'no-store' }).then(r => r.json());
      setSubscriptions(d.subscriptions || []);
      setSubscriptionMetrics(d.subscription_metrics || null);
      setError('');
    } catch { setError(t('تعذر تحميل الاشتراكات', 'Failed to load subscriptions')); }
    setLoading(false);
  };

  useEffect(() => {
    const requestedStatus = new URLSearchParams(window.location.search).get('status') || '';
    const normalizedStatus = requestedStatus === 'canceled' ? 'cancelled' : requestedStatus;
    if (Object.hasOwn(STATUS_META, normalizedStatus)) setStatusFilter(normalizedStatus);
  }, []);

  useEffect(() => {
    if (!check()) return;
    load();
    fetch(`${API}/admin/plans`, { headers: headers(), cache: 'no-store' }).then(r => r.json()).then(d => setPlans(Array.isArray(d) ? d : (d.plans || []))).catch(() => {});
  }, [check()]);

  useEffect(() => { if (check()) load(); }, [statusFilter]);

  const doAction = async (path: string, body: any, msg: string) => {
    setSaving(true);
    try {
      const res = await fetch(`${API}${path}`, { method: 'POST', headers: headers(), body: JSON.stringify(body) });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || t('تعذّر إتمام العملية، حاول مرة أخرى', 'Could not complete the action, please try again')); return; }
      toast.success(msg);
      setTarget(null); setDialog(null);
      load();
    } catch { toast.error(t('تعذر الاتصال', 'Cannot connect')); }
    setSaving(false);
  };

  // Extension is its own call so the success message can state the HONEST
  // new access date returned by the server (and detect an idempotent retry).
  const extend = async () => {
    setSaving(true);
    try {
      const res = await fetch(`${API}/admin/subscriptions/${target.id}/extend`, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ days: Number(days), request_id: requestId || undefined }),
      });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || t('تعذر تمديد الاشتراك', 'Could not extend the subscription')); return; }
      const end = d.access_ends_at ? new Date(d.access_ends_at).toLocaleDateString() : '';
      toast.success(d.already_applied
        ? t('تم تطبيق هذا التمديد مسبقًا', 'This extension was already applied')
        : t(`تم تمديد وصول المعلم وتفعيل حسابه حتى ${end}`, `Teacher access extended and account activated until ${end}`));
      setTarget(null); setDialog(null);
      load();
    } catch { toast.error(t('تعذر الاتصال', 'Cannot connect')); }
    setSaving(false);
  };

  const isAdminExtended = (s: any) => !!s?.admin_ends_at && new Date(s.admin_ends_at).getTime() > Date.now();

  const fmtDate = (v?: string | null) => (v ? new Date(v).toLocaleDateString() : '—');

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

      <AdminSubscriptionMetrics metrics={subscriptionMetrics} />

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
              const normalizedStatus = s.status === 'canceled' ? 'cancelled' : s.status;
              const meta = STATUS_META[normalizedStatus] || ['—', 'bg-gray-100 text-gray-500'];
              const trialEnd = s.trial_ends_at || (normalizedStatus === 'trialing' ? s.ends_at : null);
              const adminEnd = s.admin_ends_at;
              return (
                <div key={s.id} data-testid={`subscription-row-${s.id}`} className="p-4 flex flex-wrap items-center gap-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-50 shrink-0"><Building2 className="h-5 w-5 text-teal-600" /></div>
                  <div className="flex-1 min-w-[180px]">
                  <p className="font-medium">{s.tenant?.name || '—'}</p>
                  <p className="text-xs text-gray-500">{s.plan?.name || '—'} · {t(s.billing_cycle === 'monthly' ? 'شهري' : 'سنوي', s.billing_cycle === 'monthly' ? 'Monthly' : 'Yearly')}</p>
                    <p className="text-[11px] text-gray-400 mt-0.5">
                      {t('يبدأ', 'Starts')}: {s.starts_at?.split('T')[0]} · {t('ينتهي', 'Ends')}: {s.ends_at?.split('T')[0] || '—'}
                      {normalizedStatus === 'trialing' && (
                        <span> · {t('نهاية التجربة المجانية', 'Free trial ends')}: {trialEnd ? trialEnd.split('T')[0] : t('تاريخ غير مضبوط', 'Date not set')}</span>
                      )}
                      {adminEnd && new Date(adminEnd).getTime() > Date.now() && (
                        <span> · {t('وصول إداري حتى', 'Admin access until')}: {adminEnd.split('T')[0]}</span>
                      )}
                    </p>
                  </div>
                  <div className="flex flex-col items-end gap-1">
                    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${meta[1]}`}>{meta[0]}</span>
                    {isAdminExtended(s) && (
                      <span className="inline-flex items-center gap-1 rounded-full bg-teal-50 px-2 py-0.5 text-[11px] font-medium text-teal-700"
                        title={t('وصول ممنوح إداريًا بغض النظر عن حالة الدفع', 'Access granted administratively, independent of payment status')}>
                        <CalendarPlus className="h-3 w-3" />
                        {t('وصول إداري حتى', 'Admin access until')} {fmtDate(s.admin_ends_at)}
                      </span>
                    )}
                  </div>
                  <div className="flex gap-1.5">
                    <Button variant="outline" size="sm" className="gap-1 text-xs" onClick={() => { setTarget(s); setDays('30'); setRequestId(newRequestId()); setDialog('extend'); }}>
                      <CalendarPlus className="h-3.5 w-3.5" /> {t('تمديد', 'Extend')}
                    </Button>
                    <Button variant="outline" size="sm" className="gap-1 text-xs" onClick={() => { setTarget(s); setPlanId(s.plan_id || ''); setDialog('plan'); }}>
                      <RefreshCcw className="h-3.5 w-3.5" /> {t('تغيير الخطة', 'Change plan')}
                    </Button>
                    <Button variant="outline" size="sm" className="gap-1 text-xs" onClick={() => { setTarget(s); setStatusVal(normalizedStatus); setDialog('status'); }}>
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
                <div className="space-y-3">
                  <div className="space-y-2"><Label>{t('عدد الأيام', 'Days')}</Label><Input type="number" min={1} value={days} onChange={(e) => setDays(e.target.value)} className="h-11" /></div>
                  <p className="text-xs text-gray-500">{t('يمنح هذا التمديد وصولًا فوريًا لاستخدام المنصة حتى التاريخ الجديد، دون أي عملية دفع لدى Freemius ودون تغيير حالة الفوترة.', 'This extension grants immediate platform access until the new date — with no Freemius charge and no change to the billing state.')}</p>
                </div>
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
                    {['active', 'trialing', 'pending', 'cancelled', 'expired', 'past_due'].map(s => <option key={s} value={s}>{statusLabel(s, locale)}</option>)}
                  </Select>
                </div>
              )}
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => { setTarget(null); setDialog(null); }}>{t('إلغاء', 'Cancel')}</Button>
            <Button disabled={saving} className="gap-2" onClick={() => {
              if (dialog === 'extend') extend();
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
