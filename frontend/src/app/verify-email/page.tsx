'use client';
import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Mail, Loader2 } from 'lucide-react';
import { useLocale } from '@/store/locale';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function VerifyEmailPage() {
  const { locale } = useLocale();
  const router = useRouter();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [busy, setBusy] = useState(false);

  const resend = async () => {
    const token = localStorage.getItem('lms_authed');
    if (!token) { router.push('/teacher/login'); return; }
    setBusy(true);
    try {
      const r = await fetch(`${API}/auth/email/resend`, { method: 'POST', headers: { Accept: 'application/json' } });
      const d = await r.json();
      if (!r.ok) { toast.error(d.message || 'Failed'); return; }
      toast.success(t('تم إرسال رابط جديد إلى بريدك', 'A new verification link was sent'));
    } catch { toast.error('Failed'); }
    setBusy(false);
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4">
      <Card className="w-full max-w-md p-8 text-center"><CardContent className="space-y-4">
        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-teal-50"><Mail className="h-8 w-8 text-teal-600" /></div>
        <h1 className="text-2xl font-bold">{t('تحقق من بريدك', 'Verify your email')}</h1>
        <p className="text-gray-500">{t('أرسلنا رابط تحقق إلى بريدك الإلكتروني — افتحه من الرابط في الرسالة', 'We sent a verification link to your email — open it from the message')}</p>
        <Button onClick={resend} disabled={busy} className="rounded-full gap-2">{busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Mail className="h-4 w-4" />}{t('إعادة إرسال الرابط', 'Resend link')}</Button>
      </CardContent></Card>
    </div>
  );
}
