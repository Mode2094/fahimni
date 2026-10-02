'use client';

import { Suspense, useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { useSearchParams } from 'next/navigation';
import Link from 'next/link';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { CheckCircle2, XCircle, Loader2 } from 'lucide-react';

function SuccessInner() {
  const params = useSearchParams();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [state, setState] = useState<'loading' | 'paid' | 'failed' | 'cancelled'>('loading');

  useEffect(() => {
    const status = params.get('status');
    if (status === 'paid') setState('paid');
    else if (status === 'cancelled') setState('cancelled');
    else setState('failed');
  }, [params]);

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <Card className="w-full max-w-md border-0 shadow-lg">
        <CardContent className="p-8 flex flex-col items-center text-center gap-3">
          {state === 'loading' && <Loader2 className="h-12 w-12 animate-spin text-teal-600" />}
          {state === 'paid' && (
            <>
              <CheckCircle2 className="h-14 w-14 text-emerald-500" />
              <h1 className="text-xl font-bold">{t('تم الدفع بنجاح', 'Payment successful')}</h1>
              <p className="text-sm text-gray-500">{t('تم تأكيد حجزك. ستصل رسالة عند تأكيد المعلم للموعد مع رابط الجلسة.', 'Your booking is confirmed. You will be notified when the teacher confirms with the meeting link.')}</p>
            </>
          )}
          {state === 'cancelled' && (
            <>
              <XCircle className="h-14 w-14 text-amber-500" />
              <h1 className="text-xl font-bold">{t('تم إلغاء الدفع', 'Payment cancelled')}</h1>
              <p className="text-sm text-gray-500">{t('لم يتم خصم أي مبلغ. يمكنك المحاولة مرة أخرى.', 'No amount was charged. You can try again.')}</p>
            </>
          )}
          {state === 'failed' && (
            <>
              <XCircle className="h-14 w-14 text-red-500" />
              <h1 className="text-xl font-bold">{t('فشل الدفع', 'Payment failed')}</h1>
              <p className="text-sm text-gray-500">{t('حدث خطأ أثناء معالجة الدفع. حاول مجدداً أو تواصل مع الدعم.', 'Something went wrong. Please try again or contact support.')}</p>
            </>
          )}
          <div className="flex gap-2 mt-2">
            <Link href="/my-bookings"><Button className="rounded-full">{t('حجوزاتي', 'My bookings')}</Button></Link>
            <Link href="/"><Button variant="outline" className="rounded-full">{t('الرئيسية', 'Home')}</Button></Link>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

export default function BookingSuccessPage() {
  return (
    <Suspense fallback={<div className="min-h-screen flex items-center justify-center"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>}>
      <SuccessInner />
    </Suspense>
  );
}
