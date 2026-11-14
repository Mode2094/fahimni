'use client';

import { Suspense, useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { CheckCircle2, XCircle, Loader2, LayoutDashboard, Clock, RefreshCw } from 'lucide-react';
import Link from 'next/link';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

// Backend status -> UI state. The backend is the ONLY authority: it verifies
// the Freemius redirect signature, the authenticated session and the checkout
// intent before it ever reports `active`.
type UiState = 'checking' | 'active' | 'pending' | 'failed';

function SuccessInner() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const search = useSearchParams();

  const [state, setState] = useState<UiState>('checking');
  const [message, setMessage] = useState<string>('');
  const [busy, setBusy] = useState(false);

  // Reconcile with the backend by forwarding the FULL original query string
  // (including Freemius' `signature`). The page NEVER decides success from the
  // URL: only the backend's verified, server-side result counts.
  const reconcile = useCallback(async () => {
    setBusy(true);

    const qs = search.toString();

    // Nothing to reconcile (e.g. user opened the page directly).
    if (!qs.includes('license_id') && !qs.includes('signature')) {
      setState('failed');
      setMessage(t('لم نعثر على بيانات عملية دفع.', 'No payment details were found.'));
      setBusy(false);
      return;
    }

    try {
      // Forward the EXACT URL Freemius redirected to (query included) so the
      // backend can recompute the signature over the same string.
      const redirectUrl = typeof window !== 'undefined' ? window.location.href : '';
      const res = await fetch(
        `${API}/subscriptions/freemius/complete?redirect_url=${encodeURIComponent(redirectUrl)}`,
        {
          credentials: 'include',
          headers: { Accept: 'application/json', 'X-Tenant-Id': localStorage.getItem('lms_tenant') || '' },
        },
      );
      const data = await res.json().catch(() => ({}));

      if (res.ok && data.status === 'active') {
        setState('active');
      } else if (data.status === 'pending') {
        setState('pending');
      } else if (data.status === 'mismatch' || data.status === 'unverified' || data.status === 'no_intent') {
        setState('failed');
        setMessage(t('تعذّر تأكيد ملكية عملية الدفع لهذه المنصة.', 'We could not confirm this purchase belongs to this platform.'));
      } else if (data.status === 'unknown') {
        setState('failed');
        setMessage(t('لم يُعثر على الترخيص.', 'The license was not found.'));
      } else if (!res.ok) {
        setState('pending');
      } else {
        // Backend returned active=false (inactive entitlement).
        setState('failed');
        setMessage(t('الاشتراك غير فعّال بعد.', 'The subscription is not active yet.'));
      }
    } catch {
      // Network problem — do not claim success; offer a retry.
      setState('pending');
    }

    setBusy(false);
  }, [search, t]);

  useEffect(() => {
    reconcile();
  }, [reconcile]);

  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-b from-slate-50 to-white">
      <Card className="w-full max-w-md p-8 text-center space-y-5 animate-in">
        {state === 'checking' ? (
          <>
            <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-amber-100">
              <Clock className="h-10 w-10 text-amber-600" />
            </div>
            <h1 className="text-2xl font-bold">{t('جارٍ التحقق...', 'Verifying...')}</h1>
            <div className="flex justify-center gap-2 text-xs text-slate-400">
              <Loader2 className="h-4 w-4 animate-spin text-teal-600" />
              {t('نتحقق من عملية الدفع لدى المزود', 'Confirming your payment with the provider')}
            </div>
          </>
        ) : state === 'active' ? (
          <>
            <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-green-100"><CheckCircle2 className="h-10 w-10 text-green-600" /></div>
            <h1 className="text-2xl font-bold">{t('تم تفعيل اشتراكك!', 'Your subscription is active!')}</h1>
            <p className="text-gray-500 text-sm">
              {t('تم تأكيد الاشتراك عبر Freemius — خطتك مفعلة.', 'Subscription confirmed via Freemius — your plan is active.')}
            </p>
            <Link href="/teacher/dashboard" className="block"><Button className="w-full rounded-full gap-2"><LayoutDashboard className="h-4 w-4" />{t('الذهاب للوحة التحكم', 'Go to dashboard')}</Button></Link>
          </>
        ) : state === 'pending' ? (
          <>
            <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-amber-100"><Clock className="h-10 w-10 text-amber-600" /></div>
            <h1 className="text-2xl font-bold">{t('قيد التأكيد', 'Pending confirmation')}</h1>
            <p className="text-gray-500 text-sm">
              {t('لم يصل تأكيد الدفع بعد. سيُفعَّل اشتراكك تلقائيًا خلال لحظات.', 'Payment confirmation has not arrived yet. Your subscription will activate shortly.')}
            </p>
            <div className="flex gap-2">
              <Button variant="outline" className="flex-1 rounded-full gap-2" disabled={busy} onClick={reconcile}>
                {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
                {t('إعادة التحقق', 'Check again')}
              </Button>
              <Link href="/teacher/dashboard" className="flex-1"><Button className="w-full rounded-full">{t('لوحة التحكم', 'Dashboard')}</Button></Link>
            </div>
          </>
        ) : (
          <>
            <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-red-100"><XCircle className="h-10 w-10 text-red-500" /></div>
            <h1 className="text-2xl font-bold">{t('لم يكتمل التفعيل', 'Activation incomplete')}</h1>
            <p className="text-gray-500 text-sm">
              {message || t('تعذّر تأكيد الاشتراك. إن كنت قد دفعت، تواصل مع الدعم.', 'We could not confirm the subscription. If you were charged, contact support.')}
            </p>
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
