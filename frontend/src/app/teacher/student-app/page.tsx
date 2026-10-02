'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/store';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import QRCode from 'qrcode';
import { toast } from 'sonner';
import { Smartphone, Copy, Check, Play, Apple, Loader2, QrCode, Share2, ExternalLink, Construction } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function TeacherStudentAppPage() {
  const { check } = useAuth();
  const { locale } = useLocale();
  const { settings, load: loadSettings } = useSettings();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [app, setApp] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState(false);
  const [qr, setQr] = useState<string | null>(null);

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const load = async () => {
    setLoading(true);
    try {
      const res = await fetch(`${API}/teacher/student-app`, { headers: headers() });
      if (!res.ok) throw new Error();
      const d = await res.json();
      setApp(d.student_app || null);
      if (d.student_app?.qr_payload) {
        const dataUrl = await QRCode.toDataURL(d.student_app.qr_payload, { width: 240, margin: 1, errorCorrectionLevel: 'M' });
        setQr(dataUrl);
      } else {
        setQr(null);
      }
    } catch {
      toast.error(t('تعذر تحميل حالة تطبيق الطلاب', 'Failed to load student app status'));
    }
    setLoading(false);
  };

  useEffect(() => {
    loadSettings();
    if (check()) load();
  }, [check()]);

  const copyCode = async () => {
    try {
      await navigator.clipboard.writeText(app?.teacher_code || '');
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
      toast.success(t('تم نسخ كود المعلم', 'Teacher code copied'));
    } catch {
      toast.error(t('تعذر النسخ', 'Copy failed'));
    }
  };

  if (loading) {
    return (
      <div className="max-w-3xl mx-auto space-y-4">
        <Skeleton className="h-40 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (settings.student_app_hidden) {
    return null;
  }

  const enabled = !!app?.enabled;

  return (
    <div className="max-w-3xl mx-auto">
      <div className="flex items-center gap-3 mb-6">
        <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10">
          <Smartphone className="h-6 w-6 text-teal-600" />
        </span>
        <div>
          <h1 className="text-2xl sm:text-3xl font-bold">{t('تطبيق الطلاب', 'Student App')}</h1>
          <p className="text-muted-foreground mt-1">{t('التطبيق الرسمي — شارك كود المعلم مع طلابك', 'Official app — share your teacher code with students')}</p>
        </div>
      </div>

      {!enabled ? (
        <Card className="border-0 shadow-sm">
          <CardContent className="p-8 text-center space-y-3">
            <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-50">
              <Construction className="h-7 w-7 text-amber-500" />
            </span>
            <h2 className="text-xl font-bold">{t('تطبيق الطلاب', 'Student App')}</h2>
            <p className="text-gray-500">{t('قيد التطوير', 'Under development')}</p>
            <p className="text-sm text-gray-400">{t('سنخبرك عند توفر التطبيق.', 'We will let you know when the app is available.')}</p>
            <Badge className="bg-amber-50 text-amber-700 border-0">{t('قيد التطوير', 'Under development')}</Badge>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-6">
          <Card className="border-0 shadow-sm">
            <CardContent className="p-6">
              <div className="flex items-center justify-between mb-1">
                <h2 className="text-lg font-bold">{t('تطبيق الطلاب', 'Student App')}</h2>
                <Badge className="bg-green-100 text-green-700 border-0">{t('مفعّل', 'Active')}</Badge>
              </div>
              <p className="text-sm text-gray-400 mb-6">{t('أرسل لطلابك الكود التالي ليفتحوا التطبيق ويربطوه بمنصتك.', 'Send your students the code below so they can open the app and link it to your platform.')}</p>

              {/* كود المعلم */}
              <div className="rounded-2xl border-2 border-dashed border-teal-300 bg-teal-50/40 p-6 text-center">
                <p className="text-sm font-semibold text-teal-800 mb-3">{t('كود المعلم', 'Teacher Code')}</p>
                <p className="text-3xl sm:text-4xl font-black tracking-widest text-teal-900 mb-4" dir="ltr">{app.teacher_code}</p>
                <Button onClick={copyCode} className="gap-2 rounded-full">
                  {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                  {copied ? t('تم النسخ', 'Copied') : t('نسخ الكود', 'Copy code')}
                </Button>
              </div>

              {/* روابط المتاجر */}
              {(app.android_url || app.ios_url) && (
                <div className="mt-6">
                  <p className="text-sm font-semibold mb-3">{t('روابط التطبيق', 'App links')}</p>
                  <div className="flex flex-wrap gap-3">
                    {app.android_url && (
                      <a href={app.android_url} target="_blank" rel="noreferrer">
                        <Button variant="outline" className="gap-2 rounded-full">
                          <Play className="h-4 w-4" />Google Play <ExternalLink className="h-3.5 w-3.5 text-gray-400" />
                        </Button>
                      </a>
                    )}
                    {app.ios_url && (
                      <a href={app.ios_url} target="_blank" rel="noreferrer">
                        <Button variant="outline" className="gap-2 rounded-full">
                          <Apple className="h-4 w-4" />App Store <ExternalLink className="h-3.5 w-3.5 text-gray-400" />
                        </Button>
                      </a>
                    )}
                  </div>
                </div>
              )}
            </CardContent>
          </Card>

          {/* QR + التعليمات */}
          <Card className="border-0 shadow-sm">
            <CardContent className="p-6 grid sm:grid-cols-2 gap-6 items-center">
              <div className="text-center">
                {qr ? (
                  <img src={qr} alt="QR" className="mx-auto w-52 h-52 rounded-2xl border p-2 bg-white" />
                ) : (
                  <div className="mx-auto flex h-52 w-52 items-center justify-center rounded-2xl border bg-slate-50 text-gray-300">
                    <QrCode className="h-16 w-16" />
                  </div>
                )}
                <p className="text-xs text-gray-400 mt-2">{t('امسح الرمز — يفتح التطبيق مباشرة على شاشة كود المعلم', 'Scan — opens the app directly on the teacher-code screen')}</p>
              </div>
              <div className="space-y-4">
                <h3 className="font-bold flex items-center gap-2"><Share2 className="h-4 w-4 text-teal-600" />{t('كيف يبدأ طلابك؟', 'How do your students start?')}</h3>
                {[
                  t('حمّل التطبيق من Google Play أو App Store.', 'Download the app from Google Play or App Store.'),
                  t('افتح التطبيق وأدخل كود المعلم أعلاه.', 'Open the app and enter your teacher code above.'),
                  t('سجل الدخول بنفس حسابهم على المنصة.', 'Log in with the same account they use on the platform.'),
                ].map((s, i) => (
                  <div key={i} className="flex gap-3">
                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-teal-600 text-white text-xs font-bold shrink-0">{i + 1}</span>
                    <p className="text-sm text-gray-600">{s}</p>
                  </div>
                ))}
                <p className="text-xs text-gray-400 pt-2 border-t">{t('تغيير الكود يتم فقط من جهة المنصة، ولا يمكن للمعلم تغييره من هنا.', 'The code can only be changed by the platform — teachers cannot change it here.')}</p>
              </div>
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  );
}