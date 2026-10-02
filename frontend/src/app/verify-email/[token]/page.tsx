'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useLocale } from '@/store/locale';
import { useAuth } from '@/store';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Loader2, MailCheck, MailX, Mail } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function VerifyEmailTokenPage() {
  const { locale } = useLocale();
  const { token } = useParams();
  const router = useRouter();
  const { load } = useAuth();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [state, setState] = useState<'loading' | 'ok' | 'error' | 'no-auth'>('loading');
  const [message, setMessage] = useState('');

  useEffect(() => {
    const tokenValue = localStorage.getItem('lms_authed');
    if (!tokenValue) { setState('no-auth'); return; }
    fetch(`${API}/auth/email/verify`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ token: Array.isArray(token) ? token[0] : token }),
    })
      .then(async (r) => {
        const d = await r.json();
        if (!r.ok) { setMessage(d.message || 'Failed'); setState('error'); return; }
        setState('ok');
        await load();
        toast.success(t('تم تفعيل البريد الإلكتروني', 'Email verified'));
        setTimeout(() => router.push('/profile'), 1500);
      })
      .catch(() => { setMessage('Network error'); setState('error'); });
  }, [token]);

  const resend = async () => {
    const tokenValue = localStorage.getItem('lms_authed');
    if (!tokenValue) return;
    try {
      const r = await fetch(`${API}/auth/email/resend`, { method: 'POST', headers: { Accept: 'application/json' } });
      const d = await r.json();
      if (!r.ok) { toast.error(d.message || 'Failed'); return; }
      toast.success(t('تم إرسال رابط جديد', 'A new link was sent'));
      setState('ok');
      setTimeout(() => router.push('/profile'), 1200);
    } catch { toast.error('Failed'); }
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4">
      <Card className="w-full max-w-md p-8 text-center"><CardContent className="space-y-4">
        {state === 'loading' && (
          <>
            <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-teal-50"><Loader2 className="h-8 w-8 text-teal-600 animate-spin" /></div>
            <h1 className="text-2xl font-bold">{t('جاري التحقق...', 'Verifying...')}</h1>
          </>
        )}
        {state === 'ok' && (
          <>
            <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-50"><MailCheck className="h-8 w-8 text-green-500" /></div>
            <h1 className="text-2xl font-bold">{t('تم التحقق من بريدك!', 'Email verified!')}</h1>
            <p className="text-gray-500">{t('جاري تحويلك لملفك الشخصي...', 'Redirecting to your profile...')}</p>
          </>
        )}
        {state === 'error' && (
          <>
            <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-red-50"><MailX className="h-8 w-8 text-red-500" /></div>
            <h1 className="text-2xl font-bold">{t('تعذر التحقق', 'Verification failed')}</h1>
            <p className="text-gray-500">{message || t('الرابط غير صالح أو منتهي الصلاحية', 'The link is invalid or expired')}</p>
            <Button onClick={resend} className="rounded-full gap-2"><Mail className="h-4 w-4" />{t('إعادة إرسال الرابط', 'Resend link')}</Button>
          </>
        )}
        {state === 'no-auth' && (
          <>
            <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-amber-50"><Mail className="h-8 w-8 text-amber-500" /></div>
            <h1 className="text-2xl font-bold">{t('سجل دخولك أولاً', 'Log in first')}</h1>
            <p className="text-gray-500">{t('أكمل تسجيل الدخول ثم أعد فتح الرابط', 'Log in, then open the link again')}</p>
            <Button onClick={() => router.push('/teacher/login')} className="rounded-full">{t('تسجيل الدخول', 'Login')}</Button>
          </>
        )}
      </CardContent></Card>
    </div>
  );
}
