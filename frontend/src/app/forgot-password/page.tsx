'use client';

import { useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { BookOpen, Mail, MailWarning, ArrowLeft, ArrowRight, Loader2 } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

// `email`      → the backend confirmed a mailer that can actually deliver.
// `unconfigured` → the request was accepted, but no real provider is set up,
//                  so we MUST NOT promise an email that cannot arrive. This is
//                  derived from mailer configuration only — never from whether
//                  the address belongs to an account — so it leaks nothing.
type Delivery = 'email' | 'unconfigured' | null;

export default function ForgotPage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [delivery, setDelivery] = useState<Delivery>(null);
  const [load, setLoad] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoad(true);
    try {
      const r = await fetch(`${API}/auth/forgot-password`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email }),
      });

      if (!r.ok) {
        const d = await r.json().catch(() => ({}));
        const msg =
          r.status === 429 ? t('محاولات كثيرة — انتظر قليلاً ثم أعد المحاولة.', 'Too many attempts — wait a moment and try again.')
          : r.status === 422 ? t('أدخل بريداً إلكترونياً صحيحاً.', 'Enter a valid email address.')
          : (typeof d?.message === 'string' && d.message ? d.message : t('تعذّر إرسال الطلب — حاول مرة أخرى.', 'Could not send the request — try again.'));
        toast.error(msg);
        setLoad(false);
        return;
      }

      const d = await r.json().catch(() => ({}));
      setDelivery(d?.delivery === 'email' ? 'email' : 'unconfigured');
      setSent(true);
    } catch {
      toast.error(t('خطأ في الشبكة — حاول مرة أخرى.', 'Network error — please try again.'));
    }
    setLoad(false);
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-slate-50 to-teal-50">
      <Card className="w-full max-w-md p-8 animate-in"><CardContent className="space-y-6 text-center">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary shadow-lg"><BookOpen className="h-7 w-7 text-white" /></div>
        {sent ? (
          delivery === 'email' ? (
            <>
              <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-100"><Mail className="h-8 w-8 text-green-600" /></div>
              <h1 className="text-xl font-bold">{t('تحقّق من بريدك الإلكتروني', 'Check your email')}</h1>
              <p className="text-gray-500">{t('إن كان هذا البريد مسجّلاً لدينا، فستصلك رسالة فيها رابط لتعيين كلمة مرور جديدة.', 'If this address is registered with us, an email with a reset link is on its way.')}</p>
              <Button variant="outline" onClick={() => window.location.href = '/teacher/login'}>{t('عودة لتسجيل الدخول', 'Back to login')}</Button>
            </>
          ) : (
            <>
              <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-amber-100"><MailWarning className="h-8 w-8 text-amber-600" /></div>
              <h1 className="text-xl font-bold">{t('تم استلام طلبك', 'Request received')}</h1>
              <p className="text-gray-500">
                {t(
                  'تنبيه: خدمة إرسال البريد غير مُهيّأة بعد على المنصة، لذا قد لا تصلك رسالة إعادة التعيين. تواصل مع الدعم لتعيين كلمة المرور.',
                  'Note: email delivery is not configured on the platform yet, so the reset message may not arrive. Contact support to reset your password.'
                )}
              </p>
              <Button variant="outline" onClick={() => window.location.href = '/teacher/login'}>{t('عودة لتسجيل الدخول', 'Back to login')}</Button>
            </>
          )
        ) : (
          <form onSubmit={submit} className="space-y-5 text-start">
            <div><h1 className="text-2xl font-bold text-center">{t('نسيت كلمة المرور', 'Forgot Password')}</h1></div>
            <div className="space-y-2"><Label>{t('البريد', 'Email')}</Label><Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required className="h-11" dir="ltr" autoComplete="email" /></div>
            <Button type="submit" className="w-full h-11" disabled={load}>{load ? <Loader2 className="h-4 w-4 animate-spin mx-auto" /> : t('إرسال', 'Send')}</Button>
            <a href="/teacher/login" className="inline-flex items-center gap-1 text-sm text-primary">{locale === 'ar' ? <ArrowRight className="h-3 w-3" /> : <ArrowLeft className="h-3 w-3" />} {t('عودة', 'Back')}</a>
          </form>
        )}
      </CardContent></Card>
    </div>
  );
}
