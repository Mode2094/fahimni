'use client';

import { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { Award, QrCode } from 'lucide-react';
import { useLocale } from '@/store/locale';
import { cn } from '@/lib/utils';

export default function CertificateView({ certificate, showQr = true, className }: { certificate: any; showQr?: boolean; className?: string }) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [qrDataUrl, setQrDataUrl] = useState<string | null>(null);

  useEffect(() => {
    setQrDataUrl(null);
    if (!showQr || !certificate?.certificate_number) return;
    const url = `${window.location.origin}/verify/${certificate.certificate_number}`;
    QRCode.toDataURL(url, { width: 128, margin: 1, errorCorrectionLevel: 'M', color: { dark: '#0f172a', light: '#ffffff' } })
      .then(setQrDataUrl)
      .catch(() => setQrDataUrl(null));
  }, [certificate?.certificate_number, showQr]);

  if (!certificate) return null;

  const accent = certificate.accent_color || '#0d9488';
  const name = certificate.student_name || t('طالب', 'Student');
  const course = certificate.course_title || '';
  const num = certificate.certificate_number || '';
  const template = certificate.template || 'classic';
  const date = certificate.issued_at
    ? new Date(certificate.issued_at).toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US', { year: 'numeric', month: 'long', day: 'numeric' })
    : '';

  const Qr = () => (
    <div className="flex flex-col items-center gap-1">
      {qrDataUrl ? (
        <img src={qrDataUrl} alt="QR" className="h-20 w-20" />
      ) : (
        <div className="flex h-20 w-20 items-center justify-center border border-dashed border-gray-300 rounded">
          <QrCode className="h-6 w-6 text-gray-300" />
        </div>
      )}
      <p className="text-[9px] text-gray-400">{t('امسح للتحقق', 'Scan to verify')}</p>
    </div>
  );

  const Signature = () => (
    <div className="text-center">
      {certificate.signature_name ? (
        <>
          <p className="font-serif text-xl" style={{ color: accent }}>{certificate.signature_name}</p>
          <p className="text-xs text-gray-500 mt-1">{certificate.signature_title || ''}</p>
          <p className="text-[9px] text-gray-400 mt-1">{t('التوقيع', 'Signature')}</p>
        </>
      ) : (
        <p className="text-xs text-gray-400">{t('التوقيع', 'Signature')}</p>
      )}
    </div>
  );

  return (
    <div id="certificate-print" className={cn('w-full bg-white shadow-sm select-none', className)}>
      {template === 'modern' && (
        <div className="flex min-h-[430px] overflow-hidden">
          <div className="w-10 sm:w-16 shrink-0" style={{ background: `linear-gradient(180deg, ${accent}, ${accent}66)` }} />
          <div className="relative flex-1 p-8 sm:p-10 text-center flex flex-col items-center justify-center gap-4">
            <Award className="absolute top-6 end-6 h-10 w-10 opacity-10" style={{ color: accent }} />
            <p className="text-xs tracking-[0.35em] text-gray-400 uppercase">{t('شهادة إتمام', 'Certificate of Completion')}</p>
            <div className="flex h-14 w-14 items-center justify-center rounded-full" style={{ background: `${accent}1A`, color: accent }}><Award className="h-7 w-7" /></div>
            <p className="text-gray-500 text-sm">{t('تُمنح هذه الشهادة إلى', 'This certificate is proudly presented to')}</p>
            <p className="text-3xl sm:text-4xl font-bold" style={{ color: accent }}>{name}</p>
            <p className="text-gray-500 text-sm">{t('لإكماله بنجاح الدورة', 'for successfully completing the course')}</p>
            <p className="text-xl sm:text-2xl font-semibold text-gray-800">{course}</p>
            <p className="text-xs text-gray-400">{date}</p>
            <div className="flex w-full items-end justify-between mt-4 pt-4 border-t border-gray-200">
              <Qr />
              <div className="text-center">
                <p className="text-[10px] text-gray-400">{t('رقم الشهادة', 'Certificate no.')}</p>
                <p className="text-xs text-gray-600 font-medium mt-0.5">{num}</p>
              </div>
              <Signature />
            </div>
          </div>
        </div>
      )}

      {template === 'minimal' && (
        <div className="relative min-h-[430px] p-10 sm:p-14 text-center flex flex-col items-center justify-center gap-4">
          <div className="absolute top-7 start-10 end-10 h-px bg-gray-300" />
          <div className="absolute bottom-7 start-10 end-10 h-px bg-gray-300" />
          <p className="text-[11px] tracking-[0.4em] text-gray-400 uppercase">{t('شهادة إتمام', 'Certificate of Completion')}</p>
          <p className="text-gray-400 text-sm">{t('تُمنح إلى', 'Presented to')}</p>
          <p className="text-4xl font-light tracking-wide" style={{ color: accent }}>{name}</p>
          <p className="text-gray-500 text-sm">{t('لإكماله بنجاح دورة', 'for successfully completing the course')}</p>
          <p className="text-lg font-medium text-gray-700">{course}</p>
          <p className="text-xs text-gray-400">{date} · {num}</p>
          <div className="flex w-full items-end justify-between mt-5">
            <Qr />
            <Signature />
          </div>
        </div>
      )}

      {template !== 'modern' && template !== 'minimal' && (
        <div style={{ border: `12px solid ${accent}` }}>
          <div className="m-1.5 border border-gray-300 p-8 sm:p-10 text-center min-h-[430px] flex flex-col items-center justify-center gap-4">
            <div className="flex h-16 w-16 items-center justify-center rounded-full" style={{ background: `${accent}1A`, color: accent }}><Award className="h-8 w-8" /></div>
            <div>
              <p className="text-2xl sm:text-3xl font-bold tracking-wide">{t('شهادة إتمام', 'Certificate of Completion')}</p>
              <p className="text-gray-400 text-sm mt-2">{t('تُمنح هذه الشهادة إلى', 'This certificate is proudly presented to')}</p>
            </div>
            <p className="text-3xl sm:text-4xl font-bold" style={{ color: accent }}>{name}</p>
            <p className="text-gray-500">{t('لإكماله بنجاح الدورة', 'for successfully completing the course')}</p>
            <p className="text-xl sm:text-2xl font-semibold text-gray-800">{course}</p>
            <div className="flex items-center gap-3 text-xs text-gray-400">
              <span>{t('تاريخ الإصدار', 'Issued')}: {date}</span>
              <span className="h-1 w-1 rounded-full bg-gray-300" />
              <span>{t('الرقم', 'No.')}: {num}</span>
            </div>
            <div className="flex w-full items-end justify-between mt-4 pt-4 border-t border-gray-200">
              <Qr />
              <Signature />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
