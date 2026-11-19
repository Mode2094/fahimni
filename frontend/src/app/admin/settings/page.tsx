'use client';

import { useEffect, useRef, useState } from 'react';
import Image from 'next/image';
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
import { cn } from '@/lib/utils';
import { Save, Settings2, Palette, Bell, Shield, CreditCard, Upload, Loader2, Check, X, KeyRound, BookOpen, ChevronDown, ExternalLink, Settings, Smartphone, EyeOff, BarChart3, Gift, Users } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminSettingsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const { settings, load, save, loaded } = useSettings();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [section, setSection] = useState('trial');
  const [form, setForm] = useState<any>({});
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  useEffect(() => { if (check()) load(); }, [check()]);
  useEffect(() => { if (loaded) setForm({ ...settings }); }, [loaded]);

  if (!loaded) {
    return <div className="flex items-center justify-center h-64"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>;
  }

  const sections = [
    { id: 'trial', label: t('التجربة المجانية والعرض', 'Free trial & offer'), icon: Gift },
    { id: 'general', label: t('عام', 'General'), icon: Settings2 },
    { id: 'branding', label: t('العلامة', 'Branding'), icon: Palette },
    { id: 'payments', label: t('بوابات الدفع', 'Payments'), icon: CreditCard },
    { id: 'notifications', label: t('الإشعارات', 'Notify'), icon: Bell },
    { id: 'security', label: t('الأمان', 'Security'), icon: Shield },
    { id: 'student_app', label: t('تطبيق الطلاب', 'Student App'), icon: Smartphone },
    { id: 'analytics', label: t('التحليلات والتتبّع', 'Analytics & Meta'), icon: BarChart3 },
  ];

  const TestButton = ({ gateway }: { gateway: 'paytabs' | 'paypal' }) => {
    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);
    return (
      <div className="flex items-center gap-2">
        <Button variant="outline" size="sm" className="gap-1.5 rounded-full" disabled={testing} onClick={async () => {
          setTesting(true); setResult(null);
          try {
            const res = await fetch(`${API}/admin/payment-gateway-test`, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
              body: JSON.stringify({ gateway }),
            });
            const d = await res.json().catch(() => null);
            setResult({ ok: res.ok && d?.ok !== false, message: d?.message || (res.ok ? 'OK' : 'Failed') });
          } catch { setResult({ ok: false, message: t('فشل الاتصال', 'Connection failed') }); }
          setTesting(false);
        }}>
          {testing ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <KeyRound className="h-3.5 w-3.5" />}{t('اختبار الاتصال', 'Test connection')}
        </Button>
        {result && <span className={`text-xs flex items-center gap-1 ${result.ok ? 'text-green-600' : 'text-red-500'}`}>{result.ok ? <Check className="h-3.5 w-3.5" /> : <X className="h-3.5 w-3.5" />}{result.message}</span>}
      </div>
    );
  };

  const Row = ({ title, desc, on, onClick }: { title: string; desc?: string; on: boolean; onClick: () => void }) => (
    <div className="flex items-center justify-between gap-4 p-4 rounded-xl border">
      <div><p className="text-sm font-medium">{title}</p>{desc && <p className="text-xs text-gray-400 mt-0.5">{desc}</p>}</div>
      <Switch checked={on} onCheckedChange={onClick} label={title} />
    </div>
  );

  const saveForm = async (message?: string) => {
    setSaving(true);
    try {
      await save({
        platform_name: form.platform_name,
        currency: 'USD',
        currency_rate: 1,
        platform_domain: form.platform_domain,
        default_locale: form.default_locale,
        logo_url: form.logo_url,
        primary_color: form.primary_color,
        notify_new_teacher: form.notify_new_teacher,
        notify_payment: form.notify_payment,
        notify_exam_submitted: form.notify_exam_submitted,
        notify_low_storage: form.notify_low_storage,
        registration_enabled: form.registration_enabled,
        teacher_registration_enabled: form.teacher_registration_enabled,
        maintenance_mode: form.maintenance_mode,
        two_factor_enabled: form.two_factor_enabled,
        captcha_enabled: form.captcha_enabled,
        paytabs_profile_id: form.paytabs_profile_id,
        // Secrets are never sent empty: the server keeps the stored value
        // unless a new one is provided, so an untouched field can't wipe it.
        ...(form.paytabs_server_key?.trim() ? { paytabs_server_key: form.paytabs_server_key } : {}),
        paytabs_enabled: form.paytabs_enabled,
        paytabs_test_mode: form.paytabs_test_mode,
        paypal_client_id: form.paypal_client_id,
        ...(form.paypal_secret?.trim() ? { paypal_secret: form.paypal_secret } : {}),
        paypal_enabled: form.paypal_enabled,
        paypal_test_mode: form.paypal_test_mode,
        // Freemius — the only platform-subscription gateway. Secrets (API
        // token / secret key / public key) live ONLY in the environment and
        // are never sent from here.
        freemius_enabled: form.freemius_enabled,
        freemius_product_id: form.freemius_product_id,
        freemius_api_url: form.freemius_api_url,
        freemius_checkout_url: form.freemius_checkout_url,
        freemius_sandbox: form.freemius_sandbox,
        student_mobile_app: form.student_mobile_app,
        student_app_hidden: form.student_app_hidden,
        student_app_android_url: form.student_app_android_url,
        student_app_ios_url: form.student_app_ios_url,
        // Public tracking IDs (never secrets): Meta Pixel + Google Analytics.
        analytics_enabled: form.analytics_enabled,
        meta_pixel_id: form.meta_pixel_id,
        meta_domain_verification: form.meta_domain_verification,
        ga_measurement_id: form.ga_measurement_id,
        gtm_container_id: form.gtm_container_id,
        // Teacher free trial: master switch + limited "first N teachers" offer.
        teacher_trial_enabled: form.teacher_trial_enabled,
        teacher_trial_offer_enabled: form.teacher_trial_offer_enabled,
        teacher_trial_offer_seats: Number(form.teacher_trial_offer_seats) || 50,
        teacher_trial_offer_text: form.teacher_trial_offer_text,
      });
      toast.success(message || t('تم الحفظ — التغييرات سارية في كل المنصة', 'Saved — changes apply everywhere'));
    } catch {
      toast.error(t('فشل الحفظ', 'Save failed'));
    }
    setSaving(false);
  };

  const uploadLogo = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      const res = await fetch(`${API}/upload`, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: fd,
      });
      if (!res.ok) throw new Error();
      const d = await res.json();
      setForm({ ...form, logo_url: d.url });
      await save({ logo_url: d.url });
      await load();
      toast.success(t('تم رفع الشعار — يظهر في كل المنصة فوراً', 'Logo uploaded — visible everywhere now'));
    } catch {
      toast.error(t('فشل الرفع (PNG/JPG/SVG حتى 5MB)', 'Upload failed (PNG/JPG/SVG up to 5MB)'));
    }
    setUploading(false);
    if (fileRef.current) fileRef.current.value = '';
  };

  return (
    <div className="max-w-6xl mx-auto">
      <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ backgroundColor: '#0d948812' }}>
            <Settings className="h-6 w-6" style={{ color: '#0d9488' }} />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('الإعدادات', 'Settings')}</h1>
            <p className="text-muted-foreground mt-1">{t('إعدادات المنصة — العملة واللغة والشعار تطبق في كل مكان تلقائياً', 'Platform settings — currency, language & logo apply everywhere automatically')}</p>
          </div>
      </div>

      {/* ─── المفتاح الرئيسي للتجربة المجانية (أعلى الصفحة دائمًا) ─── */}
      <div className={cn('mb-5 rounded-2xl border-2 p-5', form.teacher_trial_enabled ? 'border-teal-200 bg-teal-50/60' : 'border-amber-300 bg-amber-50/70')}>
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="flex items-start gap-3">
            <span className={cn('flex h-11 w-11 shrink-0 items-center justify-center rounded-xl', form.teacher_trial_enabled ? 'bg-teal-500/15 text-teal-700' : 'bg-amber-500/15 text-amber-700')}>
              <Gift className="h-5 w-5" />
            </span>
            <div>
              <h2 className="font-bold">{t('تفعيل / إيقاف التجربة المجانية للمعلمين', 'Enable / disable the teacher free trial')}</h2>
              <p className="mt-0.5 max-w-2xl text-sm text-gray-600">
                {t('القرار الأعلى أولوية: عند الإيقاف تختفي كل نصوص وأزرار التجربة فورًا في كل الصفحات، ويرفض الخادم منح أي تجربة جديدة عبر البريد أو Google أو الـAPI، ويبقى حساب المعلم بحالة «بانتظار الدفع» حتى تأكيد اشتراك مدفوع عبر Freemius. التجارب والاشتراكات الجارية لا تتأثر.',
                  'Highest-priority switch: when off, all trial copy and buttons disappear instantly site-wide, the server refuses every new trial (email, Google, API) and accounts stay “pending payment” until a paid Freemius subscription is confirmed. Running trials/subscriptions are never affected.')}
              </p>
              <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
                <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-bold text-white', form.teacher_trial_enabled ? 'bg-teal-600' : 'bg-amber-500')}>
                  {form.teacher_trial_enabled ? t('التجربة مفعّلة', 'Trial ON') : t('التجربة موقوفة', 'Trial OFF')}
                </span>
                <span className="rounded-full border bg-white px-2.5 py-1 text-gray-600">{t('مدة التجربة', 'Trial length')}: {settings.trial_days} {t('يوم', 'days')}</span>
                <span className="rounded-full border bg-white px-2.5 py-1 text-gray-600">{t('التوفر الآن', 'Availability now')}: {settings.trial_available ? t('متاحة', 'Available') : t('غير متاحة', 'Unavailable')}</span>
              </div>
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-2 sm:flex-col sm:items-end">
            <Switch checked={!!form.teacher_trial_enabled} onCheckedChange={(v) => setForm({ ...form, teacher_trial_enabled: v })} label={t('تفعيل / إيقاف التجربة المجانية للمعلمين', 'Enable / disable the teacher free trial')} />
            <Button size="sm" className="gap-1.5 rounded-full" disabled={saving} onClick={() => saveForm(t('تم تحديث المفتاح الرئيسي للتجربة المجانية', 'Master trial switch updated'))}>
              {saving ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Save className="h-3.5 w-3.5" />}{t('حفظ', 'Save')}
            </Button>
          </div>
        </div>
      </div>

      <div className="flex gap-6 flex-col lg:flex-row">
        <div className="lg:w-52 shrink-0">
          <div className="bg-white rounded-xl border shadow-sm p-1.5 sticky top-20 space-y-0.5">
            {sections.map((s) => (
              <button key={s.id} onClick={() => setSection(s.id)}
                className={cn('w-full flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all',
                  section === s.id ? 'bg-teal-50 text-teal-700 border border-teal-200' : 'text-gray-600 hover:bg-gray-50')}>
                <s.icon className="h-4 w-4" /><span>{s.label}</span>
              </button>
            ))}
          </div>
        </div>

        <div className="flex-1 space-y-6">
          {section === 'trial' && (
            <div className="space-y-6">
              <Card><CardContent className="space-y-5 p-6">
                <div className="flex items-center justify-between gap-4">
                  <div>
                    <h2 className="flex items-center gap-2 font-semibold text-lg"><Gift className="h-5 w-5 text-teal-600" />{t('عرض أول معلمين — مقاعد محدودة', 'First-teachers limited offer')}</h2>
                    <p className="mt-0.5 text-sm text-gray-400">{t('شريط تسويقي حقيقي بعدد مقاعد من قاعدة البيانات (ليس رقمًا عشوائيًا). يُخصم مقعد واحد فقط عند منح تجربة فعلية لمعلم مؤهل، ويختفي الشريط فورًا عند إيقاف المفتاح الرئيسي.', 'A real DB-backed seat banner (never a random number). One seat is consumed only when an eligible teacher actually gets a trial, and the banner disappears the instant the master switch is turned off.')}</p>
                  </div>
                  <Switch checked={!!form.teacher_trial_offer_enabled} onCheckedChange={(v) => setForm({ ...form, teacher_trial_offer_enabled: v })} label={t('تفعيل شريط المقاعد المحدودة', 'Enable limited-seats banner')} />
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                  <div className="rounded-xl border bg-slate-50/70 p-4">
                    <p className="text-xs text-gray-500">{t('المقاعد المستهلكة', 'Seats used')}</p>
                    <p className="mt-1 text-2xl font-black text-teal-700">{settings.teacher_trial_offer_seats_used}</p>
                  </div>
                  <div className="rounded-xl border bg-slate-50/70 p-4">
                    <p className="text-xs text-gray-500">{t('المقاعد المتبقية', 'Seats remaining')}</p>
                    <p className="mt-1 text-2xl font-black text-emerald-600">{settings.teacher_trial_offer_seats_remaining}</p>
                  </div>
                  <div className="rounded-xl border bg-slate-50/70 p-4">
                    <p className="text-xs text-gray-500">{t('الإجمالي', 'Total')}</p>
                    <p className="mt-1 text-2xl font-black text-slate-700">{form.teacher_trial_offer_seats || 50}</p>
                  </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="space-y-2">
                    <Label>{t('العدد الإجمالي للمقاعد', 'Total seats')}</Label>
                    <Input type="number" min={1} value={form.teacher_trial_offer_seats ?? 50} onChange={(e) => setForm({ ...form, teacher_trial_offer_seats: Number(e.target.value) })} className="h-11" dir="ltr" />
                    <p className="text-xs text-gray-400">{t('افتراضيًا 50 معلمًا. لا يمكن ضبطه أقل من عدد المقاعد المستهلكة فعليًا.', 'Defaults to 50. Cannot be set below the number of seats already consumed.')}</p>
                  </div>
                  <div className="space-y-2">
                    <Label>{t('نص العرض (رموز: {seats} {remaining} {days})', 'Offer text (tokens: {seats} {remaining} {days})')}</Label>
                    <textarea
                      value={form.teacher_trial_offer_text ?? ''}
                      onChange={(e) => setForm({ ...form, teacher_trial_offer_text: e.target.value })}
                      rows={3}
                      className="w-full rounded-md border px-3 py-2 text-sm"
                      dir="rtl"
                    />
                  </div>
                </div>

                <div className="rounded-xl border border-dashed border-teal-200 bg-teal-50/50 p-4">
                  <p className="text-xs font-semibold text-teal-700">{t('معاينة الشريط', 'Banner preview')}</p>
                  <p className="mt-1 text-sm text-teal-900">{settings.teacher_trial_offer_text_rendered || t('(سيظهر النص بعد الحفظ)', '(text appears after saving)')}</p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  <Button onClick={() => saveForm(t('تم حفظ إعدادات العرض المحدود', 'Limited-offer settings saved'))} disabled={saving} className="gap-2 rounded-full">
                    {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ', 'Save')}
                  </Button>
                  {form.teacher_trial_offer_enabled && settings.trial_available && settings.teacher_trial_offer_seats_remaining > 0 && (
                    <Badge className="border-0 bg-green-100 text-green-700"><Users className="me-1 h-3.5 w-3.5" />{t('الشريط ظاهر الآن على الموقع', 'Banner is live on the site')}</Badge>
                  )}
                  {form.teacher_trial_offer_enabled && (settings.teacher_trial_offer_seats_remaining <= 0) && (
                    <Badge className="border-0 bg-amber-100 text-amber-700">{t('نفدت المقاعد — لن تُمنح تجارب عبر العرض', 'Seats exhausted — no trials via the offer')}</Badge>
                  )}
                  {form.teacher_trial_offer_enabled && !settings.trial_available && (
                    <Badge className="border-0 bg-rose-100 text-rose-700">{t('المفتاح الرئيسي موقوف — الشريط مخفي', 'Master switch OFF — banner hidden')}</Badge>
                  )}
                </div>
              </CardContent></Card>

              <div className="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-900/80">
                {t('مهم: العداد محمي بمعاملة وقيد قاعدة بيانات (صف مقفل) فلا يمكن تجاوز الحد عند التسجيل المتزامن. حساب الأدمن/الاختبار لا يستهلك مقعدًا. عند نفاد المقاعد تظهر رسالة أن الاشتراك المدفوع متاح.', 'Important: the counter is protected by a transaction and DB constraint (locked row), so concurrent signups can never exceed the limit. Admin/test accounts never consume a seat. When seats run out, the message states that the paid subscription is available.')}
              </div>
            </div>
          )}

          {section === 'general' && (
            <Card><CardContent className="space-y-5 p-6">
              <h2 className="font-semibold text-lg">{t('الإعدادات العامة', 'General settings')}</h2>
              <p className="text-sm text-gray-400 -mt-2">{t('اسم المنصة يظهر في الشريط الجانبي والموقع العام، واللغة والعملة تطبقان في كل المنصة', 'Platform name shows in sidebar & public sites; language & currency apply globally')}</p>
              <div className="grid sm:grid-cols-2 gap-5">
                <div><Label>{t('اسم المنصة', 'Platform name')}</Label><Input value={form.platform_name || ''} onChange={(e) => setForm({ ...form, platform_name: e.target.value })} className="h-11 mt-1" /></div>
                <div><Label>{t('اللغة الافتراضية', 'Default language')}</Label>
                  <Select value={form.default_locale || 'ar'} onChange={(e) => setForm({ ...form, default_locale: e.target.value })} className="h-11 mt-1">
                    <option value="ar">العربية</option>
                    <option value="en">English</option>
                  </Select>
                </div>
                <div className="p-4 rounded-xl bg-amber-50 border border-amber-100 text-sm text-amber-700 flex items-center gap-2">
                  <Check className="h-4 w-4 shrink-0" />{t('العملة الرسمية للمنصة: الدولار الأمريكي (USD) فقط', 'Platform currency: US Dollar (USD) only')}
                </div>
                <div><Label>{t('دومين المنصة الأساسي (لربط الدومينات)', 'Platform domain (for custom domain CNAME)')}</Label><Input value={form.platform_domain || 'lms.example.com'} onChange={(e) => setForm({ ...form, platform_domain: e.target.value })} className="h-11 mt-1" dir="ltr" placeholder="lms.example.com" /><p className="text-xs text-gray-400 mt-1">{t('المعلمون يوجهون سجل CNAME لدومينهم إلى هذا العنوان', 'Teachers point their CNAME record to this address')}</p></div>
              </div>
              <div className="p-3 rounded-xl bg-teal-50 text-teal-700 text-sm flex items-center gap-2">
                <Check className="h-4 w-4 shrink-0" />
                {t('جميع الأسعار في المنصة تعرض الآن بـ', 'All prices across the platform now show in')} <b>{form.currency || 'USD'}</b>
              </div>
              <Button onClick={() => saveForm()} disabled={saving} className="gap-2 rounded-full">{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ', 'Save')}</Button>
            </CardContent></Card>
          )}

          {section === 'branding' && (
            <Card><CardContent className="p-6 space-y-5">
              <h2 className="font-semibold text-lg">{t('العلامة التجارية', 'Branding')}</h2>
              <p className="text-sm text-gray-400 -mt-2">{t('الشعار يظهر في الشريط الجانبي، صفحة الهبوط، ومواقع المعلمين', 'Logo appears in sidebar, landing page & teacher sites')}</p>
              <div className="flex items-center gap-5">
                {form.logo_url ? (
                  <img src={form.logo_url} alt="logo" className="h-24 w-24 rounded-2xl border object-cover bg-gray-50" />
                ) : (
                  <Image src="/logo.png" alt="logo" width={96} height={96} className="h-24 w-24 rounded-2xl border object-cover bg-gray-50" />
                )}
                <div>
                  <p className="text-sm font-medium">{t('شعار المنصة', 'Platform logo')}</p>
                  <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp" className="hidden" onChange={uploadLogo} />
                  <div className="flex gap-2 mt-2">
                    <Button variant="outline" size="sm" onClick={() => fileRef.current?.click()} disabled={uploading} className="gap-1.5 rounded-full">
                      {uploading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Upload className="h-3.5 w-3.5" />}{uploading ? t('جاري الرفع...', 'Uploading...') : t('رفع شعار', 'Upload logo')}
                    </Button>
                    {form.logo_url && (
                      <Button variant="ghost" size="sm" className="text-red-500 gap-1 rounded-full" onClick={async () => { setForm({ ...form, logo_url: null }); try { await save({ logo_url: null }); toast.success(t('تمت الإزالة', 'Removed')); } catch { toast.error('Failed'); } }}>
                        <X className="h-3.5 w-3.5" />{t('إزالة', 'Remove')}
                      </Button>
                    )}
                  </div>
                </div>
              </div>
              <div><Label>{t('اللون الأساسي', 'Primary color')}</Label><div className="flex items-center gap-2 mt-1"><input type="color" value={form.primary_color || '#0d9488'} onChange={(e) => setForm({ ...form, primary_color: e.target.value })} className="h-11 w-11 rounded-lg border" /><Input value={form.primary_color || '#0d9488'} onChange={(e) => setForm({ ...form, primary_color: e.target.value })} className="h-11" /></div></div>
              <Button onClick={() => saveForm()} disabled={saving} className="gap-2 rounded-full">{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ', 'Save')}</Button>
            </CardContent></Card>
          )}

          {section === 'payments' && (
            <div className="space-y-6">
              <Card><CardContent className="p-6 space-y-5">
                <div className="flex items-center justify-between">
                  <div>
                    <h2 className="font-semibold text-lg">Freemius</h2>
                    <p className="text-sm text-gray-400">{t('بوابة اشتراكات المنصة الوحيدة (تتولى الضرائب والتحصيل والتجديد)', 'The only platform-subscription gateway (MoR: tax, billing & renewals)')}</p>
                  </div>
                  <Switch checked={!!form.freemius_enabled} onCheckedChange={(v) => setForm({ ...form, freemius_enabled: v })} label="Freemius" />
                </div>
                <div className="grid sm:grid-cols-2 gap-4">
                  <div className="space-y-2"><Label>Product ID</Label><Input value={form.freemius_product_id || ''} onChange={(e) => setForm({ ...form, freemius_product_id: e.target.value })} className="h-11" dir="ltr" placeholder="12345" /></div>
                  <div className="space-y-2"><Label>{t('بيئة الاختبار', 'Sandbox')}</Label>
                    <div className="flex items-center h-11"><Switch checked={!!form.freemius_sandbox} onCheckedChange={(v) => setForm({ ...form, freemius_sandbox: v })} label={t('Sandbox', 'Sandbox')} /></div>
                  </div>
                </div>
                <div className="grid sm:grid-cols-2 gap-4">
                  <div className="space-y-2"><Label>API URL</Label><Input value={form.freemius_api_url || 'https://api.freemius.com'} onChange={(e) => setForm({ ...form, freemius_api_url: e.target.value })} className="h-11" dir="ltr" /></div>
                  <div className="space-y-2"><Label>{t('رابط الدفع (Checkout URL)', 'Checkout URL')}</Label><Input value={form.freemius_checkout_url || 'https://checkout.freemius.com'} onChange={(e) => setForm({ ...form, freemius_checkout_url: e.target.value })} className="h-11" dir="ltr" /></div>
                </div>
                <div className="space-y-1">
                  <Label>{t('المفاتيح السرية (من البيئة فقط)', 'Secrets (from environment only)')}</Label>
                  <div className="grid sm:grid-cols-3 gap-2 text-xs font-mono">
                    <div className="p-3 rounded-xl border bg-gray-50">{form.freemius_api_token_set ? 'FREEMIUS_API_TOKEN ✓' : 'FREEMIUS_API_TOKEN ✗'}</div>
                    <div className="p-3 rounded-xl border bg-gray-50">{form.freemius_secret_key_set ? 'FREEMIUS_PRODUCT_SECRET_KEY ✓' : 'FREEMIUS_PRODUCT_SECRET_KEY ✗'}</div>
                    <div className="p-3 rounded-xl border bg-gray-50">{form.freemius_public_key_set ? 'FREEMIUS_PUBLIC_KEY ✓' : 'FREEMIUS_PUBLIC_KEY ✗'}</div>
                  </div>
                  <p className="text-xs text-gray-400">{t('تُضاف في ملف .env على الخادم ولا تُخزَّن في قاعدة البيانات. راجع FREEMIUS_SETUP.md.', 'Set in the server .env — never stored in the database. See FREEMIUS_SETUP.md.')}</p>
                </div>
                <div className="flex items-center gap-2 flex-wrap">
                  <Button onClick={() => saveForm(t('تم حفظ إعدادات Freemius', 'Freemius settings saved'))} disabled={saving} className="gap-2 rounded-full">{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ وتفعيل', 'Save & activate')}</Button>
                  {form.freemius_enabled && form.freemius_product_id && form.freemius_api_token_set && form.freemius_secret_key_set && <Badge className="bg-green-100 text-green-700 border-0">{t('جاهز للربط', 'Ready to connect')}</Badge>}
                </div>
              </CardContent></Card>

              <div className="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-900/80">
                {t('ملاحظة: مدفوعات الطلاب وحجوزات المعلمين (PayTabs / PayPal أدناه) منفصلة تمامًا عن اشتراكات المنصة ولا تمر عبر Freemius.', 'Note: student payments and teacher bookings (PayTabs / PayPal below) are completely separate from platform subscriptions and never routed through Freemius.')}
              </div>

              <Card><CardContent className="p-6 space-y-5">
                <div className="flex items-center justify-between">
                  <div>
                    <h2 className="font-semibold text-lg">PayTabs — {t('الحجوزات', 'Bookings')}</h2>
                    <p className="text-sm text-gray-400">{t('قبول مدى وفيزا وماستركارد لدفع حجوزات الطلاب', 'Accept Mada, Visa & Mastercard for student booking payments')}</p>
                  </div>
                  <Switch checked={!!form.paytabs_enabled} onCheckedChange={(v) => setForm({ ...form, paytabs_enabled: v })} label="PayTabs" />
                </div>
                <div className="grid sm:grid-cols-2 gap-4">
                  <div className="space-y-2"><Label>Profile ID</Label><Input value={form.paytabs_profile_id || ''} onChange={(e) => setForm({ ...form, paytabs_profile_id: e.target.value })} className="h-11" dir="ltr" placeholder="123456" /></div>
                  <div className="space-y-2"><Label>Server Key</Label><Input type="password" value={form.paytabs_server_key || ''} onChange={(e) => setForm({ ...form, paytabs_server_key: e.target.value })} className="h-11" dir="ltr" placeholder="S2J..." /></div>
                </div>
                <div className="flex items-center justify-between p-3 rounded-xl border">
                  <div><p className="text-sm font-medium">{t('وضع الاختبار', 'Test mode')}</p><p className="text-xs text-gray-400">{t('Sandbox للاختبار قبل الربط الفعلي', 'Sandbox for testing before going live')}</p></div>
                  <Switch checked={!!form.paytabs_test_mode} onCheckedChange={(v) => setForm({ ...form, paytabs_test_mode: v })} label={t('وضع الاختبار', 'Test mode')} />
                </div>
                <div className="flex items-center gap-2 flex-wrap">
                  <Button onClick={() => saveForm(t('تم حفظ إعدادات PayTabs', 'PayTabs settings saved'))} disabled={saving} className="gap-2 rounded-full">{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ وتفعيل', 'Save & activate')}</Button>
                  <TestButton gateway="paytabs" />
                  {form.paytabs_enabled && (form.paytabs_profile_id && form.paytabs_server_key) && <Badge className="bg-green-100 text-green-700 border-0">{t('جاهز للربط', 'Ready to connect')}</Badge>}
                </div>
              </CardContent></Card>

              <Card><CardContent className="p-6 space-y-5">
                <div className="flex items-center justify-between">
                  <div>
                    <h2 className="font-semibold text-lg">PayPal — {t('الحجوزات', 'Bookings')}</h2>
                    <p className="text-sm text-gray-400">{t('استقبل فيزا وماستركارد لدفع حجوزات الطلاب عبر PayPal', 'Accept Visa & Mastercard for student booking payments via PayPal')}</p>
                  </div>
                  <Switch checked={!!form.paypal_enabled} onCheckedChange={(v) => setForm({ ...form, paypal_enabled: v })} label="PayPal" />
                </div>
                <div className="grid sm:grid-cols-2 gap-4">
                  <div className="space-y-2"><Label>Client ID</Label><Input value={form.paypal_client_id || ''} onChange={(e) => setForm({ ...form, paypal_client_id: e.target.value })} className="h-11" dir="ltr" placeholder="A..." /></div>
                  <div className="space-y-2"><Label>Secret</Label><Input type="password" value={form.paypal_secret || ''} onChange={(e) => setForm({ ...form, paypal_secret: e.target.value })} className="h-11" dir="ltr" placeholder="E..." /></div>
                </div>
                <div className="flex items-center justify-between p-3 rounded-xl border">
                  <div><p className="text-sm font-medium">{t('وضع الاختبار (Sandbox)', 'Test mode (Sandbox)')}</p><p className="text-xs text-gray-400">{t('استخدم حساب Paypal Developer التجريبي', 'Use a PayPal Developer sandbox account')}</p></div>
                  <Switch checked={!!form.paypal_test_mode} onCheckedChange={(v) => setForm({ ...form, paypal_test_mode: v })} label={t('وضع الاختبار (Sandbox)', 'Test mode (Sandbox)')} />
                </div>
                <div className="flex items-center gap-2 flex-wrap">
                  <Button onClick={() => saveForm(t('تم حفظ إعدادات PayPal', 'PayPal settings saved'))} disabled={saving} className="gap-2 rounded-full">{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ وتفعيل', 'Save & activate')}</Button>
                  <TestButton gateway="paypal" />
                  {form.paypal_enabled && (form.paypal_client_id && form.paypal_secret) && <Badge className="bg-green-100 text-green-700 border-0">{t('جاهز للربط', 'Ready to connect')}</Badge>}
                </div>
              </CardContent></Card>
            </div>
          )}

          {section === 'notifications' && (
            <Card><CardContent className="p-6 space-y-4">
              <h2 className="font-semibold text-lg">{t('الإشعارات', 'Notifications')}</h2>
              <p className="text-sm text-gray-400 -mt-2">{t('تُحفظ هذه الإعدادات في الخادم وتمثل إشعارات المنصة الفعلية', 'These settings are stored on the server and drive real platform notifications')}</p>
              <Row title={t('إشعار معلم جديد', 'New teacher notification')} desc={t('عند تسجيل معلم جديد في المنصة', 'When a new teacher registers')} on={!!form.notify_new_teacher} onClick={() => setForm({ ...form, notify_new_teacher: !form.notify_new_teacher })} />
              <Row title={t('إشعارات الدفع', 'Payment notifications')} desc={t('عند إتمام أي عملية دفع أو اشتراك', 'When a payment or subscription completes')} on={!!form.notify_payment} onClick={() => setForm({ ...form, notify_payment: !form.notify_payment })} />
              <Row title={t('إشعار تقديم اختبار', 'Exam submitted notification')} desc={t('عند تقديم الطالب لاختبار', 'When a student submits an exam')} on={!!form.notify_exam_submitted} onClick={() => setForm({ ...form, notify_exam_submitted: !form.notify_exam_submitted })} />
              <Row title={t('تنبيه مساحة التخزين', 'Low storage alert')} desc={t('عند اقتراب مساحة التخزين من الامتلاء', 'When storage is nearly full')} on={!!form.notify_low_storage} onClick={() => setForm({ ...form, notify_low_storage: !form.notify_low_storage })} />
              <Button onClick={() => saveForm(t('تم حفظ الإشعارات', 'Notifications saved'))} disabled={saving} className="gap-2 rounded-full">{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ', 'Save')}</Button>
            </CardContent></Card>
          )}

          {section === 'security' && (
            <Card><CardContent className="p-6 space-y-4">
              <h2 className="font-semibold text-lg">{t('الأمان', 'Security')}</h2>
              <p className="text-sm text-gray-400 -mt-2">{t('إعدادات مفعلة فعلياً في الخادم — تسجيل المعلمين ووضع الصيانة يعملان الآن', 'Enforced on the server — registration & maintenance mode are live')}</p>
              <Row title={t('تمكين تسجيل المعلمين', 'Teacher registration')} desc={t('عند الإيقاف يرفض الخادم إنشاء حسابات جديدة', 'When off, the server rejects new signups')} on={!!form.registration_enabled} onClick={() => setForm({ ...form, registration_enabled: !form.registration_enabled })} />
              <Row title={t('تسجيل معلم جديد بدون دعوة', 'New teacher signup without invitation')} desc={t('عند الإيقاف يجب أن يحصل المعلم على دعوة من مالك منصة قبل إنشاء حسابه', 'When off, teachers must receive an invitation before creating an account')} on={!!form.teacher_registration_enabled} onClick={() => setForm({ ...form, teacher_registration_enabled: !form.teacher_registration_enabled })} />
              <Row title={t('وضع الصيانة', 'Maintenance mode')} desc={t('يعطل المنصة عن الجميع عدا الأدمن — يظهر 503', 'Blocks everyone except admins — returns 503')} on={!!form.maintenance_mode} onClick={() => setForm({ ...form, maintenance_mode: !form.maintenance_mode })} />
              <Row title={t('المصادقة الثنائية', 'Two-factor authentication')} desc={t('طلب رمز إضافي عند الدخول', 'Require an extra code at login')} on={!!form.two_factor_enabled} onClick={() => setForm({ ...form, two_factor_enabled: !form.two_factor_enabled })} />
              <Button onClick={() => saveForm(t('تم حفظ إعدادات الأمان', 'Security settings saved'))} disabled={saving} className="gap-2 rounded-full">{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ', 'Save')}</Button>
            </CardContent></Card>
          )}

          {section === 'student_app' && (
            <Card><CardContent className="p-6 space-y-5">
              <div className="flex items-center justify-between">
                <div>
                  <h2 className="font-semibold text-lg">{t('تطبيق الطلاب — Student Mobile App', 'Student Mobile App')}</h2>
                  <p className="text-sm text-gray-400 -mt-0.5">{t('التطبيق الرسمي للطلاب. عند التعطيل يرى المعلم "قيد التطوير" وتختفي روابط المتاجر.', 'Official student app. When disabled, teachers see "under development" and store links disappear.')}</p>
                </div>
                <Switch checked={!!form.student_mobile_app} onCheckedChange={(v) => setForm({ ...form, student_mobile_app: v })} label={t('تطبيق الطلاب', 'Student App')} />
              </div>

              <div className="p-4 rounded-xl border bg-slate-50">
                <div className="flex items-center justify-between gap-4">
                  <div>
                    <p className="text-sm font-medium flex items-center gap-2"><EyeOff className="h-4 w-4 text-slate-500" />{t('إخفاء التطبيق من الواجهة', 'Hide the app from the UI')}</p>
                    <p className="text-xs text-gray-400 mt-1">{t('يخفي وجود التطبيق كلياً (الخطط، الأسعار، الدفع، لوحة المعلم) حتى تُطلق التطبيق على المتاجر. كبسة واحدة تُظهره.', 'Hides the app entirely (plans, pricing, checkout, teacher panel) until you launch it on the stores. One click shows it again.')}</p>
                  </div>
                  <Switch checked={!!form.student_app_hidden} onCheckedChange={(v) => setForm({ ...form, student_app_hidden: v })} label={t('إخفاء التطبيق', 'Hide app')} />
                </div>
                {form.student_app_hidden && <Badge className="mt-3 bg-slate-200 text-slate-700 border-0">{t('مخفي — لا يظهر أي ذكر للتطبيق في المنصة', 'Hidden — the app is not mentioned anywhere in the platform')}</Badge>}
              </div>

              <div className="p-3 rounded-xl bg-teal-50 text-teal-700 text-sm flex items-center gap-2">
                <Smartphone className="h-4 w-4 shrink-0" />
                {t('بعد التفعيل: يظهر للمعلم "كود المعلم" + زر النسخ + روابط المتاجر + QR. كود واحد ثابت لكل معلم.', 'When enabled: teachers see their Teacher Code + copy button + store links + QR. One stable code per teacher.')}
              </div>

              <div className="space-y-2">
                <Label>{t('رابط Google Play (Android)', 'Google Play URL (Android)')}</Label>
                <Input value={form.student_app_android_url || ''} onChange={(e) => setForm({ ...form, student_app_android_url: e.target.value })} className="h-11" dir="ltr" placeholder="https://play.google.com/store/apps/details?id=..." />
                <p className="text-xs text-gray-400">{t('إذا تُرك فارغاً لن يظهر زر Android للمعلم. لا يُخزَّن أي رابط داخل الكود.', 'If empty, the Android button is hidden for teachers. No link is hardcoded.')}</p>
              </div>

              <div className="space-y-2">
                <Label>{t('رابط Apple App Store (iOS)', 'Apple App Store URL (iOS)')}</Label>
                <Input value={form.student_app_ios_url || ''} onChange={(e) => setForm({ ...form, student_app_ios_url: e.target.value })} className="h-11" dir="ltr" placeholder="https://apps.apple.com/app/id..." />
                <p className="text-xs text-gray-400">{t('إذا تُرك فارغاً لن يظهر زر iOS للمعلم.', 'If empty, the iOS button is hidden for teachers.')}</p>
              </div>

              <div className="flex items-center gap-2 flex-wrap">
                <Button onClick={() => saveForm(t('تم حفظ إعدادات تطبيق الطلاب — التغييرات تظهر للمعلمين فوراً', 'Student app settings saved — teachers see changes immediately'))} disabled={saving} className="gap-2 rounded-full">{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ', 'Save')}</Button>
                {form.student_mobile_app && <Badge className="bg-green-100 text-green-700 border-0">{t('مفعّل — المعلمون يرون تطبيق الطلاب', 'Enabled — teachers see the student app')}</Badge>}
                {!form.student_mobile_app && <Badge className="bg-amber-100 text-amber-700 border-0">{t('قيد التطوير — المعلمون يرون "قيد التطوير"', 'Under development — teachers see "under development"')}</Badge>}
              </div>
            </CardContent></Card>
          )}

          {section === 'analytics' && (
            <Card><CardContent className="p-6 space-y-5">
              <div className="flex items-center justify-between">
                <div>
                  <h2 className="font-semibold text-lg flex items-center gap-2"><BarChart3 className="h-5 w-5 text-teal-600" />{t('التحليلات والتتبّع', 'Analytics & tracking')}</h2>
                  <p className="text-sm text-gray-400 -mt-0.5">{t('مفاتيح التتبّع العامة (Meta Pixel و Google Analytics). تُطبَّق فوراً على المنصة وصفحات المعلمين بدون إعادة نشر.', 'Public tracking IDs (Meta Pixel & Google Analytics). Applied instantly platform-wide and on teacher pages — no redeploy needed.')}</p>
                </div>
                <Switch checked={form.analytics_enabled !== false} onCheckedChange={(v) => setForm({ ...form, analytics_enabled: v })} label={t('تفعيل التتبّع', 'Enable tracking')} />
              </div>

              <div className="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-900/80 flex items-start gap-2">
                <Shield className="h-4 w-4 mt-0.5 shrink-0" />
                {t('لا تُكوّن هذه المفاتيح أسراراً — تُخزَّن وتُعرض عبر الـAPI. المفاتيح السرية للبوابات تُضاف في بيئة الخادم فقط.', 'These IDs are not secrets — they are stored and shown via the API. Gateway secrets stay in the server environment.')}
              </div>

              <div className="grid sm:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>{t('معرّف بكسل ميتا (Meta Pixel ID)', 'Meta Pixel ID')}</Label>
                  <Input value={form.meta_pixel_id || ''} onChange={(e) => setForm({ ...form, meta_pixel_id: e.target.value })} className="h-11" dir="ltr" placeholder="1234567890123456" inputMode="numeric" />
                  <p className="text-xs text-gray-400">{t('يُلتقط تلقائياً عند التسجيل والشراء (Lead / Purchase).', 'Auto-fires on registration and purchase (Lead / Purchase).')}</p>
                </div>
                <div className="space-y-2">
                  <Label>{t('معرّف Google Analytics 4', 'GA4 Measurement ID')}</Label>
                  <Input value={form.ga_measurement_id || ''} onChange={(e) => setForm({ ...form, ga_measurement_id: e.target.value })} className="h-11" dir="ltr" placeholder="G-XXXXXXXXXX" />
                  <p className="text-xs text-gray-400">{t('يُرسل PageView عند كل تنقّل بين الصفحات.', 'Sends a PageView on every navigation.')}</p>
                </div>
              </div>

              <div className="grid sm:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>{t('معرّف حاوية Google Tag Manager', 'Google Tag Manager Container ID')}</Label>
                  <Input value={form.gtm_container_id || ''} onChange={(e) => setForm({ ...form, gtm_container_id: e.target.value })} className="h-11" dir="ltr" placeholder="GTM-XXXXXXX" />
                  <p className="text-xs text-gray-400">{t('اختياري — للاستخدام المتقدم عبر مدير الوسوم.', 'Optional — for advanced use via Tag Manager.')}</p>
                </div>
                <div className="space-y-2">
                  <Label>{t('توثيق نطاق فيسبوك (Domain Verification)', 'Facebook domain verification')}</Label>
                  <Input value={form.meta_domain_verification || ''} onChange={(e) => setForm({ ...form, meta_domain_verification: e.target.value })} className="h-11" dir="ltr" placeholder="abc123def456..." />
                  <p className="text-xs text-gray-400">{t('القيمة من Meta Business → توثيق النطاق (بديل سجل DNS).', 'Value from Meta Business → domain verification (alternative to a DNS record).')}</p>
                </div>
              </div>

              <div className="flex items-center gap-2 flex-wrap">
                <Button onClick={() => saveForm(t('تم حفظ إعدادات التتبّع — سارية فوراً على كل الصفحات', 'Tracking settings saved — live on all pages immediately'))} disabled={saving} className="gap-2 rounded-full">{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ وتفعيل', 'Save & activate')}</Button>
                {form.analytics_enabled !== false && (form.meta_pixel_id?.trim() || form.ga_measurement_id?.trim()) && <Badge className="bg-green-100 text-green-700 border-0">{t('التتبّع مفعّل', 'Tracking active')}</Badge>}
                {form.analytics_enabled !== false && !form.meta_pixel_id?.trim() && !form.ga_measurement_id?.trim() && <Badge className="bg-amber-100 text-amber-700 border-0">{t('بانتظار المعرّفات', 'Awaiting IDs')}</Badge>}
              </div>
            </CardContent></Card>
          )}
        </div>
      </div>
    </div>
  );
}
