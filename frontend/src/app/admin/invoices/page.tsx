'use client';

import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Alert } from '@/components/ui/alert';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { FileText, Receipt, AlertCircle, Eye, Search, Wallet, CheckCircle2, Clock, XCircle, CreditCard, Building2, CalendarDays } from 'lucide-react';
import { curr, date, currencyLabel } from '@/lib/utils';
import SalesTabs from '@/components/admin/sales-tabs';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminInvoicesPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [invoices, setInvoices] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [selected, setSelected] = useState<any>(null);

  useEffect(() => {
    if (!check()) return;
    fetch(`${API}/invoices`, { headers: { Accept: 'application/json' } })
      .then(r => r.json()).then(d => { setInvoices(d.invoices || []); setError(false); })
      .catch(() => setError(true))
      .finally(() => setLoading(false));
  }, [check()]);

  const statusClass = (s: string) => s === 'paid' ? 'bg-green-100 text-green-700' : s === 'pending' ? 'bg-yellow-100 text-yellow-700' : s === 'overdue' ? 'bg-red-100 text-red-700' : s === 'cancelled' ? 'bg-gray-100 text-gray-600' : 'bg-gray-100 text-gray-600';
  const statusLabel = (s: string) => s === 'paid' ? t('مدفوع', 'Paid') : s === 'pending' ? t('معلق', 'Pending') : s === 'overdue' ? t('متأخر', 'Overdue') : s === 'cancelled' ? t('ملغي', 'Cancelled') : s;

  const cycleLabel = (c?: string) => c === 'yearly' ? t('سنوي', 'Yearly') : c === 'monthly' ? t('شهري', 'Monthly') : '—';

  const gatewayLabel = (g?: string) => {
    const map: Record<string, [string, string]> = {
      paytabs: ['بطاقة (PayTabs)', 'Card (PayTabs)'],
      paypal: ['PayPal', 'PayPal'],
      manual: ['تحويل يدوي', 'Manual transfer'],
      fake: ['اختبار (Fake)', 'Test (Fake)'],
      card: ['بطاقة', 'Card'],
      free: ['مدفوع', 'Paid'],
    };
    const l = map[g || ''];
    return l ? (locale === 'ar' ? l[0] : l[1]) : g || '—';
  };

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return invoices.filter(inv => {
      if (statusFilter !== 'all' && inv.status !== statusFilter) return false;
      if (!q) return true;
      return (inv.invoice_number || '').toLowerCase().includes(q)
        || (inv.tenant_name || '').toLowerCase().includes(q)
        || (inv.subscription?.plan_name || '').toLowerCase().includes(q)
        || (inv.payment?.transaction_id || '').toLowerCase().includes(q);
    });
  }, [invoices, query, statusFilter]);

  const stats = useMemo(() => {
    const paid = invoices.filter(i => i.status === 'paid');
    const pending = invoices.filter(i => i.status === 'pending' || i.status === 'overdue');
    const cancelled = invoices.filter(i => i.status === 'cancelled');
    const sum = (list: any[]) => list.reduce((a, i) => a + (Number(i.total_amount) || 0), 0);
    return {
      total: invoices.length,
      totalAmount: sum(invoices),
      paidCount: paid.length,
      paidAmount: sum(paid),
      pendingCount: pending.length,
      pendingAmount: sum(pending),
      cancelledCount: cancelled.length,
    };
  }, [invoices]);

  const currencyOf = (inv: any) => inv.original_currency || 'JOD';

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#7c3aed12' }}>
            <FileText className="h-6 w-6" style={{ color: '#7c3aed' }} />
          </span>
          <div>
            <SalesTabs />
            <h1 className="text-2xl sm:text-3xl font-bold">{t('الفواتير', 'Invoices')}</h1>
            <p className="text-muted-foreground mt-1">{t('فواتير الاشتراكات بتفاصيل كاملة', 'Subscription invoices with full details')}</p>
          </div>
      </div>

      {/* ─── ملخص سريع ─────────────────────────────── */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[
          { label: t('إجمالي الفواتير', 'Total invoices'), value: String(stats.total), sub: curr(stats.totalAmount, 'JOD'), icon: Receipt, color: '#0d9488' },
          { label: t('المدفوعة', 'Paid'), value: String(stats.paidCount), sub: curr(stats.paidAmount, 'JOD'), icon: CheckCircle2, color: '#059669' },
          { label: t('معلقة / متأخرة', 'Pending / Overdue'), value: String(stats.pendingCount), sub: curr(stats.pendingAmount, 'JOD'), icon: Clock, color: '#d97706' },
          { label: t('ملغية', 'Cancelled'), value: String(stats.cancelledCount), sub: '—', icon: XCircle, color: '#64748b' },
        ].map((s, i) => (
          <Card key={i} className="border-0 shadow-sm card-hover"><CardContent className="p-5">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl mb-3" style={{ backgroundColor: `${s.color}15` }}><s.icon className="h-5 w-5" style={{ color: s.color }} /></div>
            <div className="text-2xl font-bold">{s.value}</div>
            <div className="text-sm text-gray-500 mt-0.5">{s.label} · <span className="font-semibold text-gray-700">{s.sub}</span></div>
          </CardContent></Card>
        ))}
      </div>

      {error && <Alert variant="error" icon={AlertCircle}>{t('تعذر تحميل الفواتير', 'Failed to load invoices')}</Alert>}

      {/* ─── بحث وتصفية ───────────────────────────── */}
      {!loading && invoices.length > 0 && (
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative flex-1 min-w-[220px] max-w-sm">
            <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input
              value={query}
              onChange={e => setQuery(e.target.value)}
              placeholder={t('ابحث برقم الفاتورة، المؤسسة، الخطة...', 'Search by invoice #, tenant, plan...')}
              className="w-full rounded-xl border border-input bg-white ps-9 pe-3 py-2 text-sm outline-none transition focus:ring-2 focus:ring-ring"
            />
          </div>
          <div className="flex flex-wrap gap-1.5">
            {[['all', t('الكل', 'All')], ['paid', t('مدفوع', 'Paid')], ['pending', t('معلق', 'Pending')], ['overdue', t('متأخر', 'Overdue')], ['cancelled', t('ملغي', 'Cancelled')]].map(([key, label]) => (
              <button
                key={key}
                onClick={() => setStatusFilter(key)}
                className={`rounded-full px-3.5 py-1.5 text-xs font-semibold transition ${statusFilter === key ? 'bg-teal-600 text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}`}
              >
                {label}
              </button>
            ))}
          </div>
        </div>
      )}

      {loading ? <Card><CardContent className="p-6"><TableSkeleton cols={8} rows={6} /></CardContent></Card> :
        invoices.length === 0 ? (
          <EmptyState icon={Receipt} title={t('لا توجد فواتير', 'No invoices')} description={t('لم تُصدر أي فواتير اشتراك بعد', 'No subscription invoices issued yet')} />
        ) : filtered.length === 0 ? (
          <EmptyState icon={Search} title={t('لا نتائج', 'No results')} description={t('لا توجد فواتير مطابقة لبحثك', 'No invoices match your search')} />
        ) : (
          <Card className="border-0 shadow-sm overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-slate-50 text-start text-xs text-gray-500">
                    {[t('الفاتورة', 'Invoice'), t('المؤسسة', 'Tenant'), t('الخطة', 'Plan'), t('الفوترة', 'Billing'), t('المبلغ', 'Amount'), t('الحالة', 'Status'), t('الدفع', 'Payment'), t('رقم العملية', 'Transaction'), ''].map((h, i) => (
                      <th key={i} className="px-4 py-3 text-start font-semibold">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {filtered.map(inv => (
                    <tr key={inv.id} className="transition hover:bg-gray-50">
                      <td className="px-4 py-3">
                        <p className="font-semibold text-gray-800">{inv.invoice_number}</p>
                        <p className="text-xs text-gray-400">{date(inv.created_at)}</p>
                      </td>
                      <td className="px-4 py-3">
                        <p className="font-medium text-gray-800">{inv.tenant_name || '—'}</p>
                        <p className="text-xs text-gray-400">{inv.subscription?.status === 'cancelled' ? t('اشتراك ملغي', 'cancelled sub') : ''}</p>
                      </td>
                      <td className="px-4 py-3">
                        <p className="font-medium text-gray-800">{inv.subscription?.plan_name || '—'}</p>
                        <p className="text-xs text-gray-400">{cycleLabel(inv.subscription?.billing_cycle)}</p>
                      </td>
                      <td className="px-4 py-3 text-gray-600">
                        <p>{curr(Number(inv.amount) || 0, currencyOf(inv))}</p>
                        {Number(inv.tax_amount) > 0 && <p className="text-xs text-gray-400">{t('ضريبة', 'Tax')}: {curr(Number(inv.tax_amount), currencyOf(inv))}</p>}
                      </td>
                      <td className="px-4 py-3 font-bold text-gray-900">{curr(Number(inv.total_amount) || 0, currencyOf(inv))}</td>
                      <td className="px-4 py-3"><Badge className={`text-xs ${statusClass(inv.status)}`}>{statusLabel(inv.status)}</Badge></td>
                      <td className="px-4 py-3">
                        <span className="inline-flex items-center gap-1 text-gray-600">
                          <CreditCard className="h-3.5 w-3.5 text-gray-400" />
                          {gatewayLabel(inv.payment?.gateway)}
                        </span>
                        {inv.paid_at && <p className="text-xs text-gray-400 mt-0.5">{t('دفع', 'Paid')}: {date(inv.paid_at)}</p>}
                      </td>
                      <td className="px-4 py-3">
                        <p className="font-mono text-xs text-gray-500 max-w-[140px] truncate" title={inv.payment?.transaction_id}>{inv.payment?.transaction_id || '—'}</p>
                      </td>
                      <td className="px-4 py-3">
                        <button
                          onClick={() => setSelected(inv)}
                          className="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-50 text-teal-700 transition hover:bg-teal-100"
                          title={t('التفاصيل', 'Details')}
                        >
                          <Eye className="h-4 w-4" />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        )}

      {/* ─── نافذة التفاصيل ────────────────────────── */}
      <Dialog open={!!selected} onOpenChange={(v) => !v && setSelected(null)} className="max-w-2xl">
        {selected && (
          <DialogContent>
            <DialogHeader>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <DialogTitle>{t('تفاصيل الفاتورة', 'Invoice details')}</DialogTitle>
                  <p className="text-xs text-gray-500 mt-1">{selected.invoice_number} · {date(selected.created_at)}</p>
                </div>
                <Badge className={`text-xs ${statusClass(selected.status)}`}>{statusLabel(selected.status)}</Badge>
              </div>
            </DialogHeader>

            {/* المبالغ */}
            <div className="grid grid-cols-3 gap-3 rounded-2xl bg-slate-50 p-4">
              <div><p className="text-[11px] text-gray-500">{t('المبلغ', 'Amount')}</p><p className="font-bold">{curr(Number(selected.amount) || 0, currencyOf(selected))}</p></div>
              <div><p className="text-[11px] text-gray-500">{t('الضريبة', 'Tax')}</p><p className="font-bold">{curr(Number(selected.tax_amount) || 0, currencyOf(selected))}</p></div>
              <div><p className="text-[11px] text-gray-500">{t('الإجمالي', 'Total')}</p><p className="font-bold text-teal-700">{curr(Number(selected.total_amount) || 0, currencyOf(selected))}</p></div>
            </div>

            {/* المؤسسة والاشتراك */}
            <div className="space-y-3">
              <div className="flex items-center gap-2 border-b pb-2">
                <Building2 className="h-4 w-4 text-teal-600" />
                <p className="text-sm font-semibold">{selected.tenant_name || '—'}</p>
                <span className="text-xs text-gray-400">{t('المؤسسة', 'Tenant')}</span>
              </div>

              {selected.subscription && (
                <div className="grid grid-cols-2 gap-3 text-sm">
                  <div><p className="text-[11px] text-gray-500">{t('الخطة', 'Plan')}</p><p className="font-medium">{selected.subscription.plan_name || '—'}</p></div>
                  <div><p className="text-[11px] text-gray-500">{t('دورة الفوترة', 'Billing cycle')}</p><p className="font-medium">{cycleLabel(selected.subscription.billing_cycle)}</p></div>
                  <div><p className="text-[11px] text-gray-500">{t('تاريخ البداية', 'Starts')}</p><p className="font-medium">{date(selected.subscription.starts_at)}</p></div>
                  <div><p className="text-[11px] text-gray-500">{t('تاريخ الانتهاء', 'Ends')}</p><p className="font-medium">{date(selected.subscription.ends_at)}</p></div>
                  <div><p className="text-[11px] text-gray-500">{t('حالة الاشتراك', 'Subscription')}</p><p className="font-medium capitalize">{selected.subscription.status}</p></div>
                </div>
              )}

              {/* الدفع */}
              <div className="rounded-2xl border border-slate-100 p-4 space-y-2.5">
                <p className="text-[11px] font-bold text-gray-400 uppercase tracking-wide">{t('بيانات الدفع', 'Payment details')}</p>
                {selected.payment ? (
                  <>
                    <div className="grid grid-cols-2 gap-3 text-sm">
                      <div><p className="text-[11px] text-gray-500">{t('البوابة', 'Gateway')}</p><p className="font-medium">{gatewayLabel(selected.payment.gateway)}</p></div>
                      <div><p className="text-[11px] text-gray-500">{t('حالة الدفع', 'Payment status')}</p><p className="font-medium">{statusLabel(selected.payment.status)}</p></div>
                      <div><p className="text-[11px] text-gray-500">{t('رقم العملية', 'Transaction ID')}</p><p className="font-mono text-xs break-all">{selected.payment.transaction_id || '—'}</p></div>
                      <div><p className="text-[11px] text-gray-500">{t('تاريخ الدفع', 'Paid at')}</p><p className="font-medium">{date(selected.payment.paid_at)}</p></div>
                    </div>
                    <div className="flex items-center gap-1.5 pt-1 text-xs text-gray-400">
                      <CalendarDays className="h-3.5 w-3.5" />
                      {t('تاريخ الاستحقاق', 'Due')}: {date(selected.due_date)} · {currencyLabel(selected.original_currency || 'JOD', locale === 'ar')}
                    </div>
                  </>
                ) : (
                  <p className="text-sm text-gray-400">{t('لا توجد بيانات دفع مرتبطة', 'No payment linked')}</p>
                )}
              </div>
            </div>

            <div className="mt-5 flex justify-end gap-2">
              <Button variant="outline" onClick={() => setSelected(null)}>{t('إغلاق', 'Close')}</Button>
            </div>
          </DialogContent>
        )}
      </Dialog>
    </div>
  );
}
