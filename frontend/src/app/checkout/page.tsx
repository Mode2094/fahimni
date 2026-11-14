'use client';

import { useEffect, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { Suspense } from 'react';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Loader2, ShieldCheck, CheckCircle2, Crown, ArrowLeft, ArrowRight, Lock, Zap, Headset, ExternalLink, CreditCard, Clock } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

type Gateway = { enabled: boolean; configured: boolean; sandbox?: boolean; public_key?: string; product_id?: string };

function CheckoutInner() {
  const { locale } = useLocale();
  const { load: loadSettings } = useSettings();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const router = useRouter();
  const search = useSearchParams();

  const [plan, setPlan] = useState<any>(null);
  const [cycle, setCycle] = useState<'monthly' | 'yearly'>(() => (search.get('cycle') === 'yearly' ? 'yearly' : 'monthly'));
  const [gateway, setGateway] = useState<Gateway>({ enabled: false, configured: false });
  const [loading, setLoading] = useState(true);
  const [paying, setPaying] = useState(false);

  useEffect(() => {
    loadSettings();
    const planId = search.get('plan');
    const token = localStorage.getItem('lms_authed');
    if (!token) { router.replace('/teacher/login'); return; }
    if (!planId) { router.replace('/pricing'); return; }
    fetch(`${API}/public/plans`).then(r => r.json()).then(d => {
      const found = (d.plans || []).find((p: any) => p.id === planId);
      if (found) setPlan(found);
      else router.replace('/pricing');
    }).catch(() => {});
    fetch(`${API}/payment-gateways`).then(r => r.json()).then(d => {
      setGateway(d.freemius || { enabled: false, configured: false });
    }).catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  const monthly = plan?.monthly_price ? parseFloat(plan.monthly_price) : 0;
  const yearly = plan?.annual_price ? parseFloat(plan.annual_price) : 0;
  const price = cycle === 'yearly' ? yearly : monthly;
  const yearlySavings = monthly > 0 && yearly > 0 ? Math.round(monthly * 12 - yearly) : 0;
  // Platform-subscription prices are always USD (Freemius does not support
  // JOD). Never fall back to the platform (booking) currency.
  const currency = plan?.currency || 'USD';
  const fmt = (n: number) => Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });

  const ready = gateway.enabled && gateway.configured;

  const pay = async () => {
    if (!plan) return;
    setPaying(true);
    try {
      const res = await fetch(`${API}/subscriptions/freemius/checkout`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Tenant-Id': localStorage.getItem('lms_tenant') || '',
        },
        body: JSON.stringify({
          plan_id: plan.id,
          billing_cycle: cycle,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        toast.error(data.message || t('فشل بدء الدفع', 'Failed to start checkout'));
        setPaying(false);
        return;
      }
      if (data.checkout_url) {
        // Redirect to the Freemius hosted checkout. Activation is confirmed
        // server-side (webhook / API re-fetch) — never by this redirect.
        window.location.href = data.checkout_url;
        return;
      }
      toast.error(t('لم يُرجع رابط الدفع', 'No payment link returned'));
      setPaying(false);
    } catch {
      toast.error(t('فشلت عملية الدفع', 'Payment failed'));
      setPaying(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center">
        <Loader2 className="w-8 h-8 animate-spin text-primary" />
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-muted/30 py-8 px-4" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
      <div className="max-w-3xl mx-auto">
        <button
          onClick={() => router.back()}
          className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground mb-6"
        >
          {locale === 'ar' ? <ArrowRight className="w-4 h-4" /> : <ArrowLeft className="w-4 h-4" />}
          {t('رجوع', 'Back')}
        </button>

        <div className="text-center mb-8">
          <h1 className="text-3xl font-bold mb-2">{t('أكمل اشتراكك', 'Complete your subscription')}</h1>
          <p className="text-muted-foreground">{t('خطوة واحدة وتفعّل خطتك فورًا', 'One step away from activating your plan')}</p>
        </div>

        <div className="grid md:grid-cols-2 gap-6">
          {/* Plan summary */}
          <Card>
            <CardContent className="p-6">
              <div className="flex items-center gap-2 mb-4">
                <Crown className="w-5 h-5 text-primary" />
                <h2 className="text-lg font-semibold">{t('ملخص الخطة', 'Plan summary')}</h2>
              </div>
              {plan ? (
                <>
                  <div className="text-2xl font-bold mb-1">{plan.name}</div>
                  {plan.description && <p className="text-sm text-muted-foreground mb-4">{plan.description}</p>}

                  <div className="flex gap-2 mb-4">
                    <button
                      onClick={() => setCycle('monthly')}
                      className={`flex-1 py-2 px-3 rounded-lg text-sm border transition ${cycle === 'monthly' ? 'border-primary bg-primary/5 text-primary' : 'border-border'}`}
                    >
                      {t('شهري', 'Monthly')}
                    </button>
                    <button
                      onClick={() => setCycle('yearly')}
                      className={`flex-1 py-2 px-3 rounded-lg text-sm border transition ${cycle === 'yearly' ? 'border-primary bg-primary/5 text-primary' : 'border-border'}`}
                    >
                      {t('سنوي', 'Yearly')}
                    </button>
                  </div>

                  <div className="flex items-baseline gap-1 mb-2">
                    <span className="text-3xl font-bold">{fmt(price)}</span>
                    <span className="text-sm text-muted-foreground">
                      {cycle === 'yearly' ? t('/ سنويًا', '/ year') : t('/ شهريًا', '/ month')}
                    </span>
                  </div>
                  <div className="text-xs text-muted-foreground">{currency}</div>

                  {cycle === 'yearly' && yearlySavings > 0 && (
                    <Badge className="mt-3 bg-green-100 text-green-700 border-0">
                      {t(`توفير ${fmt(yearlySavings)}`, `Save ${fmt(yearlySavings)}`)}
                    </Badge>
                  )}
                </>
              ) : (
                <Loader2 className="w-5 h-5 animate-spin" />
              )}
            </CardContent>
          </Card>

          {/* Payment */}
          <Card>
            <CardContent className="p-6">
              <div className="flex items-center gap-2 mb-4">
                <CreditCard className="w-5 h-5 text-primary" />
                <h2 className="text-lg font-semibold">{t('الدفع', 'Payment')}</h2>
              </div>

              {ready ? (
                <>
                  <div className="rounded-lg border border-border p-4 mb-4">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center">
                          <Lock className="w-5 h-5 text-primary" />
                        </div>
                        <div>
                          <div className="font-medium">{t('دفع آمن عبر Freemius', 'Secure payment via Freemius')}</div>
                          <div className="text-xs text-muted-foreground">
                            {t('بطاقة / Apple Pay / Google Pay', 'Card / Apple Pay / Google Pay')}
                          </div>
                        </div>
                      </div>
                      <CheckCircle2 className="w-5 h-5 text-primary" />
                    </div>
                    {gateway.sandbox && (
                      <Badge className="mt-3 bg-amber-100 text-amber-700 border-0">{t('وضع الاختبار', 'Test mode')}</Badge>
                    )}
                  </div>

                  <div className="border-t pt-4 mb-4">
                    <div className="flex items-center justify-between text-sm mb-1">
                      <span className="text-muted-foreground">{t('الإجمالي', 'Total')}</span>
                      <span className="font-semibold text-lg">{fmt(price)} {currency}</span>
                    </div>
                  </div>

                  <Button
                    onClick={pay}
                    disabled={paying || !plan}
                    className="w-full"
                    size="lg"
                  >
                    {paying ? (
                      <Loader2 className="w-4 h-4 animate-spin" />
                    ) : (
                      <>
                        {t('المتابعة للدفع', 'Continue to payment')}
                        <ExternalLink className="w-4 h-4 ms-2" />
                      </>
                    )}
                  </Button>

                  <div className="flex items-center gap-2 mt-4 text-xs text-muted-foreground justify-center">
                    <ShieldCheck className="w-4 h-4" />
                    {t('دفع آمن ومشفّر', 'Secure encrypted payment')}
                  </div>
                </>
              ) : (
                <div className="text-center py-8">
                  <Clock className="w-8 h-8 text-muted-foreground mx-auto mb-3" />
                  <p className="text-sm text-muted-foreground">
                    {t('الدفع غير متاح حاليًا. يرجى المحاولة لاحقًا.', 'Checkout is temporarily unavailable. Please try again later.')}
                  </p>
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Trust badges */}
        <div className="grid grid-cols-3 gap-4 mt-8">
          {[
            { icon: ShieldCheck, ar: 'أمان كامل', en: 'Fully secure' },
            { icon: Zap, ar: 'تفعيل فوري', en: 'Instant activation' },
            { icon: Headset, ar: 'دعم متواصل', en: 'Ongoing support' },
          ].map(({ icon: Icon, ar, en }) => (
            <div key={en} className="text-center">
              <Icon className="w-5 h-5 text-primary mx-auto mb-2" />
              <div className="text-xs text-muted-foreground">{t(ar, en)}</div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

export default function CheckoutPage() {
  return (
    <Suspense fallback={<div className="min-h-[60vh] flex items-center justify-center"><Loader2 className="w-8 h-8 animate-spin text-primary" /></div>}>
      <CheckoutInner />
    </Suspense>
  );
}
