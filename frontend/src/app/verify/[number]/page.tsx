'use client';

import { useEffect, useState } from 'react';
import { use } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Loader2, ShieldCheck, XCircle, BadgeCheck } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function VerifyCertificatePage({ params }: { params: Promise<{ number: string }> }) {
  const { number } = use(params);
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [result, setResult] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [invalid, setInvalid] = useState(false);

  useEffect(() => {
    fetch(`${API}/public/certificates/${encodeURIComponent(number)}`, { headers: { Accept: 'application/json' } })
      .then(async (r) => {
        const d = await r.json().catch(() => null);
        if (!r.ok || !d?.valid || !d?.certificate) { setInvalid(true); return; }
        setResult(d.certificate);
      })
      .catch(() => setInvalid(true))
      .finally(() => setLoading(false));
  }, [number]);

  return (
    <div className="min-h-screen flex items-center justify-center p-6 bg-gray-50">
      <Card className="w-full max-w-lg border-0 shadow-sm">
        <CardContent className="p-8">
          {loading ? (
            <div className="flex flex-col items-center gap-3 py-8 text-gray-400">
              <Loader2 className="h-8 w-8 animate-spin text-teal-600" />
              <p className="text-sm">{t('جاري التحقق...', 'Verifying...')}</p>
            </div>
          ) : invalid ? (
            <div className="text-center space-y-4 py-4">
              <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-red-50"><XCircle className="h-10 w-10 text-red-500" /></div>
              <h1 className="text-xl font-bold">{t('لا يمكن التحقق من الشهادة', 'Certificate could not be verified')}</h1>
              <p className="text-sm text-gray-500">{t('هذا الرقم غير صالح أو أن الشهادة أُلغيَت', 'This number is invalid or the certificate was revoked')}</p>
            </div>
          ) : (
            <div className="text-center space-y-6 py-2">
              <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-green-50"><ShieldCheck className="h-10 w-10 text-green-600" /></div>
              <div>
                <h1 className="text-xl font-bold text-green-700">{t('شهادة موثقة', 'Verified certificate')}</h1>
                <p className="text-sm text-gray-500 mt-1">{t('تم التحقق من صحة هذه الشهادة', 'This certificate has been verified as authentic')}</p>
              </div>
              <div className="space-y-3 rounded-2xl border bg-gray-50/60 p-5 text-sm">
                <div className="flex items-center justify-between gap-4">
                  <span className="text-gray-500 shrink-0">{t('حامل الشهادة', 'Certificate holder')}</span>
                  <span className="font-semibold text-end break-words">{result.student_name}</span>
                </div>
                <div className="flex items-center justify-between gap-4">
                  <span className="text-gray-500 shrink-0">{t('الدورة', 'Course')}</span>
                  <span className="font-semibold text-end break-words">{result.course_title}</span>
                </div>
                {result.issued_at && (
                  <div className="flex items-center justify-between gap-4">
                    <span className="text-gray-500 shrink-0">{t('تاريخ الإصدار', 'Issued')}</span>
                    <span className="font-medium text-end">{new Date(result.issued_at).toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</span>
                  </div>
                )}
                {result.issuer && (
                  <div className="flex items-center justify-between gap-4">
                    <span className="text-gray-500 shrink-0">{t('الجهة المصدرة', 'Issued by')}</span>
                    <span className="font-medium text-end break-words">{result.issuer}</span>
                  </div>
                )}
                <div className="flex items-center justify-between gap-4 pt-2 border-t">
                  <span className="text-gray-500 shrink-0">{t('رقم الشهادة', 'Certificate number')}</span>
                  <span className="font-mono text-xs font-semibold text-gray-700 text-end break-all" dir="ltr">{result.certificate_number}</span>
                </div>
              </div>
              <div className="flex items-center justify-center gap-2 text-xs text-green-600">
                <BadgeCheck className="h-4 w-4" />
                {t('نظام التحقق من الشهادات', 'Certificate verification system')}
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
