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
import { Select } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { CardSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Layers, Plus, Trash2, Loader2, Check, Crown, Pencil, RefreshCw,
  TrendingDown,
  X, HelpCircle,
} from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

// ─── المحرر المبسّط: المفاتيح التي يتحكم بها الأدمن فقط ──────────
// الباقي مفعّل ثابتاً لكل الخطط (يُخفى نهائياً من المحرر).
const EDITABLE_KEYS = [
  'students_limit', 'courses_limit', 'storage_limit_mb', 'max_students_per_course',
  'exams_limit', 'questions_limit', 'assignments_limit', 'teachers_limit',
  'videos_limit', 'cards_limit', 'appointments_limit',
  'support_level',
  'student_mobile_app',
];

// ─── القيم الثابتة للميزات المخفية (مفعّلة للجميع) ───────────────
const FIXED_HIDDEN: Record<string, string> = {
  community_enabled: 'true', community_groups_limit: '5',
  custom_branding_enabled: 'true', custom_domain: 'true', blog_enabled: 'true',
  export_reports: 'true', appointments_enabled: 'true', paid_services_enabled: 'true',
  multi_teacher_enabled: 'true', white_label: 'true', api_access: 'true',
  cards_enabled: 'true', announcements_enabled: 'true',
  video_upload_enabled: 'true', file_upload_enabled: 'true',
  live_recording_enabled: 'true', secure_playback_enabled: 'true',
  video_analytics_enabled: 'true', video_hosting: 'true', video_embeds: 'true',
  question_bank_enabled: 'true', certificates_enabled: 'true',
  assignments_enabled: 'true', scheduled_lessons_enabled: 'true',
};

type FeatureDef = {
  key: string;
  type: 'boolean' | 'numeric' | 'select' | 'custom';
  options?: { value: string; ar: string; en: string }[];
  ar: string;
  en: string;
  desc_ar?: string;
  desc_en?: string;
  allow_unlimited?: boolean;
  unit?: string;
};

export default function AdminPlansPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const { formatPrice, load: loadSettings, settings } = useSettings();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [plans, setPlans] = useState<any[]>([]);
  const [catalog, setCatalog] = useState<FeatureDef[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<any>(null);
  const [showDialog, setShowDialog] = useState(false);
  const [profitWarning, setProfitWarning] = useState<any>(null);
  const [showAllFeatures, setShowAllFeatures] = useState(false);
  const [form, setForm] = useState<any>({ name: '', description: '', monthly_price: '99', yearly_price: '', currency: 'USD', trial_days: '7', is_active: true, is_popular: false });
  const [feats, setFeats] = useState<Record<string, { enabled: boolean; value: string }>>({});
  const [saving, setSaving] = useState(false);
  const [busyId, setBusyId] = useState<string | null>(null);

  const headers = () => ({ 'Content-Type': 'application/json', Accept: 'application/json' });

  const loadCatalog = async () => {
    try {
      const res = await fetch(`${API}/admin/feature-catalog`, { headers: headers() });
      if (res.ok) {
        const d = await res.json();
        setCatalog(d.catalog || []);
      }
    } catch {
      /* fall back to empty catalog */
    }
  };

  const load = () => {
    fetch(`${API}/plans`, { headers: headers() })
      .then(r => r.json()).then(d => setPlans(Array.isArray(d) ? d : (d.plans || []))).catch(() => {})
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (check()) { loadSettings(); loadCatalog(); load(); } }, [check()]);

  const resetForm = () => {
    setForm({ name: '', description: '', monthly_price: '99', yearly_price: '', currency: 'USD', trial_days: '7', is_active: true, is_popular: false, freemius_monthly_plan_id: '', freemius_yearly_plan_id: '', freemius_monthly_pricing_id: '', freemius_yearly_pricing_id: '' });
    setFeats({});
  };

  const openAdd = () => { setEditing(null); resetForm(); setShowDialog(true); };
  const openEdit = (p: any) => {
    setEditing(p);
    setForm({
      name: p.name || '',
      description: p.description || '',
      monthly_price: p.monthly_price ?? '',
      yearly_price: p.yearly_price ?? '',
      currency: p.currency || 'USD',
      trial_days: p.trial_days ?? '14',
      is_active: p.is_active ?? true,
      is_popular: p.is_popular ?? false,
      freemius_monthly_plan_id: p.freemius_monthly_plan_id || '',
      freemius_yearly_plan_id: p.freemius_yearly_plan_id || '',
      freemius_monthly_pricing_id: p.freemius_monthly_pricing_id || '',
      freemius_yearly_pricing_id: p.freemius_yearly_pricing_id || '',
    });
    const init: Record<string, { enabled: boolean; value: string }> = {};
    const existing = (p.features || []).reduce((acc: any, f: any) => { acc[f.feature_key] = f; return acc; }, {});
    catalog.filter((d) => EDITABLE_KEYS.includes(d.key)).forEach((def) => {
      const cur = existing[def.key];
      let val = cur ? String(cur.feature_value) : '';
      // storage_limit_mb تُخزن بالميجابايت لكن المحرر يعرض ويدخل بالجيجابايت.
      if (def.key === 'storage_limit_mb' && val && val !== 'unlimited') {
        val = String(Math.round(Number(val) / 1024));
      }
      init[def.key] = { enabled: !!cur, value: val };
    });
    // keep any stored feature not present in the catalog visible
    Object.values(existing).forEach((f: any) => {
      if (!(f.feature_key in init) && !catalog.some((d: any) => d.key === f.feature_key)) init[f.feature_key] = { enabled: true, value: String(f.feature_value) };
    });
    setFeats(init);
    setShowDialog(true);
  };

  const isEnabled = (key: string) => feats[key]?.enabled || false;

  const normalizeValue = (def: FeatureDef, raw: string) => {
    let value = raw;
    if (def.type === 'boolean') value = '1';
    if (def.key === 'storage_limit_mb' && raw && raw !== 'unlimited') value = String(Math.round(Number(raw) * 1024));
    return value;
  };

  const buildFeaturesPayload = () => {
    const payload: any[] = [];
    // 1) الميزات التي يتحكم بها الأدمن
    Object.keys(feats).filter((key) => feats[key]?.enabled).forEach((key, i) => {
      const def = catalog.find((d) => d.key === key);
      payload.push({
        feature_key: key,
        feature_value: normalizeValue(def as FeatureDef, feats[key]?.value ?? ''),
        feature_type: def?.type === 'numeric' ? 'numeric' : 'string',
        sort_order: i + 1,
      });
    });
    // 2) الميزات الثابتة المخفية — مفعّلة للجميع
    Object.entries(FIXED_HIDDEN).forEach(([key, value]) => {
      payload.push({ feature_key: key, feature_value: value, feature_type: 'string', sort_order: 100 });
    });

    return payload;
  };

  const savePlan = async () => {
    if (!form.name || !form.monthly_price) { toast.error(t('أدخل الاسم والسعر', 'Enter name and price')); return; }
    setSaving(true);
    try {
      const body = {
        name: form.name,
        slug: editing?.slug || form.name.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '') + '-' + Math.random().toString(36).slice(2, 7),
        description: form.description || 'Plan',
        monthly_price: Number(form.monthly_price),
        yearly_price: form.yearly_price ? Number(form.yearly_price) : null,
        currency: form.currency,
        trial_days: Number(form.trial_days) || 0,
        is_active: form.is_active,
        is_popular: !!form.is_popular,
        freemius_monthly_plan_id: form.freemius_monthly_plan_id?.trim() || null,
        freemius_yearly_plan_id: form.freemius_yearly_plan_id?.trim() || null,
        freemius_monthly_pricing_id: form.freemius_monthly_pricing_id?.trim() || null,
        freemius_yearly_pricing_id: form.freemius_yearly_pricing_id?.trim() || null,
      };
      let planId = editing?.id;
      let warning: any = null;
      if (editing) {
        const res = await fetch(`${API}/admin/plans/${editing.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify(body) });
        if (!res.ok) throw new Error();
        const d = await res.json().catch(() => null);
        warning = d?.profitability_warning || null;
      } else {
        const res = await fetch(`${API}/admin/plans`, { method: 'POST', headers: headers(), body: JSON.stringify({ ...body, features: buildFeaturesPayload() }) });
        if (!res.ok) throw new Error();
        planId = (await res.json()).plan?.id || planId;
      }
      if (editing) {
        const w = await syncFeatures(planId);
        warning = warning || w;
      }
      toast.success(editing ? t('تم تحديث الخطة', 'Plan updated') : t('تم إنشاء الخطة', 'Plan created'));
      setProfitWarning(warning);
      setEditing(null); resetForm(); setShowDialog(false); load();
    } catch { toast.error('Failed'); }
    setSaving(false);
  };

  const syncFeatures = async (planId: string) => {
    if (!planId) return null;
    const res = await fetch(`${API}/admin/plans/${planId}/features`, { headers: headers() });
    const existing: any[] = res.ok ? ((await res.json()).features || []) : [];
    const byKey: any = existing.reduce((acc, f) => { acc[f.feature_key] = f; return acc; }, {});
    let warning: any = null;

    for (const key of Object.keys(feats)) {
      const entry = feats[key];
      const def = catalog.find((d) => d.key === key);
      const cur = byKey[key];
      if (entry?.enabled && !cur) {
        await fetch(`${API}/admin/plans/${planId}/features`, { method: 'POST', headers: headers(), body: JSON.stringify({ feature_key: key, feature_value: normalizeValue(def as FeatureDef, entry.value), feature_type: def?.type === 'numeric' ? 'numeric' : 'string', sort_order: 1 }) });
      } else if (entry?.enabled && cur) {
        const value = normalizeValue(def as FeatureDef, entry.value);
        if (String(cur.feature_value) !== String(value)) {
          const r = await fetch(`${API}/admin/plans/${planId}/features/${cur.id}`, { method: 'PUT', headers: headers(), body: JSON.stringify({ feature_value: String(value) }) });
          const d = await r.json().catch(() => null);
          warning = d?.profitability_warning || warning;
        }
      } else if (!entry?.enabled && cur) {
        await fetch(`${API}/admin/plans/${planId}/features/${cur.id}`, { method: 'DELETE', headers: headers() });
      }
    }

    // تحقق نهائي من الربحية بعد كل المزامنة
    try {
      const check = await fetch(`${API}/admin/bi/plans/${planId}`, { headers: headers() }).then(r => r.json());
      const p = check?.profitability;
      if (p?.loss) warning = { ...p, message: t(`الخطة بتكلفة متوقعة ${p.estimated_cost} USD تتجاوز سعرها — ستكون خاسرة.`, `Plan expected cost ${p.estimated_cost} USD exceeds its price — it will be loss-making.`) };
      else warning = null;
    } catch { /* */ }

    return warning;
  };

  const remove = async (p: any) => {
    if (!window.confirm(t(`حذف الخطة «${p.name}»؟`, `Delete plan "${p.name}"?`))) return;
    setBusyId(p.id);
    try {
      await fetch(`${API}/admin/plans/${p.id}`, { method: 'DELETE', headers: headers() });
      toast.success(t('تم الحذف', 'Deleted')); load();
    } catch { toast.error('Failed'); } finally { setBusyId(null); }
  };

  const featValue = (f: any) => {
    const v = String(f.feature_value);
    if (f.feature_key === 'storage_limit_mb' && Number(v) >= 1024) return `${Math.round(Number(v) / 1024)} GB`;
    const def = catalog.find((d) => d.key === f.feature_key);
    if (def?.type === 'select') {
      const opt = def.options?.find((o) => o.value === v);
      return opt ? (locale === 'ar' ? opt.ar : opt.en) : v;
    }
    if (v === '1') return t('نعم', 'Yes');
    if (v === '0') return t('لا', 'No');
    return v;
  };

  const defLabel = (key: string) => {
    const def = catalog.find((d) => d.key === key);
    return def ? (locale === 'ar' ? def.ar : def.en) : key;
  };

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#d9770612' }}>
            <Crown className="h-6 w-6" style={{ color: '#d97706' }} />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('خطط الأسعار', 'Pricing Plans')}</h1>
            <p className="text-muted-foreground mt-1">{t('إدارة الخطط ومميزاتها كاملة ديناميكياً — أي ميزة تُضاف تظهر هنا تلقائياً', 'Manage plans and features dynamically — any new feature appears here automatically')}</p>
          </div></div>
        <div className="flex gap-2">
          <Button variant="outline" className="gap-2 rounded-full" onClick={() => { loadCatalog(); toast.success(t('تم تحديث قائمة الميزات', 'Feature catalog refreshed')); }}><RefreshCw className="h-4 w-4" /> {t('تحديث الميزات', 'Refresh features')}</Button>
          <Button onClick={openAdd} className="gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('إضافة خطة', 'Add Plan')}</Button>
        </div>
      </div>

      {profitWarning && (
        <div className="flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
          <TrendingDown className="h-5 w-5 shrink-0 mt-0.5" />
          <div>
            <p className="font-bold">{t('تحذير الربحية', 'Profitability warning')}</p>
            <p className="mt-1 text-xs leading-relaxed">
              {t(`التكلفة المتوقعة للخطة (${profitWarning.estimated_cost} USD) تتجاوز سعرها (${profitWarning.price} ${profitWarning.currency}) — هامش الربح ${profitWarning.margin_percentage}%. الخطة ستكون خاسرة، فكّر برفع السعر أو خفض الحدود.`, `Expected cost (${profitWarning.estimated_cost} USD) exceeds the plan price (${profitWarning.price} ${profitWarning.currency}) — margin ${profitWarning.margin_percentage}%. The plan will be loss-making; consider raising the price or lowering limits.`)}
            </p>
          </div>
          <button onClick={() => setProfitWarning(null)} className="ms-auto text-red-400 hover:text-red-700 shrink-0" aria-label={t('إغلاق', 'Close')}>✕</button>
        </div>
      )}

      {loading ? <CardSkeleton count={6} /> :
        plans.length === 0 ? (
          <EmptyState icon={Layers} title={t('لا توجد خطط', 'No plans')} description={t('أنشئ خطتك الأولى ليظهر الطلاب قسم الأسعار', 'Create your first plan to show students a pricing section')} action={<Button onClick={openAdd} className="mt-2 gap-2 rounded-full"><Plus className="h-4 w-4" /> {t('أنشئ خطة', 'Create plan')}</Button>} />
        ) : (
          <div className="grid md:grid-cols-2 xl:grid-cols-3 gap-6">
            {plans.map((p) => (
              <Card key={p.id} className={`border-0 shadow-sm overflow-hidden card-hover ${p.name === 'Professional' ? 'ring-2 ring-teal-600 shadow-xl' : ''}`}>
                <div className="p-6">
                  <div className="flex items-start justify-between mb-4">
                    <div className="flex items-center gap-2">
                      <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50"><Crown className="h-5 w-5 text-violet-500" /></div>
                      <div><h3 className="text-xl font-bold">{p.name}</h3><p className="text-xs text-gray-400">{p.slug}</p></div>
                    </div>
                    <Badge variant={p.is_active ? 'default' : 'secondary'} className="text-xs">{p.is_active ? t('نشط', 'Active') : t('موقوف', 'Inactive')}</Badge>
                  </div>
                  <div className="flex items-end gap-1 mb-1">
                    <span className="text-3xl font-bold">{formatPrice(p.monthly_price)}</span>
                    <span className="text-sm text-gray-400 mb-1.5">/ {t('شهر', 'mo')}</span>
                  </div>
                  {p.yearly_price ? <p className="text-xs text-gray-400 mb-4">{formatPrice(p.yearly_price)} / {t('سنة', 'yr')} · {p.trial_days ? `${p.trial_days} ${t('يوم تجربة', 'trial days')}` : ''}</p> : <p className="text-xs text-gray-400 mb-4">&nbsp;</p>}
                  <div className="flex flex-wrap gap-1.5 mb-6 min-h-[28px]">
                    {(p.features || []).length === 0 ? <span className="text-xs text-gray-300">{t('بدون مميزات', 'No features')}</span> :
                      (p.features || []).slice(0, 6).map((f: any) => (
                        <span key={f.id} className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-teal-50 text-teal-700 text-[11px]"><Check className="h-3 w-3" />{defLabel(f.feature_key)} {f.feature_value !== '1' && f.feature_value !== '0' && <span className="text-teal-400">· {featValue(f)}</span>}</span>
                      ))}
                    {(p.features || []).length > 6 && <span className="text-[11px] text-gray-400 self-center">+{(p.features || []).length - 6}</span>}
                  </div>
                  <div className="flex gap-2">
                    <Button variant="outline" size="sm" className="flex-1 gap-1 rounded-full" onClick={() => openEdit(p)}><Pencil className="h-3.5 w-3.5" /> {t('تعديل', 'Edit')}</Button>
                    <Button variant="ghost" size="icon" className="text-red-500 border border-red-100" onClick={() => remove(p)} disabled={busyId === p.id} aria-label={t('حذف الخطة', 'Delete plan')} title={t('حذف الخطة', 'Delete plan')}>
                      {busyId === p.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <Trash2 className="h-4 w-4" />}
                    </Button>
                  </div>
                </div>
              </Card>
            ))}
          </div>
        )}

      <Dialog open={showDialog} onOpenChange={(v) => { if (!v) { setEditing(null); resetForm(); } setShowDialog(v); }} className="max-w-2xl">
        <DialogContent>
          <DialogHeader><DialogTitle>{editing ? t('تعديل الخطة', 'Edit plan') : t('إضافة خطة جديدة', 'Add new plan')}</DialogTitle></DialogHeader>
          <div className="space-y-5">
            <div className="grid sm:grid-cols-2 gap-4">
              <div className="space-y-2"><Label>{t('اسم الخطة', 'Name')} *</Label><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="h-11" placeholder="Professional" /></div>
              <div className="space-y-2"><Label>{t('الوصف', 'Description')}</Label><Input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="h-11" /></div>
              <div className="space-y-2"><Label>{t('السعر الشهري', 'Monthly price')} *</Label><Input type="number" value={form.monthly_price} onChange={(e) => setForm({ ...form, monthly_price: e.target.value })} className="h-11" /></div>
              <div className="space-y-2"><Label>{t('السعر السنوي', 'Yearly price')}</Label><Input type="number" value={form.yearly_price} onChange={(e) => setForm({ ...form, yearly_price: e.target.value })} className="h-11" /></div>
              <div className="space-y-2"><Label>{t('العملة', 'Currency')}</Label><div className="h-11 flex items-center px-3 rounded-lg border border-gray-200 bg-gray-50 text-sm font-medium text-gray-600">USD — {t('دولار أمريكي (ثابت لاشتراكات المنصة)', 'US Dollar (fixed for platform subscriptions)')}</div></div>
              <div className="space-y-2"><Label>{t('أيام التجربة', 'Trial days')}</Label><Input type="number" value={form.trial_days} onChange={(e) => setForm({ ...form, trial_days: e.target.value })} className="h-11" /></div>
            </div>

            <div className="p-3 rounded-xl border border-gray-100 bg-gray-50/60">
              <p className="font-medium text-sm mb-1">Freemius — {t('ربط خطة Freemius (اختياري)', 'Freemius plan mapping (optional)')}</p>
              <p className="text-xs text-gray-400 mb-3">{t('الصق معرّفات الخطة من لوحة Freemius لكل دورة فوترة. الخطة نفسها تبقى مصدر الميزات والحصص؛ هذا مجرد ربط للفوترة. إذا تُركت فارغة، سيُستخدم الربط الافتراضي من متغيرات البيئة، وإن غاب أيضًا فلن تتوفر الخطة للشراء.', 'Paste the Freemius plan IDs for each billing cycle. The plan itself stays the source of features & quotas — this is only a billing mapping. Leave empty to fall back to the environment defaults; if neither is set the plan is not purchasable.')}</p>
              <div className="grid sm:grid-cols-2 gap-3">
                <div className="space-y-2"><Label>{t('Freemius Plan ID — شهري', 'Freemius Plan ID — monthly')}</Label><Input value={form.freemius_monthly_plan_id || ''} onChange={(e) => setForm({ ...form, freemius_monthly_plan_id: e.target.value })} className="h-11" dir="ltr" placeholder="12345" /></div>
                <div className="space-y-2"><Label>{t('Freemius Plan ID — سنوي', 'Freemius Plan ID — yearly')}</Label><Input value={form.freemius_yearly_plan_id || ''} onChange={(e) => setForm({ ...form, freemius_yearly_plan_id: e.target.value })} className="h-11" dir="ltr" placeholder="12345" /></div>
                <div className="space-y-2"><Label>{t('Pricing ID — شهري (اختياري)', 'Pricing ID — monthly (optional)')}</Label><Input value={form.freemius_monthly_pricing_id || ''} onChange={(e) => setForm({ ...form, freemius_monthly_pricing_id: e.target.value })} className="h-11" dir="ltr" placeholder="67890" /></div>
                <div className="space-y-2"><Label>{t('Pricing ID — سنوي (اختياري)', 'Pricing ID — yearly (optional)')}</Label><Input value={form.freemius_yearly_pricing_id || ''} onChange={(e) => setForm({ ...form, freemius_yearly_pricing_id: e.target.value })} className="h-11" dir="ltr" placeholder="67890" /></div>
              </div>
            </div>

            <div className="flex items-center justify-between p-3 rounded-xl border border-gray-100 bg-gray-50/60">
              <div><p className="font-medium text-sm">{t('حالة الخطة', 'Plan status')}</p><p className="text-xs text-gray-400">{t('الخطط النشطة تظهر في صفحة الأسعار', 'Active plans appear on pricing page')}</p></div>
              <div className="flex items-center gap-2">
                <Switch checked={form.is_active} onCheckedChange={(v) => setForm({ ...form, is_active: v })} label={t('حالة الخطة', 'Plan status')} />
                <Badge variant={form.is_active ? 'default' : 'secondary'}>{form.is_active ? t('نشط', 'Active') : t('موقوف', 'Inactive')}</Badge>
              </div>
            </div>

            <div className="flex items-center justify-between p-3 rounded-xl border border-gray-100 bg-gray-50/60">
              <div><p className="font-medium text-sm">{t('الأكثر طلباً', 'Most popular')}</p><p className="text-xs text-gray-400">{t('تُظهر شارة مميزة على الخطة في صفحة الأسعار', 'Shows a highlight badge on the pricing page')}</p></div>
              <Switch checked={!!form.is_popular} onCheckedChange={(v) => setForm({ ...form, is_popular: v })} label={t('الأكثر طلباً', 'Most popular')} />
            </div>

            <div>
              <div className="flex items-center justify-between mb-3 flex-wrap gap-2">
                <h3 className="font-semibold">{t('المميزات', 'Features')}</h3>
                <div className="flex items-center gap-2">
                  <span className="text-xs text-gray-400">
                    {t('الباقي مفعّل تلقائياً للجميع', 'Everything else is enabled for all plans automatically')}
                  </span>
                </div>
              </div>
              <div className="space-y-2">
                {catalog.filter((def) => EDITABLE_KEYS.includes(def.key)).map((def) => {
                  const on = isEnabled(def.key);
                  return (
                    <div key={def.key} className={`flex items-center gap-3 p-3 rounded-xl border transition-colors ${on ? 'border-teal-200 bg-teal-50/50' : 'border-gray-100 bg-gray-50/40'}`}>
                      <Switch checked={on} onCheckedChange={(v) => setFeats({ ...feats, [def.key]: { enabled: v, value: feats[def.key]?.value ?? (def.type === 'boolean' ? '' : def.allow_unlimited ? '100' : '') } })} label={locale === 'ar' ? def.ar : def.en} />
                      <div className="flex-1">
                        <p className="font-medium text-sm flex items-center gap-1.5">
                          {on
                            ? <Check className="h-4 w-4 text-emerald-500 shrink-0" />
                            : <X className="h-4 w-4 text-red-500 shrink-0" />}
                          <span>{locale === 'ar' ? def.ar : def.en}</span>
                          {on && (
                            <span className="group relative inline-flex">
                              <HelpCircle className="h-3.5 w-3.5 text-gray-300 hover:text-teal-600 cursor-help transition-colors" />
                              <span className="pointer-events-none absolute bottom-full start-1/2 -translate-x-1/2 z-20 mb-1.5 hidden w-64 rounded-xl bg-gray-900 text-white text-[11px] leading-relaxed p-3 shadow-xl group-hover:block">
                                {locale === 'ar' ? (def.desc_ar || def.ar) : (def.desc_en || def.en)}
                              </span>
                            </span>
                          )}
                        </p>
                        {on && (
                          <div className="mt-1.5 max-w-[220px]">
                            {def.type === 'boolean' ? (
                              <Badge className="bg-green-100 text-green-700 border-0 text-xs">{t('مفعّل', 'Enabled')}</Badge>
                            ) : def.type === 'select' ? (
                              <Select value={feats[def.key]?.value || ''} onChange={(e) => setFeats({ ...feats, [def.key]: { enabled: true, value: e.target.value } })} className="h-8 text-xs">
                                {def.options?.map((o) => <option key={o.value} value={o.value}>{locale === 'ar' ? o.ar : o.en}</option>)}
                              </Select>
                            ) : (
                              <div className="flex items-center gap-1.5">
                                <Input type="text" value={feats[def.key]?.value || ''} onChange={(e) => setFeats({ ...feats, [def.key]: { enabled: true, value: e.target.value } })} className="h-8 text-xs" placeholder={def.allow_unlimited ? '100 / unlimited' : '0'} />
                                {def.unit && <span className="text-xs text-gray-400">{def.unit}</span>}
                                {def.allow_unlimited && (
                                  <button onClick={() => setFeats({ ...feats, [def.key]: { enabled: true, value: 'unlimited' } })} className="px-2 py-1 rounded-md bg-white border border-gray-200 text-[10px] text-gray-500 hover:border-teal-300">{t('غير محدود', '∞')}</button>
                                )}
                              </div>
                            )}
                          </div>
                        )}
                      </div>
                    </div>
                  );
                })}
                {catalog.length === 0 && <p className="text-sm text-gray-400 py-6 text-center">{t('تعذر تحميل قائمة الميزات', 'Could not load feature catalog')}</p>}
              </div>
            </div>
          </div>
          {/* ─── تحليل الربحية الحي ─────────────────── */}
          {(() => {
            const price = Number(form.monthly_price) || 0;
            // Platform-subscription prices are stored in USD — no conversion.
            const priceUsd = price;
            const storageMb = Number(feats['storage_limit_mb']?.value) || 0;
            const storageGb = storageMb > 0 ? storageMb / 1024 : 0;
            const liveMinutes = Number(feats['live_minutes_limit']?.value) || 0;
            const liveOn = feats['live_stream_enabled']?.enabled || false;
            const cost = Math.max(1, storageGb * 0.01 + storageGb * 2 * 0.01 + (liveOn ? (liveMinutes / 60) * 0.03 : 0) + 100 * 0.0001);
            const profit = priceUsd - cost;
            const roi = cost > 0 ? Math.round((profit / cost) * 100) : 0;
            const status = roi >= 300 ? 'good' : roi >= 100 ? 'warn' : 'bad';
            const tip = status === 'good'
              ? t('ربحية ممتازة — التكلفة المتوقعة أقل بكثير من السعر.', 'Excellent profitability — expected cost is far below the price.')
              : status === 'warn'
                ? t('هامش مقبول لكنه منخفض — يُفضّل رفع السعر أو خفض الحدود لتحقيق ربح ≥ 300%.', 'Acceptable but thin margin — consider raising price or lowering limits to reach ≥ 300% ROI.')
                : t('تنبيه: الخطة قد تكون خاسرة — ارفع السعر أو خفّض الحدود (التخزين/البث/الفيديوهات).', 'Warning: the plan may be loss-making — raise the price or lower limits (storage/live/videos).');
            return (
              <div className={`rounded-2xl border p-4 space-y-2 ${status === 'good' ? 'border-emerald-200 bg-emerald-50/60' : status === 'warn' ? 'border-amber-200 bg-amber-50/60' : 'border-red-200 bg-red-50/60'}`}>
                <div className="flex items-center justify-between">
                  <p className="text-sm font-bold">{t('تحليل الربحية (حي)', 'Live profitability analysis')}</p>
                  <Badge className={status === 'good' ? 'bg-emerald-100 text-emerald-700 border-0' : status === 'warn' ? 'bg-amber-100 text-amber-700 border-0' : 'bg-red-100 text-red-700 border-0'}>
                    {status === 'good' ? t('مربحة', 'Profitable') : status === 'warn' ? t('هامش ضعيف', 'Thin margin') : t('خاسرة', 'Loss-making')}
                  </Badge>
                </div>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center">
                  <div className="rounded-xl bg-white/70 p-2.5"><p className="text-[10px] text-gray-400">{t('السعر', 'Price')}</p><p className="font-bold">{price} USD</p></div>
                  <div className="rounded-xl bg-white/70 p-2.5"><p className="text-[10px] text-gray-400">{t('التكلفة المتوقعة', 'Expected cost')}</p><p className="font-bold">{cost.toFixed(2)} $</p></div>
                  <div className="rounded-xl bg-white/70 p-2.5"><p className="text-[10px] text-gray-400">{t('نسبة الربح ROI', 'ROI')}</p><p className={`font-bold ${status === 'good' ? 'text-emerald-600' : status === 'warn' ? 'text-amber-600' : 'text-red-600'}`}>{roi}%</p></div>
                </div>
                <p className={`text-[11px] leading-relaxed ${status === 'good' ? 'text-emerald-700' : status === 'warn' ? 'text-amber-700' : 'text-red-700'}`}>
                  {tip} {t('الهدف: ROI ≥ 300%.', 'Target: ROI ≥ 300%.')}
                </p>
              </div>
            );
          })()}

          <DialogFooter>
            <Button variant="outline" onClick={() => { setEditing(null); resetForm(); setShowDialog(false); }}>{t('إلغاء', 'Cancel')}</Button>
            <Button onClick={savePlan} disabled={saving} className="gap-2 rounded-full">{saving && <Loader2 className="h-4 w-4 animate-spin" />}{t('حفظ الخطة', 'Save plan')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
