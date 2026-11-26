'use client';

import { useState, useEffect } from 'react';
import { use } from 'react';
import { useRouter } from 'next/navigation';
import { useLocale } from '@/store/locale';
import { useAuth } from '@/store';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent } from '@/components/ui/card';
import { GraduationCap, Loader2, CheckCircle, ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';
import { authFetch, setSessionHint, setStoredTenantId } from '@/lib/auth-client';
import { track } from '@/lib/analytics';
import { setPendingRedirect, consumePendingRedirect } from '@/lib/post-auth-redirect';
import { useTenantBasePath } from '@/lib/tenant-host';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function RegisterPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = use(params);
  const { locale } = useLocale();
  const router = useRouter();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const base = useTenantBasePath(slug);
  const [form, setForm] = useState({ name: '', email: '', phone: '', password: '', confirm: '' });
  const [load, setLoad] = useState(false);
  const [done, setDone] = useState(false);
  const [brand, setBrand] = useState<any>(null);
  const [courseParam, setCourseParam] = useState('');
  const u = (f: string) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [f]: e.target.value });

  // The teacher's own branding, so the sign-up continues the same experience.
  useEffect(() => {
    fetch(`${API}/public/${slug}`).then((r) => r.json()).then((d) => setBrand(d.site || null)).catch(() => {});
  }, [slug]);

  // The course the visitor came from. Remembered locally so the SAME course is
  // restored after the password flow OR a Google sign-in round-trip, and never
  // dropped between sign-up and login.
  useEffect(() => {
    const course = new URLSearchParams(window.location.search).get('course') || '';
    setCourseParam(course);
    if (course) setPendingRedirect(`${base}/courses/${course}`);
  }, [slug, base]);

  const courseHref = courseParam ? `${base}/courses/${courseParam}` : `/my-learning`;

  const primary = brand?.primary_color || '#0d9488';
  const accent = brand?.accent_color || '#14b8a6';

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (form.password !== form.confirm) { toast.error(locale === 'ar' ? 'كلمة المرور غير متطابقة' : 'Passwords mismatch'); return; }
    setLoad(true);
    try {
      const res = await authFetch('/api/session/register', {
        method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ name: form.name, email: form.email, password: form.password, password_confirmation: form.confirm, tenant_slug: slug }),
      });
      const data = await res.json();
      if (!res.ok) { toast.error(data.message || t('فشل', 'Failed')); return; }
      try { localStorage.setItem('user', JSON.stringify(data.user)); } catch { /* session cookie remains authoritative */ }
      setStoredTenantId(data.tenant_id ? String(data.tenant_id) : null);
      // Sync the auth store so the app shell (sidebar etc.) appears instantly.
      setSessionHint(true);
      useAuth.setState({ user: data.user, loading: false, initialized: true });
      track('CompleteRegistration', { content_name: 'student_signup', tenant: slug }, 'sign_up');
      setDone(true);
      // Return to the course the student came from, if any.
      const next = consumePendingRedirect();
      setTimeout(() => router.push(next || courseHref), 500);
    } catch { toast.error(locale === 'ar' ? 'فشل التسجيل' : 'Failed'); }
    setLoad(false);
  };

  if (done) {
    return (
      <div className="min-h-screen flex items-center justify-center p-4">
        <Card className="w-full max-w-md p-8 text-center space-y-6"><div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-green-100"><CheckCircle className="h-10 w-10 text-green-600" /></div><h1 className="text-2xl font-bold">{locale === 'ar' ? 'تم التسجيل!' : 'Registered!'}</h1><p className="text-gray-500">{locale === 'ar' ? 'جاري التحويل للوحة الطالب...' : 'Redirecting...'}</p></Card>
      </div>
    );
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
      <div className="w-full max-w-md space-y-7 animate-in">
        <div className="text-center">
          {brand?.logo_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={brand.logo_url} alt="" className="mx-auto mb-5 h-16 w-16 rounded-2xl object-cover shadow-xl" />
          ) : (
            <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl shadow-xl" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
              <GraduationCap className="h-8 w-8 text-white" />
            </div>
          )}
          <h1 className="text-2xl font-bold">{t('سجل كطالب', 'Register as Student')}</h1>
          {brand?.name && <p className="mt-1.5 text-sm text-slate-500">{brand.name}</p>}
        </div>
        <Card className="border-0 shadow-sm"><CardContent className="p-6 space-y-4">
          <div className="space-y-2"><Label htmlFor="name">{locale === 'ar' ? 'الاسم الكامل' : 'Full name'}</Label><Input id="name" value={form.name} onChange={u('name')} required className="h-11" /></div>
          <div className="space-y-2"><Label htmlFor="email">{locale === 'ar' ? 'البريد' : 'Email'}</Label><Input id="email" type="email" value={form.email} onChange={u('email')} required className="h-11" /></div>
          <div className="space-y-2"><Label htmlFor="phone">{locale === 'ar' ? 'الهاتف' : 'Phone'}</Label><Input id="phone" type="tel" value={form.phone} onChange={u('phone')} className="h-11" /></div>
          <div className="space-y-2"><Label htmlFor="password">{locale === 'ar' ? 'كلمة المرور' : 'Password'}</Label><Input id="password" type="password" value={form.password} onChange={u('password')} required className="h-11" /></div>
          <div className="space-y-2"><Label htmlFor="confirm">{locale === 'ar' ? 'تأكيد' : 'Confirm'}</Label><Input id="confirm" type="password" value={form.confirm} onChange={u('confirm')} required className="h-11" /></div>
          <Button type="submit" onClick={submit} disabled={load} className="h-12 w-full rounded-xl text-white shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
            {load ? <Loader2 className="h-5 w-5 animate-spin" /> : (locale === 'ar' ? 'تسجيل' : 'Register')}
          </Button>
          <p className="flex items-center justify-center gap-1.5 text-center text-xs text-slate-400">
            <ShieldCheck className="h-3.5 w-3.5" />
            {locale === 'ar' ? 'سنستخدم بياناتك لإنشاء حسابك في منصة معلمك فقط' : 'We only use your data to create your account on your teacher’s platform'}
          </p>
        </CardContent></Card>
        <p className="text-center text-sm text-slate-500">
          {locale === 'ar' ? 'لديك حساب؟' : 'Have an account?'}{' '}
          <a href={`/student/login?tenant_slug=${slug}${courseParam ? `&from=${encodeURIComponent(`${base}/courses/${courseParam}`)}` : ''}`} className="font-medium underline" style={{ color: primary }}>
            {locale === 'ar' ? 'سجل دخول' : 'Log in'}
          </a>
        </p>
      </div>
    </div>
  );
}
