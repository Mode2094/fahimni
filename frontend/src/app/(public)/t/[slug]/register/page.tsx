'use client';

import { useState } from 'react';
import { use } from 'react';
import { useRouter } from 'next/navigation';
import { useLocale } from '@/store/locale';
import { useAuth } from '@/store';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent } from '@/components/ui/card';
import { GraduationCap, Loader2, CheckCircle } from 'lucide-react';
import { toast } from 'sonner';
import { authFetch, setSessionHint } from '@/lib/auth-client';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function RegisterPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = use(params);
  const { locale } = useLocale();
  const router = useRouter();
  const [form, setForm] = useState({ name: '', email: '', phone: '', password: '', confirm: '' });
  const [load, setLoad] = useState(false);
  const [done, setDone] = useState(false);
  const u = (f: string) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [f]: e.target.value });

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
      if (!res.ok) { toast.error(data.message || 'Failed'); return; }
            localStorage.setItem('user', JSON.stringify(data.user));
      if (data.tenant_id) localStorage.setItem('tenant_id', data.tenant_id);
      // Sync the auth store so the app shell (sidebar etc.) appears instantly.
      setSessionHint(true);
      useAuth.setState({ user: data.user, loading: false, initialized: true });
      setDone(true);
      setTimeout(() => router.push('/my-learning'), 500);
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
    <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-slate-50 to-teal-50">
      <div className="w-full max-w-md space-y-8 animate-in">
        <div className="text-center">
          <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-cyan-500 to-emerald-500 shadow-xl"><GraduationCap className="h-8 w-8 text-white" /></div>
          <h1 className="text-2xl font-bold">{locale === 'ar' ? 'سجل كطالب' : 'Register as Student'}</h1>
        </div>
        <Card className="border-0 shadow-sm"><CardContent className="p-6 space-y-4">
          <div className="space-y-2"><Label>{locale === 'ar' ? 'الاسم الكامل' : 'Full name'}</Label><Input value={form.name} onChange={u('name')} required className="h-11" /></div>
          <div className="space-y-2"><Label>{locale === 'ar' ? 'البريد' : 'Email'}</Label><Input type="email" value={form.email} onChange={u('email')} required className="h-11" /></div>
          <div className="space-y-2"><Label>{locale === 'ar' ? 'الهاتف' : 'Phone'}</Label><Input type="tel" value={form.phone} onChange={u('phone')} className="h-11" /></div>
          <div className="space-y-2"><Label>{locale === 'ar' ? 'كلمة المرور' : 'Password'}</Label><Input type="password" value={form.password} onChange={u('password')} required className="h-11" /></div>
          <div className="space-y-2"><Label>{locale === 'ar' ? 'تأكيد' : 'Confirm'}</Label><Input type="password" value={form.confirm} onChange={u('confirm')} required className="h-11" /></div>
          <Button type="submit" onClick={submit} disabled={load} className="w-full h-12 rounded-xl text-white" style={{ background: 'linear-gradient(135deg, #0891b2, #059669)' }}>
            {load ? <Loader2 className="h-5 w-5 animate-spin" /> : (locale === 'ar' ? 'تسجيل' : 'Register')}
          </Button>
        </CardContent></Card>
      </div>
    </div>
  );
}
