'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { useLocale } from '@/store/locale';
import { useAuth } from '@/store';
import { GraduationCap, Users, Loader2 } from 'lucide-react';
import GoogleLoginButton from '@/components/auth/google-login-button';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Link from 'next/link';
import { toast } from 'sonner';
import { authFetch, setSessionHint } from '@/lib/auth-client';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function TeacherRegisterPage() {
  const { locale } = useLocale();
  const router = useRouter();
  const [form, setForm] = useState({ name: '', email: '', phone: '', password: '', confirm: '' });
  const [load, setLoad] = useState(false);
  const [googleLoad, setGoogleLoad] = useState(false);
  const u = (f: string) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [f]: e.target.value });

  const handleGoogleSignup = async (): Promise<boolean> => {
    if (!form.name.trim()) { toast.error(locale === 'ar' ? 'أدخل الاسم الكامل' : 'Enter your full name'); return false; }
    if (!form.phone.trim()) { toast.error(locale === 'ar' ? 'أدخل رقم الهاتف' : 'Enter your phone number'); return false; }
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
        body: JSON.stringify({ name: form.name, email: form.email, password: form.password, password_confirmation: form.confirm, role: 'Teacher' }),
      });
      const data = await res.json();
      if (!res.ok) { toast.error(data.message || 'Failed'); return; }
      localStorage.setItem('user', JSON.stringify(data.user));
      setSessionHint(true);
      useAuth.setState({ user: data.user, loading: false, initialized: true });
      toast.success(locale === 'ar' ? 'تم إنشاء حساب المعلم' : 'Teacher account created');
      router.push('/teacher/dashboard');
    } catch { toast.error(locale === 'ar' ? 'فشل التسجيل' : 'Failed'); }
    setLoad(false);
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-slate-50 to-teal-50">
      <div className="w-full max-w-md space-y-8 animate-in">
        <div className="text-center">
          <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-600 to-teal-500 shadow-xl"><Users className="h-8 w-8 text-white" /></div>
          <h1 className="text-2xl font-bold">{locale === 'ar' ? 'سجل كمعلم' : 'Register as Teacher'}</h1>
          <p className="text-gray-500 mt-2">{locale === 'ar' ? 'أنشئ منصتك التعليمية الخاصة' : 'Create your own teaching platform'}</p>
        </div>
        <div className="mb-2">
          {googleLoad ? (
            <button disabled className="flex w-full items-center justify-center gap-2.5 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700">
              <Loader2 className="h-5 w-5 animate-spin" />
              {locale === 'ar' ? 'جارٍ التسجيل عبر Google…' : 'Signing up with Google…'}
            </button>
          ) : (
            <GoogleLoginButton
              role="teacher"
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
          <div className="space-y-2"><Label>{locale === 'ar' ? 'الاسم الكامل' : 'Full name'}</Label><Input value={form.name} onChange={u('name')} required className="h-11" /></div>
          <div className="space-y-2"><Label>{locale === 'ar' ? 'البريد الإلكتروني' : 'Email'}</Label><Input type="email" value={form.email} onChange={u('email')} required className="h-11" /></div>
          <div className="space-y-2"><Label>{locale === 'ar' ? 'رقم الهاتف *' : 'Phone *'}</Label><Input type="tel" value={form.phone} onChange={u('phone')} required className="h-11" /></div>
          <div className="space-y-2"><Label>{locale === 'ar' ? 'كلمة المرور' : 'Password'}</Label><Input type="password" value={form.password} onChange={u('password')} required className="h-11" /></div>
          <div className="space-y-2"><Label>{locale === 'ar' ? 'تأكيد كلمة المرور' : 'Confirm password'}</Label><Input type="password" value={form.confirm} onChange={u('confirm')} required className="h-11" /></div>
          <Button type="submit" disabled={load} className="w-full h-12 rounded-xl text-white" style={{ background: 'linear-gradient(135deg, #0d9488, #0d9488)' }}>
            {load ? <Loader2 className="h-5 w-5 animate-spin" /> : (locale === 'ar' ? 'إنشاء حساب المعلم' : 'Create Teacher Account')}
          </Button>
        </form>
        <p className="text-center text-sm text-gray-500">{locale === 'ar' ? 'لديك حساب؟' : 'Have an account?'} <Link href="/teacher/login" className="text-teal-700 font-medium hover:underline">{locale === 'ar' ? 'تسجيل دخول' : 'Login'}</Link></p>
      </div>
    </div>
  );
}
