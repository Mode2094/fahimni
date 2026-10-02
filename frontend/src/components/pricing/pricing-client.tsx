'use client';

import { useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { currencyLabel } from '@/lib/utils';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Check, Crown, Loader2, ArrowLeft, ArrowRight, ShieldCheck, CreditCard, ChevronDown, HardDrive, Users, BookOpen, Film, Radio, ClipboardList, HelpCircle, Smartphone } from 'lucide-react';
import { useRouter } from 'next/navigation';
import PlanAssistant from '@/components/assistant/plan-assistant';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function PricingClient() {
  const { locale } = useLocale();
  const { formatPrice, load, settings } = useSettings();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [plans, setPlans] = useState<any[]>([]);
  const [currentPlan, setCurrentPlan] = useState<any>(null);
  const [currentSub, setCurrentSub] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [loggedIn, setLoggedIn] = useState(false);
  const [cycle, setCycle] = useState<'monthly' | 'yearly'>('monthly');
  const router = useRouter();

  useEffect(() => {
    load();
    const token = localStorage.getItem('lms_authed');
    setLoggedIn(!!token);
    fetch(`${API}/public/plans`).then(r => r.json()).then(d => setPlans(d.plans || [])).catch(() => {}).finally(() => setLoading(false));
    if (token) {
      const tid = localStorage.getItem('tenant_id');
      fetch(`${API}/subscriptions/current`, { headers: { 'X-Tenant-Id': tid || '', Accept: 'application/json' } })
        .then(r => r.json()).then(d => { if (d.subscription) { setCurrentSub(d.subscription); setCurrentPlan(d.subscription.plan); } }).catch(() => {});
    }
  }, []);

  const yearlyDiscount = (p: any): number | null => {
    const m = Number(p.monthly_price);
    const a = Number(p.annual_price);
    if (!m || !a || a <= 0) return null;
    const pct = Math.round((1 - a / (m * 12)) * 100);
    return pct > 0 ? pct : null;
  };

  const priceOf = (p: any): number => {
    const v = cycle === 'yearly' ? Number(p.annual_price) : Number(p.monthly_price);
    return Number.isFinite(v) ? v : 0;
  };

  const handlePick = (plan: any) => {
    if (!loggedIn) { router.push('/register/teacher'); return; }
    router.push(`/checkout?plan=${plan.id}&cycle=${cycle}`);
  };

  /** الميزات الرئيسية المعروضة في البطاقة — مرتبة ومختصرة */
  const keyFeatures = (p: any): { icon: any; label: string; display: string }[] => {
    const f = (key: string) => (p.features || []).find((x: any) => (x.feature_key || x.key) === key);
    const val = (key: string, fallback: string) => {
      const item = f(key);
      if (!item) return fallback;
      const d = (locale === 'ar' ? item.display_ar : item.display_en) || item.display || item.feature_value || String(item.value ?? '');
      return d === 'مفعل' ? '' : d;
    };

    return [
      { icon: HardDrive, label: t('التخزين', 'Storage'), display: val('storage_limit_mb', '—') },
      { icon: Users, label: t('الطلاب', 'Students'), display: val('students_limit', '—') },
      { icon: BookOpen, label: t('الكورسات', 'Courses'), display: val('courses_limit', '—') },
      { icon: Film, label: t('الفيديوهات', 'Videos'), display: val('videos_limit', '—') },
      { icon: Radio, label: t('بث مباشر', 'Live'), display: val('live_stream_enabled', '') === '' && f('live_stream_enabled') ? t('مفعل', 'Yes') : val('live_stream_enabled', t('—', '—')) },
      { icon: ClipboardList, label: t('الاختبارات', 'Exams'), display: val('exams_limit', '—') },
      { icon: HelpCircle, label: t('الأسئلة', 'Questions'), display: val('questions_limit', '—') },
    ];
  };

  const allFeatures = (p: any) => (p.features || []).map((f: any) => ({
    label: (locale === 'ar' ? f.label_ar : f.label_en) || f.feature_key || f.key,
    display: (locale === 'ar' ? f.display_ar : f.display_en) || f.feature_value || f.value,
    type: f.type || 'custom',
    value: f.value ?? f.feature_value,
  }));

  return (
    <div className="min-h-screen bg-gradient-to-b from-slate-50 to-white">
      <div className="max-w-6xl mx-auto px-4 py-14">
        <div className="text-center mb-12">
          <h1 className="text-4xl font-bold mb-3">{t('خطط الأسعار', 'Pricing Plans')}</h1>
          <p className="text-gray-500 text-lg">{t('اختر الخطة المناسبة لمنصتك التعليمية', 'Choose the right plan for your platform')}</p>

          <div className="inline-flex items-center gap-1 p-1.5 rounded-full bg-gray-100 mt-6">
            <button onClick={() => setCycle('monthly')} className={`px-6 py-2.5 rounded-full text-sm font-bold transition-all ${cycle === 'monthly' ? 'bg-white text-teal-700 shadow-sm' : 'text-gray-500 hover:text-gray-800'}`}>
              {t('شهري', 'Monthly')}
            </button>
            <button onClick={() => setCycle('yearly')} className={`px-6 py-2.5 rounded-full text-sm font-bold transition-all ${cycle === 'yearly' ? 'bg-white text-teal-700 shadow-sm' : 'text-gray-500 hover:text-gray-800'}`}>
              {t('سنوي', 'Yearly')}
              {plans.some((p) => yearlyDiscount(p) !== null) && (
                <span className="ms-2 px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-[10px]">-{Math.max(...plans.map((p) => yearlyDiscount(p) ?? 0))}%</span>
              )}
            </button>
          </div>

          {currentPlan && (
            <div className="inline-flex items-center gap-2 mt-4 px-5 py-2.5 rounded-full bg-green-50 border border-green-200 text-green-700 text-sm font-medium">
              <ShieldCheck className="h-4 w-4" />{t('خطتك الحالية', 'Your current plan')}: <b>{currentPlan.name}</b>
            </div>
          )}
        </div>

        {loading ? <div className="flex justify-center py-16"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div> : (
          <div className="grid md:grid-cols-3 gap-6 max-w-5xl mx-auto">
            {plans.map((p: any, i: number) => {
              const disc = yearlyDiscount(p);
              const popular = p.popular === true || i === Math.floor(plans.length / 2);
              const isCurrent = currentPlan?.id === p.id;
              const price = String(formatPrice(priceOf(p))).split(' ')[0];
              const kf = keyFeatures(p);
              const all = allFeatures(p);
              const appF = (p.features || []).find((x: any) => (x.feature_key || x.key) === 'student_mobile_app');
              const appOn = appF ? ['true', '1', 'on'].includes(String(appF.value ?? appF.feature_value)) : false;

              return (
                <Card key={p.id} className={`relative border-0 shadow-sm p-6 flex flex-col ${popular ? 'ring-2 ring-teal-600 shadow-xl md:scale-[1.04]' : ''}`}>
                  {isCurrent && (
                    <div className="absolute -top-3 left-1/2 -translate-x-1/2 inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full bg-green-500 text-white text-xs font-bold shadow-lg whitespace-nowrap z-10">
                      <Check className="h-3.5 w-3.5" />{t('خطتك الحالية', 'Your plan')}
                    </div>
                  )}

                  {/* ─── تطبيق الطلاب (Mobile App) — ميزة بارزة في الأعلى ─── */}
                  {!settings.student_app_hidden && (
                  <div className={`-mx-6 -mt-6 mb-6 flex items-center gap-3 rounded-t-2xl px-4 py-3.5 ${appOn ? 'bg-gradient-to-r from-violet-600 via-purple-600 to-fuchsia-600' : 'bg-gradient-to-r from-violet-50 to-fuchsia-50 border-b-2 border-violet-100'}`}>
                    <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl shadow-sm ${appOn ? 'bg-white/20' : 'bg-white border border-violet-200'}`}>
                      <Smartphone className={`h-5 w-5 ${appOn ? 'text-white' : 'text-violet-600'}`} />
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className={`text-[13px] font-extrabold leading-snug ${appOn ? 'text-white' : 'text-violet-800'}`}>
                        {t('تطبيق الطلاب (Mobile App)', 'Student Mobile App')}
                      </p>
                      <p className={`text-[10px] font-medium leading-snug ${appOn ? 'text-white/80' : 'text-violet-500'}`}>
                        {t('التطبيق الرسمي على هاتف طلابك', 'Official mobile app for your students')}
                      </p>
                    </div>
                    <span className={`shrink-0 rounded-full px-2.5 py-1 text-[10px] font-extrabold whitespace-nowrap ${appOn ? 'bg-white text-violet-700' : 'bg-violet-200/80 text-violet-700'}`}>
                      {appOn ? t('مفعل ✓', 'Included') : t('غير مفعل', 'Not included')}
                    </span>
                  </div>
                  )}

                  <div className="flex items-center gap-2 mb-2">
                    <Crown className="h-5 w-5 text-teal-600" />
                    <h3 className="text-xl font-bold">{p.name}</h3>
                    {popular && <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-teal-50 text-teal-700 text-[10px] font-bold border border-teal-100">{t('الأكثر شيوعاً', 'Most Popular')}</span>}
                  </div>
                  <div className="text-4xl font-extrabold mb-1">
                    {price}
                    <span className="text-sm font-normal text-gray-400 ms-1">{currencyLabel('JOD', locale === 'ar')} / {cycle === 'yearly' ? t('سنة', 'year') : t('شهر', 'mo')}</span>
                  </div>
                  {cycle === 'yearly' && disc !== null ? (
                    <p className="text-xs font-bold text-green-600 mb-6">{t('وفّرت', 'You save')} {disc}%</p>
                  ) : <div className="mb-6" />}

                  {/* الحدود الرئيسية */}
                  <div className="grid grid-cols-2 gap-2.5 mb-5">
                    {kf.map((f, j) => (
                      <div key={j} className="rounded-xl border border-slate-100 bg-slate-50/60 px-3 py-2.5">
                        <div className="flex items-center gap-1.5 text-[10px] text-gray-500 mb-0.5">
                          <f.icon className="h-3 w-3 text-teal-600" />
                          <span className="font-semibold truncate">{f.label}</span>
                        </div>
                        <p className="text-sm font-bold text-slate-800 truncate">{f.display}</p>
                      </div>
                    ))}
                  </div>

                  {/* كل الميزات (قابلة للفتح) */}
                  {all.length > 0 && (
                    <details className="group mb-5">
                      <summary className="flex items-center justify-center gap-1.5 cursor-pointer text-xs font-semibold text-teal-700 hover:text-teal-800 list-none">
                        {t('عرض كل الميزات', 'View all features')}
                        <ChevronDown className="h-3.5 w-3.5 transition-transform group-open:rotate-180" />
                      </summary>
                      <div className="mt-3 space-y-1.5 max-h-52 overflow-y-auto pe-1">
                        {all.map((f: any, j: number) => {
                          const off = f.type === 'boolean' && !['true', '1', 'on'].includes(String(f.value));
                          return (
                            <div key={j} className={`flex items-center gap-2 text-xs ${off ? 'opacity-50' : ''}`}>
                              <Check className={`h-3.5 w-3.5 shrink-0 ${off ? 'text-gray-300' : 'text-green-500'}`} />
                              <span className={`${off ? 'text-gray-400' : 'text-gray-600'}`}>{f.label}{f.display ? ` · ${f.display}` : ''}</span>
                            </div>
                          );
                        })}
                      </div>
                    </details>
                  )}

                  <div className="mt-auto">
                    {isCurrent ? (
                      <Button className="w-full rounded-full bg-green-100 text-green-700 hover:bg-green-100 cursor-default">{t('أنت مشترك في هذه الخطة', 'You are subscribed')}</Button>
                    ) : (
                      <Button className="w-full rounded-full" variant={popular ? 'default' : 'outline'} onClick={() => handlePick(p)}>
                        <CreditCard className="h-4 w-4" />{loggedIn ? t('اشترك الآن', 'Subscribe now') : t('ابدأ الآن', 'Start')}
                      </Button>
                    )}
                  </div>
                </Card>
              );
            })}
          </div>
        )}

        <p className="text-center text-sm text-gray-400 mt-10 flex items-center justify-center gap-1.5"><ShieldCheck className="h-4 w-4 text-green-500" />{t('دفع آمن · إلغاء في أي وقت', 'Secure payment · cancel anytime')}</p>

        <PlanAssistant />
      </div>
    </div>
  );
}
