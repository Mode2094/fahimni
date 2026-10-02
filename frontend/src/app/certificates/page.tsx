'use client';

import { useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { CardSkeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { Award, ShieldCheck, Eye } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function CertificatesPage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [certificates, setCertificates] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    fetch(`${API}/student/certificates`, { headers: { 'X-Tenant-Id': tid || '', Accept: 'application/json' } })
      .then(r => r.json()).then(d => setCertificates(d.certificates || [])).catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="space-y-6 max-w-5xl mx-auto">
      <div className="flex items-center gap-3">
        <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-500/10">
          <Award className="h-6 w-6 text-amber-500" />
        </span>
        <div>
          <h1 className="text-2xl sm:text-3xl font-bold">{t('الشهادات', 'Certificates')}</h1>
          <p className="text-muted-foreground mt-1">{t('شهادات إتمام مع تحقق QR ومشاركة مباشرة', 'Completion certificates with QR verification and direct sharing')}</p>
        </div>
      </div>
      {loading ? <CardSkeleton count={3} /> :
        certificates.length === 0 ? (
          <EmptyState icon={Award} title={t('لا توجد شهادات بعد', 'No certificates yet')} description={t('أكمل دورة مؤهلة للشهادة بالكامل وسيتم إصدار شهادتك تلقائياً', 'Complete an eligible course fully and your certificate will be issued automatically')} />
        ) : (
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            {certificates.map((c) => (
              <Card key={c.id} className="border-0 shadow-sm overflow-hidden flex flex-col group">
                <div className="relative p-6 text-center overflow-hidden" style={{ background: 'linear-gradient(135deg, #0f2e2b, #115e54 55%, #0d9488)' }}>
                  <div className="absolute inset-0 opacity-[0.07]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '20px 20px' }} />
                  <div className="absolute -top-8 -end-8 h-24 w-24 rounded-full bg-amber-400/15 blur-2xl" />
                  <div className="relative mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-amber-400/20 border border-amber-300/30 transition-transform group-hover:scale-110 duration-300"><Award className="h-8 w-8 text-amber-400" /></div>
                  <h3 className="font-bold text-white relative">{c.course_title}</h3>
                  <p className="text-white/60 text-sm mt-1 relative">{c.student_name}</p>
                  {c.issued_at && <p className="text-white/40 text-[10px] mt-2 relative">{new Date(c.issued_at).toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US')}</p>}
                </div>
                <CardContent className="p-5 flex flex-col gap-2.5 flex-1">
                  <div className="flex items-center gap-1.5 text-[10px] text-gray-400">
                    <ShieldCheck className="h-3 w-3 text-green-500 shrink-0" />
                    <span dir="ltr" className="font-mono truncate">{c.certificate_number}</span>
                  </div>
                  <div className="flex items-center gap-2 mt-auto">
                    <a href={`/certificates/${c.id}`} className="flex-1"><Button className="w-full rounded-full gap-1 text-white shadow-brand" size="sm" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}><Eye className="h-4 w-4" /> {t('عرض ومشاركة', 'View & share')}</Button></a>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
    </div>
  );
}
