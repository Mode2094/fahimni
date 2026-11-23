'use client';

import { useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { usePlan } from '@/store/plan';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Loader2, Globe, Search, CheckCircle2, XCircle, ExternalLink, Copy, Rocket, ShieldCheck, ArrowRight, Link2, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import Link from 'next/link';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const PROVIDERS = [
  { name: 'Namecheap', color: '#e8730c', url: (d: string) => `https://www.namecheap.com/domains/registration/results/?domain=${encodeURIComponent(d)}` },
  { name: 'GoDaddy', color: '#1b83e6', url: (d: string) => `https://www.godaddy.com/domainsearch/find?checkAvail=1&domainToCheck=${encodeURIComponent(d)}` },
  { name: 'Cloudflare', color: '#f6821f', url: (d: string) => `https://dash.cloudflare.com/?to=/:account/domains/search#add-domain=${encodeURIComponent(d)}` },
  { name: 'Hostinger', color: '#673de6', url: (d: string) => `https://www.hostinger.com/domain-names?q=${encodeURIComponent(d)}` },
];

export default function DomainManager() {
  const { locale } = useLocale();
  const { can } = usePlan();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [connectDomain, setConnectDomain] = useState('');
  const [busy, setBusy] = useState(false);
  const [verifyResult, setVerifyResult] = useState<any>(null);
  const [checking, setChecking] = useState(false);
  const [slugDraft, setSlugDraft] = useState('');
  const [slugStatus, setSlugStatus] = useState<{ state: 'idle' | 'checking' | 'available' | 'taken' | 'reserved' | 'invalid' | 'error' }>({ state: 'idle' });
  const [savingSlug, setSavingSlug] = useState(false);

  const headers = () => {
    const token = localStorage.getItem('lms_authed');
    const tid = localStorage.getItem('tenant_id');
    return { 'Content-Type': 'application/json', 'X-Tenant-Id': tid || '', Accept: 'application/json' };
  };

  const load = () => {
    fetch(`${API}/teacher/domain`, { headers: headers() })
      .then(r => r.json()).then(d => setData(d)).catch(() => {})
      .finally(() => setLoading(false));
  };
  useEffect(() => { load(); }, []);

  // مزامنة حقل الرابط مع الرابط المحفوظ على السيرفر
  useEffect(() => { if (data?.slug) setSlugDraft(data.slug); }, [data?.slug]);

  // فحص توفّر الرابط أثناء الكتابة (مع مهلة صغيرة لتقليل الطلبات)
  useEffect(() => {
    const slug = slugDraft.trim().toLowerCase();
    const current = (data?.slug || '').toLowerCase();
    if (!slug || slug === current) { setSlugStatus({ state: 'idle' }); return; }
    setSlugStatus({ state: 'checking' });
    const id = setTimeout(() => {
      fetch(`${API}/teacher/domain/slug-available?slug=${encodeURIComponent(slug)}`, { headers: headers() })
        .then(r => r.json())
        .then(d => setSlugStatus({ state: d.available ? 'available' : (d.reason || 'taken') }))
        .catch(() => setSlugStatus({ state: 'error' }));
    }, 500);
    return () => clearTimeout(id);
  }, [slugDraft, data?.slug]);

  const currentSlug = (data?.slug || '').toLowerCase();
  const cleanSlug = slugDraft.trim().toLowerCase();
  const slugChanged = cleanSlug !== '' && cleanSlug !== currentSlug;
  const canSaveSlug = slugChanged && slugStatus.state === 'available' && !savingSlug;
  const pageLink = typeof window !== 'undefined' && data?.slug ? `${window.location.origin}/t/${data.slug}` : '';

  const saveSlug = async () => {
    if (!canSaveSlug) return;
    setSavingSlug(true);
    try {
      const res = await fetch(`${API}/teacher/domain/slug`, { method: 'PUT', headers: headers(), body: JSON.stringify({ slug: cleanSlug }) });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || t('تعذر الحفظ', 'Save failed')); return; }
      toast.success(t('تم تحديث رابط منصتك', 'Your platform link was updated'));
      setSlugDraft(d.slug || cleanSlug);
      load();
    } catch { toast.error(t('تعذر الحفظ', 'Save failed')); }
    setSavingSlug(false);
  };

  const connect = async () => {
    const domain = connectDomain.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/$/, '');
    if (!/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i.test(domain)) {
      toast.error(t('أدخل دومين صحيح (مثال: myplatform.com)', 'Enter a valid domain (e.g. myplatform.com)'));
      return;
    }
    setBusy(true);
    try {
      const res = await fetch(`${API}/teacher/domain`, { method: 'PUT', headers: headers(), body: JSON.stringify({ domain }) });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || 'Failed'); return; }
      toast.success(t('تم ربط الدومين — اتبع خطوات DNS بالأسفل', 'Domain connected — follow the DNS steps below'));
      setConnectDomain(''); load();
    } catch { toast.error(t('تعذّر إتمام العملية، حاول مرة أخرى', 'Could not complete the action, please try again')); }
    setBusy(false);
  };

  const verify = async () => {
    setChecking(true); setVerifyResult(null);
    try {
      const res = await fetch(`${API}/teacher/domain/verify`, { method: 'POST', headers: headers() });
      const d = await res.json();
      setVerifyResult({ ok: res.ok && d.verified, message: d.message, records: d.found_records || [] });
      load();
    } catch { setVerifyResult({ ok: false, message: t('تعذر التحقق', 'Verification failed') }); }
    setChecking(false);
  };

  const disconnect = async () => {
    try {
      const res = await fetch(`${API}/teacher/domain`, { method: 'DELETE', headers: headers() });
      if (!res.ok) throw new Error();
      toast.success(t('تم فصل الدومين', 'Domain disconnected'));
      load();
    } catch { toast.error(t('تعذّر إتمام العملية، حاول مرة أخرى', 'Could not complete the action, please try again')); }
  };

  const copy = async (v: string, label: string) => {
    try { await navigator.clipboard.writeText(v); toast.success(`${label} ${t('منسوخ', 'copied')}`); } catch { /* */ }
  };

  if (loading) return <div className="flex justify-center py-16"><Loader2 className="h-8 w-8 animate-spin text-teal-600" /></div>;

  const enabled = data?.enabled;

  return (
    <div className="space-y-6">
      <Card className="border-0 shadow-sm overflow-hidden">
        <div className="p-6 text-white relative overflow-hidden" style={{ background: 'linear-gradient(135deg, #0f172a, #1e293b)' }}>
          <div className="absolute -top-10 -end-10 h-48 w-48 rounded-full blur-3xl bg-teal-500/20" />
          <div className="relative flex items-center gap-4 flex-wrap">
            <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/10 border border-white/10"><Globe className="h-7 w-7 text-sky-400" /></div>
            <div className="flex-1 min-w-[220px]">
              <h2 className="text-xl font-bold">{t('دومينك الخاص — موقعك بعنوانك', 'Your own domain — your site, your address')}</h2>
              <p className="text-white/60 text-sm mt-1">{t('بدلاً من /t/your-name تصبح منصتك على myplatform.com — علامة تجارية حقيقية ترفع ثقة طلابك وتحويلاتك', 'Instead of /t/your-name, your platform lives at myplatform.com — a real brand that boosts trust and conversion')}</p>
            </div>
            <Badge className={enabled ? 'bg-green-400 text-green-950 border-0' : 'bg-white/10 text-white/70 border-0'}>
              {enabled ? t('مفعّل في خطتك', 'Included in your plan') : t('غير مشمول في خطتك', 'Not in your plan')}
            </Badge>
          </div>
        </div>
      </Card>

      {/* رابط منصتك المجاني (النطاق الفرعي التابع للمنصة) */}
      <Card className="border-0 shadow-sm overflow-hidden">
        <CardContent className="p-6 space-y-5">
          <div className="flex items-start gap-3.5">
            <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-500/10 to-emerald-500/10 border border-teal-100">
              <Link2 className="h-6 w-6 text-teal-700" />
            </span>
            <div>
              <h2 className="font-bold text-lg">{t('رابط منصتك المجاني', 'Your free platform link')}</h2>
              <p className="text-sm text-muted-foreground mt-1">{t('هذا رابط صفحتك على منصة فهمني — اختر اسماً يعبّر عنك، ونفحص توفّره فوراً', 'This is your page link on Fahimni — pick a name that represents you, we check availability instantly')}</p>
            </div>
          </div>

          {/* الرابط الحالي */}
          <div className="flex items-center gap-2 rounded-2xl border border-teal-100 bg-teal-50/50 p-2 ps-4">
            <span className="text-[11px] text-teal-600 shrink-0">{t('رابط صفحتك', 'Your page link')}</span>
            <span className="flex-1 min-w-0 font-mono text-xs text-teal-800 truncate" dir="ltr">{pageLink ? pageLink.replace(/^https?:\/\//, '') : '/t/—'}</span>
            <Button variant="ghost" size="icon" className="h-8 w-8 shrink-0" disabled={!pageLink} onClick={() => copy(pageLink, t('الرابط', 'Link'))}><Copy className="h-3.5 w-3.5" /></Button>
          </div>

          {/* تغيير الرابط */}
          <div className="rounded-2xl border border-slate-200 bg-white p-4 space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <Label className="text-xs font-bold text-muted-foreground">{t('اختر اسم رابطك', 'Choose your link name')}</Label>
              <span className="text-[11px] text-gray-400" dir="ltr">/t/{cleanSlug || 'your-name'}</span>
            </div>
            <div className="flex flex-wrap items-center gap-2.5">
              <div className={`flex flex-1 min-w-[220px] items-center rounded-xl border overflow-hidden bg-white ${slugStatus.state === 'taken' || slugStatus.state === 'reserved' || slugStatus.state === 'invalid' ? 'border-red-300' : slugStatus.state === 'available' ? 'border-emerald-400' : 'border-slate-200'}`}>
                <span className="px-3 h-11 flex items-center text-xs text-gray-400 border-e border-slate-200 bg-slate-50" dir="ltr">/t/</span>
                <Input
                  value={slugDraft}
                  onChange={(e) => setSlugDraft(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
                  dir="ltr"
                  placeholder="your-name"
                  className="h-11 border-0 rounded-none focus-visible:ring-0"
                />
              </div>
              <Button onClick={saveSlug} disabled={!canSaveSlug} className="h-11 rounded-xl gap-1.5 shrink-0 text-white" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
                {savingSlug ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}{t('حفظ الرابط', 'Save link')}
              </Button>
            </div>
            <div className="text-xs min-h-[18px]">
              {slugStatus.state === 'checking' && <span className="text-gray-400 inline-flex items-center gap-1"><Loader2 className="h-3.5 w-3.5 animate-spin" />{t('جاري الفحص...', 'Checking...')}</span>}
              {slugStatus.state === 'available' && <span className="text-emerald-600 inline-flex items-center gap-1 font-medium"><CheckCircle2 className="h-3.5 w-3.5" />{t('متاح ✔ — يمكنك حجزه', 'Available ✔ — you can claim it')}</span>}
              {slugStatus.state === 'taken' && <span className="text-red-500 inline-flex items-center gap-1 font-medium"><XCircle className="h-3.5 w-3.5" />{t('غير متاح — هذا الاسم مستخدم، اختر غيره', 'Not available — already taken, choose another')}</span>}
              {slugStatus.state === 'reserved' && <span className="text-red-500 inline-flex items-center gap-1 font-medium"><XCircle className="h-3.5 w-3.5" />{t('اسم محجوز — اختر غيره', 'Reserved name — choose another')}</span>}
              {slugStatus.state === 'invalid' && <span className="text-red-500 inline-flex items-center gap-1 font-medium"><XCircle className="h-3.5 w-3.5" />{t('حروف إنجليزية وأرقام وشرطة فقط (2-63)', 'Letters, numbers and hyphens only (2–63)')}</span>}
              {slugStatus.state === 'error' && <span className="text-amber-600 inline-flex items-center gap-1"><XCircle className="h-3.5 w-3.5" />{t('تعذر الفحص — حاول مجدداً', 'Could not check — try again')}</span>}
              {slugStatus.state === 'idle' && <span className="text-gray-400">{t('اكتب الاسم الذي تريده لرابطك', 'Type the name you want for your link')}</span>}
            </div>
          </div>
        </CardContent>
      </Card>

      {!enabled ? (
        <Card className="border-amber-200 bg-amber-50/50">
          <CardContent className="p-8 text-center space-y-4">
            <Rocket className="h-12 w-12 mx-auto text-amber-500" />
            <h3 className="text-xl font-bold">{t('الدومين الخاص متاح في الخطط الأعلى', 'Custom domain is available in higher plans')}</h3>
            <p className="text-gray-500 max-w-md mx-auto text-sm">{t('ارقِ خطتك لتحصل على دومين خاص لمنصتك مع علامة تجارية كاملة', 'Upgrade your plan to get your own domain with full branding')}</p>
            <Link href="/pricing"><Button className="gap-2 rounded-full"><ArrowRight className="h-4 w-4" />{t('عرض الخطط والترقية', 'View plans & upgrade')}</Button></Link>
          </CardContent>
        </Card>
      ) : (
        <>
          {/* Current status */}
          {data.domain && (
            <Card className="border-0 shadow-sm">
              <CardContent className="p-6">
                <div className="flex items-center gap-3 flex-wrap">
                  <div className={`flex h-11 w-11 items-center justify-center rounded-xl ${data.verified ? 'bg-green-50' : 'bg-amber-50'}`}>
                    {data.verified ? <CheckCircle2 className="h-6 w-6 text-green-600" /> : <Loader2 className="h-6 w-6 text-amber-500" />}
                  </div>
                  <div className="flex-1">
                    <p className="font-bold" dir="ltr">{data.domain}</p>
                    <p className="text-sm text-gray-500">{data.verified ? t('مفعل — منصتك تعمل على هذا الدومين', 'Verified — your site is live on this domain') : t('بانتظار التحقق من DNS', 'Waiting for DNS verification')}</p>
                  </div>
                  <Button variant="outline" size="sm" className="gap-1.5 rounded-full text-xs" onClick={verify} disabled={checking}>
                    {checking ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <ShieldCheck className="h-3.5 w-3.5" />}{t('تحقق من الربط', 'Verify now')}
                  </Button>
                  <Button variant="ghost" size="sm" className="gap-1.5 rounded-full text-xs text-red-500" onClick={disconnect}><Trash2 className="h-3.5 w-3.5" />{t('فصل', 'Disconnect')}</Button>
                </div>
                {verifyResult && (
                  <div className={`mt-4 p-4 rounded-xl text-sm ${verifyResult.ok ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'}`}>
                    <div className="flex items-center gap-2 font-medium">{verifyResult.ok ? <CheckCircle2 className="h-4 w-4" /> : <XCircle className="h-4 w-4" />}{verifyResult.message}</div>
                    {verifyResult.records?.length > 0 && <div className="mt-2 text-xs">{verifyResult.records.map((r: string, i: number) => <div key={i} dir="ltr">• {r}</div>)}</div>}
                  </div>
                )}
              </CardContent>
            </Card>
          )}

          {/* Steps */}
          <div className="grid lg:grid-cols-3 gap-6">
            {/* Step 1: buy/search */}
            <Card className="border-0 shadow-sm">
              <CardContent className="p-6 space-y-4">
                <div className="flex items-center gap-3">
                  <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-teal-50 text-teal-700 font-bold text-sm shrink-0">1</div>
                  <h3 className="font-semibold">{t('اشترِ أو ابحث عن دومين', 'Buy or search for a domain')}</h3>
                </div>
                <p className="text-sm text-gray-500">{t('اكتب اسم الدومين الذي تريده واشتره مباشرة من أي مزود — أو اربط دوميناً تملكه بالفعل', 'Type the domain you want and buy it from any provider — or connect one you already own')}</p>
                <div className="flex gap-2">
                  <div className="relative flex-1">
                    <Search className="h-4 w-4 absolute start-3 top-1/2 -translate-y-1/2 text-gray-400" />
                    <Input value={search} onChange={(e) => setSearch(e.target.value)} className="h-11 ps-9" dir="ltr" placeholder="myplatform.com" />
                  </div>
                  <a href={search ? PROVIDERS[0].url(search) : undefined} target="_blank"><Button className="h-11 rounded-xl gap-1.5" style={{ background: PROVIDERS[0].color }}><ExternalLink className="h-4 w-4" />{t('شراء', 'Buy')}</Button></a>
                </div>
                <div className="grid grid-cols-2 gap-2">
                  {PROVIDERS.slice(1).map((p) => (
                    <a key={p.name} href={search ? p.url(search) : undefined} target="_blank">
                      <Button variant="outline" size="sm" className="w-full gap-1.5 text-xs" style={{ color: p.color, borderColor: `${p.color}55` }}>
                        <Globe className="h-3.5 w-3.5" />{p.name}
                      </Button>
                    </a>
                  ))}
                </div>
              </CardContent>
            </Card>

            {/* Step 2: connect */}
            <Card className="border-0 shadow-sm">
              <CardContent className="p-6 space-y-4">
                <div className="flex items-center gap-3">
                  <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-50 text-violet-600 font-bold text-sm shrink-0">2</div>
                  <h3 className="font-semibold">{t('اربط الدومين', 'Connect your domain')}</h3>
                </div>
                <p className="text-sm text-gray-500">{t('أدخل دومينك (المشتري أو الخارجي) وسنعطيك إعدادات DNS الدقيقة', 'Enter your domain (bought or external) and we will give you the exact DNS settings')}</p>
                <div className="flex gap-2">
                  <Input value={connectDomain} onChange={(e) => setConnectDomain(e.target.value)} className="h-11" dir="ltr" placeholder="myplatform.com" />
                  <Button onClick={connect} disabled={busy} className="h-11 rounded-xl gap-1.5">{busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Link2 className="h-4 w-4" />}{t('ربط', 'Connect')}</Button>
                </div>
                <div className="p-3 rounded-xl bg-teal-50 text-xs text-teal-700 leading-relaxed">
                  <b>{t('مهم:', 'Important:')}</b> {t('يمنع ربط الدومين الأساسي للمنصة — استخدم دومينك الخاص فقط', 'You cannot connect the platform\'s own domain — use your own domain only.')}
                </div>
              </CardContent>
            </Card>

            {/* Step 3: DNS */}
            <Card className="border-0 shadow-sm">
              <CardContent className="p-6 space-y-4">
                <div className="flex items-center gap-3">
                  <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-green-50 text-green-600 font-bold text-sm shrink-0">3</div>
                  <h3 className="font-semibold">{t('إعدادات DNS', 'DNS setup')}</h3>
                </div>
                {!data.domain ? (
                  <p className="text-sm text-gray-400">{t('اربط الدومين أولاً لعرض الخطوات', 'Connect a domain first to see the steps')}</p>
                ) : (
                  <div className="space-y-3">
                    <div className="p-3 rounded-xl border bg-gray-50/60">
                      <p className="text-[11px] text-gray-400 mb-1">CNAME · {t('المضيف', 'Host')}: <b dir="ltr">{data.dns_instructions?.cname_host}</b></p>
                      <div className="flex items-center gap-2">
                        <code className="flex-1 text-xs bg-white border rounded-lg px-2 py-1.5" dir="ltr">{data.dns_instructions?.cname_value}</code>
                        <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => copy(data.dns_instructions?.cname_value, 'CNAME')}><Copy className="h-3.5 w-3.5" /></Button>
                      </div>
                    </div>
                    <ol className="space-y-2 text-xs text-gray-500">
                      {(data.dns_instructions?.steps || []).map((s: string, i: number) => (
                        <li key={i} className="flex gap-2"><span className="text-teal-600 font-bold shrink-0">{i + 1}.</span>{s}</li>
                      ))}
                    </ol>
                  </div>
                )}
              </CardContent>
            </Card>
          </div>

          {/* How it works strip */}
          <Card className="border-0 shadow-sm">
            <CardContent className="p-6">
              <h3 className="font-semibold mb-4 flex items-center gap-2"><ShieldCheck className="h-4 w-4 text-green-600" />{t('لماذا الدومين الخاص؟', 'Why a custom domain?')}</h3>
              <div className="grid sm:grid-cols-3 gap-4 text-sm">
                <div className="p-4 rounded-xl bg-gray-50"><p className="font-bold mb-1">🔒 {t('ثقة أعلى', 'More trust')}</p><p className="text-gray-500 text-xs">{t('الطلاب يثقون أكثر في عنوان مألوف واحترافي', 'Students trust a familiar, professional address more')}</p></div>
                <div className="p-4 rounded-xl bg-gray-50"><p className="font-bold mb-1">📈 {t('تحويل أعلى', 'Higher conversion')}</p><p className="text-gray-500 text-xs">{t('علامة تجارية كاملة ترفع معدل التسجيل والدفع', 'A complete brand raises enrollment & payment rates')}</p></div>
                <div className="p-4 rounded-xl bg-gray-50"><p className="font-bold mb-1">✨ {t('احترافية كاملة', 'Full professionalism')}</p><p className="text-gray-500 text-xs">{t('موقعك بعنوانك في كل الإعلانات والروابط', 'Your site under your own address everywhere')}</p></div>
              </div>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}
