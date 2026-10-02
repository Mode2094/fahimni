'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { CardSkeleton } from '@/components/ui/skeleton';
import { Alert } from '@/components/ui/alert';
import { Radio, CheckCircle2, XCircle, RefreshCw, Video, ExternalLink } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function AdminLivePage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [provider, setProvider] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const headers = () => ({ Accept: 'application/json' });

  const load = () => {
    fetch(`${API}/admin/live/providers`, { headers: headers() })
      .then(r => r.json())
      .then(d => { setProvider(d.providers?.google_meet || null); setError(''); })
      .catch(() => setError(t('تعذر تحميل البيانات', 'Failed to load data')))
      .finally(() => setLoading(false));
  };

  useEffect(() => { if (check()) load(); }, [check]);

  if (loading) return <div className="space-y-6 max-w-4xl mx-auto"><CardSkeleton count={2} /></div>;

  const ready = !!provider?.health?.ok;

  return (
    <div className="space-y-6 max-w-4xl mx-auto">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
            <Radio className="h-6 w-6 text-teal-700" />
          </span>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold">{t('البث المباشر', 'Live Streaming')}</h1>
            <p className="text-muted-foreground mt-1">{t('Google Meet — المعلم يضيف رابط الحصة الخاص به', 'Google Meet — the teacher adds their own meeting link')}</p>
          </div>
        </div>
        <button onClick={load} className="flex items-center gap-2 rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50">
          <RefreshCw className="h-4 w-4" /> {t('تحديث', 'Refresh')}
        </button>
      </div>

      {error && <Alert variant="error" icon={XCircle}>{error}</Alert>}

      <Card className="border-0 shadow-sm">
        <CardContent className="p-6 space-y-4">
          <div className="flex items-center justify-between gap-2 flex-wrap">
            <div className="flex items-center gap-2">
              <h3 className="font-bold text-lg">{provider?.label || 'Google Meet'}</h3>
              <Badge className={ready ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'}>
                {ready ? t('جاهز', 'Ready') : t('غير جاهز', 'Not ready')}
              </Badge>
              <Badge className="bg-teal-100 text-teal-700">{t('افتراضي', 'Default')}: {t('نعم', 'Yes')}</Badge>
            </div>
          </div>

          <div className="rounded-xl border border-teal-100 bg-teal-50/60 p-4 flex items-start gap-3">
            <Video className="h-5 w-5 text-teal-600 shrink-0 mt-0.5" />
            <div className="space-y-1">
              <p className="text-sm font-bold text-teal-800">
                {t('المعلم يضيف رابط meet.google.com الخاص به عند إنشاء الحصة.', 'The teacher adds their own meet.google.com link when creating a session.')}
              </p>
              <p className="text-xs text-teal-700 leading-relaxed">
                {t('لا يحتاج Google Meet أي اعتمادات أو API أو ربط حساب — لا Client ID ولا Client Secret ولا Refresh Token.', 'Google Meet requires no credentials, API, or account linking — no Client ID, no Client Secret, no Refresh Token.')}
              </p>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
            <div className="flex items-center gap-2 text-gray-600">
              <CheckCircle2 className="h-4 w-4 text-green-600" />
              {t('لا Google OAuth ولا Google Calendar API', 'No Google OAuth, no Google Calendar API')}
            </div>
            <div className="flex items-center gap-2 text-gray-600">
              <CheckCircle2 className="h-4 w-4 text-green-600" />
              {t('الطلاب المسجلون فقط يرون زر دخول الحصة', 'Only enrolled students see the join button')}
            </div>
          </div>

          <a href="https://meet.google.com" target="_blank" rel="noopener noreferrer"
            className="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:underline">
            <ExternalLink className="h-4 w-4" /> meet.google.com
          </a>
        </CardContent>
      </Card>
    </div>
  );
}