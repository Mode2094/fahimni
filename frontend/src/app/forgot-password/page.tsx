'use client';

import { useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { BookOpen, Mail, ArrowLeft, ArrowRight, Loader2 } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function ForgotPage() {
  const { locale } = useLocale();
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [load, setLoad] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault(); setLoad(true);
    try {
      await fetch(`${API}/auth/forgot-password`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email }) });
      setSent(true);
    } catch { toast.error('Failed'); }
    setLoad(false);
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-slate-50 to-teal-50">
      <Card className="w-full max-w-md p-8 animate-in"><CardContent className="space-y-6 text-center">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary shadow-lg"><BookOpen className="h-7 w-7 text-white" /></div>
        {sent ? (
          <><div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-100"><Mail className="h-8 w-8 text-green-600" /></div><p className="text-gray-500">{locale === 'ar' ? 'تحقق من بريدك' : 'Check your email'}</p><Button variant="outline" onClick={() => window.location.href = '/teacher/login'}>{locale === 'ar' ? 'عودة' : 'Back'}</Button></>
        ) : (
          <form onSubmit={submit} className="space-y-5 text-start">
            <div><h1 className="text-2xl font-bold text-center">{locale === 'ar' ? 'نسيت كلمة المرور' : 'Forgot Password'}</h1></div>
            <div className="space-y-2"><Label>{locale === 'ar' ? 'البريد' : 'Email'}</Label><Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required className="h-11" dir="ltr" /></div>
            <Button type="submit" className="w-full h-11" disabled={load}>{load ? <Loader2 className="h-4 w-4 animate-spin mx-auto" /> : (locale === 'ar' ? 'إرسال' : 'Send')}</Button>
            <a href="/teacher/login" className="inline-flex items-center gap-1 text-sm text-primary">{locale === 'ar' ? <ArrowRight className="h-3 w-3" /> : <ArrowLeft className="h-3 w-3" />} {locale === 'ar' ? 'عودة' : 'Back'}</a>
          </form>
        )}
      </CardContent></Card>
    </div>
  );
}
