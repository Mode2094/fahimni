'use client';

import { useEffect, useState } from 'react';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { resolveLogoUrl } from '@/lib/logo';
import { currencyLabel } from '@/lib/utils';
import Link from 'next/link';
import { toast } from 'sonner';
import {
  Sparkles, CheckCircle, ArrowLeft, BookOpen, CreditCard, BarChart3, Globe, Video,
  ShieldCheck, ChevronDown, Menu, X, Loader2, LayoutGrid, Wallet, TrendingUp, HelpCircle,
  MessageCircle, Phone, Smartphone, Headphones, RefreshCw,
} from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import PlanAssistant from '@/components/assistant/plan-assistant';
import QuranTeacherBanner from '@/components/quran-teacher-banner';
import DashboardPreview from '@/components/home/dashboard-preview';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

/* رقم واتساب دعم المنصة — غيّره من هنا */
const SUPPORT_WHATSAPP = '962793519944';

/* الصفحة تعرض فقط ما هو متاح فعليًا كميزات منصة؛ ما يعتمد على الخطة
   (بث مباشر، دومين، تطبيق الطلاب…) يُشار إليه بوضوح عند الأسعار. */
const MAIN_FEATURES = [
  { icon: LayoutGrid, title: ['منشئ كورسات', 'Course Builder'], desc: ['أقسام، دروس، فيديو، وملفات — أنشئ كورساً احترافياً في دقائق', 'Sections, lessons, video & files — build a professional course in minutes'], color: '#0d9488' },
  { icon: CreditCard, title: ['بطاقات تسجيل ذكية', 'Smart Registration Cards'], desc: ['رمز واحد يسجل طلابك تلقائياً — اطبعها أو أرسلها واتساب', 'One code enrolls students automatically — print or send via WhatsApp'], color: '#059669' },
  { icon: Wallet, title: ['تحصيل المدفوعات', 'Collect payments'], desc: ['استقبل مدفوعات طلابك عبر بوابات الدفع مع فواتير تلقائية', 'Accept student payments via payment gateways with automatic invoices'], color: '#d97706' },
  { icon: BarChart3, title: ['تحليلات وتقارير', 'Analytics & Reports'], desc: ['تقارير أداء طلابك ونسب الإكمال والنتائج في لوحة واحدة', 'Student performance, completion and results in one dashboard'], color: '#ca8a04' },
  { icon: Globe, title: ['موقع خاص بعلامتك', 'Your Own Branded Site'], desc: ['موقع عام بألوانك وشعارك، يبني علامتك التجارية تلقائياً', 'Public site with your colors & logo, building your brand automatically'], color: '#14b8a6' },
  { icon: Video, title: ['دروس مسجلة وحصص مباشرة', 'Recorded & Live Lessons'], desc: ['دروس فيديو عالية الجودة، وحصص مباشرة لطلابك حسب خطتك', 'High-quality video lessons and live sessions for your students, per plan'], color: '#dc2626' },
];

const STEPS = [
  { icon: Sparkles, title: ['أنشئ حسابك', 'Create your account'], desc: ['سجّل واحصل على موقعك التعليمي الخاص فوراً', 'Sign up and get your own educational site instantly'] },
  { icon: BookOpen, title: ['أضف كورساتك', 'Add your courses'], desc: ['أنشئ الأقسام والدروس والاختبارات وحدد أسعارك', 'Create sections, lessons, exams and set your prices'] },
  { icon: TrendingUp, title: ['اجذب طلابك واربح', 'Attract students & earn'], desc: ['شارك بطاقات التسجيل واستقبل مدفوعاتك مباشرة', 'Share registration cards and receive your payments directly'] },
];

const FAQS: [string, string][] = [
  ['كيف أبدأ مع المنصة؟', 'سجّل حسابك، أنشئ كورساتك وأقسامك ودروسك خلال دقائق، ثم شارك رابط موقعك مع طلابك.'],
  ['هل يمكنني بيع الكورسات ببطاقات التسجيل؟', 'نعم — ولّد بطاقات برمز QR أو اطبعها أو أرسلها واتساب، وعند الاسترداد يُسجل الطالب تلقائياً في كورسك.'],
  ['هل المنصة تدعم الدفع الإلكتروني؟', 'نعم، ندعم بوابات دفع متعددة مع فواتير تلقائية وملخص إيرادات لحظي. تختلف البوابات المتاحة حسب إعدادات المنصة.'],
  ['هل أحصل على موقع خاص بعلامتي التجارية؟', 'نعم — موقع عام كامل بألوانك وشعارك مع إمكانية ربط دومين مخصص حسب خطتك.'],
  ['هل يمكنني متابعة أداء طلابي؟', 'نعم — تقارير تقدم الطلاب، نسب الإكمال، نتائج الامتحانات، ومتابعة الأجهزة في لوحة تحليلات متكاملة.'],
  ['كيف ألغي اشتراكي؟', 'من لوحة التحكم أو بمراسلة الدعم — الإلغاء فوري.'],
];

export default function HomeClient() {
  const { locale } = useLocale();
  const { settings, formatPrice, load } = useSettings();
  const [plans, setPlans] = useState<any[]>([]);
  const [openFaq, setOpenFaq] = useState<number | null>(0);
  const [menuOpen, setMenuOpen] = useState(false);
  const [cycle, setCycle] = useState<'monthly' | 'yearly'>('monthly');
  const [allExpanded, setAllExpanded] = useState(false);
  const [callbackName, setCallbackName] = useState('');
  const [callbackPhone, setCallbackPhone] = useState('');
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  useEffect(() => {
    load();
    fetch(`${API}/public/plans`)
      .then(r => r.json())
      .then(d => setPlans(d.plans || []))
      .catch(() => {});
  }, []);

  // Yearly discount computed from the admin-set prices automatically.
  const yearlyDiscount = (p: any): number | null => {
    const m = Number(p.monthly_price);
    const a = Number(p.annual_price);
    if (!m || !a || a <= 0) return null;
    const pct = Math.round((1 - a / (m * 12)) * 100);
    return pct > 0 ? pct : null;
  };

  const livePlans = plans;

  // عرض تسويقي: أهم الميزات المفعّلة فقط (لا فوضى ولا ميزات معطلة).
  const PRIORITY = ['storage_limit_mb', 'students_limit', 'courses_limit', 'videos_limit', 'live_stream_enabled', 'live_minutes_limit', 'video_watch_minutes_limit', 'exams_limit', 'questions_limit', 'assignments_limit', 'community_enabled', 'cards_enabled', 'custom_branding_enabled', 'custom_domain', 'blog_enabled', 'export_reports', 'appointments_enabled', 'paid_services_enabled', 'multi_teacher_enabled', 'white_label', 'api_access', 'support_level', 'certificates_enabled', 'scheduled_lessons_enabled', 'secure_playback_enabled', 'video_analytics_enabled'];
  const orderedFeatures = (features: any[], all = false): any[] => {
    const enabled = (f: any) => {
      const v = typeof f === 'string' ? f.split('|')[1] : f.value ?? f.feature_value;
      if (v === '1' || v === 'true' || v === 'on') return true;
      return v !== undefined && v !== null && v !== '' && v !== '0' && v !== 'false' && v !== 'off';
    };
    const rank = (f: any) => {
      const k = typeof f === 'string' ? f.split('|')[0] : f.key ?? f.feature_key;
      const i = PRIORITY.indexOf(k);
      return i === -1 ? PRIORITY.length : i;
    };
    const list = (features || []).filter(enabled).sort((a, b) => rank(a) - rank(b));
    return all ? list : list.slice(0, 8);
  };
  const enabledCount = (features: any[]): number => {
    const v = (f: any) => (typeof f === 'string' ? f.split('|')[1] : f.value ?? f.feature_value);
    return (features || []).filter((f) => {
      const x = v(f);
      return x === '1' || x === 'true' || x === 'on' || (x !== undefined && x !== null && x !== '' && x !== '0' && x !== 'false' && x !== 'off');
    }).length;
  };

  const scrollTo = (id: string) => {
    setMenuOpen(false);
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });
  };

  // 4 روابط أساسية فقط في الترويسة — الباقي في التذييل.
  const navLinks = [
    { id: 'features', label: t('المميزات', 'Features') },
    { id: 'how', label: t('كيف تعمل', 'How it works') },
    { id: 'preview', label: t('المنصة', 'Platform') },
    { id: 'pricing', label: t('الأسعار', 'Pricing') },
  ];

  return (
    <div className="min-h-screen bg-white" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
      {/* ─── Header ─────────────────────────────────────── */}
      <header className="sticky top-0 z-50 glass-light border-b border-teal-100/60">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center gap-6">
          <Link href="/" className="flex items-center gap-2.5 shrink-0">
            <img src={resolveLogoUrl(settings.logo_url)} alt="" className="h-9 w-9 rounded-xl object-cover shadow-sm" onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }} />
            <span className="text-lg font-bold text-teal-950">{settings.platform_name || t('منصة فهمني', 'Fahimni')}</span>
          </Link>
          <nav className="hidden md:flex items-center gap-1 flex-1" aria-label={t('التنقل', 'Navigation')}>
            {navLinks.map(l => (
              <button key={l.id} onClick={() => scrollTo(l.id)} className="px-3.5 py-2 rounded-lg text-sm text-teal-900/70 hover:text-teal-950 hover:bg-teal-500/10 transition-colors">
                {l.label}
              </button>
            ))}
          </nav>
          <div className="hidden md:flex items-center gap-2 ms-auto">
            <Link href="/teacher/login" className="px-4 py-2 rounded-xl text-sm font-medium text-teal-900/80 hover:bg-teal-500/10 transition-colors">
              {t('تسجيل الدخول', 'Log in')}
            </Link>
            <Link href="/register/teacher" className="px-5 py-2 rounded-xl text-sm font-semibold text-white shadow-brand transition-all hover:opacity-90" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
              {t('ابدأ مجانًا', 'Start free')}
            </Link>
          </div>
          <button className="md:hidden ms-auto text-teal-950 flex h-11 w-11 items-center justify-center" onClick={() => setMenuOpen(!menuOpen)} aria-label={t('القائمة', 'Menu')} aria-expanded={menuOpen}>
            {menuOpen ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
          </button>
        </div>
        {menuOpen && (
          <div className="md:hidden border-t border-teal-100/60 bg-white/95 backdrop-blur-xl p-4 space-y-1">
            {navLinks.map(l => (
              <button key={l.id} onClick={() => scrollTo(l.id)} className="block w-full text-start px-3 py-3 rounded-lg text-sm text-teal-950/80 hover:bg-teal-500/10">{l.label}</button>
            ))}
            <div className="flex gap-2 pt-2">
              <Link href="/teacher/login" className="flex-1 text-center px-4 py-3 rounded-xl text-sm font-medium border border-teal-200 text-teal-900">{t('تسجيل الدخول', 'Log in')}</Link>
              <Link href="/register/teacher" className="flex-1 text-center px-4 py-3 rounded-xl text-sm font-semibold text-white" style={{ background: '#0d9488' }}>{t('ابدأ مجانًا', 'Start free')}</Link>
            </div>
          </div>
        )}
      </header>

      {/* ─── Hero ─────────────────────────────────────── */}
      <section className="relative overflow-hidden hero-gradient">
        <div className="absolute -top-24 start-1/4 h-80 w-80 rounded-full bg-teal-300/15 blur-3xl" aria-hidden="true" />
        <div className="absolute -bottom-24 end-1/4 h-72 w-72 rounded-full bg-emerald-300/10 blur-3xl" aria-hidden="true" />
        <div className="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 py-16 sm:py-24">
          <div className="grid lg:grid-cols-2 gap-12 lg:gap-10 items-center">
            <div className="text-center lg:text-start">
              <span className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium mb-6 glass">
                <Sparkles className="h-3.5 w-3.5 text-teal-200" />
                <span className="text-white">{t('منصة تعليمية للمعلمين المستقلين', 'A teaching platform for independent teachers')}</span>
              </span>
              <h1 className="text-3xl sm:text-5xl font-black text-white leading-[1.25]">
                {t('منصتك التعليمية،', 'Your teaching platform,')}{' '}
                <span className="text-amber-200">{t('باسمك', 'in your name')}</span>
              </h1>
              <p className="text-white/70 text-base sm:text-lg mt-5 max-w-xl mx-auto lg:mx-0 leading-relaxed">
                {t('أنشئ منصتك خلال دقائق: دورات مسجّلة، بطاقات تسجيل، اختبارات، وموقع باسمك وعلامتك — وأدر طلابك وأرباحك بدون وسطاء.', 'Launch your own platform in minutes: recorded courses, registration cards, exams and a branded website — manage your students and revenue with no middlemen.')}
              </p>

              <div className="flex flex-wrap justify-center lg:justify-start gap-3 mt-8">
                <Link href="/register/teacher" className="px-8 py-4 rounded-2xl text-white font-bold shadow-brand transition-all hover:opacity-90 hover:scale-[1.02] cta-shine" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
                  {t('ابدأ مجانًا', 'Start free')} {locale === 'ar' ? '←' : '→'}
                </Link>
                <button onClick={() => scrollTo('how')} className="px-8 py-4 rounded-2xl font-bold text-white border-2 border-white/30 bg-white/10 backdrop-blur-sm hover:bg-white/20 transition-colors">
                  {t('شاهد كيف تعمل', 'See how it works')}
                </button>
              </div>
            </div>

            <div className="relative mx-auto w-full max-w-xl lg:max-w-none">
              <DashboardPreview withPhone={false} />
              <p className="mt-3 text-center text-xs text-white/50">
                {t('معاينة توضيحية للوحة تحكم المعلم', 'Illustrative preview of the teacher dashboard')}
              </p>
            </div>
          </div>
        </div>
      </section>

      {/* ─── المميزات ───────────────────────────────────── */}
      <section id="features" className="py-20">
        <div className="max-w-7xl mx-auto px-4 sm:px-6">
          <div className="text-center max-w-2xl mx-auto mb-14">
            <h2 className="text-3xl sm:text-4xl font-bold text-teal-950">{t('كل ما تحتاجه في منصة واحدة', 'Everything you need in one platform')}</h2>
            <p className="text-teal-900/60 mt-3 text-lg">{t('أدوات التدريس والإدارة والنمو — بدون تشتّت بين تطبيقات متعددة', 'Teaching, management and growth tools — no more juggling multiple apps')}</p>
          </div>
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            {MAIN_FEATURES.map((f, i) => (
              <Card key={i} className="border-0 shadow-sm card-hover">
                <CardContent className="p-6">
                  <div className="flex h-12 w-12 items-center justify-center rounded-2xl mb-4" style={{ backgroundColor: f.color + '15' }}>
                    <f.icon className="h-6 w-6" style={{ color: f.color }} />
                  </div>
                  <h3 className="text-lg font-bold text-teal-950">{t(f.title[0], f.title[1])}</h3>
                  <p className="text-teal-900/60 text-sm mt-2 leading-relaxed">{t(f.desc[0], f.desc[1])}</p>
                </CardContent>
              </Card>
            ))}
          </div>
          <p className="text-center text-sm text-teal-900/50 mt-8">
            {t('تختلف الميزات المتاحة (البث المباشر، الدومين، تطبيق الطلاب…) حسب الخطة — راجع الأسعار.', 'Available features (live sessions, custom domain, student app…) depend on your plan — see pricing.')}
          </p>
        </div>
      </section>

      {/* ─── كيف تعمل ───────────────────────────────────── */}
      <section id="how" className="py-20" style={{ background: 'linear-gradient(180deg, #f0fdfa, #e8f6f3)' }}>
        <div className="max-w-7xl mx-auto px-4 sm:px-6">
          <div className="text-center mb-14">
            <h2 className="text-3xl sm:text-4xl font-bold text-teal-950">{t('كيف تعمل؟', 'How it works?')}</h2>
            <p className="text-teal-900/60 mt-3 text-lg">{t('ابدأ في 3 خطوات', 'Start in 3 steps')}</p>
          </div>
          <div className="grid md:grid-cols-3 gap-8">
            {STEPS.map((s, i) => (
              <div key={i} className="relative text-center">
                <div className="mx-auto w-20 h-20 rounded-3xl bg-white shadow-brand border border-teal-100 flex items-center justify-center mb-5">
                  <s.icon className="h-9 w-9 text-teal-600" />
                </div>
                <span className="absolute top-0 -start-4 text-7xl font-black text-teal-600/10 select-none" aria-hidden="true">{i + 1}</span>
                <h3 className="text-xl font-bold text-teal-950 mt-2">{t(s.title[0], s.title[1])}</h3>
                <p className="text-teal-900/60 mt-2 text-sm leading-relaxed max-w-xs mx-auto">{t(s.desc[0], s.desc[1])}</p>
                {i < 2 && (
                  <div className="hidden md:flex absolute top-10 -end-10 items-center justify-center" aria-hidden="true">
                    <ArrowLeft className="h-7 w-7 text-teal-300" style={locale === 'ar' ? { transform: 'rotate(180deg)' } : undefined} />
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ─── نظرة على المنصة ─────────────────────────────── */}
      <section id="preview" className="py-20">
        <div className="max-w-7xl mx-auto px-4 sm:px-6">
          <div className="grid lg:grid-cols-5 gap-10 lg:gap-14 items-center">
            <div className="lg:col-span-2">
              <span className="inline-block px-4 py-1.5 rounded-full text-sm font-medium mb-4" style={{ backgroundColor: '#0d948815', color: '#0d9488' }}>
                {t('نظرة على المنصة', 'Platform tour')}
              </span>
              <h2 className="text-3xl sm:text-4xl font-bold text-teal-950">{t('لوحة تحكم واضحة، تعمل على الجوال والحاسوب', 'A clear dashboard on desktop and mobile')}</h2>
              <p className="text-teal-900/60 mt-4 text-lg leading-relaxed">
                {t('كل شيء أمامك في مكان واحد: الطلاب، الكورسات، الاختبارات والتحليلات — بواجهة عربية بسيطة.', 'Everything in one place: students, courses, exams and analytics — in a simple Arabic interface.')}
              </p>
              <ul className="mt-7 space-y-3.5">
                {[
                  t('أضف كورساً وأقساماً ودروساً في دقائق', 'Add a course with sections and lessons in minutes'),
                  t('تابع تسجيل الطلاب وتقدمهم لحظياً', 'Track student enrollment and progress live'),
                  t('أنشئ الاختبارات وصحّحها تلقائياً', 'Create exams and grade them automatically'),
                  t('اطبع بطاقات التسجيل برمز QR', 'Print registration cards with a QR code'),
                ].map((x, i) => (
                  <li key={i} className="flex items-start gap-2.5 text-teal-900/70">
                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-teal-500/10">
                      <CheckCircle className="h-4 w-4 text-teal-600" />
                    </span>
                    <span className="text-sm font-medium leading-relaxed">{x}</span>
                  </li>
                ))}
              </ul>
            </div>
            <div className="lg:col-span-3">
              <DashboardPreview withPhone />
              <p className="mt-10 text-center text-xs text-slate-400 sm:mt-12">
                {t('معاينة توضيحية — تختلف الواجهة حسب بيانات منصتك.', 'Illustrative preview — the interface reflects your own data.')}
              </p>
            </div>
          </div>
        </div>
      </section>

      {/* ─── الأسعار ─────────────────────────────────── */}
      <section id="pricing" className="py-20" style={{ background: 'linear-gradient(180deg, #ffffff, #f0fdfa)' }}>
        <div className="max-w-7xl mx-auto px-4 sm:px-6">
          <div className="text-center max-w-2xl mx-auto mb-14">
            <h2 className="text-3xl sm:text-4xl font-bold text-teal-950">{t('الأسعار', 'Pricing')}</h2>
            <p className="text-teal-900/60 mt-3 text-lg">{t('خطط بسيطة تناسب كل معلم', 'Simple plans for every teacher')}</p>
          </div>

          {/* ─── Billing cycle toggle ─────────────────── */}
          <div className="flex justify-center mb-10">
            <div className="inline-flex items-center gap-1 p-1.5 rounded-full bg-teal-100/70 border border-teal-200/60">
              <button
                onClick={() => setCycle('monthly')}
                className={`px-6 py-3 rounded-full text-sm font-bold transition-all ${cycle === 'monthly' ? 'bg-white text-teal-700 shadow-sm' : 'text-teal-800/60 hover:text-teal-950'}`}
              >
                {t('شهري', 'Monthly')}
              </button>
              <button
                onClick={() => setCycle('yearly')}
                className={`px-6 py-3 rounded-full text-sm font-bold transition-all ${cycle === 'yearly' ? 'bg-white text-teal-700 shadow-sm' : 'text-teal-800/60 hover:text-teal-950'}`}
              >
                {t('سنوي', 'Yearly')}
                {livePlans.some((p) => yearlyDiscount(p) !== null) && (
                  <span className="ms-2 px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-xs">
                    -{Math.max(...livePlans.map((p) => yearlyDiscount(p) ?? 0))}%
                  </span>
                )}
              </button>
            </div>
          </div>

          <div className="max-w-5xl mx-auto mb-4 flex justify-end">
            <button
              onClick={() => setAllExpanded(!allExpanded)}
              className="rounded-full border border-teal-200 bg-teal-50 px-4 py-2.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100"
            >
              {allExpanded ? t('إخفاء الميزات من الكل', 'Hide all features') : t('عرض المقارنة الكاملة (كل الميزات)', 'Full comparison (all features)')}
            </button>
          </div>
          <div className="grid md:grid-cols-3 gap-6 max-w-5xl mx-auto">
            {livePlans.length === 0 && (
              <div className="md:col-span-3 flex justify-center py-12">
                <Loader2 className="h-8 w-8 animate-spin text-teal-500" />
              </div>
            )}
            {livePlans.map((p: any) => {
              const disc = yearlyDiscount(p);
              const price = cycle === 'yearly' ? Number(p.annual_price ?? p.monthly_price) : Number(p.monthly_price);
              // Most-popular = the middle plan by price (works with any names
              // the admin sets).
              const popular = p.popular === true || livePlans.indexOf(p) === Math.floor(livePlans.length / 2);
              return (
              <Card key={p.id} className={`border-0 shadow-sm relative ${popular ? 'ring-2 ring-amber-500 scale-[1.02] shadow-brand' : ''}`}>
                {popular && (
                  <span className="absolute -top-3.5 left-1/2 -translate-x-1/2 px-4 py-1.5 rounded-full text-xs font-bold text-white shadow-lg whitespace-nowrap accent-gradient">
                    {t('الأكثر شيوعاً', 'Most Popular')}
                  </span>
                )}
                <CardContent className="p-7">
                  {/* ─── تطبيق الطلاب (Mobile App) — ميزة بارزة في الأعلى ─── */}
                  {!settings.student_app_hidden && (() => {
                    const appF = (p.features || []).find((x: any) => (x.feature_key || x.key) === 'student_mobile_app');
                    const appOn = appF ? ['true', '1', 'on'].includes(String(appF.value ?? appF.feature_value)) : false;
                    return (
                      <div className={`-mx-7 -mt-7 mb-6 flex items-center gap-3 rounded-t-2xl px-4 py-3.5 ${appOn ? 'bg-gradient-to-r from-violet-600 via-purple-600 to-fuchsia-600' : 'bg-gradient-to-r from-violet-50 to-fuchsia-50 border-b-2 border-violet-100'}`}>
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
                    );
                  })()}
                  <div className="flex items-center gap-2">
                    <h3 className="text-xl font-bold text-teal-950">{p.name}</h3>
                    {popular && (
                      <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 text-[10px] font-bold border border-amber-200">
                        <Sparkles className="h-3 w-3" />{t('الأكثر شيوعاً', 'Most Popular')}
                      </span>
                    )}
                  </div>
                  <div className="mt-4 flex items-end gap-1">
                    <span className="text-4xl font-black text-teal-950">{formatPrice(price).split(' ')[0]}</span>
                    <span className="text-teal-900/50 pb-1.5">{currencyLabel(settings.currency, locale === 'ar')} / {cycle === 'yearly' ? t('سنة', 'year') : t('شهر', 'month')}</span>
                  </div>
                  {cycle === 'yearly' && disc !== null && (
                    <p className="mt-1 text-xs font-bold text-green-600">{t('وفّرت', 'You save')} {disc}%</p>
                  )}
                  <div className="mt-6 space-y-3">
                    {(allExpanded ? orderedFeatures(p.features, true) : orderedFeatures(p.features)).map((f: any, i: number) => {
                      const isBool = f.type === 'boolean';
                      const value = f.value ?? f.feature_value;
                      const label = (locale === 'ar' ? f.label_ar : f.label_en) || f.key || f.feature_key;
                      const display = (locale === 'ar' ? f.display_ar : f.display_en) || value;
                      const desc = (locale === 'ar' ? f.desc_ar : f.desc_en) || '';
                      return (
                        <div key={i} className="group relative flex items-center gap-2.5 text-sm">
                          <span className="h-4 w-1 rounded-full bg-emerald-500 shrink-0" />
                          <span className="text-teal-900/70 cursor-help">
                            {label}
                            {!isBool && display && display !== 'مفعل' && display !== 'Enabled' && <b> · {display}</b>}
                            {desc && (
                              <span className="pointer-events-none absolute bottom-full right-0 z-20 mb-1.5 hidden w-60 rounded-xl bg-gray-900 text-white text-xs leading-relaxed p-3 shadow-xl group-hover:block">
                                {desc}
                              </span>
                            )}
                          </span>
                        </div>
                      );
                    })}
                    {!allExpanded && enabledCount(p.features) > 8 && (
                      <p className="text-xs text-gray-400 mt-1">+ {enabledCount(p.features) - 8} {t('ميزة أخرى', 'more features')}</p>
                    )}
                  </div>
                  <Link
                    href="/register/teacher"
                    onClick={(e) => {
                      // Route based on auth state without breaking hydration.
                      if (typeof window !== 'undefined' && localStorage.getItem('lms_authed')) {
                        e.preventDefault();
                        window.location.href = `/checkout?plan=${p.id}&cycle=${cycle}`;
                      }
                    }}
                    className={`mt-7 w-full flex justify-center items-center gap-2 px-5 py-3.5 rounded-xl text-sm font-semibold transition-all ${p.popular ? 'text-white shadow-brand hover:opacity-90' : 'border border-teal-200 text-teal-800 hover:border-teal-400 hover:text-teal-700'}`}
                    style={p.popular ? { background: 'linear-gradient(135deg, #d97706, #f59e0b)' } : undefined}>
                    {t('اختر الخطة', 'Choose Plan')}
                  </Link>
                </CardContent>
              </Card>
              );
            })}
          </div>
          <p className="text-center text-teal-900/50 text-sm mt-8">
            {t('جميع الخطط تشمل: دعم فني وتحديثات مستمرة', 'All plans include: support & continuous updates')}
          </p>
        </div>
      </section>

      {/* ─── الأسئلة الشائعة ────────────────────────────── */}
      <section id="faq" className="py-24" style={{ background: '#f0fdfa' }}>
        <div className="max-w-3xl mx-auto px-4 sm:px-6">
          <div className="text-center mb-14">
            <div className="inline-block px-4 py-1.5 rounded-full text-sm font-medium mb-4" style={{ backgroundColor: '#0d948815', color: '#0d9488' }}>
              <HelpCircle className="h-4 w-4 inline me-1" />{t('الأسئلة الشائعة', 'FAQ')}
            </div>
            <h2 className="text-3xl sm:text-4xl font-bold text-teal-950">{t('لديك سؤال؟', 'Have a question?')}</h2>
            <p className="text-teal-900/60 mt-3 text-lg">{t('كل ما تريد معرفته عن المنصة', 'Everything you want to know about the platform')}</p>
          </div>

          <div className="space-y-3.5">
            {FAQS.map((f, i) => {
              const open = openFaq === i;
              return (
                <div
                  key={i}
                  className={`rounded-2xl border bg-white transition-all duration-200 ${
                    open ? 'border-teal-300 shadow-sm' : 'border-teal-100/80 hover:border-teal-200'
                  }`}
                >
                  <button
                    onClick={() => setOpenFaq(open ? null : i)}
                    className="w-full flex items-center gap-4 px-5 sm:px-6 py-5 text-start"
                    aria-expanded={open}
                  >
                    <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-sm font-black ${open ? 'bg-teal-600 text-white' : 'bg-teal-50 text-teal-600'}`}>
                      {String(i + 1).padStart(2, '0')}
                    </span>
                    <span className={`flex-1 font-bold text-[15px] leading-relaxed ${open ? 'text-teal-950' : 'text-teal-900/80'}`}>
                      {t(f[0], f[0])}
                    </span>
                    <ChevronDown className={`h-4 w-4 shrink-0 text-teal-500 transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
                  </button>
                  <div className={`grid transition-all duration-200 ease-in-out ${open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'}`}>
                    <div className="overflow-hidden">
                      <div className="px-5 sm:px-6 pb-5 ps-[4.6rem] text-teal-900/60 text-sm leading-loose border-t border-teal-50 pt-4">
                        {t(f[1], f[1])}
                      </div>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>

          {/* بطاقة تواصل */}
          <div className="mt-10 flex flex-wrap items-center justify-center gap-4 rounded-3xl border border-teal-100 bg-white px-6 py-5 shadow-sm">
            <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-teal-50"><MessageCircle className="h-5 w-5 text-teal-600" /></span>
            <p className="text-sm text-teal-900/70">
              {t('لم تجد إجابتك؟', 'Didn\'t find your answer?')}{' '}
              <a href={`mailto:${settings.contact_email || 'support@fahimni.me'}`} className="font-bold text-teal-700 underline underline-offset-4 hover:text-teal-600 transition-colors">
                {t('تواصل مع الدعم الفني', 'Contact support')}
              </a>
            </p>
          </div>

          {/* نموذج نرجع عليك — بدون التزام */}
          <div className="mt-6 overflow-hidden rounded-3xl border border-teal-100 bg-white shadow-lg">
            <div className="grid md:grid-cols-2">
              <div className="relative p-7 sm:p-8" style={{ background: 'linear-gradient(135deg, #0f2e2b, #115e54 60%, #0d9488)' }}>
                <div className="relative">
                  <span className="flex h-12 w-12 items-center justify-center rounded-2xl glass mb-4"><Phone className="h-6 w-6 text-teal-200" /></span>
                  <h3 className="text-xl font-bold text-white">{t('محتار؟ نرجع عليك', 'Unsure? We\'ll call you')}</h3>
                  <p className="text-white/60 text-sm mt-2 leading-relaxed">
                    {t('اترك اسمك ورقم واتساب — أحد خبرائنا يشرح لك كل شيء. بدون أي التزام.', 'Leave your name and WhatsApp number — one of our experts explains everything. No commitment.')}
                  </p>
                  <div className="mt-5 space-y-2">
                    {[
                      t('شرح كامل للمنصة والميزات', 'Full platform walkthrough'),
                      t('إجابة عن كل أسئلتك', 'Answers to all your questions'),
                      t('تساعدك في اختيار الخطة المناسبة', 'Help choosing the right plan'),
                    ].map((x, i) => (
                      <div key={i} className="flex items-center gap-2 text-sm text-white/80">
                        <CheckCircle className="h-4 w-4 shrink-0 text-emerald-300" />{x}
                      </div>
                    ))}
                  </div>
                </div>
              </div>

              <div className="p-7 sm:p-8">
                <form
                  onSubmit={(e) => {
                    e.preventDefault();
                    if (!callbackName.trim() || !callbackPhone.trim()) {
                      toast.error(t('أدخل اسمك ورقم واتساب', 'Enter your name and WhatsApp number'));
                      return;
                    }
                    const msg = encodeURIComponent(`${t('مرحباً، أنا', 'Hello, I am')} ${callbackName} — ${t('أرغب باستشارة حول المنصة', 'I would like a consultation about the platform')}.`);
                    window.open(`https://wa.me/${SUPPORT_WHATSAPP}?text=${msg}`, '_blank');
                    toast.success(t('تم فتح واتساب — رسالتك جاهزة للإرسال', 'WhatsApp opened — your message is ready to send'));
                  }}
                  className="space-y-4"
                >
                  <div>
                    <label className="text-xs font-bold text-teal-900/50">{t('اسمك', 'Your name')}</label>
                    <Input value={callbackName} onChange={(e) => setCallbackName(e.target.value)} placeholder={t('مثال: أحمد محمد', 'e.g. Ahmed Mohammed')} className="h-12 mt-1.5 rounded-xl border-teal-100" />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-teal-900/50">{t('رقم واتساب', 'WhatsApp number')}</label>
                    <Input value={callbackPhone} onChange={(e) => setCallbackPhone(e.target.value)} placeholder="+962 7X XXX XXXX" dir="ltr" className="h-12 mt-1.5 rounded-xl border-teal-100 text-end" />
                  </div>
                  <button type="submit" className="w-full rounded-full py-3.5 text-white font-bold shadow-brand transition-all hover:scale-[1.01] cta-shine" style={{ background: 'linear-gradient(135deg, #25d366, #128c7e)' }}>
                    <MessageCircle className="h-4 w-4 inline me-1.5" />{t('يتواصل معي خبير', 'An expert will contact me')}
                  </button>
                  <p className="text-center text-[11px] text-teal-900/40">{t('بياناتك لا تُشارك مع أي طرف ثالث', 'Your data is never shared with third parties')}</p>
                </form>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ─── CTA ────────────────────────────────────────── */}
      <section className="py-20 px-4">
        <div className="max-w-5xl mx-auto rounded-3xl overflow-hidden relative" style={{ background: 'linear-gradient(135deg, #0f2e2b, #115e54, #0d9488)' }}>
          <div className="absolute top-0 right-0 w-72 h-72 bg-teal-300/20 rounded-full blur-3xl" aria-hidden="true" />
          <div className="relative z-10 px-6 py-16 text-center">
            <h2 className="text-3xl sm:text-4xl font-bold text-white">{t('جاهز لبناء علامتك التعليمية؟', 'Ready to build your teaching brand?')}</h2>
            <p className="text-white/70 text-lg mt-4">{t('ابدأ مجانًا اليوم — ولا تدفع إلا عندما تصبح جاهزًا للنمو.', 'Start free today — pay only when you are ready to grow.')}</p>
            <div className="flex flex-wrap justify-center gap-3 mt-8">
              <Link href="/register/teacher" className="px-7 py-3.5 rounded-2xl font-semibold text-white shadow-xl hover:scale-[1.02] transition-all" style={{ background: 'linear-gradient(135deg, #d97706, #f59e0b)' }}>
                {t('ابدأ مجانًا', 'Start free')}
              </Link>
              <button onClick={() => scrollTo('pricing')} className="px-7 py-3.5 rounded-2xl font-semibold text-white border border-white/25 glass hover:bg-white/10 transition-colors">
                {t('شاهد الأسعار', 'See Pricing')}
              </button>
            </div>

            {/* شارات الأمان */}
            <div className="mt-6 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-xs text-white/55">
              <span className="inline-flex items-center gap-1.5"><Headphones className="h-3.5 w-3.5 text-teal-300" />{t('دعم فني', 'Support')}</span>
              <span className="inline-flex items-center gap-1.5"><RefreshCw className="h-3.5 w-3.5 text-teal-300" />{t('تحديثات مستمرة', 'Continuous updates')}</span>
              <span className="inline-flex items-center gap-1.5"><ShieldCheck className="h-3.5 w-3.5 text-teal-300" />{t('بيانات مشفّرة', 'Encrypted data')}</span>
            </div>
          </div>
        </div>
      </section>

      {/* ─── بانر مدرسي القرءان ─────────────────────────────── */}
      <div id="quran-teachers">
        <QuranTeacherBanner />
      </div>

      {/* ─── Footer ─────────────────────────────────────── */}
      <footer className="border-t border-teal-900" style={{ background: '#0f2e2b' }}>
        <div className="max-w-7xl mx-auto px-4 sm:px-6 py-14 grid sm:grid-cols-2 lg:grid-cols-4 gap-10">
          <div>
            <div className="flex items-center gap-2.5 mb-4">
              <img src={resolveLogoUrl(settings.logo_url)} alt="" className="h-9 w-9 rounded-xl object-cover ring-1 ring-white/20" onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }} />
              <span className="text-lg font-bold text-white">{settings.platform_name || t('منصة فهمني', 'Fahimni')}</span>
            </div>
            <p className="text-white/50 text-sm leading-relaxed">{t('منصة تعليمية متكاملة تساعد المعلمين على بناء علامتهم التجارية وزيادة إيراداتهم.', 'An all-in-one platform helping teachers build their brand and grow revenue.')}</p>
          </div>
          <div>
            <h4 className="text-white font-semibold mb-4">{t('المنصة', 'Platform')}</h4>
            <ul className="space-y-2.5 text-sm">
              <li><button onClick={() => scrollTo('features')} className="text-white/50 hover:text-white transition-colors">{t('المميزات', 'Features')}</button></li>
              <li><button onClick={() => scrollTo('how')} className="text-white/50 hover:text-white transition-colors">{t('كيف تعمل', 'How it works')}</button></li>
              <li><button onClick={() => scrollTo('preview')} className="text-white/50 hover:text-white transition-colors">{t('نظرة على المنصة', 'Platform tour')}</button></li>
              <li><button onClick={() => scrollTo('pricing')} className="text-white/50 hover:text-white transition-colors">{t('الأسعار', 'Pricing')}</button></li>
              <li><button onClick={() => scrollTo('quran-teachers')} className="text-white/50 hover:text-white transition-colors">{t('مدرّسو القرءان', 'Quran teachers')}</button></li>
            </ul>
          </div>
          <div>
            <h4 className="text-white font-semibold mb-4">{t('حسابك', 'Your Account')}</h4>
            <ul className="space-y-2.5 text-sm">
              <li><Link href="/register/teacher" className="text-white/50 hover:text-white transition-colors">{t('إنشاء حساب معلم', 'Create Teacher Account')}</Link></li>
              <li><Link href="/teacher/login" className="text-white/50 hover:text-white transition-colors">{t('دخول المعلمين', 'Teacher Login')}</Link></li>
              <li><Link href="/student/login" className="text-white/50 hover:text-white transition-colors">{t('دخول الطلاب', 'Student Login')}</Link></li>
              <li><Link href="/admin/login" className="text-white/50 hover:text-white transition-colors">{t('دخول الأدمن', 'Admin Login')}</Link></li>
            </ul>
          </div>
          <div>
            <h4 className="text-white font-semibold mb-4">{t('تواصل معنا', 'Contact Us')}</h4>
            <ul className="space-y-2.5 text-sm text-white/50">
              <li className="flex items-center gap-2"><ShieldCheck className="h-4 w-4 text-emerald-400" />{t('دعم فني', 'Support')}</li>
              <li className="flex items-center gap-2"><span className="text-teal-300">@</span>{settings.contact_email || 'support@fahimni.me'}</li>
            </ul>
          </div>
        </div>
          <div className="border-t border-white/10">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 py-5 flex flex-wrap items-center justify-between gap-3 text-xs text-white/40">
            <span>© 2026 {settings.platform_name || 'منصة فهمني'} · {t('جميع الحقوق محفوظة', 'All rights reserved')}</span>
            <div className="flex gap-4">
              <a href="/privacy" className="hover:text-white/70">{t('سياسة الخصوصية', 'Privacy Policy')}</a>
              <a href="/terms" className="hover:text-white/70">{t('الشروط والأحكام', 'Terms & Conditions')}</a>
              <a href="/refund-policy" className="hover:text-white/70">{t('سياسة استرداد الأموال', 'Refund Policy')}</a>
              <a href="/contact" className="hover:text-white/70">{t('اتصل بنا', 'Contact Us')}</a>
            </div>
          </div>
        </div>
      </footer>

      <PlanAssistant />
    </div>
  );
}
