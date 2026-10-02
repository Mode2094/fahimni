'use client';

import { useEffect, useRef, useState } from 'react';
import QRCode from 'qrcode';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Loader2, ShieldCheck, ShieldOff, KeyRound, Smartphone, Copy, CheckCircle2, Lock } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function TwoFactorPanel() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const { settings, load } = useSettings();

  const [enabled, setEnabled] = useState(false);
  const [setup, setSetup] = useState<any>(null);
  const [code, setCode] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const qrRef = useRef<HTMLDivElement>(null);

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  useEffect(() => {
    load();
    fetch(`${API}/auth/me`, { headers: headers() })
      .then(r => r.json()).then(d => setEnabled(!!d.user?.two_factor_enabled)).catch(() => {});
  }, []);

  useEffect(() => {
    if (setup && setup.otpauth_url && qrRef.current) {
      QRCode.toDataURL(setup.otpauth_url, { width: 180, margin: 1 })
        .then((url) => {
          if (qrRef.current) {
            qrRef.current.innerHTML = '';
            const img = document.createElement('img');
            img.src = url;
            img.alt = '2FA QR code';
            img.width = 180;
            img.height = 180;
            qrRef.current.appendChild(img);
          }
        })
        .catch(() => {});
    }
  }, [setup]);

  const startSetup = async () => {
    if (!password) { toast.error(t('أدخل كلمة المرور لتأكيد هويتك', 'Enter your password to confirm your identity')); return; }
    setBusy(true);
    try {
      const res = await fetch(`${API}/auth/2fa/setup`, { method: 'POST', headers: headers(), body: JSON.stringify({ password }) });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      setSetup(d);
      setPassword('');
    } catch { toast.error('Failed'); }
    setBusy(false);
  };

  const enable = async () => {
    if (code.length !== 6) { toast.error(t('أدخل الرمز المكون من 6 أرقام', 'Enter the 6-digit code')); return; }
    setBusy(true);
    try {
      const res = await fetch(`${API}/auth/2fa/enable`, { method: 'POST', headers: headers(), body: JSON.stringify({ code }) });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      toast.success(t('تم تفعيل المصادقة الثنائية', '2FA enabled'));
      setEnabled(true); setSetup(null); setCode('');
    } catch { toast.error('Failed'); }
    setBusy(false);
  };

  const disable = async () => {
    if (!password) { toast.error(t('أدخل كلمة المرور لتأكيد هويتك', 'Enter your password to confirm your identity')); return; }
    if (code.length !== 6) { toast.error(t('أدخل الرمز الحالي', 'Enter your current code')); return; }
    setBusy(true);
    try {
      const res = await fetch(`${API}/auth/2fa/disable`, { method: 'POST', headers: headers(), body: JSON.stringify({ code, password }) });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      toast.success(t('تم تعطيل المصادقة الثنائية', '2FA disabled'));
      setEnabled(false); setCode(''); setPassword('');
    } catch { toast.error('Failed'); }
    setBusy(false);
  };

  const globalEnabled = !!settings.two_factor_enabled;

  if (!globalEnabled) {
    return (
      <Card>
        <CardContent className="p-6 flex items-center gap-4">
          <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100"><ShieldOff className="h-6 w-6 text-gray-400" /></div>
          <div>
            <p className="font-semibold">{t('المصادقة الثنائية معطلة في المنصة', '2FA is disabled on the platform')}</p>
            <p className="text-sm text-gray-500">{t('يمكن لمدير المنصة تفعيلها من الإعدادات ← الأمان', 'The platform admin can enable it from Settings → Security')}</p>
          </div>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card className="border-0 shadow-sm">
      <CardContent className="p-6 space-y-5">
        <div className="flex items-center justify-between flex-wrap gap-3">
          <div className="flex items-center gap-3">
            <div className={`flex h-12 w-12 items-center justify-center rounded-xl ${enabled ? 'bg-green-50' : 'bg-gray-100'}`}>
              {enabled ? <ShieldCheck className="h-6 w-6 text-green-600" /> : <KeyRound className="h-6 w-6 text-gray-400" />}
            </div>
            <div>
              <p className="font-semibold">{t('المصادقة الثنائية (2FA)', 'Two-factor authentication (2FA)')}</p>
              <p className="text-sm text-gray-500">{enabled ? t('مفعّلة — رمز إضافي عند كل تسجيل دخول', 'Enabled — extra code at every login') : t('معطلة — يُنصح بتفعيلها لحماية حسابك', 'Disabled — recommended for account security')}</p>
            </div>
          </div>
          <Badge className={enabled ? 'bg-green-100 text-green-700 border-0' : 'bg-gray-100 text-gray-500 border-0'}>{enabled ? t('مفعّلة', 'ON') : t('معطلة', 'OFF')}</Badge>
        </div>

        {!enabled && !setup && (
          <div className="space-y-4">
            <div className="grid sm:grid-cols-3 gap-3 text-sm">
              <div className="p-3 rounded-xl bg-gray-50"><p className="font-bold mb-1">1. {t('ثبّت التطبيق', 'Install the app')}</p><p className="text-xs text-gray-500">{t('Google Authenticator أو Microsoft Authenticator أو Authy', 'Google / Microsoft Authenticator or Authy')}</p></div>
              <div className="p-3 rounded-xl bg-gray-50"><p className="font-bold mb-1">2. {t('امسح الرمز', 'Scan the code')}</p><p className="text-xs text-gray-500">{t('صوّر رمز QR بالخطوة التالية', 'Scan the QR code in the next step')}</p></div>
              <div className="p-3 rounded-xl bg-gray-50"><p className="font-bold mb-1">3. {t('أدخل الرمز', 'Enter the code')}</p><p className="text-xs text-gray-500">{t('رمز 6 أرقام يتغير كل 30 ثانية', 'A 6-digit code that changes every 30s')}</p></div>
            </div>
            <div className="max-w-xs space-y-2">
              <div className="relative">
                <Lock className="absolute end-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} className="h-11 pe-10" placeholder={t('كلمة المرور للتأكيد', 'Password to confirm')} />
              </div>
              <Button onClick={startSetup} disabled={busy || !password} className="gap-2 rounded-full">{busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <KeyRound className="h-4 w-4" />}{t('تفعيل 2FA', 'Enable 2FA')}</Button>
            </div>
          </div>
        )}

        {!enabled && setup && (
          <div className="space-y-4">
            <div className="grid sm:grid-cols-2 gap-6 items-center">
              <div className="space-y-2 text-center">
                <p className="text-sm font-medium">{t('امسح رمز QR بتطبيق المصادقة', 'Scan the QR code with your authenticator app')}</p>
                <div ref={qrRef} className="mx-auto w-fit p-3 bg-white border rounded-2xl" />
                <p className="text-xs text-gray-400">{t('أو أدخل المفتاح يدوياً:', 'Or enter the key manually:')}</p>
                <div className="flex items-center justify-center gap-2">
                  <code className="text-xs bg-gray-100 rounded-lg px-2 py-1.5" dir="ltr">{setup.secret}</code>
                  <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => { navigator.clipboard.writeText(setup.secret); toast.success(t('منسوخ', 'Copied')); }} aria-label={t('نسخ المفتاح', 'Copy key')}><Copy className="h-3.5 w-3.5" /></Button>
                </div>
              </div>
              <div className="space-y-3">
                <div className="flex items-center gap-2 p-3 rounded-xl bg-teal-50 text-teal-700 text-sm"><Smartphone className="h-5 w-5 shrink-0" />{t('أدخل الرمز من التطبيق لتأكيد التفعيل', 'Enter the code from the app to confirm')}</div>
                <Input value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} maxLength={6} className="h-12 text-center font-mono text-lg tracking-widest" placeholder="123456" inputMode="numeric" />
                <Button onClick={enable} disabled={busy} className="w-full gap-2 rounded-full">{busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}{t('تأكيد وتفعيل', 'Confirm & enable')}</Button>
                <Button variant="ghost" size="sm" className="w-full" onClick={() => setSetup(null)}>{t('إلغاء', 'Cancel')}</Button>
              </div>
            </div>
          </div>
        )}

        {enabled && (
          <div className="space-y-3">
            <p className="text-sm text-gray-500">{t('عند تسجيل الدخول سيُطلب منك إدخال الرمز من تطبيق المصادقة بعد كلمة المرور', 'On login you will be asked for the app code after your password')}</p>
            <div className="max-w-xs space-y-2">
              <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} className="h-11" placeholder={t('كلمة المرور للتأكيد', 'Password to confirm')} />
              <Input value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} maxLength={6} className="h-11 text-center font-mono tracking-widest" placeholder={t('الرمز الحالي للتعطيل', 'Current code to disable')} inputMode="numeric" />
              <Button variant="outline" onClick={disable} disabled={busy || !password} className="w-full gap-2 rounded-full text-red-600 border-red-100">{busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <ShieldOff className="h-4 w-4" />}{t('تعطيل 2FA', 'Disable 2FA')}</Button>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
