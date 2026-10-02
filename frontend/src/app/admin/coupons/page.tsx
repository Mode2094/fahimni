'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Select } from '@/components/ui/select';
import { CardSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Alert } from '@/components/ui/alert';
import { Ticket, Plus, Trash2, Loader2, Search, Copy, Check, ToggleLeft, ToggleRight, Percent, CircleDollarSign, AlertCircle } from 'lucide-react';
import { toast } from 'sonner';
import SalesTabs from '@/components/admin/sales-tabs';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminCouponsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [coupons, setCoupons] = useState<any[]>([]);
  const [stats, setStats] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [editing, setEditing] = useState<any>(null);
  const [showDialog, setShowDialog] = useState(false);
  const [saving, setSaving] = useState(false);
  const [copied, setCopied] = useState('');
  const [deleting, setDeleting] = useState('');
  const [error, setError] = useState('');
  const [form, setForm] = useState<any>({ code: '', type: 'percent', value: '', max_uses: '1', expires_at: '', starts_at: '', description: '', is_active: true, tenant_id: '' });
  const [tenants, setTenants] = useState<any[]>([]);

  const headers = () => ({ 'Content-Type': 'application/json', Accept: 'application/json' });

  const load = async () => {
    try {
      const q = search ? `?search=${encodeURIComponent(search)}` : '';
      const d = await fetch(`${API}/admin/coupons${q}`, { headers: headers() }).then(r => r.json());
      setCoupons(d.coupons || []);
      setStats(d.stats || null);
      setError('');
    } catch { setError(t('تعذر تحميل الكوبونات', 'Failed to load coupons')); }
    setLoading(false);
  };

  useEffect(() => { if (check()) load(); }, [check()]);
  useEffect(() => { if (check()) load(); }, [search]);
  useEffect(() => {
    fetch(`${API}/admin/tenants/options`, { headers: headers() }).then(r => r.json()).then(d => setTenants(d.tenants || [])).catch(() => {});
  }, []);

  const openAdd = () => { setEditing(null); setForm({ code: '', type: 'percent', value: '', max_uses: '1', expires_at: '', starts_at: '', description: '', is_active: true, tenant_id: '' }); setShowDialog(true); };
  const openEdit = (c: any) => {
    setEditing(c);
    setForm({
      code: c.code, type: c.type, value: String(c.value), max_uses: String(c.max_uses ?? ''),
      expires_at: c.expires_at ? c.expires_at.split('T')[0] : '', starts_at: c.starts_at ? c.starts_at.split('T')[0] : '',
      description: c.description || '', is_active: c.is_active,
      tenant_id: c.tenant_id || '',
    });
    setShowDialog(true);
  };

  const save = async () => {
    if (!form.code || !form.value) { toast.error(t('أدخل الكود والقيمة', 'Enter code and value')); return; }
    setSaving(true);
    const body = {
      code: form.code, type: form.type, value: Number(form.value),
      tenant_id: form.tenant_id || null,
      max_uses: form.max_uses ? Number(form.max_uses) : null,
      expires_at: form.expires_at || null, starts_at: form.starts_at || null,
      description: form.description || null, is_active: form.is_active,
    };
    try {
      const res = editing
        ? await fetch(`${API}/admin/coupons/${editing.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify(body) })
        : await fetch(`${API}/admin/coupons`, { method: 'POST', headers: headers(), body: JSON.stringify(body) });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      toast.success(editing ? t('تم تحديث الكوبون', 'Coupon updated') : t('تم إنشاء الكوبون', 'Coupon created'));
      setShowDialog(false); load();
    } catch { toast.error(t('تعذر الاتصال', 'Cannot connect')); }
    setSaving(false);
  };

  const toggle = async (id: string) => {
    try {
      await fetch(`${API}/admin/coupons/${id}/toggle`, { method: 'POST', headers: headers() });
      load();
    } catch {}
  };

  const remove = async (id: string) => {
    if (!window.confirm(t('حذف هذا الكوبون؟', 'Delete this coupon?'))) return;
    setDeleting(id);
    try {
      await fetch(`${API}/admin/coupons/${id}`, { method: 'DELETE', headers: headers() });
      toast.success(t('تم الحذف', 'Deleted')); load();
    } catch {}
    setDeleting('');
  };

  const copyCode = (code: string) => { navigator.clipboard.writeText(code); setCopied(code); setTimeout(() => setCopied(''), 2000); };

  const discountLabel = (c: any) => c.type === 'percent' ? `${c.value}%` : `${c.value} ${c.currency || ''}`;

  const statCards = [
    { label: t('إجمالي الكوبونات', 'Total coupons'), value: stats?.total ?? 0 },
    { label: t('نشطة', 'Active'), value: stats?.active ?? 0, color: 'text-green-600' },
    { label: t('منتهية', 'Expired'), value: stats?.expired ?? 0, color: 'text-red-500' },
    { label: t('مرات الاستخدام', 'Total uses'), value: stats?.total_uses ?? 0, color: 'text-teal-700' },
  ];

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#d9770612' }}>
            <Percent className="h-6 w-6" style={{ color: '#d97706' }} />
          </span>
          <div>
            <SalesTabs />
            <h1 className="text-2xl sm:text-3xl font-bold">{t('إدارة الكوبونات', 'Coupons')}</h1>
            <p className="text-muted-foreground mt-1">{t('كوبونات الخصم للمعلمين والطلاب', 'Discount coupons for teachers and students')}</p>
          </div></div>
        <Button onClick={openAdd} className="gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('إضافة كوبون', 'Add Coupon')}</Button>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 max-w-2xl">
        {statCards.map((s, i) => (
          <Card key={i}><CardContent className="p-4">
            <p className="text-sm text-gray-500">{s.label}</p>
            <p className={`text-xl font-bold mt-1 ${s.color || ''}`}>{s.value}</p>
          </CardContent></Card>
        ))}
      </div>

      <div className="relative max-w-sm">
        <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
        <Input placeholder={t('بحث بالكود...', 'Search code...')} value={search} onChange={(e) => setSearch(e.target.value)} className="ps-9 h-10" />
      </div>

      {error && <Alert variant="error" icon={AlertCircle}>{error}</Alert>}

      {loading ? <CardSkeleton count={6} /> :
        coupons.length === 0 ? (
          <EmptyState icon={Ticket} title={t('لا توجد كوبونات', 'No coupons')} description={t('أنشئ أول كوبون خصم', 'Create your first discount coupon')} action={<Button onClick={openAdd} className="gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('إضافة كوبون', 'Add Coupon')}</Button>} />
        ) : (
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {coupons.map((c) => (
              <Card key={c.id} className="border-0 shadow-sm card-hover"><CardContent className="p-5">
                <div className="flex items-start justify-between mb-3">
                  <button onClick={() => copyCode(c.code)} className="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-gray-100 font-mono font-bold text-sm hover:bg-teal-50">
                    {c.code}
                    {copied === c.code ? <Check className="h-3.5 w-3.5 text-green-500" /> : <Copy className="h-3.5 w-3.5 text-gray-400" />}
                  </button>
                  <button onClick={() => toggle(c.id)} aria-label={c.is_active ? t('تعطيل الكوبون', 'Disable coupon') : t('تفعيل الكوبون', 'Enable coupon')}>
                    {c.is_active ? <ToggleRight className="h-7 w-7 text-green-500" /> : <ToggleLeft className="h-7 w-7 text-gray-300" />}
                  </button>
                </div>
                <div className="flex items-center gap-3 mb-3">
                  <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-50">
                    {c.type === 'percent' ? <Percent className="h-5 w-5 text-teal-600" /> : <CircleDollarSign className="h-5 w-5 text-emerald-500" />}
                  </div>
                  <div>
                    <p className="text-xl font-bold">{discountLabel(c)}</p>
                    <p className="text-xs text-gray-400">{c.type === 'percent' ? t('نسبة خصم', 'Percentage off') : t('خصم ثابت', 'Fixed amount off')}</p>
                  </div>
                </div>
                <div className="flex items-center justify-between text-xs text-gray-500">
                  <span>{t('الاستخدام', 'Usage')}: <b>{c.used_count}</b> / {c.max_uses ?? '∞'}</span>
                  {c.expires_at && <span className={new Date(c.expires_at) < new Date() ? 'text-red-500' : ''}>{t('تنتهي', 'Expires')}: {c.expires_at.split('T')[0]}</span>}
                </div>
                <div className="flex items-center gap-1.5 mt-2 flex-wrap">
                  {c.tenant && <Badge className="bg-violet-100 text-violet-700 border-0 text-[10px]">{c.tenant.name}</Badge>}
                  {!c.tenant && <Badge className="bg-teal-100 text-teal-700 border-0 text-[10px]">{t('كل المنصة', 'Platform-wide')}</Badge>}
                  {c.expires_at && new Date(c.expires_at) < new Date() && <Badge className="bg-red-100 text-red-600 border-0 text-[10px]">{t('منتهي', 'Expired')}</Badge>}
                  {c.starts_at && new Date(c.starts_at) > new Date() && <Badge className="bg-amber-100 text-amber-700 border-0 text-[10px]">{t('لم يبدأ', 'Not started')}</Badge>}
                </div>
                {c.description && <p className="text-xs text-gray-400 mt-2">{c.description}</p>}
                <div className="flex gap-2 mt-4">
                  <Button variant="outline" size="sm" className="flex-1" onClick={() => openEdit(c)}>{t('تعديل', 'Edit')}</Button>
                  <Button variant="ghost" size="icon" className="text-red-500" onClick={() => remove(c.id)} disabled={deleting === c.id} aria-label={t('حذف الكوبون', 'Delete coupon')}><Trash2 className="h-4 w-4" /></Button>
                </div>
              </CardContent></Card>
            ))}
          </div>
        )}

      <Dialog open={showDialog} onOpenChange={setShowDialog}>
        <DialogContent>
          <DialogHeader><DialogTitle>{editing ? t('تعديل الكوبون', 'Edit coupon') : t('إضافة كوبون', 'Add coupon')}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-2"><Label>{t('الكود', 'Code')} *</Label><Input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} className="h-11 font-mono uppercase" placeholder="SAVE10" /></div>
              <div className="space-y-2"><Label>{t('النوع', 'Type')}</Label>
                <Select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} className="h-11">
                  <option value="percent">{t('نسبة %', 'Percentage')}</option>
                  <option value="fixed">{t('مبلغ ثابت', 'Fixed amount')}</option>
                </Select>
              </div>
              <div className="space-y-2"><Label>{form.type === 'percent' ? t('النسبة %', 'Percent %') : t('المبلغ', 'Amount')} *</Label><Input type="number" min={0.01} step="0.01" value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} className="h-11" /></div>
              <div className="space-y-2"><Label>{t('حد الاستخدام', 'Max uses')}</Label><Input type="number" min={1} value={form.max_uses} onChange={(e) => setForm({ ...form, max_uses: e.target.value })} className="h-11" placeholder={t('∞ غير محدود', '∞ unlimited')} /></div>
              <div className="space-y-2"><Label>{t('يبدأ من', 'Valid from')}</Label><Input type="date" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} className="h-11" /></div>
              <div className="space-y-2"><Label>{t('تنتهي في', 'Expires')}</Label><Input type="date" value={form.expires_at} onChange={(e) => setForm({ ...form, expires_at: e.target.value })} className="h-11" /></div>
            </div>
            <div className="space-y-2">
              <Label>{t('المنصة (اختياري)', 'Platform (optional)')}</Label>
              <Select value={form.tenant_id} onChange={(e) => setForm({ ...form, tenant_id: e.target.value })} className="h-11">
                <option value="">{t('كل المنصة — يطبّق على الجميع', 'Platform-wide — applies to everyone')}</option>
                {tenants.map((tn) => <option key={tn.id} value={tn.id}>{tn.name}</option>)}
              </Select>
            </div>
            <div className="space-y-2"><Label>{t('الوصف', 'Description')}</Label><Input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="h-11" /></div>
            <button onClick={() => setForm({ ...form, is_active: !form.is_active })} className="flex items-center gap-2">
              {form.is_active ? <ToggleRight className="h-7 w-7 text-green-500" /> : <ToggleLeft className="h-7 w-7 text-gray-300" />}
              <Badge variant={form.is_active ? 'default' : 'secondary'}>{form.is_active ? t('نشط', 'Active') : t('موقوف', 'Inactive')}</Badge>
            </button>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowDialog(false)}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={save} disabled={saving} className="gap-2">{saving && <Loader2 className="h-4 w-4 animate-spin" />}{t('حفظ', 'Save')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
