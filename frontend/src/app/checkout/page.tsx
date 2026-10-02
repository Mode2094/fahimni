'use client';

import { useEffect, useRef, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { Suspense } from 'react';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { currencyLabel } from '@/lib/utils';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Loader2, ShieldCheck, CheckCircle2, Crown, ArrowLeft, ArrowRight, Landmark, Building2, Lock, Tag, Zap, Headset, Sparkles, RefreshCcw, Clock, Upload, CreditCard, Smartphone, ShoppingCart } from 'lucide-react';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

function CheckoutInner() {
  const { locale } = useLocale();
  const { formatPrice, load: loadSettings, settings } = useSettings();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const router = useRouter();
  const search = useSearchParams();

  const [plan, setPlan] = useState<any>(null);
  const [cycle, setCycle] = useState<'monthly' | 'yearly'>(() => (search.get('cycle') === 'yearly' ? 'yearly' : 'monthly'));
  const [method, setMethod] = useState<'paytabs' | 'paypal' | 'paddle' | 'lemonsqueezy' | 'manual'>('paytabs');
  const [gateways, setGateways] = useState<any>({ paytabs: { enabled: false, configured: false }, paypal: { enabled: false, configured: false }, paddle: { enabled: false, configured: false }, lemonsqueezy: { enabled: false, configured: false }, manual: { enabled: false, configured: false } });
  const [loading, setLoading] = useState(true);
  const [paying, setPaying] = useState(false);
  const [done, setDone] = useState(false);
  const [awaitingApproval, setAwaitingApproval] = useState(false);
  const [coupon, setCoupon] = useState('');
  const [manualForm, setManualForm] = useState({ payer_name: '', reference: '', notes: '' });
  const [proofFile, setProofFile] = useState<File | null>(null);

  // ── PayPal CardFields (inline) state ────────────────────────────────
  const [pp, setPp] = useState<{ payment_id: string; order_id: string; client_id: string; redirect_url: string; env: 'sandbox' | 'live' } | null>(null);
  const [ppStatus, setPpStatus] = useState<'idle' | 'creating' | 'sdk-loading' | 'ready' | 'submitting' | 'error'>('idle');
  const [ppEligible, setPpEligible] = useState<boolean | null>(null);
  const [ppMode, setPpMode] = useState<'advanced' | 'guest' | null>(null);
  const [ppRedirect, setPpRedirect] = useState(false);
  const [ppError, setPpError] = useState('');
  const cardFieldsRef = useRef<HTMLDivElement>(null);
  const ppInstanceRef = useRef<any>(null);

  // ── Paddle state ──────────────────────────────────────────────────────
  const [pdStatus, setPdStatus] = useState<'idle' | 'creating' | 'ready' | 'verifying' | 'error'>('idle');
  const [pdError, setPdError] = useState('');
  const [pd, setPd] = useState<{ payment_id: string; paddle_transaction_id: string; paddle_client_token: string; paddle_env: string } | null>(null);
  const pdRef = useRef<typeof pd>(null);
  const paddleRef = useRef<any>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const resetPayPalCard = () => {
    setPp(null);
    setPpStatus('idle');
    setPpEligible(null);
    setPpMode(null);
    setPpRedirect(false);
    setPpError('');
    ppInstanceRef.current = null;
  };

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
      const g = { paytabs: d.paytabs, paypal: d.paypal, paddle: d.paddle, lemonsqueezy: d.lemonsqueezy, manual: d.manual };
      setGateways(g);
      // Pick the first available gateway — never default to a disabled one.
      const order: any[] = [g.paypal, g.paddle, g.lemonsqueezy, g.paytabs, g.manual];
      const first = ['paypal', 'paddle', 'lemonsqueezy', 'paytabs', 'manual'].find((k, i) => order[i]?.enabled && order[i]?.configured);
      setMethod((first || 'paypal') as any);
    }).catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  // Reset payment states when billing cycle changes so the user initiates a fresh transaction
  useEffect(() => {
    setPdStatus('idle');
    setPd(null);
    setPpStatus('idle');
    setPp(null);
    setPpEligible(null);
  }, [cycle]);

  const monthly = plan?.monthly_price ? parseFloat(plan.monthly_price) : 0;
  const yearly = plan?.annual_price ? parseFloat(plan.annual_price) : 0;
  const price = cycle === 'yearly' ? yearly : monthly;
  const yearlySavings = monthly > 0 && yearly > 0 ? Math.round(monthly * 12 - yearly) : 0;
  const crossed = cycle === 'yearly' && yearlySavings > 0 ? monthly * 12 : 0;
  const currency = 'JOD';

  const methods: { id: 'paytabs' | 'paypal' | 'paddle' | 'lemonsqueezy' | 'manual'; label: string; icon: any; desc: string; show: boolean }[] = [
    { id: 'paytabs', label: 'PayTabs', icon: Landmark, desc: t('مدى / فيزا / ماستركارد', 'Mada / Visa / Mastercard'), show: gateways?.paytabs?.enabled && gateways?.paytabs?.configured },
    { id: 'paypal', label: 'PayPal', icon: Building2, desc: t('بطاقة فيزا / ماستركارد أو حساب باي بال', 'Visa / Mastercard or PayPal account'), show: gateways?.paypal?.enabled && gateways?.paypal?.configured },
    { id: 'paddle', label: 'Paddle', icon: CreditCard, desc: t('بطاقة / محفظة / طرق دفع محلية', 'Card / wallet / local payment methods'), show: gateways?.paddle?.enabled && gateways?.paddle?.configured },
    { id: 'lemonsqueezy', label: 'Lemon Squeezy', icon: ShoppingCart, desc: t('بطاقة / Apple Pay / Google Pay', 'Card / Apple Pay / Google Pay'), show: gateways?.lemonsqueezy?.enabled && gateways?.lemonsqueezy?.configured },
    { id: 'manual', label: gateways?.manual?.label || t('دفع يدوي (كليك/محفظة)', 'Manual (Click/wallet)'), icon: Landmark, desc: t('تحويل يدوي + رفع إثبات الدفع', 'Manual transfer + upload proof'), show: gateways?.manual?.enabled && gateways?.manual?.configured },
  ];
  const available = methods.filter((m) => m.show);

  const pay = async () => {
    if (!plan) return;
    // PayPal now defaults to inline CardFields (no redirect).
    if (method === 'paypal') { startPayPalCard(); return; }
    setPaying(true);
    try {
      const token = localStorage.getItem('lms_authed');
      const tid = localStorage.getItem('tenant_id');
      const h = { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };

      const body: any = { plan_id: plan.id, billing_cycle: cycle, gateway: method };
      if (coupon.trim()) body.coupon_code = coupon.trim();
      const res = await fetch(`${API}/payments/initiate`, { method: 'POST', headers: h, body: JSON.stringify(body) });
      const d = await res.json().catch(() => null);
      if (!res.ok) { toast.error(d?.message || t('فشل إنشاء الدفع', 'Failed to create payment')); return; }
      if (d?.status === 'completed' || d?.free) {
        // $0 checkout (100% coupon) — already paid, no gateway redirect.
        toast.success(t('تم تفعيل اشتراكك!', 'Your subscription is active!'));
        setDone(true);
        setTimeout(() => router.push('/teacher/dashboard'), 2000);
        return;
      }
      if (d?.status === 'awaiting_approval') {
        // Manual payment: submit the proof + transfer details next.
        if (!proofFile || !manualForm.payer_name.trim() || !manualForm.reference.trim()) {
          toast.error(t('أكمل بيانات التحويل وأرفق صورة الإثبات', 'Complete the transfer details and attach the proof image'));
          setPaying(false);
          return;
        }
        const fd = new FormData();
        fd.append('payer_name', manualForm.payer_name.trim());
        fd.append('reference', manualForm.reference.trim());
        fd.append('notes', manualForm.notes.trim());
        fd.append('proof', proofFile);
        const pr = await fetch(`${API}/payments/${d.payment_id}/manual-proof`, { method: 'POST', headers: { 'X-Tenant-Id': tid || '', Accept: 'application/json' }, body: fd });
        const pd = await pr.json().catch(() => null);
        if (!pr.ok) { toast.error(pd?.message || t('فشل إرسال الإثبات', 'Failed to submit proof')); return; }
        setAwaitingApproval(true);
        return;
      }
      if (d?.redirect_url) { window.location.href = d.redirect_url; return; }
      toast.error(t('لا يوجد رابط دفع', 'No payment link returned'));
      return;
    } catch {
      toast.error(t('فشلت عملية الدفع', 'Payment failed'));
    }
    setPaying(false);
  };

  // ── Paddle Checkout flow (client-side token only) ───────────────────
  // The backend creates the Paddle transaction with the server-computed
  // price; the frontend only opens it. The backend webhook is the single
  // source of truth for activation — the checkout.completed event here only
  // switches the UI to "verifying" and starts polling payment status.
  const loadPaddleScript = (): Promise<any> =>
    new Promise((resolve, reject) => {
      const existing = document.getElementById('paddle-v2-sdk') as HTMLScriptElement | null;
      if (existing) {
        if ((window as any).Paddle) return resolve((window as any).Paddle);
        existing.addEventListener('load', () => resolve((window as any).Paddle));
        existing.addEventListener('error', () => reject(new Error('Paddle CDN script failed to load')));
        return;
      }
      const script = document.createElement('script');
      script.id = 'paddle-v2-sdk';
      script.src = 'https://cdn.paddle.com/paddle/v2/paddle.js';
      const timer = setTimeout(() => reject(new Error('Paddle CDN script timed out')), 15000);
      script.onload = () => { clearTimeout(timer); resolve((window as any).Paddle); };
      script.onerror = () => { clearTimeout(timer); reject(new Error('Paddle CDN script failed to load')); };
      document.body.appendChild(script);
    });

  const initPaddle = async (token: string, env: string): Promise<any> => {
    if (paddleRef.current) return paddleRef.current;
    let paddle: any = null;
    try {
      paddle = await loadPaddleScript();
      paddle.Environment.set(env === 'live' ? 'production' : 'sandbox');
      paddle.Initialize({ token, eventCallback: handlePaddleEvent });
      paddleRef.current = paddle;
      return paddle;
    } catch (e) {
      // One retry on transient CDN / init failures.
      if (paddle === null) {
        try {
          paddle = await loadPaddleScript();
          paddle.Environment.set(env === 'live' ? 'production' : 'sandbox');
          paddle.Initialize({ token, eventCallback: handlePaddleEvent });
          paddleRef.current = paddle;
          return paddle;
        } catch {
          throw e;
        }
      }
      throw e;
    }
  };

  const openPaddleCheckout = async (txnId: string, token: string, env: string) => {
    const paddle = await initPaddle(token, env);
    if (!paddle?.Checkout) throw new Error('Paddle not initialized');
    paddle.Checkout.open({
      transactionId: txnId,
      settings: {
        displayMode: 'overlay',
        theme: 'light',
        locale: locale === 'ar' ? 'ar' : 'en',
        allowLogout: false,
        showAddDiscounts: false,
      },
    });
  };

  const handlePaddleEvent = (e: any) => {
    if (e?.name === 'checkout.completed') {
      setPdStatus('verifying');
      if (pdRef.current?.payment_id) pollPayment(pdRef.current.payment_id);
    } else if (e?.name === 'checkout.closed') {
      setPdStatus((s) => (s === 'verifying' ? s : 'ready'));
    } else if (e?.name === 'checkout.error' || e?.name === 'checkout.payment.failed') {
      toast.error(t('حدث خطأ في عملية الدفع — أعد المحاولة', 'Payment error — try again'));
      setPdStatus('ready');
    }
  };

  // Polling only READS the payment status — it never activates anything.
  const pollPayment = (paymentId: string) => {
    if (pollRef.current) clearInterval(pollRef.current);
    pollRef.current = setInterval(async () => {
      try {
        const token = localStorage.getItem('lms_authed');
        const tid = localStorage.getItem('tenant_id');
        const res = await fetch(`${API}/payments/${paymentId}`, {
          headers: { 'X-Tenant-Id': tid || '', Accept: 'application/json' },
        });
        const d = await res.json().catch(() => null);
        const st = d?.payment?.status;
        if (st === 'completed') {
          if (pollRef.current) clearInterval(pollRef.current);
          toast.success(t('تم تفعيل اشتراكك!', 'Your subscription is active!'));
          setDone(true);
          setTimeout(() => router.push('/teacher/dashboard'), 2000);
        } else if (st === 'failed' || st === 'refunded') {
          if (pollRef.current) clearInterval(pollRef.current);
          setPdStatus('error');
          setPdError(t('لم يكتمل الدفع — أعد المحاولة', 'Payment did not complete — try again'));
        }
      } catch {
        // transient — keep polling
      }
    }, 4000);
    setTimeout(() => { if (pollRef.current) clearInterval(pollRef.current); }, 90000);
  };

  const startPaddle = async () => {
    if (!plan) return;
    setPdStatus('creating');
    setPdError('');
    try {
      const token = localStorage.getItem('lms_authed');
      const tid = localStorage.getItem('tenant_id');
      const h = { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
      const body: any = { plan_id: plan.id, billing_cycle: cycle, gateway: 'paddle' };
      if (coupon.trim()) body.coupon_code = coupon.trim();

      const res = await fetch(`${API}/payments/initiate`, { method: 'POST', headers: h, body: JSON.stringify(body) });
      const d = await res.json().catch(() => null);
      if (!res.ok) { throw new Error(d?.message || 'Failed to create payment'); }
      if (d?.status === 'completed' || d?.free) {
        toast.success(t('تم تفعيل اشتراكك!', 'Your subscription is active!'));
        setDone(true);
        setTimeout(() => router.push('/teacher/dashboard'), 2000);
        return;
      }
      if (!d?.paddle_transaction_id || !d?.paddle_client_token) {
        throw new Error(t('بوابة Paddle غير مهيأة بالكامل', 'Paddle gateway is not fully configured'));
      }

      const next = { payment_id: d.payment_id, paddle_transaction_id: d.paddle_transaction_id, paddle_client_token: d.paddle_client_token, paddle_env: d.paddle_env || 'sandbox' };
      setPd(next);
      pdRef.current = next;
      setPdStatus('ready');

      await openPaddleCheckout(next.paddle_transaction_id, next.paddle_client_token, next.paddle_env);
    } catch (e: any) {
      console.error('[paddle] start failed', e);
      setPdStatus('error');
      setPdError(e?.message || t('تعذر بدء الدفع عبر Paddle', 'Could not start Paddle payment'));
    }
  };

  const reopenPaddle = async () => {
    if (!pd) return;
    try {
      await openPaddleCheckout(pd.paddle_transaction_id, pd.paddle_client_token, pd.paddle_env);
    } catch {
      toast.error(t('تعذر فتح نافذة الدفع — أعد المحاولة', 'Could not open the payment window — try again'));
    }
  };

  useEffect(() => () => { if (pollRef.current) clearInterval(pollRef.current); }, []);

  // Load the official PayPal JavaScript SDK v6 core script.
  const loadPayPalSdk = (env: 'sandbox' | 'live'): Promise<any> =>
    new Promise((resolve, reject) => {
      const id = 'pp-v6-sdk';
      const existing = document.getElementById(id) as HTMLScriptElement | null;
      if (existing) {
        if ((window as any).paypal) return resolve((window as any).paypal);
        existing.addEventListener('load', () => resolve((window as any).paypal));
        existing.addEventListener('error', () => reject(new Error('SDK failed to load')));
        return;
      }
      const script = document.createElement('script');
      script.id = id;
      script.src = env === 'sandbox'
        ? 'https://www.sandbox.paypal.com/web-sdk/v6/core'
        : 'https://www.paypal.com/web-sdk/v6/core';
      const timer = setTimeout(() => reject(new Error('SDK timeout')), 15000);
      script.onload = () => { clearTimeout(timer); resolve((window as any).paypal); };
      script.onerror = () => { clearTimeout(timer); reject(new Error('SDK failed to load')); };
      document.body.appendChild(script);
    });

  // ── PayPal CardFields flow (JavaScript SDK v6) ────────────────────
  // Creates the PayPal order server-side (backend is the price source),
  // then mounts the inline card fields. The redirect URL is kept as the
  // PayPal-account fallback and never used unless the user chooses it.
  const startPayPalCard = async () => {
    if (!plan) return;
    setPpStatus('creating');
    setPpError('');
    setPpRedirect(false);
    try {
      const token = localStorage.getItem('lms_authed');
      const tid = localStorage.getItem('tenant_id');
      const h = { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
      const body: any = { plan_id: plan.id, billing_cycle: cycle, gateway: 'paypal' };
      if (coupon.trim()) body.coupon_code = coupon.trim();

      const res = await fetch(`${API}/payments/initiate`, { method: 'POST', headers: h, body: JSON.stringify(body) });
      const d = await res.json().catch(() => null);
      if (!res.ok) { throw new Error(d?.message || 'Failed to create payment'); }
      if (d?.status === 'completed' || d?.free) {
        toast.success(t('تم تفعيل اشتراكك!', 'Your subscription is active!'));
        setDone(true);
        setTimeout(() => router.push('/teacher/dashboard'), 2000);
        return;
      }
      if (!d?.paypal_order_id || !d?.paypal_client_id) {
        throw new Error(t('بوابة باي بال غير مهيأة بالكامل', 'PayPal gateway is not fully configured'));
      }

      setPp({ payment_id: d.payment_id, order_id: d.paypal_order_id, client_id: d.paypal_client_id, redirect_url: d.redirect_url, env: d.paypal_env === 'live' ? 'live' : 'sandbox' });
      setPpStatus('sdk-loading');

      const paypal = await loadPayPalSdk(d.paypal_env === 'live' ? 'live' : 'sandbox');

      console.log('[paypal] SDK loaded — creating instance...');
      const sdk = await Promise.race([
        paypal.createInstance({
          clientId: d.paypal_client_id,
          components: ['card-fields', 'paypal-guest-payments'],
          pageType: 'checkout',
        }),
        new Promise((_, reject) => setTimeout(() => reject(new Error('SDK initialization timeout')), 20000)),
      ]);
      console.log('[paypal] instance ready — card mode:', d.paypal_card_mode);

      // Card mode is decided server-side (PayPal's eligibility endpoint can
      // reject browser calls for sandbox apps — the backend is authoritative).
      const advancedOk = d.paypal_card_mode === 'advanced';
      const basicOk = d.paypal_card_mode === 'basic';

      const orderId = d.paypal_order_id;
      const paymentId = d.payment_id;

      // Server-side capture shared by both flows.
      const captureServer = async () => {
        const captureRes = await fetch(`${API}/payments/${paymentId}/paypal-capture`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' },
          body: JSON.stringify({ order_id: orderId }),
        });
        const cd = await captureRes.json().catch(() => null);
        if (!captureRes.ok) {
          toast.error(cd?.message || t('فشل تأكيد الدفع — حاول مجددًا', 'Capture failed — try again'));
          return false;
        }
        toast.success(t('تم الدفع بنجاح!', 'Payment successful!'));
        setDone(true);
        setTimeout(() => router.push('/teacher/dashboard'), 2000);
        return true;
      };

      // Path 1: inline card fields (advanced card payments).
      if (advancedOk) {
        setPpEligible(true);
        setPpMode('advanced');

        const cardSession = sdk.createCardFieldsOneTimePaymentSession();
        const makeField = (type: 'number' | 'expiry' | 'cvv' | 'name', placeholder: string) => {
          return cardSession.createCardFieldsComponent({ type, placeholder });
        };

        const nameEl = makeField('name', t('اسم حامل البطاقة', 'Cardholder name'));
        const numberEl = makeField('number', t('رقم البطاقة', 'Card number'));
        const expiryEl = makeField('expiry', t('MM/YY', 'MM/YY'));
        const cvvEl = makeField('cvv', t('CVV', 'CVV'));

        if (cardFieldsRef.current) {
          cardFieldsRef.current.innerHTML = '';
          cardFieldsRef.current.appendChild(nameEl);
          cardFieldsRef.current.appendChild(numberEl);
          cardFieldsRef.current.appendChild(expiryEl);
          cardFieldsRef.current.appendChild(cvvEl);
          cardFieldsRef.current.classList.remove('hidden');
        }

        ppInstanceRef.current = {
          submit: async () => {
            const outcome = await cardSession.submit(orderId);
            if (outcome?.state === 'succeeded') {
              await captureServer();
            } else if (outcome?.state === 'canceled') {
              toast.info(t('أُلغيت عملية الدفع', 'Payment canceled'));
            } else {
              toast.error(outcome?.data?.message || t('فشل الدفع — تحقق من بيانات البطاقة', 'Payment failed — check your card details'));
            }
          },
        };

        setPpStatus('ready');
        return;
      }

      // Path 2: PayPal-hosted guest card form (basic cards) — stays on the
      // page as a secure overlay, no account required.
      if (basicOk) {
        setPpEligible(true);
        setPpMode('guest');
        const redirectUrl = d.redirect_url as string | undefined;
        let waitingExternalCapture = false;

        console.log('[paypal] creating guest session...');
        const guestSession = sdk.createPayPalGuestOneTimePaymentSession({
          onApprove: async (data: any) => {
            if (!data?.orderId) return;
            await captureServer();
          },
          onComplete: () => {},
          onCancel: () => toast.info(t('أُلغيت عملية الدفع بالبطاقة', 'Card payment canceled')),
          onError: (err: any) => toast.error(err?.message || t('خطأ في الدفع بالبطاقة', 'Card payment error')),
        });

        // PayPal requires the guest session targetElement to be a real
        // <paypal-basic-card-button> element. Create it directly in the
        // document body (it is not yet mounted in the ready section when
        // this code runs), visually hidden so it never covers the UI.
        let guestBtnRef: HTMLElement | null = null;
        try {
          const host = document.createElement('paypal-basic-card-container');
          host.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:1px;overflow:hidden;opacity:0;pointer-events:none;';
          const btn = document.createElement('paypal-basic-card-button');
          btn.id = 'pp-guest-card-btn';
          host.appendChild(btn);
          document.body.appendChild(host);
          guestBtnRef = btn;
        } catch {
          guestBtnRef = null;
        }
        console.log('[paypal] guest flow ready (target btn:', !!guestBtnRef, ')');

        ppInstanceRef.current = {
          // Start the PayPal-hosted guest card overlay. PayPal requires the
          // targetElement to be a real <paypal-basic-card-button>.
          submit: async () => {
            try {
              if (waitingExternalCapture) {
                await captureServer();
                return;
              }

              console.log('[paypal] starting guest session...');
              // Some PayPal sandbox/app setups reject all guest presentation
              // modes. Try modal first, then fall back to the backend approve
              // URL in a new tab and let the user confirm capture on return.
              try {
                await guestSession.start({ presentationMode: 'modal', targetElement: guestBtnRef || undefined }, Promise.resolve({ orderId }));
                console.log('[paypal] modal session resolved');
              } catch (e1: any) {
                console.log('[paypal] modal failed:', e1?.code, '— opening backend approval URL in a new tab');
                if (!redirectUrl) throw e1;
                const opened = window.open(redirectUrl, '_blank', 'noopener,width=500,height=720');
                if (opened) {
                  toast.info(t('فُتحت صفحة PayPal في نافذة جديدة. بعد إكمال الدفع ارجع واضغط "أكملت الدفع — فعّل اشتراكي".', 'PayPal opened in a new tab. After payment, come back and click "I completed the payment — activate my plan".'));
                  setPpRedirect(true);
                  waitingExternalCapture = true;
                } else {
                  toast.error(t('المتصفح منع فتح نافذة PayPal. افتحها من زر "الدفع بحساب PayPal" أدناه.', 'Your browser blocked the PayPal window. Use the "Pay with PayPal account" button below.'));
                  throw e1;
                }
              }
              console.log('[paypal] guest session resolved');
            } catch (err: any) {
              console.error('[paypal] guest session error:', err?.code, err?.message, err);
              const detail = [err?.code, err?.message].filter(Boolean).join(' — ');
              toast.error(t(`تعذر فتح نافذة الدفع: ${detail || 'خطأ غير معروف'}. جرّب إعادة المحاولة أو استخدم حساب PayPal.`, `Payment window failed: ${detail || 'unknown error'}. Retry or use a PayPal account.`));
              setPpStatus('ready');
            }
          },
        };

        setPpStatus('ready');
        return;
      }

      // Neither advanced nor basic cards are available.
      setPpEligible(false);
      setPpStatus('error');
      setPpError(t('الدفع بالبطاقة غير متاح حاليًا على هذا الحساب — يمكنك الدفع بحساب PayPal', 'Card payments are not available on this account right now — you can still pay with a PayPal account'));
    } catch (e: any) {
      setPpStatus('error');
      setPpError(e?.message || t('تعذر بدء الدفع بالبطاقة', 'Could not start card payment'));
    }
  };

  const submitPayPalCard = async () => {
    if (!ppInstanceRef.current) return;
    setPpStatus('submitting');
    try {
      await ppInstanceRef.current.submit();
    } catch {
      // handled inside flow-specific submit handlers
    } finally {
      setPpStatus('ready');
    }
  };

  // PayPal-account fallback: keeps the original redirect flow untouched.
  const payWithAccount = async () => {
    if (pp?.redirect_url) { window.location.href = pp.redirect_url; return; }
    if (!plan) return;
    setPaying(true);
    try {
      const token = localStorage.getItem('lms_authed');
      const tid = localStorage.getItem('tenant_id');
      const h = { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
      const body: any = { plan_id: plan.id, billing_cycle: cycle, gateway: 'paypal' };
      if (coupon.trim()) body.coupon_code = coupon.trim();
      const res = await fetch(`${API}/payments/initiate`, { method: 'POST', headers: h, body: JSON.stringify(body) });
      const d = await res.json().catch(() => null);
      if (!res.ok) { toast.error(d?.message || t('فشل إنشاء الدفع', 'Failed to create payment')); return; }
      if (d?.redirect_url) { window.location.href = d.redirect_url; return; }
      toast.error(t('لا يوجد رابط دفع', 'No payment link returned'));
    } catch {
      toast.error(t('فشلت عملية الدفع', 'Payment failed'));
    }
    setPaying(false);
  };

  if (loading) return <div className="min-h-screen flex items-center justify-center"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>;

  if (done) {
    return (
      <div className="min-h-screen flex items-center justify-center p-4 bg-slate-50">
        <Card className="w-full max-w-md p-8 text-center space-y-5 border-0 shadow-xl rounded-2xl animate-in">
          <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-emerald-100"><CheckCircle2 className="h-10 w-10 text-emerald-600" /></div>
          <h1 className="text-2xl font-bold">{t('تم الاشتراك بنجاح!', 'Subscribed successfully!')}</h1>
          <p className="text-gray-500 text-sm">{t('جاري تحويلك للوحة التحكم...', 'Redirecting to your dashboard...')}</p>
        </Card>
      </div>
    );
  }

  if (awaitingApproval) {
    return (
      <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-b from-slate-50 to-white">
        <Card className="w-full max-w-md p-8 text-center space-y-5 border-0 shadow-xl rounded-2xl animate-in">
          <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-amber-100">
            <Clock className="h-10 w-10 text-amber-600" />
          </div>
          <h1 className="text-2xl font-bold">{t('بانتظار موافقة الأدمن', 'Awaiting admin approval')}</h1>
          <p className="text-gray-500 text-sm">
            {t('تم استلام بياناتك وإثبات الدفع. سيراجعها فريق المنصة ويُفعَّل اشتراكك فور قبولها — ستصل إشعارًا.', 'We received your transfer details and proof. The platform team will review them and your plan activates as soon as it is approved — you will get a notification.')}
          </p>
          <a href="/teacher/dashboard" className="inline-flex items-center justify-center gap-2 w-full py-3.5 rounded-xl bg-gradient-to-r from-teal-600 to-teal-500 text-white text-sm font-bold shadow-lg shadow-teal-600/25 hover:opacity-95 transition-all">
            {t('متابعة للوحة التحكم', 'Go to dashboard')}
          </a>
        </Card>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gradient-to-b from-slate-50 to-white py-10 px-4 sm:py-14">
      <div className="max-w-5xl mx-auto">
        {/* ── Header ── */}
        <div className="mb-6">
          <a href="/pricing" className="inline-flex items-center gap-1.5 text-sm text-gray-400 hover:text-gray-700 transition-colors mb-4">
            {locale === 'ar' ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />}
            {t('رجوع للخطط', 'Back to plans')}
          </a>
          <div className="flex items-end justify-between gap-4 flex-wrap">
            <div>
              <h1 className="text-2xl sm:text-3xl font-bold tracking-tight">{t('أكمل اشتراكك', 'Complete your subscription')}</h1>
              <p className="text-gray-500 mt-1 flex items-center gap-1.5">
                <Zap className="h-4 w-4 text-amber-500" />
                {t('خطوة واحدة وتفعّل خطتك فورًا', 'One step away — your plan activates instantly')}
              </p>
            </div>
            <div className="flex items-center gap-1.5 text-xs text-gray-400 bg-white border rounded-full px-3 py-1.5">
              <Lock className="h-3.5 w-3.5" />
              {t('دفع آمن ومشفّر', 'Secure encrypted payment')}
            </div>
          </div>
        </div>



        <div className="grid lg:grid-cols-5 gap-6 items-start">
          {/* ── Order summary (left) ── */}
          <div className="lg:col-span-2 lg:sticky lg:top-6 space-y-4">
            <Card className="border-0 shadow-sm rounded-2xl overflow-hidden">
              <div className="px-6 py-5 border-b border-slate-100 flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-teal-600 to-teal-500">
                  <Crown className="h-5 w-5 text-white" />
                </div>
                <div>
                  <p className="font-bold leading-tight">{plan?.name}</p>
                  <p className="text-xs text-gray-400">{t('خطة اشتراك', 'Subscription plan')}</p>
                </div>
                {cycle === 'yearly' && yearlySavings > 0 && (
                  <Badge className="ms-auto bg-emerald-100 text-emerald-700 border-0">{t('وفّر', 'Save')} {yearlySavings} {currency}</Badge>
                )}
              </div>

              <CardContent className="p-6 space-y-5">
                {/* ─── تطبيق الطلاب (Mobile App) — ميزة بارزة ─── */}
                {!settings.student_app_hidden && (() => {
                  const appF = (plan?.features || []).find((x: any) => (x.feature_key || x.key) === 'student_mobile_app');
                  const appOn = appF ? ['true', '1', 'on'].includes(String(appF.value ?? appF.feature_value)) : false;
                  return (
                    <div className={`flex items-center gap-3 rounded-xl px-4 py-3 ${appOn ? 'bg-gradient-to-r from-violet-600 via-purple-600 to-fuchsia-600 text-white' : 'bg-violet-50 border-2 border-violet-100 text-violet-800'}`}>
                      <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${appOn ? 'bg-white/20' : 'bg-white border border-violet-200'}`}>
                        <Smartphone className={`h-4 w-4 ${appOn ? 'text-white' : 'text-violet-600'}`} />
                      </span>
                      <div className="min-w-0 flex-1">
                        <p className={`text-[13px] font-extrabold leading-snug ${appOn ? 'text-white' : 'text-violet-800'}`}>{t('تطبيق الطلاب (Mobile App)', 'Student Mobile App')}</p>
                        <p className={`text-[10px] font-medium leading-snug ${appOn ? 'text-white/80' : 'text-violet-500'}`}>{t('التطبيق الرسمي على هاتف طلابك', 'Official mobile app for your students')}</p>
                      </div>
                      <span className={`shrink-0 rounded-full px-2.5 py-1 text-[10px] font-extrabold whitespace-nowrap ${appOn ? 'bg-white text-violet-700' : 'bg-violet-200/80 text-violet-700'}`}>
                        {appOn ? t('مفعل ✓', 'Included') : t('غير مفعل', 'Not included')}
                      </span>
                    </div>
                  );
                })()}
                {/* Billing cycle segmented control */}
                <div className="grid grid-cols-2 gap-1.5 p-1 bg-slate-100 rounded-xl">
                  <button
                    onClick={() => setCycle('monthly')}
                    className={`rounded-lg py-2.5 text-sm font-medium transition-all ${cycle === 'monthly' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
                  >
                    {t('شهري', 'Monthly')}
                    <span className="block text-[11px] font-normal text-gray-400">{monthly ? formatPrice(monthly) : ''}</span>
                  </button>
                  <button
                    onClick={() => setCycle('yearly')}
                    className={`rounded-lg py-2.5 text-sm font-medium transition-all relative ${cycle === 'yearly' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
                  >
                    {t('سنوي', 'Yearly')}
                    <span className="block text-[11px] font-normal text-gray-400">
                      {yearly ? formatPrice(yearly) : t('وفّر 20%', 'Save 20%')}
                    </span>
                  </button>
                </div>

                {/* Price with anchoring */}
                <div className="flex items-baseline gap-2 flex-wrap">
                  {crossed > 0 && (
                    <span className="text-base text-slate-300 line-through">{formatPrice(crossed)}</span>
                  )}
                  <span className="text-4xl font-bold tracking-tight">{String(formatPrice(price)).split(' ')[0]}</span>
                  <span className="text-sm text-gray-400">
                    {currencyLabel(currency, locale === 'ar')} / {t('شهر', 'mo')}
                  </span>
                  {cycle === 'yearly' && yearlySavings > 0 && (
                    <Badge className="bg-amber-100 text-amber-700 border-0">{t('عرض توفير سنوي', 'Yearly deal')}</Badge>
                  )}
                </div>
                {cycle === 'yearly' && yearlySavings > 0 && (
                  <p className="text-xs text-emerald-600 flex items-center gap-1">
                    <Sparkles className="h-3.5 w-3.5" />
                    {t(`شهريًا ستدفع ${formatPrice(monthly)} — بالتسويق السنوي توفر ${yearlySavings} ${currency} سنويًا`, `Monthly would cost ${formatPrice(monthly)} — going yearly saves you ${yearlySavings} ${currency} a year`)}
                  </p>
                )}


              </CardContent>
            </Card>
          </div>

          {/* ── Payment details (right) ── */}
          <div className="lg:col-span-3">
            <Card className="border-0 shadow-sm rounded-2xl">
              <CardContent className="p-6 sm:p-8 space-y-6">
                <div>
                  <h2 className="font-bold text-lg">{t('طريقة الدفع', 'Payment method')}</h2>
                  <p className="text-sm text-gray-400 mt-0.5">{t('اختر الطريقة المناسبة لك — دفعة واحدة آمنة', 'Choose your preferred method — one secure payment')}</p>
                </div>

                {/* Gateway cards */}
                <div className="space-y-3">
                  {available.length === 0 && (
                    <div className="p-6 rounded-xl border border-dashed border-slate-200 text-center text-sm text-slate-400">
                      {t('لا توجد بوابات دفع مفعلة حالياً — تواصل مع الدعم', 'No payment gateways available — contact support')}
                    </div>
                  )}
                  {available.map((m) => (
                    <button
                      key={m.id}
                      onClick={() => setMethod(m.id)}
                      className={`w-full flex items-center gap-4 p-4 rounded-xl border-2 text-start transition-all ${method === m.id ? 'border-teal-500 bg-teal-50/50 shadow-sm' : 'border-slate-200 hover:border-slate-300 bg-white'}`}
                    >
                      <span className={`flex h-10 w-10 items-center justify-center rounded-xl shrink-0 ${method === m.id ? 'bg-teal-600 text-white' : 'bg-slate-100 text-slate-500'}`}>
                        <m.icon className="h-5 w-5" />
                      </span>
                      <span className="flex-1 min-w-0">
                        <span className="block font-semibold text-sm">{m.label}</span>
                        <span className="block text-xs text-gray-400 mt-0.5">{m.desc}</span>
                      </span>
                      <span className={`flex h-5 w-5 items-center justify-center rounded-full border-2 shrink-0 ${method === m.id ? 'border-teal-600' : 'border-slate-300'}`}>
                        {method === m.id && <span className="h-2.5 w-2.5 rounded-full bg-teal-600" />}
                      </span>
                    </button>
                  ))}
                </div>

                {/* Gateway note */}
                {available.length > 0 && method === 'paytabs' && (
                  <div className="flex items-start gap-3 p-4 rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-600">
                    <Landmark className="h-5 w-5 shrink-0 text-teal-600 mt-0.5" />
                    <div>
                      <p className="font-semibold text-slate-700">{t('مدى · فيزا · ماستركارد', 'Mada · Visa · Mastercard')}</p>
                      <p className="text-xs text-slate-500 mt-1">{t('ستُحوَّل إلى صفحة PayTabs الآمنة لإدخال بيانات بطاقتك — دون الحاجة لأي حساب.', 'You will be redirected to the secure PayTabs page to enter your card details — no account needed.')}</p>
                    </div>
                  </div>
                )}
                {available.length > 0 && method === 'paypal' && (
                  <div className="space-y-4">
                    {/* Inline CardFields — primary path */}
                    <div className="p-4 rounded-xl bg-white border border-slate-200">
                      <div className="flex items-center gap-2 mb-3">
                        <Building2 className="h-5 w-5 text-teal-600" />
                        <div>
                          <p className="font-semibold text-sm text-slate-700">{t('الدفع ببطاقة (فيزا / ماستركارد)', 'Pay by card (Visa / Mastercard)')}</p>
                          <p className="text-xs text-slate-400 mt-0.5">{ppRedirect ? t('تم فتح PayPal في نافذة جديدة. بعد الإكمال ارجع واضغط زر تفعيل الاشتراك.', 'PayPal was opened in a new tab. After payment, come back and click the activation button.') : ppMode === 'guest' ? t('ستفتح نافذة دفع آمنة من PayPal داخل الصفحة — بدون مغادرة الموقع وبدون حساب PayPal.', 'A secure PayPal payment window opens on this page — no redirect and no PayPal account needed.') : t('أدخل بيانات بطاقتك مباشرة — دون مغادرة الموقع. تُعالج البطاقة بأمان من PayPal.', 'Enter your card details right here — no redirect. The card is processed securely by PayPal.')}</p>
                        </div>
                      </div>

                      {ppStatus === 'idle' && (
                        <Button onClick={startPayPalCard} disabled={paying} className="w-full py-3 rounded-xl gap-2 font-semibold bg-slate-900 hover:bg-slate-800 text-white">
                          <Lock className="h-4 w-4" />
                          {t('الدفع ببطاقة', 'Pay by card')}
                        </Button>
                      )}
                      {(ppStatus === 'creating' || ppStatus === 'sdk-loading') && (
                        <div className="flex items-center justify-center gap-2 py-4 text-sm text-slate-500">
                          <Loader2 className="h-5 w-5 animate-spin text-teal-600" />
                          {t('جارٍ تجهيز الدفع الآمن...', 'Preparing secure payment...')}
                        </div>
                      )}
                      {ppStatus === 'ready' && (
                        <div className="space-y-3">
                          <div ref={cardFieldsRef} className="space-y-3 hidden" />
                          <div className="grid grid-cols-2 gap-3">
                            <Button onClick={submitPayPalCard} disabled={paying} className="col-span-2 py-3.5 rounded-xl font-bold text-white gap-2 shadow-lg shadow-teal-600/25" style={{ background: 'linear-gradient(135deg, #0d9488, #1d4ed8)' }}>
                              <Lock className="h-4 w-4" />
                              {ppRedirect ? t('أكملت الدفع — فعّل اشتراكي', 'I completed the payment — activate my plan') : ppMode === 'guest' ? t('الدفع بالبطاقة', 'Pay by card') : t('إتمام الدفع', 'Complete payment')}
                            </Button>
                          </div>
                          <p className="text-[11px] text-slate-400 flex items-center gap-1.5">
                            <ShieldCheck className="h-3.5 w-3.5" />
                            {t('لا نخزن بيانات بطاقتك — PayPal يتعامل معها مباشرة.', 'We never store your card data — PayPal handles it directly.')}
                          </p>
                        </div>
                      )}
                      {ppStatus === 'error' && (
                        <div className="space-y-3">
                          <div className="flex items-start gap-2 p-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-800">
                            <ShieldCheck className="h-4 w-4 shrink-0 mt-0.5" />
                            <span>{ppError || t('تعذر الدفع بالبطاقة — استخدم حساب PayPal أدناه.', 'Card payment unavailable — use a PayPal account below.')}</span>
                          </div>
                          <button onClick={() => { resetPayPalCard(); startPayPalCard(); }} className="text-xs text-teal-700 font-medium hover:underline">
                            {t('إعادة المحاولة بالبطاقة', 'Retry with card')}
                          </button>
                        </div>
                      )}
                    </div>

                    {/* PayPal-account fallback (original redirect flow) */}
                    <button
                      onClick={payWithAccount}
                      disabled={paying}
                      className="w-full flex items-center justify-center gap-2 p-3.5 rounded-xl border border-slate-200 bg-slate-50 hover:bg-slate-100 text-sm font-semibold text-slate-700 transition-colors"
                    >
                      {paying ? <Loader2 className="h-4 w-4 animate-spin" /> : <Building2 className="h-4 w-4" />}
                      {t('أو الدفع بحساب PayPal (تحويل لصفحة PayPal)', 'Or pay with a PayPal account (go to PayPal)')}
                    </button>
                  </div>
                )}

                {/* ─── Paddle — card / wallet / local methods ─── */}
                {available.length > 0 && method === 'paddle' && (
                  <div className="space-y-4">
                    <div className="p-4 rounded-xl bg-white border border-slate-200">
                      <div className="flex items-center gap-2 mb-3">
                        <CreditCard className="h-5 w-5 text-teal-600" />
                        <div>
                          <p className="font-semibold text-sm text-slate-700">{t('الدفع عبر Paddle', 'Pay with Paddle')}</p>
                          <p className="text-xs text-slate-400 mt-0.5">{t('بطاقة / محفظة رقمية / طرق دفع محلية — تُعالج بأمان داخل نافذة دفع آمنة.', 'Card / digital wallet / local payment methods — processed securely inside a safe payment window.')}</p>
                        </div>
                      </div>

                      {pdStatus === 'idle' && (
                        <Button onClick={startPaddle} disabled={paying} className="w-full py-3 rounded-xl gap-2 font-semibold bg-slate-900 hover:bg-slate-800 text-white">
                          <Lock className="h-4 w-4" />
                          {t('الدفع عبر Paddle', 'Pay with Paddle')}
                        </Button>
                      )}
                      {pdStatus === 'creating' && (
                        <div className="flex items-center justify-center gap-2 py-4 text-sm text-slate-500">
                          <Loader2 className="h-5 w-5 animate-spin text-teal-600" />
                          {t('جارٍ تجهيز الدفع الآمن...', 'Preparing secure payment...')}
                        </div>
                      )}
                      {pdStatus === 'ready' && (
                        <div className="space-y-3">
                          <p className="text-sm text-slate-600">{t('فُتحت نافذة الدفع الآمنة — أكملها لإتمام العملية.', 'The secure payment window is open — complete it to finish.')}</p>
                          <Button onClick={reopenPaddle} className="w-full py-3 rounded-xl gap-2 font-semibold bg-slate-900 hover:bg-slate-800 text-white">
                            <RefreshCcw className="h-4 w-4" />
                            {t('إعادة فتح نافذة الدفع', 'Reopen payment window')}
                          </Button>
                        </div>
                      )}
                      {pdStatus === 'verifying' && (
                        <div className="flex items-center justify-center gap-2 py-4 text-sm text-slate-600">
                          <Loader2 className="h-5 w-5 animate-spin text-teal-600" />
                          {t('تم استلام عملية الدفع، جاري تأكيد الاشتراك...', 'Payment received — confirming your subscription...')}
                        </div>
                      )}
                      {pdStatus === 'error' && (
                        <div className="space-y-3">
                          <div className="flex items-start gap-2 p-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-800">
                            <ShieldCheck className="h-4 w-4 shrink-0 mt-0.5" />
                            <span>{pdError || t('تعذر الدفع عبر Paddle', 'Paddle payment failed')}</span>
                          </div>
                          <button onClick={() => setPdStatus('idle')} className="text-xs text-teal-700 font-medium hover:underline">
                            {t('إعادة المحاولة', 'Retry')}
                          </button>
                        </div>
                      )}

                      <p className="text-[11px] text-slate-400 flex items-center gap-1.5 mt-3">
                        <ShieldCheck className="h-3.5 w-3.5" />
                        {t('لا تُرسل بيانات البطاقة إلى موقعنا — Paddle تعالجها مباشرة.', 'Card data is never sent to our site — Paddle handles it directly.')}
                      </p>
                    </div>
                  </div>
                )}

                {available.length > 0 && method === 'manual' && (
                  <div className="space-y-4">
                    {/* Account details + instructions */}
                    <div className="p-4 rounded-xl bg-teal-50 border border-teal-100 text-sm">
                      <p className="font-bold text-teal-900 flex items-center gap-2"><Landmark className="h-4 w-4" />{gateways?.manual?.label || t('الدفع اليدوي', 'Manual payment')}</p>
                      <div className="mt-2 space-y-1.5 text-teal-900/80 text-xs leading-relaxed whitespace-pre-line">
                        {gateways?.manual?.account && (
                          <p className="font-mono font-semibold text-sm" dir="ltr">{gateways.manual.account}</p>
                        )}
                        {gateways?.manual?.instructions && <p>{gateways.manual.instructions}</p>}
                      </div>
                    </div>

                    {/* Transfer details + proof */}
                    <div className="grid sm:grid-cols-2 gap-3">
                      <div className="space-y-2">
                        <Label>{t('اسم المُحوِّل', 'Sender name')}</Label>
                        <Input value={manualForm.payer_name} onChange={(e) => setManualForm({ ...manualForm, payer_name: e.target.value })} className="h-11" placeholder={t('الاسم كما في المحفظة', 'Name as on the wallet')} />
                      </div>
                      <div className="space-y-2">
                        <Label>{t('رقم العملية / المرجع', 'Transaction / reference no.')}</Label>
                        <Input value={manualForm.reference} onChange={(e) => setManualForm({ ...manualForm, reference: e.target.value })} className="h-11" dir="ltr" placeholder="XXXX-XXXX" />
                      </div>
                    </div>
                    <div className="space-y-2">
                      <Label>{t('ملاحظات (اختياري)', 'Notes (optional)')}</Label>
                      <Input value={manualForm.notes} onChange={(e) => setManualForm({ ...manualForm, notes: e.target.value })} className="h-11" />
                    </div>
                    <div className="space-y-2">
                      <Label>{t('إثبات الدفع (صورة الوصل)', 'Payment proof (receipt image)')}</Label>
                      <label className="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-slate-200 rounded-xl p-6 cursor-pointer hover:border-teal-400 transition-colors bg-slate-50">
                        <Upload className="h-6 w-6 text-slate-300" />
                        {proofFile ? (
                          <span className="text-xs font-medium text-teal-700">{proofFile.name}</span>
                        ) : (
                          <span className="text-xs text-slate-400">{t('اضغط لاختيار صورة الوصل (JPG/PNG/WebP)', 'Click to choose the receipt image (JPG/PNG/WebP)')}</span>
                        )}
                        <input type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(e) => setProofFile(e.target.files?.[0] || null)} />
                      </label>
                      {proofFile && (
                        <button onClick={() => setProofFile(null)} className="text-[11px] text-rose-500 hover:underline">
                          {t('إزالة الصورة', 'Remove image')}
                        </button>
                      )}
                    </div>
                    <p className="text-[11px] text-slate-400 flex items-center gap-1.5">
                      <Clock className="h-3.5 w-3.5" />
                      {t('سيُراجع الأدمن إثباتك خلال ساعات العمل ويُفعِّل خطتك فور الموافقة.', 'The admin reviews your proof during business hours and activates your plan right after approval.')}
                    </p>
                  </div>
                )}



                {/* Coupon */}
                <div>
                  <Label className="text-sm font-medium">{t('كود الخصم (اختياري)', 'Coupon code (optional)')}</Label>
                  <div className="relative mt-1.5">
                    <Tag className="absolute end-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-300" />
                    <Input value={coupon} onChange={(e) => setCoupon(e.target.value)} className="h-11 uppercase pe-10 rounded-xl" dir="ltr" placeholder="SAVE10" maxLength={50} />
                  </div>
                </div>

                {/* Totals */}
                <div className="rounded-xl bg-slate-50 border border-slate-100 p-5 space-y-3">
                  <div className="flex items-center justify-between text-sm text-slate-500">
                    <span>{cycle === 'yearly' ? t('إجمالي الاشتراك (سنوي)', 'Subscription total (yearly)') : t('إجمالي الاشتراك (شهري)', 'Subscription total (monthly)')}</span>
                    <span className="font-semibold text-slate-700">{formatPrice(price)}</span>
                  </div>
                  <div className="flex items-center justify-between text-sm text-slate-500">
                    <span>{t('الضريبة', 'Tax')}</span>
                    <span className="font-semibold text-slate-700">{t('مشمولة', 'Included')}</span>
                  </div>
                  <div className="h-px bg-slate-200" />
                  <div className="flex items-center justify-between">
                    <span className="font-bold">{t('الإجمالي النهائي', 'Total due today')}</span>
                    <span className="text-xl font-bold tracking-tight">{formatPrice(price)}</span>
                  </div>
                </div>

                {/* CTA */}
                <Button
                  className="w-full py-4 rounded-xl text-white font-bold shadow-lg shadow-teal-600/25 gap-2 hover:opacity-95 transition-all"
                  style={{ background: 'linear-gradient(135deg, #0d9488, #1d4ed8)' }}
                  onClick={method === 'paddle' ? (pdStatus === 'ready' ? reopenPaddle : startPaddle) : method === 'paypal' && ppStatus === 'ready' ? submitPayPalCard : pay}
                  disabled={paying || available.length === 0 || ppStatus === 'submitting' || ppStatus === 'sdk-loading' || ppStatus === 'creating' || ppStatus === 'error' || pdStatus === 'creating' || pdStatus === 'verifying'}
                >
                  {paying || ppStatus === 'submitting' || ppStatus === 'sdk-loading' || ppStatus === 'creating' || pdStatus === 'creating' || pdStatus === 'verifying' ? <Loader2 className="h-5 w-5 animate-spin" /> : <Lock className="h-4 w-4" />}
                  {method === 'paddle' ? t('الدفع عبر Paddle', 'Pay with Paddle') : method === 'paytabs' ? t('الدفع عبر PayTabs', 'Pay with PayTabs') : method === 'manual' ? t('إرسال بيانات الدفع', 'Submit payment details') : ppStatus === 'ready' ? t('إتمام الدفع بالبطاقة', 'Complete card payment') : t('الدفع عبر PayPal', 'Pay with PayPal')}
                </Button>
                <p className="text-center text-[11px] text-slate-400 -mt-2">
                  {t('دفعة واحدة · تفعيل فوري · إلغاء في أي وقت', 'One payment · instant activation · cancel anytime')}
                </p>

                {/* Help */}
                <div className="flex items-center justify-center gap-2 pt-1 text-xs text-slate-400">
                  <Headset className="h-4 w-4" />
                  {t('تحتاج مساعدة؟', 'Need help?')}
                  <a href="/support" className="text-teal-700 font-medium hover:underline">{t('تواصل مع الدعم', 'Contact support')}</a>
                </div>
              </CardContent>
            </Card>
          </div>
        </div>

        <p className="text-center text-xs text-slate-300 mt-8">© {new Date().getFullYear()} {t('Tadreesly · جميع الحقوق محفوظة', 'Tadreesly · All rights reserved')}</p>
      </div>
    </div>
  );
}

export default function CheckoutPage() {
  return (
    <Suspense fallback={<div className="min-h-screen flex items-center justify-center"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>}>
      <CheckoutInner />
    </Suspense>
  );
}
