'use client';

import { useEffect, useRef, useState } from 'react';
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
import { Save, Settings2, Palette, Bell, Shield, CreditCard, Upload, Loader2, Check, X, KeyRound, BookOpen, ChevronDown, ExternalLink, Settings, Smartphone, EyeOff } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminSettingsPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const { settings, load, save, loaded } = useSettings();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [section, setSection] = useState('general');
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
    { id: 'general', label: t('عام', 'General'), icon: Settings2 },
    { id: 'branding', label: t('العلامة', 'Branding'), icon: Palette },
    { id: 'payments', label: t('بوابات الدفع', 'Payments'), icon: CreditCard },
    { id: 'notifications', label: t('الإشعارات', 'Notify'), icon: Bell },
    { id: 'security', label: t('الأمان', 'Security'), icon: Shield },
    { id: 'student_app', label: t('تطبيق الطلاب', 'Student App'), icon: Smartphone },
  ];

  const TestButton = ({ gateway }: { gateway: 'paytabs' | 'paypal' | 'paddle' | 'lemonsqueezy' }) => {
    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);
    return (
      <div className="flex items-center gap-2">
        <Button variant="outline" size="sm" className="gap-1.5 rounded-full" disabled={testing} onClick={async () => {
          setTesting(true); setResult(null);
          try {
            const res = await fetch(`${API}/payment-gateway-test`, {
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
        currency: 'JOD',
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
        manual_payment_enabled: form.manual_payment_enabled,
        manual_payment_label: form.manual_payment_label,
        manual_payment_account: form.manual_payment_account,
        manual_payment_instructions: form.manual_payment_instructions,
        paddle_enabled: form.paddle_enabled,
        paddle_environment: form.paddle_environment || 'sandbox',
        // Secrets are never sent empty: the server keeps the stored value.
        ...(form.paddle_client_token?.trim() ? { paddle_client_token: form.paddle_client_token } : {}),
        ...(form.paddle_api_key?.trim() ? { paddle_api_key: form.paddle_api_key } : {}),
        ...(form.paddle_webhook_secret?.trim() ? { paddle_webhook_secret: form.paddle_webhook_secret } : {}),
        student_mobile_app: form.student_mobile_app,
        student_app_hidden: form.student_app_hidden,
        student_app_android_url: form.student_app_android_url,
        student_app_ios_url: form.student_app_ios_url,
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
                  <Check className="h-4 w-4 shrink-0" />{t('العملة الرسمية للمنصة: الدينار الأردني (JOD) فقط', 'Platform currency: Jordanian Dinar (JOD) only')}
                </div>
                <div><Label>{t('دومين المنصة الأساسي (لربط الدومينات)', 'Platform domain (for custom domain CNAME)')}</Label><Input value={form.platform_domain || 'lms.example.com'} onChange={(e) => setForm({ ...form, platform_domain: e.target.value })} className="h-11 mt-1" dir="ltr" placeholder="lms.example.com" /><p className="text-xs text-gray-400 mt-1">{t('المعلمون يوجهون سجل CNAME لدومينهم إلى هذا العنوان', 'Teachers point their CNAME record to this address')}</p></div>
              </div>
              <div className="p-3 rounded-xl bg-teal-50 text-teal-700 text-sm flex items-center gap-2">
                <Check className="h-4 w-4 shrink-0" />
                {t('جميع الأسعار في المنصة تعرض الآن بـ', 'All prices across the platform now show in')} <b>{form.currency || 'JOD'}</b>
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
                  <img src="/logo.png" alt="logo" className="h-24 w-24 rounded-2xl border object-cover bg-gray-50" onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }} />
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
                    <h2 className="font-semibold text-lg">PayTabs</h2>
                    <p className="text-sm text-gray-400">{t('قبول مدى وفيزا وماستركارد عبر PayTabs', 'Accept Mada, Visa & Mastercard via PayTabs')}</p>
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
                    <h2 className="font-semibold text-lg">PayPal</h2>
                    <p className="text-sm text-gray-400">{t('استقبل فيزا وماستركارد من خلال حساب PayPal الخاص بك', 'Receive Visa & Mastercard through your own PayPal account')}</p>
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
                  {form.paypal_enabled && (form.paypal_client_id && form.paypal_secret) && <Badge className="bg-green-100 text-green-700 border-0">{t('جاهز للربط — يستقبل فيزا وماستركارد', 'Ready — accepts Visa & Mastercard')}</Badge>}
                </div>

              </CardContent></Card>

              <Card><CardContent className="p-6 space-y-5">
                <div className="flex items-center justify-between">
                  <div>
                    <h2 className="font-semibold text-lg">Paddle</h2>
                    <p className="text-sm text-gray-400">{t('بطاقة / محفظة / طرق دفع محلية عبر Paddle (تتولى الضرائب والتحصيل)', 'Card / wallet / local methods via Paddle (MoR handles tax & payouts)')}</p>
                  </div>
                  <Switch checked={!!form.paddle_enabled} onCheckedChange={(v) => setForm({ ...form, paddle_enabled: v })} label="Paddle" />
                </div>
                <div className="flex items-center justify-between p-3 rounded-xl border">
                  <div><p className="text-sm font-medium">{t('البيئة', 'Environment')}</p><p className="text-xs text-gray-400">{t('Sandbox للاختبار قبل الربط الفعلي', 'Sandbox for testing before going live')}</p></div>
                  <Select value={form.paddle_environment || 'sandbox'} onChange={(e) => setForm({ ...form, paddle_environment: e.target.value })} className="h-11">
                    <option value="sandbox">{t('Sandbox (اختبار)', 'Sandbox (test)')}</option>
                    <option value="live">{t('Live (إنتاج)', 'Live (production)')}</option>
                  </Select>
                </div>
                <div className="grid sm:grid-cols-2 gap-4">
                  <div className="space-y-2"><Label>{t('Client-side token', 'Client-side token')}</Label><Input type="password" value={form.paddle_client_token || ''} onChange={(e) => setForm({ ...form, paddle_client_token: e.target.value })} className="h-11" dir="ltr" placeholder="test_..." /></div>
                  <div className="space-y-2"><Label>{t('API Key (خلفي فقط)', 'API Key (backend only)')}</Label><Input type="password" value={form.paddle_api_key || ''} onChange={(e) => setForm({ ...form, paddle_api_key: e.target.value })} className="h-11" dir="ltr" placeholder="pdl_sdbx_apikey_..." /></div>
                </div>
                <div className="space-y-2">
                  <Label>{t('Webhook Secret (مفتاح توقيع الإشعارات)', 'Webhook Secret (notification signing key)')}</Label>
                  <Input type="password" value={form.paddle_webhook_secret || ''} onChange={(e) => setForm({ ...form, paddle_webhook_secret: e.target.value })} className="h-11" dir="ltr" placeholder="pdl_ntfset_..." />
                  <p className="text-xs text-gray-400">{t('انسخه من Paddle → Developer tools → Notifications → Edit destination', 'Copy it from Paddle → Developer tools → Notifications → Edit destination')}</p>
                </div>
                <div className="flex items-center gap-2 flex-wrap">
                  <Button onClick={() => saveForm(t('تم حفظ إعدادات Paddle', 'Paddle settings saved'))} disabled={saving} className="gap-2 rounded-full">{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ وتفعيل', 'Save & activate')}</Button>
                  <TestButton gateway="paddle" />
                  {form.paddle_enabled && (form.paddle_client_token && form.paddle_api_key && form.paddle_webhook_secret) && <Badge className="bg-green-100 text-green-700 border-0">{t('جاهز للربط', 'Ready to connect')}</Badge>}
                </div>
              </CardContent></Card>

              <Card><CardContent className="p-6 space-y-5">
                <div className="flex items-center justify-between">
                  <div>
                    <h2 className="font-semibold text-lg">Lemon Squeezy</h2>
                    <p className="text-sm text-gray-400">{t('بطاقة / Apple Pay / Google Pay عبر Lemon Squeezy (تتولى الضرائب والتحصيل)', 'Card / Apple Pay / Google Pay via Lemon Squeezy (MoR handles tax & payouts)')}</p>
                  </div>
                  <Switch checked={!!form.lemon_enabled} onCheckedChange={(v) => setForm({ ...form, lemon_enabled: v })} label="Lemon Squeezy" />
                </div>
                <div className="grid sm:grid-cols-2 gap-4">
                  <div className="space-y-2"><Label>{t('Store ID', 'Store ID')}</Label><Input value={form.lemon_store_id || ''} onChange={(e) => setForm({ ...form, lemon_store_id: e.target.value })} className="h-11" dir="ltr" placeholder="123456" /></div>
                  <div className="space-y-2"><Label>{t('API URL', 'API URL')}</Label><Input value={form.lemon_api_url || 'https://api.lemonsqueezy.com'} onChange={(e) => setForm({ ...form, lemon_api_url: e.target.value })} className="h-11" dir="ltr" placeholder="https://api.lemonsqueezy.com" /></div>
                </div>
                <div className="space-y-1">
                  <Label>{t('API Key (من البيئة — LEMON_SQUEEZY_API_KEY)', 'API Key (from env — LEMON_SQUEEZY_API_KEY)')}</Label>
                  <div className="p-3 rounded-xl border bg-gray-50 text-sm font-mono">
                    {form.lemon_api_key_set ? '••••••••••••••••••••••••' : t('غير مضبوط — أضف LEMON_SQUEEZY_API_KEY إلى .env', 'Not set — add LEMON_SQUEEZY_API_KEY to .env')}
                  </div>
                </div>
                <div className="space-y-1">
                  <Label>{t('Webhook Signing Secret (من البيئة — LEMON_SQUEEZY_SIGNING_SECRET)', 'Webhook Signing Secret (from env — LEMON_SQUEEZY_SIGNING_SECRET)')}</Label>
                  <div className="p-3 rounded-xl border bg-gray-50 text-sm font-mono">
                    {form.lemon_signing_secret_set ? '••••••••••••••••••••••••' : t('غير مضبوط — أضف LEMON_SQUEEZY_SIGNING_SECRET إلى .env', 'Not set — add LEMON_SQUEEZY_SIGNING_SECRET to .env')}
                  </div>
                  <p className="text-xs text-gray-400">{t('الأسرار تُدار من البيئة فقط ولا تُخزن في قاعدة البيانات', 'Secrets are managed from the environment only — never stored in the database')}</p>
                </div>
                <div className="flex items-center gap-2 flex-wrap">
                  <Button onClick={() => saveForm(t('تم حفظ إعدادات Lemon Squeezy', 'Lemon Squeezy settings saved'))} disabled={saving} className="gap-2 rounded-full">{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ وتفعيل', 'Save & activate')}</Button>
                  <TestButton gateway="lemonsqueezy" />
                  {form.lemon_enabled && form.lemon_api_key_set && form.lemon_signing_secret_set && form.lemon_store_id && <Badge className="bg-green-100 text-green-700 border-0">{t('جاهز للربط', 'Ready to connect')}</Badge>}
                </div>
              </CardContent></Card>

              <Card><CardContent className="p-6 space-y-5">
                {/* ─── Manual payment (Click / wallet) ─── */}
                <div className="flex items-center justify-between">
                  <div>
                    <h2 className="font-semibold text-lg">{t('الدفع اليدوي (كليك / محفظة)', 'Manual payment (Click / wallet)')}</h2>
                    <p className="text-sm text-gray-400">{t('المعلم يحوّل يدويًا ويرفع إثبات الدفع وتقبله من لوحة المدفوعات', 'Teacher transfers manually, uploads proof — you approve it from the payments panel')}</p>
                  </div>
                  <Switch checked={!!form.manual_payment_enabled} onCheckedChange={(v) => setForm({ ...form, manual_payment_enabled: v })} label={t('الدفع اليدوي', 'Manual payment')} />
                </div>
                <div className="space-y-2">
                  <Label>{t('اسم طريقة الدفع (يظهر للمعلم)', 'Payment method name (shown to teacher)')}</Label>
                  <Input value={form.manual_payment_label || ''} onChange={(e) => setForm({ ...form, manual_payment_label: e.target.value })} className="h-11" placeholder={t('مثال: كليك / محفظة', 'e.g. Click / wallet')} />
                </div>
                <div className="space-y-2">
                  <Label>{t('بيانات الحساب / المحفظة (رقم المحفظة، الاسم...)', 'Account / wallet details (wallet no., name...)')}</Label>
                  <Input value={form.manual_payment_account || ''} onChange={(e) => setForm({ ...form, manual_payment_account: e.target.value })} className="h-11" dir="ltr" placeholder={t('مثال: Click wallet — 079 1234 567 · الاسم', 'e.g. Click wallet — 079 1234 567 · name')} />
                </div>
                <div className="space-y-2">
                  <Label>{t('إرشادات التحويل (تظهر للمعلم في صفحة الدفع)', 'Transfer instructions (shown on the checkout page)')}</Label>
                  <textarea
                    value={form.manual_payment_instructions || ''}
                    onChange={(e) => setForm({ ...form, manual_payment_instructions: e.target.value })}
                    rows={4}
                    className="w-full rounded-xl border border-input bg-background px-4 py-3 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-teal-600/30"
                    placeholder={t('1. حوّل المبلغ إلى الحساب أعلاه\n2. احتفظ برقم العملية\n3. املأ النموذج وارفع صورة الوصل', '1. Transfer the amount to the account above\n2. Keep the transaction number\n3. Fill the form and upload the receipt image')}
                  />
                </div>
                <div className="flex items-center gap-2 flex-wrap">
                  <Button onClick={() => saveForm(t('تم حفظ إعدادات الدفع اليدوي', 'Manual payment settings saved'))} disabled={saving} className="gap-2 rounded-full">{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}{t('حفظ وتفعيل', 'Save & activate')}</Button>
                  {form.manual_payment_enabled && form.manual_payment_account && <Badge className="bg-green-100 text-green-700 border-0">{t('مفعّل — المعلم يرى خيار الدفع اليدوي', 'Enabled — teachers see the manual option')}</Badge>}
                </div>

                {/* ─── PayPal setup guide ─────────────────── */}
                <details className="rounded-xl border border-teal-100 bg-teal-50/50 p-4 group">
                  <summary className="flex items-center gap-2 cursor-pointer text-sm font-bold text-teal-700 list-none">
                    <BookOpen className="h-4 w-4" />{t('دليل إعداد PayPal الكامل (خطوة بخطوة)', 'Full PayPal setup guide (step by step)')}
                    <ChevronDown className="h-4 w-4 ms-auto group-open:rotate-180 transition-transform" />
                  </summary>
                  <div className="mt-4 space-y-3 text-sm">
                    <p className="text-teal-900/80 text-xs leading-relaxed">
                      {t('بعد الربط، يستطيع طلابك الدفع بـ: حساب PayPal، أو بطاقة فيزا / ماستركارد مباشرة دون حساب PayPal.', 'Once connected, your students can pay with: a PayPal account, or a Visa / Mastercard directly without a PayPal account.')}
                    </p>
                    {[
                      { step: 1, title: t('أنشئ حساب PayPal Business', 'Create a PayPal Business account'), body: t('سجّل حساب أعمال (Business) — أو حدّث حسابك الشخصي إلى Business من داخل PayPal.', 'Sign up for a Business account — or upgrade your personal account inside PayPal.'), link: 'https://www.paypal.com/business', linkLabel: 'paypal.com/business' },
                      { step: 2, title: t('افتح PayPal Developer واربط حسابك', 'Open PayPal Developer and link your account'), body: t('ادخل ببيانات حسابك نفسه ثم اربط حسابك التجاري.', 'Log in with the same account and link your business account.'), link: 'https://developer.paypal.com', linkLabel: 'developer.paypal.com' },
                      { step: 3, title: t('أنشئ تطبيقًا واحصل على بيانات الاعتماد', 'Create an app and get your credentials'), body: t('من Apps & Credentials → Create App. ستظهر Client ID و Secret — انسخهما.', 'From Apps & Credentials → Create App. Client ID and Secret will appear — copy them.'), link: 'https://developer.paypal.com/dashboard/applications', linkLabel: 'Apps & Credentials' },
                      { step: 4, title: t('الصق Client ID و Secret في الحقلين أعلاه', 'Paste Client ID and Secret into the fields above'), body: t('ثم اضغط "حفظ وتفعيل" ثم "اختبار الاتصال" للتحقق.', 'Then click Save & activate, then Test connection to verify.'), link: null },
                      { step: 5, title: t('جرّب بوضع الاختبار (Sandbox)', 'Test with Sandbox mode'), body: t('استخدم حسابات Sandbox التجريبية وبطاقات الاختبار من PayPal Developer قبل تفعيل الدفع الحقيقي.', 'Use PayPal Developer sandbox accounts and test cards before going live.'), link: 'https://developer.paypal.com/dashboard/accounts', linkLabel: 'Sandbox accounts' },
                      { step: 6, title: t('فعّل الدفع الفعلي', 'Go live'), body: t('عطّل "وضع الاختبار" في الحقل أعلاه — أي دفع سابقًا في وضع الاختبار لن يُحصَّل فعليًا!', 'Turn OFF "Test mode" above — payments made in test mode are NOT actually charged!'), link: null },
                    ].map((s) => (
                      <div key={s.step} className="flex gap-3">
                        <span className="flex h-6 w-6 items-center justify-center rounded-full bg-teal-600 text-white text-xs font-bold shrink-0">{s.step}</span>
                        <div className="flex-1">
                          <p className="font-semibold text-gray-800">{s.title}</p>
                          <p className="text-gray-500 text-xs mt-0.5">{s.body}</p>
                          {s.link && (
                            <a href={s.link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 mt-1 text-teal-700 text-xs font-medium hover:underline">
                              {s.linkLabel} <ExternalLink className="h-3 w-3" />
                            </a>
                          )}
                        </div>
                      </div>
                    ))}
                    <div className="p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs leading-relaxed">
                      <b>{t('مهم لاستقبال الفيزا دون حساب PayPal:', 'Important for accepting cards without a PayPal account:')}</b>{' '}
                      {t('من حسابك في PayPal: Account Settings → Payment preferences → تأكد أن "Allow customers to pay with their credit card without a PayPal account" مفعّلة.', 'In your PayPal account: Settings → Payments → ensure "Allow customers to pay with their credit card without a PayPal account" is enabled.')}
                    </div>
                  </div>
                </details>
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
        </div>
      </div>
    </div>
  );
}
