'use client';

import { Suspense, useEffect, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useLocale } from '@/store/locale';
import { useAuth } from '@/store';
import { useSettings } from '@/store/settings';
import { resolveLogoUrl } from '@/lib/logo';
import { Loader2, ShieldCheck } from 'lucide-react';
import GoogleLoginButton from '@/components/auth/google-login-button';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Captcha } from '@/components/security/captcha';
import Link from 'next/link';
import { toast } from 'sonner';
import { authFetch, setSessionHint, setStoredTenantId } from '@/lib/auth-client';
import { track } from '@/lib/analytics';
import PlatformLogo from '@/components/brand/platform-logo';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function StudentRegisterPage() {
  return (
    <Suspense fallback={null}>
      <StudentRegisterForm />
    </Suspense>
  );
}

function StudentRegisterForm() {
  const { locale } = useLocale();
  const { settings } = useSettings();
  const router = useRouter();
  const searchParams = useSearchParams();
  const [form, setForm] = useState({ name: '', email: '', phone: '', password: '', confirm: '' });
  const [load, setLoad] = useState(false);
  const [googleLoad, setGoogleLoad] = useState(false);
  const [captchaAnswer, setCaptchaAnswer] = useState('');
  const [captchaId, setCaptchaId] = useState('');
  // The math CAPTCHA id is single-use server-side; remounting mints a fresh one.
  const [captchaKey, setCaptchaKey] = useState(0);
  const u = (f: string) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [f]: e.target.value });

  // Fire `signup_start` once per visit.
  useEffect(() => { track('signup_start', { content_name: 'student_signup' }); }, []);

  const handleGoogleSignup = async (): Promise<boolean> => {
    // Google supplies name/email — no mandatory fields block the fast path.
    setGoogleLoad(true);
    return true;
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (form.password !== form.confirm) { toast.error(locale === 'ar' ? 'كلمة المرور غير متطابقة' : 'Passwords mismatch'); return; }
    setLoad(true);
    try {
      const res = await authFetch('/api/session/register', {
        method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ name: form.name, email: form.email, phone: form.phone, password: form.password, password_confirmation: form.confirm, tenant_slug: searchParams.get('tenant_slug') || searchParams.get('slug'), captcha_id: captchaId, captcha_answer: captchaAnswer }),
      });
      const data = await res.json();
      if (!res.ok) { toast.error(data.message || 'Failed'); setCaptchaKey((k) => k + 1); return; }
      try { localStorage.setItem('user', JSON.stringify(data.user)); } catch { /* session cookie remains authoritative */ }
      setSessionHint(true);
      useAuth.setState({ user: data.user, loading: false, initialized: true });
      setStoredTenantId(data.tenant_id ? String(data.tenant_id) : null);
      track('CompleteRegistration', { content_name: 'student_signup' }, 'sign_up');
      toast.success(locale === 'ar' ? 'تم إنشاء حساب الطالب' : 'Student account created');
      const from = searchParams.get('from');
      if (from && from.startsWith('/') && !from.startsWith('//') && !from.startsWith('/\\')) router.push(from);
      else router.push('/my-learning');
    } catch { toast.error(locale === 'ar' ? 'فشل التسجيل' : 'Failed'); setCaptchaKey((k) => k + 1); }
    setLoad(false);
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-slate-50 to-cyan-50">
      <div className="w-full max-w-md space-y-8 animate-in">
        <div className="text-center">
          {settings.logo_url ? (
            <img src={resolveLogoUrl(settings.logo_url)} alt="logo" className="mx-auto mb-5 h-16 w-16 rounded-2xl object-cover shadow-xl" onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }} />
          ) : (
            <PlatformLogo className="mx-auto mb-5 h-16 w-16 rounded-2xl object-contain shadow-xl" />
          )}
          <h1 className="text-2xl font-bold">{locale === 'ar' ? 'سجل كطالب' : 'Register as Student'}</h1>
          <p className="text-gray-500 mt-2">{locale === 'ar' ? 'ابدأ رحلة تعلمك الآن' : 'Start your learning journey'}</p>
        </div>
        <div className="mb-2 space-y-2">
          <p className="flex items-center justify-center gap-1.5 text-xs font-semibold text-cyan-700">
            <ShieldCheck className="h-3.5 w-3.5" />{locale === 'ar' ? 'الأسرع — التسجيل خلال ثوانٍ بحساب Google' : 'Fastest — sign up in seconds with Google'}
          </p>
          {googleLoad ? (
            <button disabled className="flex w-full items-center justify-center gap-2.5 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700">
              <Loader2 className="h-5 w-5 animate-spin" />
              {locale === 'ar' ? 'جارٍ التسجيل عبر Google…' : 'Signing up with Google…'}
            </button>
          ) : (
            <GoogleLoginButton
              role="student"
              tenantSlug={searchParams.get('tenant_slug') || searchParams.get('slug')}
              redirectTo={searchParams.get('from')}
              variant="primary"
              label={locale === 'ar' ? 'التسجيل عبر Google' : 'Sign up with Google'}
              formData={{ name: form.name, phone: form.phone }}
              onClick={handleGoogleSignup}
            />
          )}
        </div>
        <div className="relative my-4">
          <div className="absolute inset-0 flex items-center"><div className="w-full border-t border-gray-200" /></div>
          <div className="relative flex justify-center text-xs"><span className="bg-white px-3 text-gray-400">{locale === 'ar' ? 'أو سجل بالبريد الإلكتروني' : 'or sign up with email'}</span></div>
        </div>
        <form onSubmit={submit} className="space-y-4 bg-white p-6 rounded-2xl border shadow-sm">
          <div className="space-y-2"><Label htmlFor="sreg-name">{locale === 'ar' ? 'الاسم الكامل' : 'Full name'}</Label><Input id="sreg-name" value={form.name} onChange={u('name')} required className="h-11" autoComplete="name" /></div>
          <div className="space-y-2"><Label htmlFor="sreg-email">{locale === 'ar' ? 'البريد الإلكتروني' : 'Email'}</Label><Input id="sreg-email" type="email" value={form.email} onChange={u('email')} required className="h-11" autoComplete="email" /></div>
          <div className="space-y-2"><Label htmlFor="sreg-phone">{locale === 'ar' ? 'رقم الهاتف (اختياري)' : 'Phone (optional)'}</Label><Input id="sreg-phone" type="tel" value={form.phone} onChange={u('phone')} className="h-11" autoComplete="tel" /></div>
          <div className="space-y-2"><Label htmlFor="sreg-password">{locale === 'ar' ? 'كلمة المرور' : 'Password'}</Label><Input id="sreg-password" type="password" value={form.password} onChange={u('password')} required className="h-11" autoComplete="new-password" /></div>
          <div className="space-y-2"><Label htmlFor="sreg-confirm">{locale === 'ar' ? 'تأكيد كلمة المرور' : 'Confirm password'}</Label><Input id="sreg-confirm" type="password" value={form.confirm} onChange={u('confirm')} required className="h-11" autoComplete="new-password" /></div>
          <Captcha key={captchaKey} value={captchaAnswer} onChange={setCaptchaAnswer} onId={setCaptchaId} locale={locale} />
          <Button type="submit" disabled={load} className="w-full h-12 rounded-xl text-white" style={{ background: 'linear-gradient(135deg, #0891b2, #059669)' }}>
            {load ? <Loader2 className="h-5 w-5 animate-spin" aria-hidden="true" /> : (locale === 'ar' ? 'إنشاء حساب الطالب' : 'Create Student Account')}
          </Button>
        </form>
        <p className="text-center text-sm text-gray-500">{locale === 'ar' ? 'لديك حساب؟' : 'Have an account?'} <Link href="/student/login" className="text-cyan-600 font-medium hover:underline">{locale === 'ar' ? 'تسجيل دخول' : 'Login'}</Link></p>
      </div>
    </div>
  );
}
