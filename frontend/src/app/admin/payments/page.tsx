'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select } from '@/components/ui/select';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Alert } from '@/components/ui/alert';
import { CreditCard, Search, RefreshCcw, Eye, AlertCircle, Wallet, User, ReceiptText, Hash, Building2, Landmark, ImageIcon, MessageSquareText } from 'lucide-react';
import { toast } from 'sonner';
import SalesTabs from '@/components/admin/sales-tabs';
import { curr, date, currencyLabel } from '@/lib/utils';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const STATUS_META: Record<string, [string, string]> = {
  completed: ['مكتملة', 'bg-green-100 text-green-700'],
  pending: ['معلقة', 'bg-yellow-100 text-yellow-700'],
  failed: ['فاشلة', 'bg-red-100 text-red-700'],
  refunded: ['مستردة', 'bg-gray-100 text-gray-500'],
};

const stLabel = (s: string, locale: string) => {
  if (locale === 'ar') return STATUS_META[s]?.[0] || s;
  return { completed: 'Completed', pending: 'Pending', failed: 'Failed', refunded: 'Refunded' }[s] || s;
};

export default function AdminPaymentsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [payments, setPayments] = useState<any[]>([]);
  const [stats, setStats] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState('');
  const [gatewayFilter, setGatewayFilter] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [search, setSearch] = useState('');
  const [detail, setDetail] = useState<any>(null);
  const [error, setError] = useState('');

  const headers = () => ({ 'Content-Type': 'application/json', Accept: 'application/json' });

  const load = async () => {
    const q = new URLSearchParams();
    if (statusFilter) q.set('status', statusFilter);
    if (gatewayFilter) q.set('gateway', gatewayFilter);
    if (from) q.set('from', from);
    if (to) q.set('to', to);
    if (search) q.set('search', search);
    try {
      const d = await fetch(`${API}/admin/payments?${q}`, { headers: headers() }).then(r => r.json());
      setPayments(d.payments || []);
      setStats(d.stats || null);
      setError('');
    } catch { setError(t('تعذر تحميل المدفوعات', 'Failed to load payments')); }
    setLoading(false);
  };

  useEffect(() => { if (check()) load(); }, [check()]);
  useEffect(() => { if (check()) load(); }, [statusFilter, gatewayFilter, from, to]);

  const openDetail = async (id: string) => {
    try {
      const d = await fetch(`${API}/admin/payments/${id}`, { headers: headers() }).then(r => r.json());
      setDetail(d);
    } catch {}
  };

  const setStatus = async (id: string, status: string) => {
    try {
      const res = await fetch(`${API}/admin/payments/${id}/status`, { method: 'PUT', headers: headers(), body: JSON.stringify({ status }) });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      toast.success(t('تم تحديث الحالة', 'Status updated'));
      load();
    } catch { toast.error(t('تعذر الاتصال', 'Cannot connect')); }
  };

  const statCards = [
    { label: t('إجمالي المعاملات', 'Total transactions'), value: stats?.total ?? 0 },
    { label: t('مكتملة', 'Completed'), value: stats?.completed ?? 0, color: 'text-green-600' },
    { label: t('معلقة', 'Pending'), value: stats?.pending ?? 0, color: 'text-yellow-600' },
    { label: t('فاشلة', 'Failed'), value: stats?.failed ?? 0, color: 'text-red-500' },
    { label: t('مستردة', 'Refunded'), value: stats?.refunded ?? 0, color: 'text-gray-500' },
    { label: t('الإيرادات', 'Revenue'), value: curr(stats?.revenue ?? 0), color: 'text-emerald-600' },
  ];

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#05966912' }}>
            <Wallet className="h-6 w-6" style={{ color: '#059669' }} />
          </span>
          <div>
            <SalesTabs />
            <h1 className="text-2xl sm:text-3xl font-bold">{t('إدارة المدفوعات', 'Payments')}</h1>
            <p className="text-muted-foreground mt-1">{t('جميع معاملات الدفع على المنصة', 'All payment transactions on the platform')}</p>
          </div></div>

      <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        {statCards.map((s, i) => (
          <Card key={i}><CardContent className="p-4">
            <p className="text-sm text-gray-500">{s.label}</p>
            <p className={`text-xl font-bold mt-1 ${s.color || ''}`}>{s.value}</p>
          </CardContent></Card>
        ))}
      </div>

      <div className="flex flex-wrap gap-3 items-center">
        <div className="relative flex-1 min-w-[220px] max-w-sm">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
          <Input placeholder={t('بحث بالمعلم أو رقم العملية...', 'Search teacher or transaction...')} value={search} onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && load()} className="ps-9 h-10" />
        </div>
        <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="w-auto" aria-label={t('حالة الدفع', 'Payment status')}>
          <option value="">{t('كل الحالات', 'All statuses')}</option>
          {Object.keys(STATUS_META).map(s => <option key={s} value={s}>{stLabel(s, locale)}</option>)}
        </Select>
        <Select value={gatewayFilter} onChange={(e) => setGatewayFilter(e.target.value)} className="w-auto" aria-label={t('البوابة', 'Gateway')}>
          <option value="">{t('كل البوابات', 'All gateways')}</option>
          <option value="paytabs">PayTabs</option>
          <option value="paypal">PayPal</option>
          <option value="fake">Fake</option>
        </Select>
        <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-10 border border-gray-200 rounded-lg px-3 text-sm bg-white" title={t('من', 'From')} />
        <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-10 border border-gray-200 rounded-lg px-3 text-sm bg-white" title={t('إلى', 'To')} />
        <Button variant="outline" size="sm" className="gap-2" onClick={load}><RefreshCcw className="h-4 w-4" /> {t('تحديث', 'Refresh')}</Button>
      </div>

      {error && <Alert variant="error" icon={AlertCircle}>{error}</Alert>}

      {loading ? <Card><CardContent className="p-6"><TableSkeleton cols={5} rows={6} /></CardContent></Card> :
        payments.length === 0 ? (
          <EmptyState icon={CreditCard} title={t('لا توجد مدفوعات', 'No payments')} description={t('جرّب تعديل الفلاتر أو انتظر عمليات دفع جديدة', 'Try adjusting the filters or wait for new payments')} />
        ) : (
          <Card><div className="divide-y">
            {payments.map((p) => {
              const meta = STATUS_META[p.status] || ['—', 'bg-gray-100'];
              return (
                <div key={p.id} className="flex flex-wrap items-center gap-4 p-4 hover:bg-gray-50">
                  <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 shrink-0"><CreditCard className="h-5 w-5 text-emerald-600" /></div>
                  <div className="flex-1 min-w-[180px]">
                    <p className="font-medium flex items-center gap-1.5"><User className="h-3.5 w-3.5 text-gray-400" /> {p.payer_name || p.tenant?.name || t('غير معروف', 'Unknown')}
                      <Badge className="bg-teal-50 text-teal-700 border-0 text-[10px]">{p.plan?.name ? t('الخطة', 'Plan') + ': ' + p.plan.name : ''}</Badge>
                    </p>
                    <p className="text-xs text-gray-500">
                      {p.gateway === 'paypal' ? 'PayPal' : p.gateway === 'manual' ? t('يدوي (محفظة/كليك)', 'Manual (wallet/Click)') : p.gateway === 'paytabs' ? 'PayTabs' : p.gateway} · {date(p.created_at)} · <span className="font-mono">{p.transaction_id || ''}</span>
                    </p>
                    {p.plan && <p className="text-[11px] text-teal-700 mt-0.5">{t('الخطة', 'Plan')}: {p.plan.name} {p.invoice ? `· ${t('فاتورة', 'Invoice')} ${p.invoice.number}` : ''}</p>}
                  </div>
                  <div className="text-end">
                    <p className="font-semibold">{curr(p.amount)}</p>
                    <Badge className={`mt-1 text-xs ${meta[1]}`}>{meta[0]}</Badge>
                  </div>
                  {p.status === 'pending' && (
                    <div className="flex flex-col gap-1">
                      <Button size="sm" className="text-xs bg-green-600 hover:bg-green-700" onClick={() => setStatus(p.id, 'completed')}>{t('تأكيد', 'Confirm')}</Button>
                      <Button size="sm" variant="outline" className="text-xs text-red-500" onClick={() => setStatus(p.id, 'failed')}>{t('فشل', 'Fail')}</Button>
                    </div>
                  )}
                  {p.status === 'completed' && (
                    <Button size="sm" variant="outline" className="text-xs text-gray-500" onClick={() => setStatus(p.id, 'refunded')}>{t('استرداد', 'Refund')}</Button>
                  )}
                  <Button size="sm" variant="ghost" className="text-xs gap-1 text-teal-700" onClick={() => openDetail(p.id)}><Eye className="h-3.5 w-3.5" /> {t('تفاصيل', 'Details')}</Button>
                </div>
              );
            })}
          </div></Card>
        )}

      <Dialog open={!!detail} onOpenChange={(v) => { if (!v) setDetail(null); }}>
        <DialogContent className="max-w-2xl">
          {detail?.payment && (
            <>
              <DialogHeader><DialogTitle>{t('تفاصيل الدفعة', 'Payment details')} · {detail.payment.transaction_id}</DialogTitle></DialogHeader>
              <div className="space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                  <div className="p-3 rounded-xl bg-gray-50"><p className="text-xs text-gray-400">{t('المبلغ', 'Amount')}</p><p className="mt-0.5 font-bold text-lg">{detail.payment.amount} {currencyLabel(detail.payment.currency, locale === 'ar')}</p></div>
                  <div className="p-3 rounded-xl bg-gray-50"><p className="text-xs text-gray-400">{t('الحالة', 'Status')}</p><p className="mt-0.5">{stLabel(detail.payment.status, locale)}</p></div>
                  <div className="p-3 rounded-xl bg-gray-50"><p className="text-xs text-gray-400">{t('البوابة', 'Gateway')}</p><p className="mt-0.5">{detail.payment.gateway} · {detail.payment.payment_method || '—'}</p></div>
                  <div className="p-3 rounded-xl bg-gray-50"><p className="text-xs text-gray-400">{t('التاريخ', 'Date')}</p><p className="mt-0.5">{date(detail.payment.created_at)}</p></div>
                  {detail.payment.tenant_full && (
                    <div className="p-3 rounded-xl bg-gray-50 sm:col-span-2"><p className="text-xs text-gray-400">{t('المعلم', 'Teacher')}</p><p className="mt-0.5">{detail.payment.tenant_full.name}</p></div>
                  )}
                  {detail.payment.subscription && (
                    <div className="p-3 rounded-xl bg-gray-50 sm:col-span-2">
                      <p className="text-xs text-gray-400">{t('الاشتراك', 'Subscription')}</p>
                      <p className="mt-0.5">{detail.payment.subscription.plan} · {detail.payment.subscription.billing_cycle} · {detail.payment.subscription.status}
                        {detail.payment.subscription.ends_at && <> · {t('ينتهي', 'Ends')}: {detail.payment.subscription.ends_at.split('T')[0]}</>}</p>
                    </div>
                  )}
                  {detail.payment.invoice_full && (
                    <div className="p-3 rounded-xl bg-gray-50 sm:col-span-2 flex items-center gap-2">
                      <ReceiptText className="h-4 w-4 text-teal-600" />
                      <div><p className="text-xs text-gray-400">{t('الفاتورة', 'Invoice')}</p><p className="mt-0.5 font-mono">{detail.payment.invoice_full.invoice_number} · {detail.payment.invoice_full.status}</p></div>
                    </div>
                  )}
                </div>
                {detail.payment.gateway === 'manual' && detail.payment.gateway_data?.manual && (
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div className="p-3 rounded-xl bg-violet-50">
                      <p className="text-xs text-gray-400 flex items-center gap-1"><User className="h-3.5 w-3.5" />{t('اسم المُحوِّل', 'Sender name')}</p>
                      <p className="mt-0.5 font-semibold">{detail.payment.gateway_data.payer_name || '—'}</p>
                    </div>
                    <div className="p-3 rounded-xl bg-violet-50">
                      <p className="text-xs text-gray-400 flex items-center gap-1"><Hash className="h-3.5 w-3.5" />{t('رقم العملية', 'Transaction no.')}</p>
                      <p className="mt-0.5 font-mono font-semibold" dir="ltr">{detail.payment.gateway_data.reference || '—'}</p>
                    </div>
                    {detail.payment.gateway_data.notes && (
                      <div className="p-3 rounded-xl bg-violet-50 sm:col-span-2">
                        <p className="text-xs text-gray-400 flex items-center gap-1"><MessageSquareText className="h-3.5 w-3.5" />{t('ملاحظات', 'Notes')}</p>
                        <p className="mt-0.5">{detail.payment.gateway_data.notes}</p>
                      </div>
                    )}
                    {detail.payment.gateway_data.proof_path && <ProofImage paymentId={detail.payment.id} />}
                  </div>
                )}
                {detail.payment.gateway_data && Object.keys(detail.payment.gateway_data).length > 0 && !detail.payment.gateway_data?.manual && (
                  <div>
                    <h4 className="font-semibold mb-2 flex items-center gap-1.5"><Landmark className="h-4 w-4 text-emerald-500" />{t('بيانات البوابة', 'Gateway data')}</h4>
                    <pre dir="ltr" className="p-3 rounded-xl bg-gray-900 text-gray-100 text-[11px] overflow-x-auto max-h-48">{JSON.stringify(detail.payment.gateway_data, null, 2)}</pre>
                  </div>
                )}
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}


function ProofImage({ paymentId }: { paymentId: string }) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [url, setUrl] = useState<string | null>(null);

  useEffect(() => {
    let revoked: string | null = null;
    let alive = true;
    fetch(`${API}/admin/payments/${paymentId}/proof`, {
      headers: { Accept: 'image/*' },
    })
      .then((r) => (r.ok ? r.blob() : null))
      .then((b) => {
        if (!b || !alive) return;
        revoked = URL.createObjectURL(b);
        setUrl(revoked);
      })
      .catch(() => {});
    return () => {
      alive = false;
      if (revoked) URL.revokeObjectURL(revoked);
    };
  }, [paymentId]);

  if (!url) {
    return (
      <div className="sm:col-span-2">
        <p className="text-xs text-gray-400 mb-1.5 flex items-center gap-1"><ImageIcon className="h-3.5 w-3.5" />{t('إثبات الدفع', 'Payment proof')}</p>
        <div className="flex h-40 items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50 text-xs text-slate-400">
          {t('جاري تحميل الصورة...', 'Loading image...')}
        </div>
      </div>
    );
  }

  return (
    <div className="sm:col-span-2">
      <p className="text-xs text-gray-400 mb-1.5 flex items-center gap-1"><ImageIcon className="h-3.5 w-3.5" />{t('إثبات الدفع', 'Payment proof')}</p>
      <a href={url} target="_blank" rel="noreferrer" className="block max-w-xs rounded-xl overflow-hidden border border-slate-200">
        <img src={url} alt={t('إثبات الدفع', 'Payment proof')} className="w-full h-40 object-cover hover:opacity-90 transition-opacity" />
      </a>
    </div>
  );
}
