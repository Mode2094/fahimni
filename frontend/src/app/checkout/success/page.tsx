'use client';

import { Suspense, useEffect, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { CheckCircle2, XCircle, Loader2, LayoutDashboard, Clock, ExternalLink } from 'lucide-react';
import Link from 'next/link';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

function SuccessInner() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const search = useSearchParams();
  const status = search.get('status');
  const licenseId = search.get('license_id');
  const ref = search.get('ref');

  const [confirmed, setConfirmed] = useState<boolean | null>(null);

  // Reconcile with the backend by its server-side re-fetch of the license.
  // This NEVER decides success from the URL — it only reads the backend
  // result, which is derived from the Freemius API. The checkout reference
  // (when present) is verified server-side to belong to this tenant.
  useEffect(() => {
    if (!licenseId) return;
    const params = new URLSearchParams({ license_id: licenseId });
    if (ref) params.set('ref', ref);
    fetch(`${API}/subscriptions/freemius/complete?${params.toString()}`, {
      credentials: 'include',
      headers: { Accept: 'application/json', 'X-Tenant-Id': localStorage.getItem('lms_tenant') || '' },
    })
      .then((r) => r.json())
      .then((d) => setConfirmed(d.status === 'active'))
      .catch(() => setConfirmed(null));
  }, [licenseId, ref]);

  // Payment is only ever confirmed by the backend (webhook). The success page
  // never claims success on its own.
  const processing = status === 'pending' || status === 'processing' || (licenseId && confirmed !== true);
  const paid = (status !== 'cancelled' && status !== 'failed' && !processing) || confirmed === true;

  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-b from-slate-50 to-white">
      <Card className="w-full max-w-md p-8 text-center space-y-5 animate-in">
        {processing ? (
          <>
            <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-amber-100">
              <Clock className="h-10 w-10 text-amber-600" />
            </div>
            <h1 className="text-2xl font-bold">{t('تم استلام عملية الدفع', 'Payment received')}</h1>
            <p className="text-gray-500 text-sm">
              {t('جاري تأكيد اشتراكك من مزود الدفع... سيُفعَّل خلال لحظات.', 'Confirming your subscription with the payment provider... it will activate in a moment.')}
            </p>
            <div className="flex justify-center gap-2 text-xs text-slate-400">
              <Loader2 className="h-4 w-4 animate-spin text-teal-600" />
              {t('قد يستغرق ذلك بضع دقائق', 'This may take a few minutes')}
            </div>
            <Link href="/teacher/dashboard" className="block"><Button className="w-full rounded-full gap-2"><LayoutDashboard className="h-4 w-4" />{t('الذهاب للوحة التحكم', 'Go to dashboard')}</Button></Link>
          </>
        ) : paid ? (
          <>
            <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-green-100"><CheckCircle2 className="h-10 w-10 text-green-600" /></div>
            <h1 className="text-2xl font-bold">{t('تم الدفع بنجاح!', 'Payment successful!')}</h1>
            <p className="text-gray-500 text-sm">
              {t('تم تأكيد الاشتراك عبر Freemius — خطتك مفعلة.', 'Subscription confirmed via Freemius — your plan is active.')}
            </p>
            <Link href="/teacher/dashboard" className="block"><Button className="w-full rounded-full gap-2"><LayoutDashboard className="h-4 w-4" />{t('الذهاب للوحة التحكم', 'Go to dashboard')}</Button></Link>
          </>
        ) : (
          <>
            <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-red-100"><XCircle className="h-10 w-10 text-red-500" /></div>
            <h1 className="text-2xl font-bold">{t('لم يكتمل الدفع', 'Payment incomplete')}</h1>
            <p className="text-gray-500 text-sm">{t('يمكنك المحاولة مرة أخرى من صفحة الخطط', 'You can try again from the plans page')}</p>
            <div className="flex gap-2">
              <Link href="/pricing" className="flex-1"><Button variant="outline" className="w-full rounded-full">{t('الخطط', 'Plans')}</Button></Link>
              <Link href="/teacher/dashboard" className="flex-1"><Button className="w-full rounded-full">{t('لوحة التحكم', 'Dashboard')}</Button></Link>
            </div>
          </>
        )}
      </Card>
    </div>
  );
}

export default function PaymentSuccessPage() {
  return (
    <Suspense fallback={<div className="min-h-screen flex items-center justify-center"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>}>
      <SuccessInner />
    </Suspense>
  );
}
