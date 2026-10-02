'use client';

import { useState, useEffect } from 'react';
import GoogleLoginButton from '@/components/auth/google-login-button';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { resolveLogoUrl } from '@/lib/logo';
import { t } from '@/lib/i18n';
import { GraduationCap, Eye, EyeOff, ArrowLeft, Users, BookOpen, Sparkles, Shield, Loader2, KeyRound } from 'lucide-react';
import { Captcha } from '@/components/security/captcha';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Link from 'next/link';

export default function TeacherLoginPage() {
  // بيانات دخول تجريبية معبأة تلقائياً (يظهر اسم المستخدم بجانبها).
  const [email, setEmail] = useState('');
  const [pass, setPass] = useState('');
  const [show, setShow] = useState(false);
  const [err, setErr] = useState('');
  const [load, setLoad] = useState(false);
  const [remember, setRemember] = useState(false);
  const [need2fa, setNeed2fa] = useState(false);
  const [twoFactorCode, setTwoFactorCode] = useState('');
  const [captchaAnswer, setCaptchaAnswer] = useState('');
  const [captchaId, setCaptchaId] = useState('');
  const router = useRouter();
  const login = useAuth((s) => s.login);
  const { locale } = useLocale();
  const { settings } = useSettings();

  useEffect(() => {
    // Credentials are never persisted client-side — purge any legacy copy.
    localStorage.removeItem('lms_remembered_password');
    const savedEmail = localStorage.getItem('lms_remembered_email');
    if (savedEmail) { setEmail(savedEmail); setRemember(true); }
  }, []);

  const persistRemember = () => {
    if (remember) {
      localStorage.setItem('lms_remembered_email', email);
    } else {
      localStorage.removeItem('lms_remembered_email');
    }
    localStorage.removeItem('lms_remembered_password');
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErr('');
    setLoad(true);
    try {
      const { requires2fa } = await login(email, pass);
      if (requires2fa) { setNeed2fa(true); setLoad(false); return; }
      persistRemember();
      const stored = useAuth.getState().user;
      if (stored?.roles?.includes('Teacher')) router.push('/teacher/dashboard');
      else if (stored?.is_super_admin) router.push('/admin/dashboard');
      else {
        setErr(locale === 'ar' ? 'هذا الدخول مخصص للمعلمين فقط' : 'For teachers only');
        await useAuth.getState().logout();
      }
    } catch (err: any) {
      setErr(err?.response?.data?.message ? String(err.response.data.message).replace('Device limit reached', locale === 'ar' ? 'تم الوصول للحد الأقصى للأجهزة المسموحة. تواصل مع معلمك لإعادة تعيين أجهزتك' : 'Device limit reached') : err?.response?.status ? t(locale, 'common.invalidCredentials') : (locale === 'ar' ? 'تعذر الاتصال بالخادم' : 'Cannot reach server'));
    }
    setLoad(false);
  };

  const confirm2fa = async () => {
    setLoad(true);
    setErr('');
    try {
      await useAuth.getState().login2fa(twoFactorCode);
      persistRemember();
      const stored = useAuth.getState().user;
      if (stored?.roles?.includes('Teacher')) router.push('/teacher/dashboard');
      else if (stored?.is_super_admin) router.push('/admin/dashboard');
      else {
        setErr(locale === 'ar' ? 'هذا الدخول مخصص للمعلمين فقط' : 'For teachers only');
        await useAuth.getState().logout();
      }
    } catch (error: any) {
      setErr(error?.response?.data?.message || error?.message || '2FA');
    }
    setLoad(false);
  };

  return (
    <div className="min-h-screen flex">
      <div className="hidden lg:flex lg:w-[460px] relative overflow-hidden shrink-0" style={{ background: 'linear-gradient(135deg, #0f172a 0%, #1e293b 60%, #334155 100%)' }}>
        <div className="absolute inset-0">
          <div className="absolute top-1/4 start-1/4 w-96 h-96 bg-teal-500/10 rounded-full blur-3xl" />
          <div className="absolute bottom-1/4 end-1/4 w-80 h-80 bg-teal-400/10 rounded-full blur-3xl" />
        </div>
        <div className="relative z-10 flex flex-col justify-center px-12 w-full">
          <div className="flex items-center gap-3 mb-14">
            {settings.logo_url ? (
              <img src={resolveLogoUrl(settings.logo_url)} alt="logo" className="h-12 w-12 rounded-2xl object-cover shadow-xl" onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }} />
            ) : (
              <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-500 to-teal-600 shadow-xl">
                <GraduationCap className="h-6 w-6 text-white" />
              </div>
            )}
            <div><span className="text-xl font-bold text-white">{settings.platform_name || 'Tadreesly'}</span><p className="text-xs text-white/60">{locale === 'ar' ? 'منصة المعلمين' : 'Teacher Portal'}</p></div>
          </div>
          <h1 className="text-4xl font-bold text-white leading-tight mb-6 animate-in">
            {locale === 'ar' ? 'ابنِ علامتك التعليمية' : 'Build Your'}
            <br /><span className="text-transparent bg-clip-text bg-gradient-to-r from-teal-300 to-emerald-300">{locale === 'ar' ? 'وأنشئ علامتك التجارية' : 'Teaching Brand'}</span>
          </h1>
          <p className="text-white/60 text-lg mb-10 animate-in animate-in-delay-1">{locale === 'ar' ? 'كورساتك، طلابك، بطاقاتك، وإيراداتك — كلها في مكان واحد' : 'Courses, students, cards & revenue in one place.'}</p>
          <div className="space-y-4 animate-in animate-in-delay-2">
            {[{ icon: BookOpen, text: locale === 'ar' ? 'أنشئ كورساتك وأقسامها ودروسها' : 'Create courses & lessons' }, { icon: Users, text: locale === 'ar' ? 'أدر طلابك وتسجيلاتهم' : 'Manage students' }, { icon: Sparkles, text: locale === 'ar' ? 'بطاقات تسجيل ذكية' : 'Smart registration cards' }, { icon: Shield, text: locale === 'ar' ? 'موقع عام خاص بك' : 'Your own public website' }].map((it, i) => (
              <div key={i} className="flex items-center gap-3 text-white/75"><div className="flex h-9 w-9 items-center justify-center rounded-xl bg-white/10"><it.icon className="h-4 w-4" /></div><span className="text-sm">{it.text}</span></div>
            ))}
          </div>
          <div className="mt-auto pt-14 text-white/40 text-xs">© 2026 Tadreesly · {locale === 'ar' ? 'جميع الحقوق محفوظة' : 'All rights reserved'}</div>
        </div>
      </div>

      <div className="flex-1 flex items-center justify-center p-6 sm:p-12 bg-gray-50">
        <div className="w-full max-w-md space-y-8">
          <div className="text-center">
            {settings.logo_url ? (
              <img src={resolveLogoUrl(settings.logo_url)} alt="logo" className="mx-auto mb-5 h-16 w-16 rounded-2xl object-cover shadow-xl shadow-teal-500/25" onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }} />
            ) : (
              <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-600 to-teal-500 shadow-xl shadow-teal-500/25">
                <GraduationCap className="h-8 w-8 text-white" />
              </div>
            )}
            <h1 className="text-2xl sm:text-3xl font-bold">{locale === 'ar' ? 'بوابة المعلمين' : 'Teacher Portal'}</h1>
            <p className="text-gray-500 mt-2">{locale === 'ar' ? 'سجل دخولك لإدارة منصتك التعليمية' : 'Sign in to manage your platform'}</p>

          </div>
          <div className="mb-4"><GoogleLoginButton role="teacher" label="المتابعة عبر Google" /></div><form onSubmit={submit} className="space-y-5 bg-white p-6 sm:p-8 rounded-2xl border shadow-sm">
            <div className="space-y-2">
              <Label className="text-sm font-medium">{t(locale, 'common.email')}</Label>
              <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required className="h-12 bg-gray-50" placeholder="teacher@example.com" />
            </div>
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label className="text-sm font-medium">{t(locale, 'common.password')}</Label>
                <a href="/forgot-password" className="text-xs text-teal-700 hover:underline font-medium">{t(locale, 'common.forgotPassword')}</a>
              </div>
              <div className="relative">
                <Input type={show ? 'text' : 'password'} value={pass} onChange={(e) => setPass(e.target.value)} required className="h-12 bg-gray-50 pe-11" placeholder="••••••••" />
                <button type="button" onClick={() => setShow(!show)} className="absolute end-3 top-1/2 -translate-y-1/2 text-gray-400" aria-label={show ? (locale === 'ar' ? 'إخفاء كلمة المرور' : 'Hide password') : (locale === 'ar' ? 'إظهار كلمة المرور' : 'Show password')}>{show ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}</button>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <label className="flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="h-4 w-4 rounded border-gray-300 accent-teal-600" />
                <span className="text-sm text-gray-600">{locale === 'ar' ? 'تذكرني' : 'Remember me'}</span>
              </label>
            </div>
            {err && <div className="rounded-xl bg-red-50 border border-red-100 p-3 text-sm text-red-600">{err}</div>}
            <Button type="submit" disabled={load} className="w-full h-12 text-base rounded-xl shadow-lg shadow-teal-500/20 text-white" style={{ background: 'linear-gradient(135deg, #0d9488, #0d9488)' }}>
              {load ? <Loader2 className="h-5 w-5 animate-spin" /> : (locale === 'ar' ? 'تسجيل دخول المعلم' : 'Teacher Sign In')}
            </Button>
            {need2fa && (
              <div className="space-y-3 rounded-xl border border-teal-100 bg-teal-50/60 p-4">
                <Label>{locale === 'ar' ? 'رمز التحقق بخطوتين' : 'Two-factor code'}</Label>
                <Input
                  value={twoFactorCode}
                  onChange={(e) => setTwoFactorCode(e.target.value)}
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={6}
                  placeholder="000000"
                  className="text-center tracking-widest"
                />
                <Button
                  type="button"
                  onClick={confirm2fa}
                  disabled={load || twoFactorCode.length < 6}
                  className="w-full"
                >
                  {locale === 'ar' ? 'تأكيد الرمز' : 'Verify code'}
                </Button>
              </div>
            )}
          </form>
          <div className="text-center space-y-2">
            <p className="text-sm text-gray-500">{locale === 'ar' ? 'ليس لديك حساب؟' : "Don't have an account?"} <Link href="/register/teacher" className="text-teal-700 font-medium hover:underline">{locale === 'ar' ? 'سجل كمعلم الآن' : 'Register'}</Link></p>
            <Link href="/" className="inline-flex items-center gap-1 text-xs text-gray-400 hover:text-gray-600"><ArrowLeft className="h-3 w-3 rtl:rotate-180" /> {locale === 'ar' ? 'العودة للصفحة الرئيسية' : 'Back home'}</Link>
          </div>
        </div>
      </div>
    </div>
  );
}
