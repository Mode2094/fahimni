'use client';

import { useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { CheckCircle2, HelpCircle, Loader2, Crown } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function TeacherFeaturesCard() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);


  useEffect(() => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    if (!token) return;
    fetch(`${API}/teacher/plan`, {
      headers: { 'X-Tenant-Id': tid || '', Accept: 'application/json' },
    })
      .then((r) => r.json())
      .then((d) => setData(d))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <Card className="border-0 shadow-sm"><CardContent className="p-5 flex items-center justify-center gap-2 text-sm text-gray-400">
        <Loader2 className="h-4 w-4 animate-spin" /> {t('جاري تحميل ميزات خطتك...', 'Loading your plan features...')}
      </CardContent></Card>
    );
  }

  const matrix: any[] = data?.feature_matrix || [];
  if (matrix.length === 0) return null;

  // الميزات المتاحة فقط — غير المتاحة لا تظهر إطلاقاً.
  const visible = matrix.filter((m) => m.enabled);

  const fmtValue = (m: any) => {
    if (m.type === 'boolean') return null;
    if (m.type === 'select') {
      return m.value === 'email' ? t('بريد إلكتروني', 'Email') : m.value === 'priority' ? t('أولوية', 'Priority') : m.value === 'dedicated' ? t('مخصص', 'Dedicated') : m.value;
    }
    if (m.key === 'storage_limit_mb' && Number(m.value) > 0) return `${Math.round(Number(m.value) / 1024)} GB`;
    if (m.value === 'unlimited') return t('غير محدود', 'Unlimited');
    return m.value;
  };

  return (
    <Card className="border-0 shadow-sm">
      <CardContent className="p-6">
        <div className="flex items-center justify-between mb-5 flex-wrap gap-2">
          <div className="flex items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-500/10">
              <Crown className="h-4.5 w-4.5 text-amber-500" />
            </span>
            <div>
              <h3 className="font-bold text-sm">{t('ميزات خطتك', 'Your plan features')}</h3>
              <p className="text-[11px] text-muted-foreground">{t('كل ما يمكنك استخدامه الآن', 'Everything available to you now')}</p>
            </div>
          </div>
          <Badge className="bg-emerald-100 text-emerald-700 border-0 rounded-full">{visible.length} {t('ميزة متاحة', 'features')}</Badge>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
          {visible.map((m) => (
            <div
              key={m.key}
              className="group relative flex items-center gap-2.5 rounded-xl px-3 py-2 text-[13px] bg-slate-50 transition-colors hover:bg-teal-50/50"
            >
              <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10">
                <CheckCircle2 className="h-4 w-4 text-emerald-500" />
              </span>
              <span className="min-w-0 text-slate-700">
                <span className="font-medium">{locale === 'ar' ? m.label_ar : m.label_en}</span>
                {fmtValue(m) && <span className="text-slate-400"> · <b>{fmtValue(m)}</b></span>}
              </span>
              {(m.desc_ar || m.desc_en) && (
                <span className="group relative inline-flex ms-auto shrink-0">
                  <HelpCircle className="h-3.5 w-3.5 text-gray-300 hover:text-teal-600 cursor-help transition-colors" />
                  <span className="pointer-events-none absolute bottom-full end-0 z-30 mb-1.5 hidden w-60 rounded-xl bg-gray-900 text-white text-[11px] leading-relaxed p-3 shadow-xl group-hover:block">
                    {locale === 'ar' ? m.desc_ar : m.desc_en}
                  </span>
                </span>
              )}
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
