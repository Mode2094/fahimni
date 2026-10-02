'use client';

import { useEffect, useState } from 'react';
import { use } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import CertificateView from '@/components/certificate/certificate-view';
import { Button } from '@/components/ui/button';
import { ArrowLeft, ArrowRight, Loader2, Printer, Share2, Check, Link2, MessageCircle } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function CertificatePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [cert, setCert] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!check()) return;
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    fetch(`${API}/student/certificates/${id}`, { headers: { 'X-Tenant-Id': tid || '', Accept: 'application/json' } })
      .then(r => r.json()).then(d => setCert(d.certificate || null)).catch(() => {})
      .finally(() => setLoading(false));
  }, [id, check()]);

  const verifyUrl = cert?.certificate_number ? `${window.location.origin}/verify/${cert.certificate_number}` : '';

  const share = async (target?: string) => {
    const text = t(`حصلت على شهادة "${cert.course_title}"`, `I earned a certificate for "${cert.course_title}"`);
    if (target === 'wa') { window.open(`https://wa.me/?text=${encodeURIComponent(`${text} ${verifyUrl}`)}`, '_blank'); return; }
    if (target === 'copy') {
      try {
        await navigator.clipboard.writeText(verifyUrl);
        setCopied(true); setTimeout(() => setCopied(false), 2000);
        toast.success(t('تم نسخ رابط الشهادة', 'Certificate link copied'));
      } catch { toast.error('Failed'); }
      return;
    }
    try {
      if (navigator.share) { await navigator.share({ title: t('شهادتي', 'My certificate'), text, url: verifyUrl }); return; }
    } catch { /* fallback to copy */ }
    try {
      await navigator.clipboard.writeText(verifyUrl);
      setCopied(true); setTimeout(() => setCopied(false), 2000);
      toast.success(t('تم نسخ رابط الشهادة', 'Certificate link copied'));
    } catch { toast.error('Failed'); }
  };

  if (loading) return <div className="flex justify-center py-16"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>;
  if (!cert) return <div className="text-center py-16 text-gray-400">{t('الشهادة غير موجودة', 'Certificate not found')}</div>;

  return (
    <div className="space-y-6 max-w-4xl mx-auto">
      <div className="flex items-start justify-between flex-wrap gap-4 print:hidden">
        <div>
          <a href="/certificates" className="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-800 mb-2">{locale === 'ar' ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />} {t('رجوع للشهادات', 'Back to certificates')}</a>
          <h1 className="text-2xl font-bold">{cert.course_title}</h1>
          <p className="text-sm text-gray-400 mt-1">{t('شهادة إتمام مع تحقق QR', 'Completion certificate with QR verification')}</p>
        </div>
        <div className="flex items-center gap-2 print:hidden flex-wrap">
          <Button variant="outline" className="gap-1.5 rounded-full" onClick={() => share('wa')}><MessageCircle className="h-4 w-4" />{t('واتساب', 'WhatsApp')}</Button>
          <Button variant="outline" className="gap-1.5 rounded-full" onClick={() => share()}><Share2 className="h-4 w-4" />{t('مشاركة مباشرة', 'Share')}</Button>
          <Button variant="outline" className="gap-1.5 rounded-full" onClick={() => share('copy')}><Link2 className="h-4 w-4" />{copied ? t('تم النسخ', 'Copied') : t('نسخ الرابط', 'Copy link')}</Button>
          <Button className="gap-1.5 rounded-full" onClick={() => window.print()}><Printer className="h-4 w-4" />{t('تحميل PDF', 'Download PDF')}</Button>
        </div>
      </div>

      <div className="overflow-x-auto">
        <CertificateView certificate={cert} />
      </div>

      <div className="print:hidden">
        <div className="flex items-center gap-2 p-4 rounded-2xl border bg-green-50/60 text-sm text-green-700">
          <Check className="h-4 w-4 shrink-0" />
          {t('شهادة موثقة — يمكن التحقق من صحتها بمسح رمز QR أو عبر رابط التحقق', 'Verified certificate — anyone can verify it by scanning the QR code or opening the verify link')}
        </div>
      </div>
    </div>
  );
}
