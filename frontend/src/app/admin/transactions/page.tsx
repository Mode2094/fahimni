'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/table';
import { TableSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Alert } from '@/components/ui/alert';
import { Select } from '@/components/ui/select';
import { ArrowDownLeft, ArrowUpRight, Search, RefreshCcw, Wallet, Building2, AlertCircle, ArrowLeftRight } from 'lucide-react';
import { curr } from '@/lib/utils';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminTransactionsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [transactions, setTransactions] = useState<any[]>([]);
  const [stats, setStats] = useState<any>(null);
  const [tenants, setTenants] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [directionFilter, setDirectionFilter] = useState('');
  const [tenantFilter, setTenantFilter] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [detail, setDetail] = useState<any>(null);
  const [error, setError] = useState('');

  const headers = () => ({ 'X-Tenant-Id': localStorage.getItem('tenant_id') || '', Accept: 'application/json' });

  const load = () => {
    setLoading(true);
    const q = new URLSearchParams();
    if (search) q.set('search', search);
    if (typeFilter) q.set('type', typeFilter);
    if (directionFilter) q.set('direction', directionFilter);
    if (tenantFilter) q.set('tenant_id', tenantFilter);
    if (from) q.set('from', from);
    if (to) q.set('to', to);
    fetch(`${API}/transactions?${q}`, { headers: headers() })
      .then(r => r.json()).then(d => { setTransactions(d.transactions || []); setStats(d.stats || null); setError(''); })
      .catch(() => setError(t('تعذر تحميل المعاملات', 'Failed to load transactions')))
      .finally(() => setLoading(false));
  };

  useEffect(() => { if (check()) load(); }, [check()]);
  useEffect(() => { if (check()) load(); }, [typeFilter, directionFilter, tenantFilter, from, to]);
  useEffect(() => {
    fetch(`${API}/admin/tenants/options`, { headers: { Accept: 'application/json' } })
      .then(r => r.json()).then(d => setTenants(d.tenants || [])).catch(() => {});
  }, []);

  const totals = {
    in: stats?.in_total ?? transactions.filter(t => t.direction === 'in').reduce((s, t) => s + Number(t.amount), 0),
    out: stats?.out_total ?? transactions.filter(t => t.direction === 'out').reduce((s, t) => s + Number(t.amount), 0),
  };

  const typeLabel = (tp: string) => {
    const map: Record<string, [string, string]> = {
      payment: ['دفع', 'Payment'],
      subscription: ['اشتراك', 'Subscription'],
      refund: ['استرداد', 'Refund'],
      withdrawal: ['سحب', 'Withdrawal'],
      bonus: ['مكافأة', 'Bonus'],
    };
    const l = map[tp] || [tp, tp];
    return locale === 'ar' ? l[0] : l[1];
  };

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#0891b212' }}>
            <ArrowLeftRight className="h-6 w-6" style={{ color: '#0891b2' }} />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('المعاملات المالية', 'Financial Transactions')}</h1>
            <p className="text-muted-foreground mt-1">{t('كل الحركات المالية في المنصة (داخل/خارج)', 'All financial movements (in/out)')}</p>
          </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <Card className="border-0 shadow-sm"><CardContent className="p-5 flex items-center gap-4">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-500/10"><ArrowDownLeft className="h-5 w-5 text-emerald-600" /></div>
          <div>
            <p className="text-2xl font-bold text-emerald-600">{curr(totals.in)}</p>
            <p className="text-sm text-gray-500">{t('إجمالي الوارد', 'Total inflows')} · {stats?.in_count ?? 0}</p>
          </div>
        </CardContent></Card>
        <Card className="border-0 shadow-sm"><CardContent className="p-5 flex items-center gap-4">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-red-500/10"><ArrowUpRight className="h-5 w-5 text-red-600" /></div>
          <div>
            <p className="text-2xl font-bold text-red-600">{curr(totals.out)}</p>
            <p className="text-sm text-gray-500">{t('إجمالي الصادر', 'Total outflows')} · {stats?.out_count ?? 0}</p>
          </div>
        </CardContent></Card>
        <Card className="border-0 shadow-sm"><CardContent className="p-5 flex items-center gap-4">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-500/10"><Wallet className="h-5 w-5 text-teal-700" /></div>
          <div>
            <p className="text-2xl font-bold text-teal-700">{curr(stats?.net ?? 0)}</p>
            <p className="text-sm text-gray-500">{t('صافي الحركة', 'Net')}</p>
          </div>
        </CardContent></Card>
        <Card className="border-0 shadow-sm"><CardContent className="p-5 flex items-center gap-4">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gray-500/10"><ArrowDownLeft className="h-5 w-5 text-gray-500" /></div>
          <div>
            <p className="text-2xl font-bold">{stats?.total ?? 0}</p>
            <p className="text-sm text-gray-500">{t('إجمالي المعاملات', 'Total transactions')}</p>
          </div>
        </CardContent></Card>
      </div>

      <div className="flex flex-wrap gap-3 items-center">
        <div className="relative max-w-md flex-1 min-w-[220px]">
          <Search className="h-4 w-4 absolute start-3.5 top-1/2 -translate-y-1/2 text-gray-400" />
          <Input value={search} onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && load()} placeholder={t('ابحث بالوصف أو رقم المرجع...', 'Search description or reference...')} className="h-10 ps-10 rounded-full" />
        </div>
        <Select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} className="w-auto" aria-label={t('نوع المعاملة', 'Transaction type')}>
          <option value="">{t('كل الأنواع', 'All types')}</option>
          {['payment', 'subscription', 'refund', 'withdrawal', 'bonus'].map(tp => <option key={tp} value={tp}>{typeLabel(tp)}</option>)}
        </Select>
        <Select value={directionFilter} onChange={(e) => setDirectionFilter(e.target.value)} className="w-auto" aria-label={t('الاتجاه', 'Direction')}>
          <option value="">{t('كل الاتجاهات', 'All directions')}</option>
          <option value="in">{t('داخل', 'In')}</option>
          <option value="out">{t('خارج', 'Out')}</option>
        </Select>
        <Select value={tenantFilter} onChange={(e) => setTenantFilter(e.target.value)} className="w-auto" aria-label={t('المعلم', 'Teacher')}>
          <option value="">{t('كل المعلمين', 'All teachers')}</option>
          {tenants.map(tn => <option key={tn.id} value={tn.id}>{tn.name}</option>)}
        </Select>
        <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-10 border border-gray-200 rounded-lg px-3 text-sm bg-white" title={t('من', 'From')} />
        <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-10 border border-gray-200 rounded-lg px-3 text-sm bg-white" title={t('إلى', 'To')} />
        <Button variant="outline" size="sm" className="gap-2" onClick={load}><RefreshCcw className="h-4 w-4" /> {t('تحديث', 'Refresh')}</Button>
      </div>

      {error && <Alert variant="error" icon={AlertCircle}>{error}</Alert>}

      {loading ? (
        <Card className="border-0 shadow-sm"><CardContent className="p-6"><TableSkeleton cols={7} rows={6} /></CardContent></Card>
      ) : transactions.length === 0 ? (
        <EmptyState icon={Wallet} title={t('لا توجد معاملات', 'No transactions')} description={t('لم تُسجل أي حركات مالية بعد', 'No financial movements recorded yet')} />
      ) : (
        <Table className="border-0 shadow-sm">
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <TableHead>{t('الوصف', 'Description')}</TableHead>
              <TableHead>{t('النوع', 'Type')}</TableHead>
              <TableHead>{t('المعلم', 'Teacher')}</TableHead>
              <TableHead>{t('الاتجاه', 'Direction')}</TableHead>
              <TableHead>{t('المبلغ', 'Amount')}</TableHead>
              <TableHead>{t('الرصيد بعد', 'Balance after')}</TableHead>
              <TableHead>{t('التاريخ', 'Date')}</TableHead>
              <TableHead className="text-end">{t('إجراءات', 'Actions')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {transactions.map(tx => (
              <TableRow key={tx.id}>
                <TableCell className="font-semibold max-w-xs truncate">{tx.description || '—'}</TableCell>
                <TableCell><Badge className="bg-gray-50 text-gray-600 border-0">{typeLabel(tx.type)}</Badge></TableCell>
                <TableCell className="text-gray-600">
                  <span className="flex items-center gap-1"><Building2 className="h-3 w-3 text-gray-300 shrink-0" />{tx.tenant?.name || tx.tenant_id?.slice(0, 8) || '—'}</span>
                </TableCell>
                <TableCell>
                  {tx.direction === 'in' ? (
                    <Badge className="bg-emerald-50 text-emerald-700 border-0 flex items-center gap-1 w-fit"><ArrowDownLeft className="h-3 w-3" />{t('داخل', 'In')}</Badge>
                  ) : (
                    <Badge className="bg-red-50 text-red-600 border-0 flex items-center gap-1 w-fit"><ArrowUpRight className="h-3 w-3" />{t('خارج', 'Out')}</Badge>
                  )}
                </TableCell>
                <TableCell className="font-bold">{curr(Number(tx.amount))}</TableCell>
                <TableCell className="text-gray-600">{curr(Number(tx.balance_after ?? 0))}</TableCell>
                <TableCell className="text-gray-500">{tx.created_at ? new Date(tx.created_at).toLocaleString() : ''}</TableCell>
                <TableCell className="text-end">
                  <Button size="sm" variant="outline" onClick={() => setDetail(tx)}>{t('تفاصيل', 'Details')}</Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}

      <Dialog open={!!detail} onOpenChange={(v) => { if (!v) setDetail(null); }}>
        <DialogContent>
          <DialogHeader><DialogTitle>{t('تفاصيل المعاملة', 'Transaction details')}</DialogTitle></DialogHeader>
          {detail && (
            <div className="space-y-2 text-sm">
              <p><b>{t('الوصف:', 'Description:')}</b> {detail.description || '—'}</p>
              <p><b>{t('النوع:', 'Type:')}</b> {typeLabel(detail.type)} ({detail.type})</p>
              <p><b>{t('الاتجاه:', 'Direction:')}</b> {detail.direction}</p>
              <p><b>{t('المبلغ:', 'Amount:')}</b> {detail.amount}</p>
              <p><b>{t('الرصيد قبل/بعد:', 'Balance before/after:')}</b> {detail.balance_before} / {detail.balance_after}</p>
              <p><b>{t('المرجع:', 'Reference:')}</b> {detail.reference_type} · {detail.reference_id}</p>
              <p><b>{t('المعلم:', 'Teacher:')}</b> {detail.tenant?.name || detail.tenant_id}</p>
              <p><b>{t('التاريخ:', 'Date:')}</b> {detail.created_at ? new Date(detail.created_at).toLocaleString() : '—'}</p>
              <DialogFooter>
                <Button variant="outline" onClick={() => setDetail(null)}>{t('إغلاق', 'Close')}</Button>
              </DialogFooter>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
