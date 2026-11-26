'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Alert } from '@/components/ui/alert';
import { Loader2, CalendarPlus, AlertCircle, CheckCircle2, Crown, ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const OPERATION_TYPES = [
  { value: 'activate', ar: 'تفعيل اشتراك مدفوع', en: 'Paid activation' },
  { value: 'renew', ar: 'تجديد', en: 'Renewal' },
  { value: 'upgrade', ar: 'ترقية الخطة', en: 'Upgrade' },
  { value: 'downgrade', ar: 'تخفيض الخطة', en: 'Downgrade' },
  { value: 'plan_change', ar: 'تغيير الخطة', en: 'Plan change' },
  { value: 'extend', ar: 'تمديد المدة', en: 'Extension' },
  { value: 'free_grant', ar: 'منح مجاني (بدون تحصيل)', en: 'Free grant (no charge)' },
];

const PAYMENT_METHODS = [
  { value: 'cliq', ar: 'كليك (CliQ)', en: 'CliQ' },
  { value: 'bank_transfer', ar: 'حوالة بنكية', en: 'Bank transfer' },
  { value: 'cash', ar: 'نقدًا', en: 'Cash' },
  { value: 'other', ar: 'أخرى', en: 'Other' },
];

const CURRENCIES = ['USD', 'JOD', 'SAR', 'AED', 'EUR'];

export type ManualSubscriptionTeacher = { id: string; name: string; email?: string };
export type ManualSubscriptionCurrent = {
  plan_name?: string | null;
  status?: string | null;
  source?: string | null;
  ends_at?: string | null;
  trial_ends_at?: string | null;
} | null;

type Props = {
  open: boolean;
  onOpenChange: (v: boolean) => void;
  teacher: ManualSubscriptionTeacher;
  current?: ManualSubscriptionCurrent;
  plans: any[];
  onDone: () => void;
};

type Preview = {
  operation_type: string;
  billing_cycle: string;
  duration_days: number | null;
  starts_at: string;
  ends_at: string;
  previous_ends_at: string | null;
  remaining_days_preserved: boolean;
  amount: number | null;
  currency: string | null;
  is_free_grant: boolean;
  plan: { id: string; name: string };
  warnings: Array<{ resource: string; label: string; used: number; limit: number }>;
};

const todayISO = () => new Date().toISOString().slice(0, 10);
const newRequestId = () =>
  globalThis.crypto?.randomUUID?.() ?? `req_${Date.now()}_${Math.random().toString(36).slice(2)}`;

export default function ManualSubscriptionModal({
  open,
  onOpenChange,
  teacher,
  current,
  plans,
  onDone,
}: Props) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const headers = { 'X-Tenant-Id': '', Accept: 'application/json' };

  const [planId, setPlanId] = useState('');
  const [operationType, setOperationType] = useState('activate');
  const [billingCycle, setBillingCycle] = useState<'monthly' | 'yearly'>('monthly');
  const [durationMode, setDurationMode] = useState<'month' | 'year' | 'custom'>('month');
  const [durationValue, setDurationValue] = useState('1');
  const [startsAt, setStartsAt] = useState(todayISO());
  const [endsAt, setEndsAt] = useState('');
  const [paymentMethod, setPaymentMethod] = useState('cliq');
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [paymentDate, setPaymentDate] = useState(todayISO());
  const [paymentReference, setPaymentReference] = useState('');
  const [notes, setNotes] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [proof, setProof] = useState<File | null>(null);

  const [preview, setPreview] = useState<Preview | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const requestIdRef = useRef('');
  const amountTouchedRef = useRef(false);
  const amountTouched = useCallback((v: boolean) => { amountTouchedRef.current = v; }, []);

  const isFree = operationType === 'free_grant';

  const applyDefaults = useCallback(() => {
    const currentPlan = plans.find((p) => p.name === current?.plan_name) ?? plans[0];
    setPlanId(currentPlan?.id ?? '');
    setOperationType(current?.status === 'active' ? 'renew' : 'activate');
    setBillingCycle('monthly');
    setDurationMode('month');
    setDurationValue('1');
    setStartsAt(todayISO());
    setEndsAt('');
    setPaymentMethod('cliq');
    setCurrency(currentPlan?.currency ?? 'USD');
    setAmount(currentPlan?.monthly_price != null ? String(currentPlan.monthly_price) : '');
    setPaymentDate(todayISO());
    setPaymentReference('');
    setNotes('');
    setConfirmed(false);
    setProof(null);
    setPreview(null);
    setError('');
    requestIdRef.current = newRequestId();
    amountTouchedRef.current = false;
  }, [plans, current?.plan_name, current?.status]);

  useEffect(() => {
    if (open) applyDefaults();
  }, [open, applyDefaults]);

  const selectedPlan = plans.find((p) => p.id === planId);

  // Keep the amount aligned with the selected plan/cycle unless the admin has
  // typed a custom figure.
  useEffect(() => {
    if (isFree || amountTouchedRef.current || !selectedPlan) return;
    const price = billingCycle === 'yearly' ? selectedPlan.yearly_price ?? selectedPlan.monthly_price * 12 : selectedPlan.monthly_price;
    if (price != null) setAmount(String(price));
  }, [planId, billingCycle, isFree, selectedPlan]);

  const buildPayload = useCallback((): Record<string, any> => {
    const payload: Record<string, any> = {
      plan_id: planId,
      operation_type: operationType,
      billing_cycle: billingCycle,
      starts_at: startsAt || undefined,
      ends_at: endsAt || undefined,
      payment_method: isFree ? undefined : paymentMethod,
      amount: isFree ? 0 : Number(amount || 0),
      currency: isFree ? undefined : currency,
      payment_date: isFree ? undefined : paymentDate || undefined,
      payment_reference: isFree ? undefined : paymentReference || undefined,
      notes: notes || undefined,
      request_id: requestIdRef.current,
      confirm_payment_received: isFree ? undefined : confirmed,
    };

    if (durationMode === 'month') {
      payload.duration_value = Number(durationValue);
      payload.duration_unit = 'month';
    } else if (durationMode === 'year') {
      payload.duration_value = Number(durationValue);
      payload.duration_unit = 'year';
    } else {
      payload.duration_value = Number(durationValue);
      payload.duration_unit = 'day';
    }

    return payload;
  }, [planId, operationType, billingCycle, startsAt, endsAt, paymentMethod, amount, currency, paymentDate, paymentReference, notes, isFree, confirmed, durationMode, durationValue]);

  // Live preview (debounced) — "preview of changes before confirming".
  useEffect(() => {
    if (!open || !planId) return;
    const timer = setTimeout(async () => {
      setPreviewing(true);
      try {
        const res = await fetch(`${API}/admin/teachers/${teacher.id}/subscription/preview`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', ...headers },
          body: JSON.stringify(buildPayload()),
        });
        const d = await res.json();
        if (res.ok) setPreview(d.preview);
      } catch {
        /* preview is best-effort */
      } finally {
        setPreviewing(false);
      }
    }, 350);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, planId, operationType, billingCycle, durationMode, durationValue, startsAt, endsAt, paymentMethod, amount, currency, paymentDate, paymentReference, notes, confirmed, isFree, teacher.id]);

  const submit = async () => {
    setError('');
    if (!planId) { setError(t('اختر خطة', 'Select a plan')); return; }
    if (!isFree) {
      if (!paymentMethod) { setError(t('اختر طريقة الدفع', 'Select a payment method')); return; }
      if (!(Number(amount) > 0)) { setError(t('أدخل المبلغ المدفوع', 'Enter the paid amount')); return; }
      if (!confirmed) { setError(t('يجب تأكيد التحقق من استلام الدفعة', 'You must confirm you verified receipt of the payment')); return; }
    }

    setSaving(true);
    try {
      const payload = buildPayload();
      let res: Response;

      if (proof) {
        const fd = new FormData();
        Object.entries(payload).forEach(([k, v]) => {
          if (v === undefined || v === null) return;
          fd.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v));
        });
        fd.append('proof', proof);
        res = await fetch(`${API}/admin/teachers/${teacher.id}/subscription/manual`, { method: 'POST', headers, body: fd });
      } else {
        res = await fetch(`${API}/admin/teachers/${teacher.id}/subscription/manual`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', ...headers },
          body: JSON.stringify(payload),
        });
      }

      const d = await res.json().catch(() => ({}));
      if (!res.ok) {
        setError(d.message || t('تعذّر تنفيذ العملية', 'Could not complete the operation'));
        return;
      }

      const end = d.operation?.ends_at ? new Date(d.operation.ends_at).toLocaleDateString() : '';
      toast.success(d.already_applied
        ? t('تم تطبيق هذه العملية مسبقًا', 'This operation was already applied')
        : t(`تم تفعيل الاشتراك بنجاح حتى ${end}`, `Subscription activated successfully until ${end}`));
      onOpenChange(false);
      onDone();
    } catch {
      setError(t('تعذّر الاتصال بالخادم', 'Could not reach the server'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(v) => !saving && onOpenChange(v)}>
      <DialogContent className="max-w-2xl max-h-[88vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Crown className="h-5 w-5 text-amber-500" />
            {t('تفعيل / إدارة الاشتراك يدويًا', 'Activate / manage the subscription manually')}
          </DialogTitle>
        </DialogHeader>

        <div className="space-y-4" data-testid="manual-subscription-modal">
          <div className="p-3 rounded-xl bg-gray-50 border text-sm flex flex-wrap items-center justify-between gap-2">
            <div>
              <span className="text-gray-400 text-xs">{t('المعلم', 'Teacher')}</span>
              <div className="font-semibold">{teacher.name}</div>
            </div>
            <div className="text-end">
              <span className="text-gray-400 text-xs">{t('الحالة الحالية', 'Current status')}</span>
              <div className="font-medium">{current?.plan_name || t('بدون خطة', 'No plan')} · {current?.status ?? '—'}</div>
            </div>
          </div>

          {/* Plan */}
          <div className="space-y-2">
            <Label>{t('الخطة', 'Plan')}</Label>
            <Select value={planId} onChange={(e) => setPlanId(e.target.value)} className="h-11" data-testid="plan-select">
              <option value="">{t('اختر خطة', 'Select a plan')}</option>
              {plans.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name} — {p.monthly_price} {p.currency}/{t('شهر', 'mo')}
                </option>
              ))}
            </Select>
            {selectedPlan && (
              <div className="text-[11px] text-gray-500 flex flex-wrap gap-x-3 gap-y-1">
                <span>{t('شهري', 'Monthly')}: <b dir="ltr">{selectedPlan.monthly_price} {selectedPlan.currency}</b></span>
                <span>{t('سنوي', 'Yearly')}: <b dir="ltr">{selectedPlan.yearly_price ?? '—'} {selectedPlan.currency}</b></span>
              </div>
            )}
          </div>

          {/* Operation + cycle */}
          <div className="grid sm:grid-cols-2 gap-3">
            <div className="space-y-2">
              <Label>{t('نوع العملية', 'Operation type')}</Label>
              <Select value={operationType} onChange={(e) => setOperationType(e.target.value)} className="h-11" data-testid="operation-type">
                {OPERATION_TYPES.map((o) => <option key={o.value} value={o.value}>{t(o.ar, o.en)}</option>)}
              </Select>
            </div>
            <div className="space-y-2">
              <Label>{t('دورة الفوترة', 'Billing cycle')}</Label>
              <Select value={billingCycle} onChange={(e) => setBillingCycle(e.target.value as 'monthly' | 'yearly')} className="h-11">
                <option value="monthly">{t('شهري', 'Monthly')}</option>
                <option value="yearly">{t('سنوي', 'Yearly')}</option>
              </Select>
            </div>
          </div>

          {/* Duration */}
          <div className="grid sm:grid-cols-2 gap-3">
            <div className="space-y-2">
              <Label>{t('المدة', 'Duration')}</Label>
              <Select value={durationMode} onChange={(e) => { setDurationMode(e.target.value as any); setDurationValue(e.target.value === 'month' || e.target.value === 'year' ? '1' : '30'); }} className="h-11">
                <option value="month">{t('شهر', 'Month')}</option>
                <option value="year">{t('سنة', 'Year')}</option>
                <option value="custom">{t('مخصص (بالأيام)', 'Custom (days)')}</option>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>{durationMode === 'custom' ? t('عدد الأيام', 'Number of days') : t('عدد الوحدات', 'Number of units')}</Label>
              <Input type="number" min={1} step={1} value={durationValue} onChange={(e) => setDurationValue(e.target.value)} className="h-11" data-testid="duration-value" />
            </div>
          </div>

          {/* Dates */}
          <div className="grid sm:grid-cols-2 gap-3">
            <div className="space-y-2">
              <Label>{t('تاريخ البداية', 'Start date')}</Label>
              <Input type="date" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} className="h-11" dir="ltr" />
            </div>
            <div className="space-y-2">
              <Label>{t('تاريخ النهاية (يُحسب تلقائيًا)', 'End date (auto-calculated)')}</Label>
              <Input type="date" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} className="h-11" dir="ltr" />
            </div>
          </div>

          {/* Payment */}
          {!isFree ? (
            <div className="p-3 rounded-2xl border bg-white space-y-3" data-testid="payment-fields">
              <div className="grid sm:grid-cols-2 gap-3">
                <div className="space-y-2">
                  <Label>{t('طريقة الدفع', 'Payment method')}</Label>
                  <Select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)} className="h-11" data-testid="payment-method">
                    {PAYMENT_METHODS.map((m) => <option key={m.value} value={m.value}>{t(m.ar, m.en)}</option>)}
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label>{t('تاريخ الدفع', 'Payment date')}</Label>
                  <Input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} className="h-11" dir="ltr" />
                </div>
              </div>
              <div className="grid sm:grid-cols-2 gap-3">
                <div className="space-y-2">
                  <Label>{t('المبلغ', 'Amount')}</Label>
                  <Input type="number" min={0} step="0.01" value={amount} onChange={(e) => { amountTouched(true); setAmount(e.target.value); }} className="h-11" dir="ltr" data-testid="amount" />
                </div>
                <div className="space-y-2">
                  <Label>{t('العملة', 'Currency')}</Label>
                  <Select value={currency} onChange={(e) => setCurrency(e.target.value)} className="h-11">
                    {Array.from(new Set([currency, selectedPlan?.currency, ...CURRENCIES].filter(Boolean))).map((c) => (
                      <option key={c as string} value={c as string}>{c}</option>
                    ))}
                  </Select>
                </div>
              </div>
              <div className="space-y-2">
                <Label>{t('رقم مرجع الدفع (كليك / الحوالة)', 'Payment reference (CliQ / transfer)')}</Label>
                <Input value={paymentReference} onChange={(e) => setPaymentReference(e.target.value)} className="h-11" dir="ltr" placeholder="CLIQ-XXXXXXXX" data-testid="payment-reference" />
              </div>
            </div>
          ) : (
            <Alert variant="info" icon={Crown}>
              {t('منح مجاني — لن يُسجَّل أي مبلغ أو إيراد ولن تُنشأ فاتورة.', 'Free grant — no amount or revenue is recorded and no invoice is created.')}
            </Alert>
          )}

          <div className="space-y-2">
            <Label>{t('ملاحظات الإدارة', 'Admin notes')}</Label>
            <Input value={notes} onChange={(e) => setNotes(e.target.value)} className="h-11" placeholder={t('سبب العملية / تفاصيل التحقق', 'Reason / verification details')} />
          </div>

          <div className="space-y-2">
            <Label>{t('إثبات الدفع (اختياري — خاص)', 'Payment proof (optional — private)')}</Label>
            <input
              type="file"
              accept="image/*,application/pdf"
              onChange={(e) => setProof(e.target.files?.[0] ?? null)}
              className="block w-full text-sm text-gray-500 file:me-3 file:rounded-xl file:border-0 file:bg-teal-50 file:px-4 file:py-2 file:text-teal-700 hover:file:bg-teal-100"
              data-testid="proof-input"
            />
            <p className="text-[11px] text-gray-400">{t('يُخزَّن الملف بشكل خاص ولا يظهر إلا للمشرفين.', 'The file is stored privately and visible to admins only.')}</p>
          </div>

          {!isFree && (
            <label className="flex items-start gap-3 p-3 rounded-xl border bg-amber-50/50 cursor-pointer">
              <Switch checked={confirmed} onCheckedChange={setConfirmed} label={t('تأكيد استلام الدفعة', 'Confirm payment received')} />
              <span className="text-sm text-amber-900">
                {t('أؤكد أنني تحققت فعليًا من استلام المبلغ (وليس مجرد وجود رقم مرجع).', 'I confirm I actually verified receipt of the money (not merely a reference number).')}
              </span>
            </label>
          )}

          {/* Preview */}
          {preview && (
            <div className="p-3 rounded-xl bg-gradient-to-br from-teal-50/70 to-white border space-y-2 text-sm" data-testid="manual-preview">
              <div className="font-semibold flex items-center gap-1.5">
                {previewing ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <CheckCircle2 className="h-4 w-4 text-teal-600" />}
                {t('معاينة التغييرات', 'Preview of changes')}
              </div>
              <div className="grid grid-cols-2 gap-2 text-[13px]">
                <div><span className="text-gray-400">{t('الخطة الجديدة', 'New plan')}: </span><b>{preview.plan?.name}</b></div>
                <div><span className="text-gray-400">{t('المبلغ', 'Amount')}: </span><b dir="ltr">{preview.is_free_grant ? t('مجاني', 'Free') : `${preview.amount} ${preview.currency}`}</b></div>
                <div><span className="text-gray-400">{t('البداية', 'Start')}: </span><b dir="ltr">{new Date(preview.starts_at).toLocaleDateString()}</b></div>
                <div><span className="text-gray-400">{t('النهاية', 'End')}: </span><b dir="ltr">{new Date(preview.ends_at).toLocaleDateString()}</b></div>
              </div>
              {preview.remaining_days_preserved && (
                <div className="text-[11px] text-teal-700 flex items-center gap-1">
                  <ShieldCheck className="h-3.5 w-3.5" />
                  {t('سيتم الحفاظ على الأيام المدفوعة المتبقية.', 'Remaining paid days will be preserved.')}
                </div>
              )}
              {preview.warnings?.length > 0 && (
                <Alert variant="warning" icon={AlertCircle}>
                  <div className="space-y-1">
                    <div className="font-semibold">{t('تنبيه: الاستخدام يتجاوز حدود الخطة الجديدة', 'Warning: usage exceeds the new plan limits')}</div>
                    <ul className="text-[11px] list-disc ps-4">
                      {preview.warnings.map((w) => (
                        <li key={w.resource}>{w.label}: {w.used} / {w.limit}</li>
                      ))}
                    </ul>
                    <div className="text-[11px]">{t('لن يُحذف أي محتوى — سيمنع فقط إنشاء عناصر جديدة تتجاوز الحد.', 'No content is deleted — only new items beyond the limit are blocked.')}</div>
                  </div>
                </Alert>
              )}
            </div>
          )}

          {error && <Alert variant="error" icon={AlertCircle}>{error}</Alert>}
        </div>

        <DialogFooter className="mt-4">
          <Button variant="outline" disabled={saving} onClick={() => onOpenChange(false)}>{t('إلغاء', 'Cancel')}</Button>
          <Button className="gap-2" disabled={saving || !planId} onClick={submit} data-testid="manual-submit">
            {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <CalendarPlus className="h-4 w-4" />}
            {t('تأكيد وتفعيل', 'Confirm & activate')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
