'use client';

import { useState, useEffect } from 'react';
import GoogleLoginButton from '@/components/auth/google-login-button';
import { Suspense } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { resolveLogoUrl } from '@/lib/logo';
import { t } from '@/lib/i18n';
import { Eye, EyeOff, ArrowLeft, BookOpen, Trophy, Award, Sparkles, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Link from 'next/link';
import PlatformLogo from '@/components/brand/platform-logo';

function StudentLoginPageInner() {
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
  const search = useSearchParams();
  const tenantSlug = search.get('tenant_slug') || '';
  const { settings } = useSettings();
  const from = search.get('from') || '';
  const login = useAuth((s) => s.login);
  const login2fa = useAuth((s) => s.login2fa);
  const { locale } = useLocale();

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

  const finishLogin = async () => {
    const user = useAuth.getState().user;

    // Site-specific login (tenant_slug from the teacher's public site):
    // select the matching tenant after the session is established.
    if (tenantSlug && user?.tenants?.length) {
      const match = user.tenants.find((tn: any) => tn.slug === tenantSlug);
      if (match?.id) localStorage.setItem('tenant_id', match.id);
    }

    const roles: string[] = user?.roles || [];
    if (user?.is_super_admin) router.push('/admin/dashboard');
    else if (roles.includes('Teacher')) router.push('/teacher/dashboard');
    else if (from && from.startsWith('/') && !from.startsWith('//') && !from.startsWith('/\\')) router.push(from);
    else router.push('/my-learning');
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErr(''); setLoad(true);
    try {
      const { requires2fa } = await login(email, pass, {
        captcha_id: captchaId,
        captcha_answer: captchaAnswer,
      });
      if (requires2fa) { setNeed2fa(true); return; }
      persistRemember();
      await finishLogin();
    } catch (error: any) {
      setErr(error?.response?.data?.message || error?.message || (locale === 'ar' ? 'فشل الدخول' : 'Login failed'));
    }
    setLoad(false);
  };

  const confirm2fa = async () => {
    setLoad(true);
    setErr('');
    try {
      await login2fa(twoFactorCode);
      persistRemember();
      await finishLogin();
    } catch (error: any) {
      setErr(error?.response?.data?.message || error?.message || (locale === 'ar' ? 'رمز التحقق غير صحيح' : 'Invalid 2FA code'));
    }
    setLoad(false);
  };

  return (
    <div className="min-h-screen flex">
      <div className="hidden lg:flex lg:w-[460px] relative overflow-hidden shrink-0" style={{ background: 'linear-gradient(135deg, #0f2e2b 0%, #115e54 55%, #0d9488 100%)' }}>
        <div className="absolute inset-0">
          <div className="absolute top-1/4 left-1/4 w-96 h-96 bg-teal-300/10 rounded-full blur-3xl" />
          <div className="absolute bottom-1/4 right-1/4 w-80 h-80 bg-emerald-300/10 rounded-full blur-3xl" />
        </div>
        <div className="relative z-10 flex flex-col justify-center px-12 w-full">
          <div className="flex items-center gap-3 mb-14">
            {settings.logo_url ? (
              <img src={resolveLogoUrl(settings.logo_url)} alt="logo" className="h-12 w-12 rounded-2xl object-cover shadow-xl" onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }} />
            ) : (
              <PlatformLogo className="h-12 w-12 rounded-2xl object-contain shadow-xl" />
            )}
            <div><span className="text-xl font-bold text-white">{settings.platform_name || 'فهمني'}</span><p className="text-xs text-white/70">{locale === 'ar' ? 'منصة الطلاب' : 'Student Portal'}</p></div>
          </div>
          <h2 className="text-4xl font-bold text-white leading-tight mb-6 animate-in">
            {locale === 'ar' ? 'رحلة تعلمك' : 'Your Learning'}
            <br /><span className="text-transparent bg-clip-text bg-gradient-to-r from-teal-200 to-emerald-200">{locale === 'ar' ? 'تبدأ من هنا' : 'Journey Starts Here'}</span>
          </h2>
          <p className="text-white/70 text-lg mb-10 animate-in animate-in-delay-1">{locale === 'ar' ? 'كورساتك، تقدمك، اختباراتك، وشهاداتك — في مكان واحد' : 'Courses, progress, exams & certificates in one place.'}</p>
          <div className="space-y-4 animate-in animate-in-delay-2">
            {[{ icon: BookOpen, text: locale === 'ar' ? 'مئات الكورسات المتاحة' : 'Hundreds of courses' }, { icon: Trophy, text: locale === 'ar' ? 'تتبع تقدمك خطوة بخطوة' : 'Track progress' }, { icon: Award, text: locale === 'ar' ? 'شهادات عند الإتمام' : 'Certificates' }, { icon: Sparkles, text: locale === 'ar' ? 'سجل بالبطاقة من معلمك' : 'Enroll via card' }].map((it, i) => (
              <div key={i} className="flex items-center gap-3 text-white/80"><div className="flex h-9 w-9 items-center justify-center rounded-xl bg-white/10"><it.icon className="h-4 w-4" /></div><span className="text-sm">{it.text}</span></div>
            ))}
          </div>
          <div className="mt-auto pt-14 text-white/40 text-xs">© 2026 منصة فهمني · {locale === 'ar' ? 'جميع الحقوق محفوظة' : 'All rights reserved'}</div>
        </div>
      </div>

      <div className="flex-1 flex items-center justify-center p-6 sm:p-12 bg-gray-50">
        <div className="w-full max-w-md space-y-8">
          <div className="text-center">
            {settings.logo_url ? (
              <img src={resolveLogoUrl(settings.logo_url)} alt="logo" className="mx-auto mb-5 h-16 w-16 rounded-2xl object-cover shadow-xl shadow-teal-500/25" onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }} />
            ) : (
              <PlatformLogo className="mx-auto mb-5 h-16 w-16 rounded-2xl object-contain shadow-xl shadow-teal-500/25" />
            )}
            <h1 className="text-2xl sm:text-3xl font-bold">{locale === 'ar' ? 'بوابة الطلاب' : 'Student Portal'}</h1>
            <p className="text-gray-500 mt-2">{locale === 'ar' ? 'سجل دخولك لمتابعة تعلمك' : 'Sign in to continue learning'}</p>
          </div>
          <div className="mb-4"><GoogleLoginButton role="student" label="المتابعة عبر Google" /></div><form onSubmit={submit} className="space-y-5 bg-white p-6 sm:p-8 rounded-2xl border shadow-sm">
            <div className="space-y-2">
              <Label htmlFor="email" className="text-sm font-medium">{t(locale, 'common.email')}</Label>
              <Input id="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required className="h-12 bg-gray-50" placeholder="student@example.com" />
            </div>
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label htmlFor="password" className="text-sm font-medium">{t(locale, 'common.password')}</Label>
                <a href="/forgot-password" className="text-xs text-teal-600 hover:underline font-medium">{t(locale, 'common.forgotPassword')}</a>
              </div>
              <div className="relative">
                <Input id="password" type={show ? 'text' : 'password'} value={pass} onChange={(e) => setPass(e.target.value)} required className="h-12 bg-gray-50 pe-11" placeholder="••••••••" />
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
            <Button type="submit" disabled={load} className="w-full h-12 text-base rounded-xl shadow-lg shadow-teal-500/20 text-white" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
              {load ? <Loader2 className="h-5 w-5 animate-spin" /> : (locale === 'ar' ? 'تسجيل دخول الطالب' : 'Student Sign In')}
            </Button>
          </form>
          <div className="text-center space-y-2">
            <p className="text-sm text-gray-500">{locale === 'ar' ? 'ليس لديك حساب؟' : "Don't have an account?"} <Link href="/register/student" className="text-teal-600 font-medium hover:underline">{locale === 'ar' ? 'سجل كطالب الآن' : 'Register'}</Link></p>
            <Link href="/" className="inline-flex items-center gap-1 text-xs text-gray-400 hover:text-gray-600"><ArrowLeft className="h-3 w-3" /> {locale === 'ar' ? 'العودة للصفحة الرئيسية' : 'Back home'}</Link>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function StudentLoginPage() {
  return (
    <Suspense fallback={<div className="min-h-screen flex items-center justify-center text-gray-400">{'…'}</div>}>
      <StudentLoginPageInner />
    </Suspense>
  );
}
