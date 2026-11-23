'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useLocale } from '@/store/locale';
import { useAuth } from '@/store';
import { useSettings } from '@/store/settings';
import { Loader2, ShieldCheck } from 'lucide-react';
import GoogleLoginButton from '@/components/auth/google-login-button';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Captcha } from '@/components/security/captcha';
import Link from 'next/link';
import { toast } from 'sonner';
import { authFetch, setSessionHint } from '@/lib/auth-client';
import { track } from '@/lib/analytics';
import PlatformLogo from '@/components/brand/platform-logo';

export default function TeacherRegisterPage() {
  const { locale } = useLocale();
  const { settings } = useSettings();
  const router = useRouter();
  const [form, setForm] = useState({ name: '', email: '', phone: '', password: '', confirm: '' });
  const [load, setLoad] = useState(false);
  const [googleLoad, setGoogleLoad] = useState(false);
  const [captchaAnswer, setCaptchaAnswer] = useState('');
  const [captchaId, setCaptchaId] = useState('');
  // The math CAPTCHA id is single-use server-side; remounting mints a fresh one.
  const [captchaKey, setCaptchaKey] = useState(0);
  const u = (f: string) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [f]: e.target.value });

  // Fire `signup_start` once per visit (no sensitive fields).
  useEffect(() => { track('signup_start', { content_name: 'teacher_signup' }); }, []);

  const handleGoogleSignup = async (): Promise<boolean> => {
    // Google provides the name/email; nothing is required up-front, so the
    // fastest path has zero mandatory fields.
    setGoogleLoad(true);
    return true;
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (form.password !== form.confirm) { toast.error(locale === 'ar' ? 'كلمة المرور غير متطابقة' : 'Passwords mismatch'); return; }
    if (!form.phone.trim()) { toast.error(locale === 'ar' ? 'رقم الهاتف مطلوب' : 'Phone number is required'); return; }
    setLoad(true);
    try {
      const res = await authFetch('/api/session/register', {
        method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ name: form.name, email: form.email, phone: form.phone, password: form.password, password_confirmation: form.confirm, role: 'Teacher', captcha_id: captchaId, captcha_answer: captchaAnswer }),
      });
      const data = await res.json();
      if (!res.ok) { toast.error(data.message || 'Failed'); setCaptchaKey((k) => k + 1); return; }
      localStorage.setItem('user', JSON.stringify(data.user));
      setSessionHint(true);
      // The register response already carries the new workspace id — persist it
      // so the very next tenant-scoped call (plan/limits) works without a reload.
      if (data.tenant_id) localStorage.setItem('tenant_id', data.tenant_id);
      useAuth.setState({ user: data.user, loading: false, initialized: true });
      track('CompleteRegistration', { content_name: 'teacher_signup' }, 'sign_up');
      if (data.trial_started) track('trial_start', { content_name: 'teacher_trial' });
      toast.success(locale === 'ar' ? 'تم إنشاء حساب المعلم' : 'Teacher account created');
      router.push(data.trial_started ? '/teacher/dashboard' : '/pricing');
    } catch { toast.error(locale === 'ar' ? 'فشل التسجيل' : 'Failed'); setCaptchaKey((k) => k + 1); }
    setLoad(false);
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-slate-50 to-teal-50">
      <div className="w-full max-w-md space-y-8 animate-in">
        <div className="text-center">
          <PlatformLogo className="mx-auto mb-5 h-16 w-16 rounded-2xl object-contain shadow-xl" />
          <h1 className="text-2xl font-bold">{locale === 'ar' ? 'سجل كمعلم' : 'Register as Teacher'}</h1>
          <p className="text-gray-500 mt-2">
            {settings.trial_available
              ? (locale === 'ar' ? `أنشئ منصتك التعليمية الخاصة — تجربة مجانية لمدة ${settings.trial_days} أيام` : `Create your own teaching platform — ${settings.trial_days}-day free trial`)
              : (locale === 'ar' ? 'أنشئ منصتك التعليمية واختر خطة اشتراكك' : 'Create your teaching platform and choose your plan')}
          </p>
        </div>
        <div className="mb-2 space-y-2">
          <p className="flex items-center justify-center gap-1.5 text-xs font-semibold text-teal-700">
            <ShieldCheck className="h-3.5 w-3.5" />{locale === 'ar' ? 'الأسرع — التسجيل خلال ثوانٍ بحساب Google' : 'Fastest — sign up in seconds with Google'}
          </p>
          {googleLoad ? (
            <button disabled className="flex w-full items-center justify-center gap-2.5 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700">
              <Loader2 className="h-5 w-5 animate-spin" />
              {locale === 'ar' ? 'جارٍ التسجيل عبر Google…' : 'Signing up with Google…'}
            </button>
          ) : (
            <GoogleLoginButton
              role="teacher"
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
          <div className="space-y-2"><Label htmlFor="reg-name">{locale === 'ar' ? 'الاسم الكامل' : 'Full name'}</Label><Input id="reg-name" value={form.name} onChange={u('name')} required className="h-11" autoComplete="name" /></div>
          <div className="space-y-2"><Label htmlFor="reg-email">{locale === 'ar' ? 'البريد الإلكتروني' : 'Email'}</Label><Input id="reg-email" type="email" value={form.email} onChange={u('email')} required className="h-11" autoComplete="email" /></div>
          <div className="space-y-2"><Label htmlFor="reg-phone">{locale === 'ar' ? 'رقم الهاتف' : 'Phone number'}</Label><Input id="reg-phone" type="tel" inputMode="tel" dir="ltr" required value={form.phone} onChange={u('phone')} placeholder="+962791234567" className="h-11" autoComplete="tel" /><p className="text-xs text-gray-500">{locale === 'ar' ? 'مع رمز الدولة (الأردن +962 افتراضيًا)' : 'Include the country code (Jordan +962 by default)'}</p></div>
          <div className="space-y-2"><Label htmlFor="reg-password">{locale === 'ar' ? 'كلمة المرور' : 'Password'}</Label><Input id="reg-password" type="password" value={form.password} onChange={u('password')} required className="h-11" autoComplete="new-password" /></div>
          <div className="space-y-2"><Label htmlFor="reg-confirm">{locale === 'ar' ? 'تأكيد كلمة المرور' : 'Confirm password'}</Label><Input id="reg-confirm" type="password" value={form.confirm} onChange={u('confirm')} required className="h-11" autoComplete="new-password" /></div>
          <Captcha key={captchaKey} value={captchaAnswer} onChange={setCaptchaAnswer} onId={setCaptchaId} locale={locale} />
          <Button type="submit" disabled={load} className="w-full h-12 rounded-xl text-white" style={{ background: 'linear-gradient(135deg, #0d9488, #0d9488)' }}>
            {load ? <Loader2 className="h-5 w-5 animate-spin" aria-hidden="true" /> : (locale === 'ar' ? 'إنشاء حساب المعلم' : 'Create Teacher Account')}
          </Button>
        </form>
        <p className="text-center text-sm text-gray-500">{locale === 'ar' ? 'لديك حساب؟' : 'Have an account?'} <Link href="/teacher/login" className="text-teal-700 font-medium hover:underline">{locale === 'ar' ? 'تسجيل دخول' : 'Login'}</Link></p>
      </div>
    </div>
  );
}
