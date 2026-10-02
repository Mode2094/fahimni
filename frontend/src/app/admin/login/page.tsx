'use client';

import { useState, useEffect } from 'react';
import GoogleLoginButton from '@/components/auth/google-login-button';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/store';
import { authFetch } from '@/lib/auth-client';
import { useLocale } from '@/store/locale';
import { t } from '@/lib/i18n';
import { ShieldCheck, Eye, EyeOff, ArrowLeft, Users, BarChart3, Lock, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Link from 'next/link';

export default function AdminLoginPage() {
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
  const login2fa = useAuth((s) => s.login2fa);
  const { locale } = useLocale();

  useEffect(() => {
    // Admin uses its OWN remembered key — and never stores the password.
    // Purge any legacy plaintext copy from older builds.
    localStorage.removeItem('lms_admin_remembered_password');
    const savedEmail = localStorage.getItem('lms_admin_remembered_email');
    if (savedEmail) { setEmail(savedEmail); setRemember(true); }
  }, []);

  const persistRemember = () => {
    if (remember) {
      localStorage.setItem('lms_admin_remembered_email', email);
    } else {
      localStorage.removeItem('lms_admin_remembered_email');
    }
    localStorage.removeItem('lms_admin_remembered_password');
  };

  const finishAdminLogin = async (): Promise<boolean> => {
    const user = useAuth.getState().user;

    if (!user?.is_super_admin) {
      // Never leave a non-admin session active in the admin portal.
      try { await authFetch('/api/session/logout', { method: 'POST' }); } catch { /* */ }
      useAuth.setState({ user: null, initialized: true });
      setErr(locale === 'ar' ? 'هذا الحساب ليس لديه صلاحيات الإدارة' : 'This account does not have admin access');
      return false;
    }

    // Full session context reset: a stale tenant from a previous (e.g.
    // teacher) session must never leak into the admin session.
    localStorage.removeItem('tenant_id');
    persistRemember();
    router.push('/admin/dashboard');

    return true;
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErr(''); setLoad(true);
    try {
      const { requires2fa } = await login(email, pass, {
        captcha_id: captchaId,
        captcha_answer: captchaAnswer,
      });
      if (requires2fa) { setNeed2fa(true); setLoad(false); return; }
      await finishAdminLogin();
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
      await finishAdminLogin();
    } catch (error: any) {
      setErr(error?.response?.data?.message || error?.message || '2FA');
    }
    setLoad(false);
  };

  return (
    <div className="min-h-screen flex">
      <div className="hidden lg:flex lg:w-[460px] relative overflow-hidden shrink-0" style={{ background: 'linear-gradient(135deg, #111827 0%, #1e293b 55%, #312e81 100%)' }}>
        <div className="absolute inset-0">
          <div className="absolute top-1/4 left-1/4 w-96 h-96 bg-violet-600/10 rounded-full blur-3xl" />
          <div className="absolute bottom-1/4 right-1/4 w-80 h-80 bg-teal-500/10 rounded-full blur-3xl" />
        </div>
        <div className="relative z-10 flex flex-col justify-center px-12 w-full">
          <div className="flex items-center gap-3 mb-14">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-600 to-teal-800 shadow-xl">
              <ShieldCheck className="h-6 w-6 text-white" />
            </div>
            <div><span className="text-xl font-bold text-white">Tadreesly</span><p className="text-xs text-white/60">{locale === 'ar' ? 'بوابة الإدارة' : 'Admin Portal'}</p></div>
          </div>
          <h1 className="text-4xl font-bold text-white leading-tight mb-6 animate-in">
            {locale === 'ar' ? 'التحكم الكامل' : 'Full Platform'}
            <br /><span className="text-transparent bg-clip-text bg-gradient-to-r from-teal-300 to-emerald-300">{locale === 'ar' ? 'في مكان واحد' : 'Control'}</span>
          </h1>
          <p className="text-white/60 text-lg mb-10 animate-in animate-in-delay-1">{locale === 'ar' ? 'أدر المعلمين، الخطط، المدفوعات، والفواتير' : 'Manage teachers, plans, payments & invoices.'}</p>
          <div className="space-y-4 animate-in animate-in-delay-2">
            {[{ icon: Users, text: locale === 'ar' ? 'إدارة المعلمين والمستأجرين' : 'Manage teachers & tenants' }, { icon: BarChart3, text: locale === 'ar' ? 'تقارير وإحصائيات المنصة' : 'Platform reports' }, { icon: Lock, text: locale === 'ar' ? 'إعدادات الأمان والصلاحيات' : 'Security & permissions' }].map((it, i) => (
              <div key={i} className="flex items-center gap-3 text-white/75"><div className="flex h-9 w-9 items-center justify-center rounded-xl bg-white/10"><it.icon className="h-4 w-4" /></div><span className="text-sm">{it.text}</span></div>
            ))}
          </div>
          <div className="mt-auto pt-14 text-white/40 text-xs">© 2026 Tadreesly · {locale === 'ar' ? 'جميع الحقوق محفوظة' : 'All rights reserved'}</div>
        </div>
      </div>

      <div className="flex-1 flex items-center justify-center p-6 sm:p-12 bg-gray-50">
        <div className="w-full max-w-md space-y-8">
          <div className="text-center">
            <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-600 to-teal-800 shadow-xl shadow-violet-500/25">
              <ShieldCheck className="h-8 w-8 text-white" />
            </div>
            <h1 className="text-2xl sm:text-3xl font-bold">{locale === 'ar' ? 'بوابة الإدارة' : 'Admin Portal'}</h1>
            <p className="text-gray-500 mt-2">{locale === 'ar' ? 'دخول مديري المنصة فقط' : 'Platform admins only'}</p>
          </div>
          <div className="mb-4"><GoogleLoginButton role="admin" label="دخول الأدمن عبر Google" /></div><form onSubmit={submit} className="space-y-5 bg-white p-6 sm:p-8 rounded-2xl border shadow-sm">
            <div className="space-y-2">
              <Label className="text-sm font-medium">{t(locale, 'common.email')}</Label>
              <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required className="h-12 bg-gray-50" placeholder="admin@example.com" />
            </div>
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label className="text-sm font-medium">{t(locale, 'common.password')}</Label>
                <a href="/forgot-password" className="text-xs text-violet-600 hover:underline font-medium">{t(locale, 'common.forgotPassword')}</a>
              </div>
              <div className="relative">
                <Input type={show ? 'text' : 'password'} value={pass} onChange={(e) => setPass(e.target.value)} required className="h-12 bg-gray-50 pe-11" placeholder="••••••••" />
                <button type="button" onClick={() => setShow(!show)} className="absolute end-3 top-1/2 -translate-y-1/2 text-gray-400">{show ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}</button>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <label className="flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="h-4 w-4 rounded border-gray-300 accent-violet-600" />
                <span className="text-sm text-gray-600">{locale === 'ar' ? 'تذكرني' : 'Remember me'}</span>
              </label>
            </div>
            {err && <div className="rounded-xl bg-red-50 border border-red-100 p-3 text-sm text-red-600">{err}</div>}
            <Button type="submit" disabled={load} className="w-full h-12 text-base rounded-xl shadow-lg shadow-violet-500/20 text-white" style={{ background: 'linear-gradient(135deg, #6d28d9, #0d9488)' }}>
              {load ? <Loader2 className="h-5 w-5 animate-spin" /> : (locale === 'ar' ? 'دخول المدير' : 'Admin Sign In')}
            </Button>
          </form>
          <div className="text-center">
            <Link href="/" className="inline-flex items-center gap-1 text-xs text-gray-400 hover:text-gray-600"><ArrowLeft className="h-3 w-3" /> {locale === 'ar' ? 'العودة للصفحة الرئيسية' : 'Back home'}</Link>
          </div>
        </div>
      </div>
    </div>
  );
}
