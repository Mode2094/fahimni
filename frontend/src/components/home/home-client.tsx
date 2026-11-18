'use client';

import { useEffect, useState, useRef } from 'react';
import { useLocale } from '@/store/locale';
import { useSettings } from '@/store/settings';
import { resolveLogoUrl } from '@/lib/logo';
import { currencyLabel } from '@/lib/utils';
import Link from 'next/link';
import { toast } from 'sonner';
import {
  GraduationCap, Sparkles, CheckCircle, BookOpen, CreditCard,
  BarChart3, Globe, Video, ShieldCheck, Users, Clock, Plus, Minus,
  Wallet, TrendingUp, LayoutGrid, Zap, ChevronDown, Menu, X, Loader2,
  Atom, Calculator, Pencil, Play, Award, FileCheck2, Trophy, Rocket, Medal,
  Timer, BookMarked, Layers, CheckCheck, MousePointerClick, ClipboardList, AlertCircle,
  Pause, QrCode, Download, Share2, Check, PartyPopper, HelpCircle, MessageCircle, Ban,
  Phone, Lock, Smartphone, Gift, BadgeCheck,
} from 'lucide-react';;
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import QuranTeacherBanner from '@/components/quran-teacher-banner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

/** Official WhatsApp glyph (lucide has no brand icons). */
function WhatsAppIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className} aria-hidden="true">
      <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.263.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" />
    </svg>
  );
}

const HERO = {
  icon: GraduationCap, label: ['المعلم الفردي', 'Individual Teacher'], badge: ['المنصة المثالية للمعلمين', 'The ideal platform for teachers'],
  title: ['امتلك موقعك التعليمي', 'Own your teaching brand'],
  subtitle: ['وأنشئ علامتك التجارية — كورسات، بطاقات تسجيل، أرباحك، وتحليلات، كل ما يحتاجه معلم ناجح في منصة واحدة.', 'Courses, registration cards, your earnings & analytics — everything a successful teacher needs in one platform.'],
  bullets: [
    ['منشئ كورسات كامل', 'Complete course builder'],
    ['بطاقات تسجيل برمز QR', 'QR registration cards'],
    ['موقع عام بعلامتك + دومين خاص', 'Public site + custom domain'],
    ['تحصيل أرباحك عبر بوابتك', 'Collect earnings via your own gateway'],
  ],
  cta: ['ابدأ الآن', 'Start now'], href: '/register/teacher', color: '#0d9488',
};

const MAIN_FEATURES = [
  { icon: LayoutGrid, title: ['منشئ كورسات', 'Course Builder'], desc: ['أقسام، دروس، فيديو، وملفات — أنشئ كورساً احترافياً في دقائق', 'Sections, lessons, video & files — build a professional course in minutes'], color: '#0d9488' },
  { icon: CreditCard, title: ['بطاقات تسجيل ذكية', 'Smart Registration Cards'], desc: ['رمز واحد يسجل طلابك تلقائياً — اطبعها أو أرسلها واتساب', 'One code enrolls students automatically — print or send via WhatsApp'], color: '#059669' },
  { icon: ClipboardList, title: ['اختبارات وواجبات', 'Exams & Assignments'], desc: ['أنشئ اختبارات وواجبات، صحّحها تلقائياً وتابع نتائج طلابك لحظياً', 'Create exams & assignments, auto-grade them and track student results in real time'], color: '#d97706' },
  { icon: BarChart3, title: ['تحليلات وإيرادات', 'Analytics & Revenue'], desc: ['تقارير أداء طلابك وإيراداتك لحظياً في لوحة واحدة', 'Real-time student performance & revenue reports in one dashboard'], color: '#ca8a04' },
  { icon: Globe, title: ['موقع خاص بعلامتك', 'Your Own Branded Site'], desc: ['موقع عام بألوانك وشعارك، يبني علامتك التجارية تلقائياً', 'Public site with your colors & logo, building your brand automatically'], color: '#14b8a6' },
  { icon: Video, title: ['بث مباشر', 'Live Streaming'], desc: ['جلسات مباشرة مع طلابك، حجز وحضور وإشعارات تلقائية', 'Live sessions with your students, booking, attendance & auto notifications'], color: '#dc2626' },
];

const FAQS: [string, string][] = [
  ['كيف أبدأ مع المنصة؟', 'سجّل حسابك، أنشئ كورساتك وأقسامك ودروسك خلال دقائق، ثم شارك رابط موقعك مع طلابك.'],
  ['هل يمكنني بيع الكورسات ببطاقات التسجيل؟', 'نعم — ولّد بطاقات برمز QR أو اطبعها أو أرسلها واتساب، وعند الاسترداد يُسجل الطالب تلقائياً في كورسك.'],
  ['هل أحصل على موقع خاص بعلامتي التجارية؟', 'نعم — موقع عام كامل بألوانك وشعارك مع إمكانية ربط دومين مخصص، وأدوات تصميم متقدمة.'],
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

  const [quizState, setQuizState] = useState<'idle' | 'wrong' | 'right'>('idle');
  const [quizPick, setQuizPick] = useState<number | null>(null);
  const [calcStudents, setCalcStudents] = useState(50);
  const [calcPrice, setCalcPrice] = useState(25);
  const [calcActive, setCalcActive] = useState(false);
  const [callbackName, setCallbackName] = useState('');
  const [callbackPhone, setCallbackPhone] = useState('');
  const [scrolledPast, setScrolledPast] = useState(false);
  const calcRef = useRef<HTMLDivElement | null>(null);
  const heroRef = useRef<HTMLElement | null>(null);
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  /* إظهار الشريط السفلي بعد تجاوز الهيرو */
  useEffect(() => {
    const onScroll = () => setScrolledPast(window.scrollY > 560);
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  /* رقم واتساب دعم المنصة — غيّره من هنا */
  const SUPPORT_WHATSAPP = '962793519944';

  /* تفعيل عدّادات الحاسبة عند الظهور */
  useEffect(() => {
    if (!calcRef.current) return;
    const io = new IntersectionObserver((entries) => {
      if (entries[0].isIntersecting) { setCalcActive(true); io.disconnect(); }
    }, { threshold: 0.3 });
    io.observe(calcRef.current);
    return () => io.disconnect();
  }, []);

  const calcRevenue = Math.round(calcStudents * calcPrice);
  const calcCompetitorFee = Math.round(calcRevenue * 0.2);
  const calcSave = Math.round(calcCompetitorFee * 12);

  /* اختبار تفاعلي تجريبي */
  const QUIZ = {
    question: t('ما ناتج 7 × 8؟', 'What is 7 × 8?'),
    options: ['54', '56', '64', '58'],
    correct: 1,
    wrong: t('ليست الإجابة الصحيحة — حاول مرة أخرى', 'Not the right answer — try again'),
    right: t('أحسنت! 7 × 8 = 56 — تماماً كما يتدرب طلابك هنا', 'Correct! 7 × 8 = 56 — just like your students practice here'),
  };
  const pickOption = (i: number) => {
    setQuizPick(i);
    if (i === QUIZ.correct) setQuizState('right');
    else setQuizState('wrong');
  };

  /* رحلة الطالب: خطوات تفاعلية */
  const [journeyStep, setJourneyStep] = useState<number>(0);
  const [playing, setPlaying] = useState(false);
  const [playback, setPlayback] = useState(38);
  const [certIssued, setCertIssued] = useState(false);
  const [typedName, setTypedName] = useState('');

  useEffect(() => {
    if (!playing) return;
    const iv = setInterval(() => {
      setPlayback((p) => {
        if (p >= 100) { setPlaying(false); return 100; }
        return p + 1;
      });
    }, 75);
    return () => clearInterval(iv);
  }, [playing]);

  useEffect(() => {
    if (!certIssued) return;
    let i = 0;
    const name = locale === 'ar' ? 'أحمد الطالب' : 'Ahmad Student';
    setTypedName('');
    const iv = setInterval(() => {
      i++;
      setTypedName(name.slice(0, i));
      if (i >= name.length) clearInterval(iv);
    }, 90);
    return () => clearInterval(iv);
  }, [certIssued]);

  const lessonDone = playback >= 100;
  const quizDone = quizState === 'right';
  const certDone = certIssued;
  const journeyDoneCount = [lessonDone, quizDone, certDone].filter(Boolean).length;

  const JOURNEY_STEPS = [
    { icon: Play, label: t('شاهد الدرس', 'Watch lesson') },
    { icon: ClipboardList, label: t('حل الاختبار', 'Take the quiz') },
    { icon: Award, label: t('استلم الشهادة', 'Get your certificate') },
  ];

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
  const seg = HERO;
  const monthlyPrices = livePlans.map((p: any) => Number(p.monthly_price)).filter((n) => n > 0);
  const minMonthly = monthlyPrices.length ? Math.min(...monthlyPrices) : null;

  /* إحصاءات وثقة — أرقام صحيحة فقط (بدون أرقام وهمية) */
  const proofStats = [
    { icon: Wallet, value: t('0%', '0%'), label: t('عمولة على مبيعات كورساتك', 'commission on your sales') },
    { icon: Timer, value: t('7 أيام', '7 days'), label: t('تجربة مجانية بدون بطاقة', 'free trial, no card') },
    { icon: ShieldCheck, value: t('100%', '100%'), label: t('ملكية المحتوى والطلاب', 'ownership of content & students') },
    { icon: MessageCircle, value: t('24/7', '24/7'), label: t('دعم عربي متواصل', 'Arabic support') },
  ];

  // عرض تسويقي: أهم الميزات المفعّلة فقط (لا فوضى 46 سطراً ولا ميزات معطلة).
  const PRIORITY = ['storage_limit_mb', 'students_limit', 'courses_limit', 'videos_limit', 'live_stream_enabled', 'live_minutes_limit', 'video_watch_minutes_limit', 'exams_limit', 'questions_limit', 'assignments_limit', 'community_enabled', 'cards_enabled', 'custom_branding_enabled', 'custom_domain', 'blog_enabled', 'export_reports', 'appointments_enabled', 'paid_services_enabled', 'multi_teacher_enabled', 'white_label', 'api_access', 'support_level', 'certificates_enabled', 'scheduled_lessons_enabled', 'secure_playback_enabled', 'video_analytics_enabled'];
  // عرض تسويقي مبسّط: أهم 8 ميزات متاحة فقط (مفعّلة) — غير المتاحة
  // لا تظهر إطلاقاً، ولا عرض لجميع الميزات (لا ازدحام).
  const orderedFeatures = (features: any[], all = false): any[] => {
    const enabled = (f: any) => {
      const v = typeof f === 'string' ? f.split('|')[1] : f.value ?? f.feature_value;
      if (v === '1' || v === 'true' || v === 'on') return true;
      // الحدود الرقمية مفعّلة ما دامت قيمة موجودة وغير صفرية.
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

  const navLinks = [
    { id: 'features', label: t('المميزات', 'Features') },
    { id: 'student', label: t('منصة طلابك', "Your students' view") },
    { id: 'earnings', label: t('الأرباح', 'Earnings') },
    { id: 'pricing', label: t('الأسعار', 'Pricing') },
    { id: 'faq', label: t('الأسئلة', 'FAQ') },
  ];

  return (
    <div className="min-h-screen bg-white" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
      {/* ─── شريط الإعلان التسويقي ─────────────────────── */}
      <div className="relative overflow-hidden text-white" style={{ background: 'linear-gradient(90deg, #0f2e2b, #0d9488 55%, #115e54)' }}>
        <div className="max-w-7xl mx-auto px-3 sm:px-4 py-2 flex items-center justify-center gap-2 text-[12px] sm:text-[13px] font-semibold leading-tight">
          <Gift className="h-4 w-4 shrink-0 text-amber-300" />
          <span className="text-white/90 text-center">
            <b className="text-white">{t('جرّب فهمني مجاناً 7 أيام', 'Try Fahimni free for 7 days')}</b>
            <span className="hidden sm:inline">{t(' — بدون بطاقة ائتمان، وبدون أي عمولة على مبيعاتك', ' — no credit card, zero commission on your sales')}</span>
            <span className="sm:hidden">{t(' — بدون بطاقة، وبدون عمولة', ' — no card, no commission')}</span>
          </span>
          <Link href="/register/teacher" className="ms-1 inline-flex shrink-0 items-center gap-1 rounded-full border border-white/30 bg-white/15 px-3 py-1 text-[12px] font-bold text-white transition-colors hover:bg-white/25">
            {t('ابدأ الآن', 'Start now')} <span className="hidden sm:inline">{locale === 'ar' ? '←' : '→'}</span>
          </Link>
        </div>
      </div>

      {/* ─── Header ─────────────────────────────────────── */}
      <header className="sticky top-0 z-50 glass-light border-b border-teal-100/60">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center gap-6">
          <div className="flex items-center gap-2.5 shrink-0">
            <img src={resolveLogoUrl(settings.logo_url)} alt="logo" className="h-9 w-9 rounded-xl object-cover shadow-sm" onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }} />
            <span className="text-lg font-bold text-teal-950">{settings.platform_name || t('منصة فهمني', 'Fahimni')}</span>
          </div>
          <nav className="hidden md:flex items-center gap-1 flex-1">
            {navLinks.map(l => (
              <button key={l.id} onClick={() => scrollTo(l.id)} className="px-3.5 py-2 rounded-lg text-sm text-teal-900/70 hover:text-teal-950 hover:bg-teal-500/10 transition-colors">
                {l.label}
              </button>
            ))}
          </nav>
          <div className="hidden md:flex items-center gap-2 ms-auto">
            <Link href="/teacher/login" className="px-4 py-2 rounded-xl text-sm font-medium text-teal-900/80 hover:bg-teal-500/10 transition-colors">
              {t('دخول المعلمين', 'Teacher Login')}
            </Link>
            <Link href="/register/teacher" className="px-4 py-2 rounded-xl text-sm font-semibold text-white shadow-brand transition-all hover:opacity-90" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
                {t('ابدأ الآن', 'Start Now')}
            </Link>
          </div>
          <button className="md:hidden ms-auto text-teal-950 flex h-11 w-11 items-center justify-center" onClick={() => setMenuOpen(!menuOpen)}>
            {menuOpen ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
          </button>
        </div>
        {menuOpen && (
          <div className="md:hidden border-t border-teal-100/60 bg-white/90 backdrop-blur-xl p-4 space-y-1">
            {navLinks.map(l => (
              <button key={l.id} onClick={() => scrollTo(l.id)} className="block w-full text-start px-3 py-3 rounded-lg text-sm text-teal-950/80 hover:bg-teal-500/10">{l.label}</button>
            ))}
            <div className="flex gap-2 pt-2">
              <Link href="/teacher/login" className="flex-1 text-center px-4 py-3 rounded-xl text-sm font-medium border border-teal-200 text-teal-900">{t('دخول المعلمين', 'Teacher Login')}</Link>
              <Link href="/register/teacher" className="flex-1 text-center px-4 py-3 rounded-xl text-sm font-semibold text-white" style={{ background: '#0d9488' }}>{t('ابدأ الآن', 'Start Now')}</Link>
            </div>
          </div>
        )}
      </header>

      {/* ─── Hero ─────────────────────────────────────── */}
      <section ref={heroRef} className="relative overflow-hidden">
        <div className="absolute inset-0 animate-gradient" style={{ background: 'linear-gradient(130deg, #0f2e2b 0%, #115e54 30%, #0d9488 55%, #115e54 80%, #0f2e2b 100%)' }}>
          <div className="absolute top-0 left-1/4 w-96 h-96 bg-teal-300/20 rounded-full blur-3xl float-y" />
          <div className="absolute bottom-0 right-1/4 w-80 h-80 bg-amber-200/10 rounded-full blur-3xl float-y-delay" />
          <div className="absolute inset-0 opacity-[0.05]"
            style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '34px 34px' }} />
        </div>

        <div className="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 py-16 sm:py-24">
          <div className="grid lg:grid-cols-2 gap-12 lg:gap-10 items-center">
            {/* النص */}
            <div className="text-center lg:text-start animate-in">
              <span className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium mb-6 glass" style={{ borderColor: seg.color + '55' }}>
                <Sparkles className="h-3.5 w-3.5 text-teal-200" /> <span className="text-white">{t(seg.badge[0], seg.badge[1])}</span>
              </span>
              <h1 className="text-4xl sm:text-5xl lg:text-[3.4rem] font-black text-white leading-[1.15] tracking-tight">
                <span className="block">{t('منصتك التعليمية،', 'Your teaching platform,')}</span>
                <span className="underline-draw text-shimmer block text-transparent bg-clip-text bg-gradient-to-r from-amber-200 via-yellow-100 to-amber-300">
                  {t('باسمك', 'in your name')}
                </span>
              </h1>
              <p className="text-white/70 text-base sm:text-lg mt-6 max-w-xl mx-auto lg:mx-0 leading-relaxed">
                {t('موقعك التعليمي الخاص بشعارك وألوانك — أنشئ كورساتك، أدر طلابك، وتابع أرباحك من لوحة تحكم واحدة.', 'Your own branded teaching site — build your courses, manage your students and track your revenue from one dashboard.')}
              </p>

              <div className="flex flex-wrap justify-center lg:justify-start gap-3 mt-8">
                <Link href={seg.href} className="px-8 py-4 rounded-2xl text-white font-bold shadow-brand transition-all hover:opacity-90 hover:scale-[1.02] cta-shine"
                  style={{ background: `linear-gradient(135deg, ${seg.color}, ${seg.color}cc)` }}>
                  {t(seg.cta[0], seg.cta[1])} {locale === 'ar' ? '←' : '→'}
                </Link>
                <button onClick={() => scrollTo('pricing')} className="px-8 py-4 rounded-2xl font-bold text-white border-2 border-white/25 bg-white/10 backdrop-blur-sm hover:bg-white/20 transition-colors">
                  {t('شاهد الأسعار', 'See Pricing')}
                </button>
              </div>

              <div className="flex flex-wrap justify-center lg:justify-start gap-x-6 gap-y-2 mt-8 text-sm text-white/85">
                {seg.bullets.slice(0, 3).map((b, i) => (
                  <span key={i} className="inline-flex items-center gap-2">
                    <CheckCircle className="h-4 w-4 shrink-0 text-teal-300" />
                    {t(b[0], b[1])}
                  </span>
                ))}
              </div>

              {/* شارات الثقة */}
              <div className="mt-6 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:justify-center lg:justify-start">
                {[
                  t('بدون بطاقة ائتمان', 'No credit card'),
                  t('إلغاء في أي وقت', 'Cancel anytime'),
                  t('0% عمولة على مبيعاتك', '0% commission on your sales'),
                  t('دعم عربي 24/7', '24/7 Arabic support'),
                ].map((x, i) => (
                  <span key={i} className="inline-flex items-center justify-center gap-1.5 rounded-full glass px-3 py-2 text-center text-xs font-semibold text-white/90 sm:justify-start sm:py-1.5">
                    <BadgeCheck className="h-3.5 w-3.5 shrink-0 text-teal-300" />{x}
                  </span>
                ))}
              </div>

              {/* مرساة السعر — عنصر الإقناع الأبرز */}
              {minMonthly !== null && (
                <div className="mt-6 inline-flex flex-wrap items-center justify-center gap-x-2 gap-y-1 rounded-2xl border border-amber-300/40 bg-amber-400/10 px-4 py-3 text-center backdrop-blur-sm lg:justify-start">
                  <span className="text-sm font-medium text-white/75">{t('تبدأ من', 'Starting at')}</span>
                  <span className="text-2xl font-black leading-none text-amber-200">{formatPrice(minMonthly)}</span>
                  <span className="text-sm font-medium text-white/75">{t('شهرياً', 'per month')}</span>
                  <span className="mx-1 hidden h-4 w-px bg-white/25 sm:inline-block" />
                  <span className="text-sm font-bold text-white">{t('وكل ما تربحه من كورساتك يبقى لك', 'and everything your courses earn stays yours')}</span>
                </div>
              )}
            </div>

            {/* عنصر بصري واحد: لوحة تحكم المنصة */}
            <div className="relative mx-auto w-full max-w-xl lg:max-w-none animate-in">
              <div className="float-y relative overflow-hidden rounded-3xl border border-white/15 bg-white shadow-2xl shadow-teal-950/40">
                {/* شريط المتصفح */}
                <div className="flex items-center gap-2 border-b border-slate-100 bg-slate-50/80 px-4 py-3">
                  <span className="h-3 w-3 rounded-full bg-rose-400/80" />
                  <span className="h-3 w-3 rounded-full bg-amber-400/80" />
                  <span className="h-3 w-3 rounded-full bg-emerald-400/80" />
                  <span className="mx-auto inline-flex items-center gap-1.5 rounded-lg border border-slate-100 bg-white px-3 py-1 text-[11px] text-slate-400" dir="ltr">
                    <Lock className="h-3 w-3" /> fahimni.me/<span className="font-semibold text-teal-600">{locale === 'ar' ? 'اسمك' : 'your-name'}</span>
                  </span>
                </div>

                <div className="grid grid-cols-[62px_1fr] sm:grid-cols-[84px_1fr]">
                  {/* الشريط الجانبي */}
                  <div className="flex flex-col items-center gap-3 border-e border-white/5 bg-[#0f2e2b] p-3 sm:p-4">
                    <span className="flex h-9 w-9 items-center justify-center rounded-xl shadow-lg" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
                      <GraduationCap className="h-5 w-5 text-white" />
                    </span>
                    {[LayoutGrid, BookOpen, Users, CreditCard, BarChart3, Video].map((I, i) => (
                      <span key={i} className={`flex h-8 w-8 items-center justify-center rounded-lg transition-colors ${i === 0 ? 'bg-teal-500/20 text-teal-200' : 'text-white/40'}`}>
                        <I className="h-4 w-4" />
                      </span>
                    ))}
                  </div>

                  {/* المحتوى */}
                  <div className="space-y-4 bg-white p-4 sm:p-5" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
                    <div className="flex items-center justify-between">
                      <div>
                        <p className="text-[11px] text-slate-400">{t('لوحة تحكم المعلم', 'Teacher dashboard')}</p>
                        <p className="text-sm font-black text-slate-800">{t('مرحباً، أ. محمد 👋', 'Welcome, Mr. Mohammed 👋')}</p>
                      </div>
                      <span className="flex h-8 w-8 items-center justify-center rounded-full bg-teal-50 text-teal-600"><Users className="h-4 w-4" /></span>
                    </div>

                    {/* بطاقات الإحصاء */}
                    <div className="grid grid-cols-3 gap-2.5">
                      {[
                        { label: t('الطلاب', 'Students'), value: '318', icon: Users, color: '#0d9488' },
                        { label: t('الكورسات', 'Courses'), value: '12', icon: BookOpen, color: '#d97706' },
                        { label: t('الإيرادات', 'Revenue'), value: '$4,250', icon: Wallet, color: '#059669' },
                      ].map((s, i) => (
                        <div key={i} className="rounded-2xl border border-slate-100 bg-slate-50/60 p-2.5">
                          <s.icon className="mb-1.5 h-4 w-4" style={{ color: s.color }} />
                          <p className="text-sm font-black leading-none text-slate-800 sm:text-base">{s.value}</p>
                          <p className="mt-1 text-[10px] text-slate-400">{s.label}</p>
                        </div>
                      ))}
                    </div>

                    {/* رسم الإيرادات */}
                    <div className="rounded-2xl border border-slate-100 p-3">
                      <div className="mb-2 flex items-center justify-between">
                        <p className="text-[11px] font-bold text-slate-700">{t('الإيرادات الشهرية', 'Monthly revenue')}</p>
                        <span className="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-600"><TrendingUp className="h-3 w-3" /> +18%</span>
                      </div>
                      <div className="flex h-16 items-end gap-1.5">
                        {[45, 62, 38, 74, 55, 88, 70].map((h, i) => (
                          <span key={i} className="flex-1 rounded-t-md" style={{ height: `${h}%`, background: i === 5 ? 'linear-gradient(180deg,#fbbf24,#d97706)' : 'linear-gradient(180deg,#5eead4,#0d9488)' }} />
                        ))}
                      </div>
                    </div>

                    {/* صفوف الكورسات */}
                    <div className="space-y-2.5">
                      {[
                        { name: t('كورس الرياضيات', 'Mathematics course'), pct: 82 },
                        { name: t('كورس الفيزياء', 'Physics course'), pct: 64 },
                      ].map((c, i) => (
                        <div key={i} className="flex items-center gap-3">
                          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-teal-600"><Play className="h-3.5 w-3.5" /></span>
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-[11px] font-bold text-slate-700">{c.name}</p>
                            <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                              <span className="block h-full rounded-full" style={{ width: `${c.pct}%`, background: 'linear-gradient(90deg,#0d9488,#5eead4)' }} />
                            </div>
                          </div>
                          <span className="text-[10px] font-bold text-slate-400">{c.pct}%</span>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              </div>

              {/* شريحة عائمة: أرباحك لك بالكامل */}
              <div className="float-y-delay absolute -bottom-4 start-2 z-20 flex items-center gap-2.5 rounded-2xl border border-slate-100 bg-white px-4 py-2.5 shadow-2xl sm:start-6">
                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-emerald-400/20 to-teal-400/20"><TrendingUp className="h-4 w-4 text-emerald-500" /></span>
                <div>
                  <p className="text-xs font-black text-slate-800">{t('أرباحك 100% لك', 'Your earnings, 100% yours')}</p>
                  <p className="text-[10px] text-slate-400">{t('بدون وسطاء', 'no middlemen')}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* شريط الإحصاءات والثقة */}
      <div className="relative z-10 border-b border-teal-900/20 bg-[#0a1715] py-7">
        <div className="mx-auto grid max-w-6xl grid-cols-2 gap-x-4 gap-y-6 px-4 sm:grid-cols-4">
          {proofStats.map((s, i) => (
            <div key={i} className="flex flex-col items-center gap-1.5 text-center">
              <s.icon className="h-5 w-5 text-teal-400" />
              <span className="text-2xl font-black leading-none text-white sm:text-3xl">{s.value}</span>
              <span className="text-xs leading-snug text-white/55">{s.label}</span>
            </div>
          ))}
        </div>
      </div>

      {/* ─── المميزات ───────────────────────────────────── */}
      <section id="features" className="py-20">
        <div className="max-w-7xl mx-auto px-4 sm:px-6">
          <div className="text-center max-w-2xl mx-auto mb-14">
            <h2 className="text-3xl sm:text-4xl font-bold">{t('المميزات', 'Features')}</h2>
            <p className="text-gray-500 mt-3 text-lg">{t('كل ما تحتاجه في منصة واحدة', 'Everything you need in one platform')}</p>
            <p className="text-gray-400 mt-1">{t('صممنا المنصة لتوفر عليك الوقت وتضاعف إيراداتك', 'Built to save you time and double your revenue')}</p>
          </div>
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            {MAIN_FEATURES.map((f, i) => (
              <Card key={i} className="group border-0 shadow-sm card-hover">
                <CardContent className="p-6">
                  <div className="flex h-12 w-12 items-center justify-center rounded-2xl mb-4 wiggle-hover transition-all group-hover:scale-110" style={{ backgroundColor: f.color + '15' }}>
                    <f.icon className="h-6 w-6" style={{ color: f.color }} />
                  </div>
                  <h3 className="text-lg font-bold">{t(f.title[0], f.title[1])}</h3>
                  <p className="text-gray-500 text-sm mt-2 leading-relaxed">{t(f.desc[0], f.desc[1])}</p>
                </CardContent>
              </Card>
            ))}
          </div>

          {/* CTA مكرر بعد المميزات */}
          <div className="mt-12 text-center">
            <p className="text-sm text-gray-400 mb-4">{t('ابدأ خلال دقائق — كل ما تحتاجه في مكان واحد', 'Start in minutes — everything you need in one place')}</p>
            <Link href="/register/teacher">
              <button className="inline-flex items-center gap-2 rounded-full px-8 py-3.5 text-white font-bold shadow-brand transition-all hover:scale-[1.02] cta-shine" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
                {t('ابدأ الآن', 'Start now')} {locale === 'ar' ? '←' : '→'}
              </button>
            </Link>
          </div>
        </div>
      </section>

      {/* ─── رحلة الطالب التفاعلية (عِش تجربة طالبك) ────── */}
      <section id="student" className="py-20">
        <div className="max-w-6xl mx-auto px-4 sm:px-6">
          <div className="text-center max-w-2xl mx-auto mb-12">
            <div className="inline-block px-4 py-1.5 rounded-full text-sm font-medium mb-4" style={{ backgroundColor: '#0d948815', color: '#0d9488' }}>
              <MousePointerClick className="h-4 w-4 inline me-1" />{t('جرّبها بنفسك الآن', 'Try it yourself')}
            </div>
            <h2 className="text-3xl sm:text-4xl font-bold text-teal-950">{t('كيف يرى طلابك المنصة', 'How your students see the platform')}</h2>
            <p className="text-gray-500 text-lg mt-3 leading-relaxed">
              {t('واجهة أنيقة وسهلة: يفتح الطالب الدرس، يحل الاختبار بتصحيح فوري، ويستلم شهادته الموثقة تلقائياً — كل ذلك باسمك أنت. جرّبها كما يراها طلابك:', 'A clean, effortless experience: your students open the lesson, take an instantly-graded quiz, and receive an auto-issued verified certificate — all under your name. Try it exactly as they see it:')}
            </p>
          </div>

          <div className="grid lg:grid-cols-2 gap-10 items-start">
            {/* الجانب الأيسر: الفوائد + تقدم الرحلة */}
            <div className="lg:sticky lg:top-20">
              <div className="space-y-3">
                {[
                  { icon: Play, text: t('مشاهدة دروس فيديو عالية الجودة مع تتبع تقدم لحظي', 'High-quality video lessons with live progress tracking') },
                  { icon: Zap, text: t('اختبارات تفاعلية بتصحيح فوري ونقاط تحفيزية', 'Interactive exams with instant grading & reward points') },
                  { icon: Award, text: t('شهادات موثقة برمز QR تصل تلقائياً عند الإتمام', 'QR-verified certificates issued automatically on completion') },
                ].map((b, i) => (
                  <div key={i} className="flex items-center gap-3 text-teal-900/70">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-500/10"><b.icon className="h-5 w-5 text-teal-600" /></span>
                    <span className="text-sm font-medium">{b.text}</span>
                  </div>
                ))}
              </div>

              {/* مؤشر تقدم الرحلة */}
              <div className="mt-8 rounded-2xl border border-teal-100 bg-gradient-to-b from-teal-50/80 to-emerald-50/50 p-5">
                <div className="flex items-center justify-between mb-4">
                  <p className="font-bold text-sm text-teal-950">{t('تقدم رحلتك', 'Your journey progress')}</p>
                  <span className="text-sm font-black text-teal-700">{journeyDoneCount}/3</span>
                </div>
                <div className="h-2 rounded-full bg-teal-100 overflow-hidden mb-5">
                  <div className="h-full rounded-full bg-gradient-to-r from-teal-500 to-emerald-500 transition-all duration-700" style={{ width: `${(journeyDoneCount / 3) * 100}%` }} />
                </div>
                <div className="space-y-2.5">
                  {JOURNEY_STEPS.map((s, i) => {
                    const done = i === 0 ? lessonDone : i === 1 ? quizDone : certDone;
                    const active = journeyStep === i;
                    return (
                      <button
                        key={i}
                        onClick={() => setJourneyStep(i)}
                        className={`w-full flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm transition-all text-start ${active ? 'bg-white shadow-sm border border-teal-200' : 'border border-transparent hover:bg-white/70'}`}
                      >
                        <span className={`flex h-8 w-8 items-center justify-center rounded-full text-xs font-black shrink-0 transition-colors ${done ? 'bg-emerald-500 text-white' : active ? 'bg-teal-600 text-white' : 'bg-teal-100 text-teal-600'}`}>
                          {done ? <Check className="h-4 w-4" /> : i + 1}
                        </span>
                        <s.icon className={`h-4 w-4 shrink-0 ${done ? 'text-emerald-600' : 'text-teal-600'}`} />
                        <span className={`font-bold ${done ? 'text-emerald-700' : 'text-teal-950'}`}>{s.label}</span>
                        {done && <span className="ms-auto text-[10px] font-bold text-emerald-600">{t('مكتمل', 'Done')}</span>}
                      </button>
                    );
                  })}
                </div>
                {journeyDoneCount === 3 && (
                  <div className="pop-in mt-4 flex items-center gap-2 rounded-xl bg-gradient-to-r from-teal-600 to-emerald-500 text-white px-4 py-3 text-sm font-bold shadow-brand">
                    <PartyPopper className="h-4 w-4" />{t('أكملت رحلتك التعليمية بنجاح!', 'You completed your learning journey!')}
                  </div>
                )}
              </div>
            </div>

            {/* بطاقة الرحلة التفاعلية */}
            <div className="relative rounded-[2rem] border border-teal-100 bg-white shadow-xl p-6 sm:p-8" style={{ boxShadow: '0 24px 60px -24px rgba(13,148,136,0.25)' }}>
              {/* تبويبات الخطوات */}
              <div className="flex gap-2 mb-7">
                {JOURNEY_STEPS.map((s, i) => {
                  const done = i === 0 ? lessonDone : i === 1 ? quizDone : certDone;
                  return (
                    <button
                      key={i}
                      onClick={() => setJourneyStep(i)}
                      className={`flex-1 flex flex-col items-center gap-1.5 rounded-2xl border-2 px-2 py-3 text-[11px] font-bold transition-all duration-300 ${
                        journeyStep === i
                          ? 'border-teal-500 bg-teal-50 text-teal-800 shadow-sm'
                          : done
                            ? 'border-emerald-200 bg-emerald-50/50 text-emerald-700'
                            : 'border-slate-100 bg-slate-50/50 text-slate-400 hover:border-teal-200'
                      }`}
                    >
                      <span className={`flex h-7 w-7 items-center justify-center rounded-full text-[10px] font-black ${done ? 'bg-emerald-500 text-white' : journeyStep === i ? 'bg-teal-600 text-white' : 'bg-white text-slate-400 border border-slate-200'}`}>
                        {done ? <Check className="h-3.5 w-3.5" /> : i + 1}
                      </span>
                      <span className="flex items-center gap-1"><s.icon className="h-3 w-3" />{s.label}</span>
                    </button>
                  );
                })}
              </div>

              {/* الخطوة 1: شاهد الدرس */}
              {journeyStep === 0 && (
                <div className="space-y-4">
                  <div className="relative flex aspect-video items-center justify-center rounded-2xl overflow-hidden cursor-pointer" style={{ background: 'linear-gradient(135deg, #0f2e2b, #101f2e)' }} onClick={() => { if (playback < 100) setPlaying(!playing); }}>
                    <div className="absolute inset-0 opacity-[0.06]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '18px 18px' }} />
                    {playback >= 100 ? (
                      <div className="pop-in flex flex-col items-center gap-2 text-emerald-300">
                        <span className="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-500/15 border border-emerald-400/30"><CheckCircle className="h-8 w-8" /></span>
                        <p className="text-sm font-bold">{t('تمت مشاهدة الدرس', 'Lesson watched')}</p>
                      </div>
                    ) : playing ? (
                      <>
                        <span className="absolute inset-0 bg-black/20" />
                        <span className="pulse-ring relative flex h-16 w-16 items-center justify-center rounded-full bg-white/15 backdrop-blur text-white" aria-hidden="true"><Pause className="h-7 w-7" /></span>
                      </>
                    ) : (
                      <button className="pulse-ring relative flex h-16 w-16 items-center justify-center rounded-full bg-teal-500 text-white shadow-2xl shadow-teal-500/50 transition-transform hover:scale-110" aria-label={t('تشغيل', 'Play')}>
                        <Play className="h-7 w-7 ms-0.5" />
                      </button>
                    )}
                    {playing && (
                      <div className="absolute bottom-4 start-4 flex items-end gap-1 h-8" aria-hidden="true">
                        {[45, 80, 60, 95, 50, 70].map((h, i) => (
                          <span key={i} className="eq-bar w-1.5 rounded-full bg-teal-400" style={{ height: `${h}%`, animationDelay: `${i * 0.12}s` }} />
                        ))}
                      </div>
                    )}
                    <span className="absolute top-3 end-3 rounded-full bg-black/40 backdrop-blur px-2.5 py-1 text-[10px] font-bold text-white">HD</span>
                  </div>

                  <div className="flex items-center justify-between">
                    <div>
                      <p className="font-bold text-sm">{t('درس: أساسيات الجبر — الجزء الأول', 'Lesson: Algebra basics — Part 1')}</p>
                      <p className="text-[10px] text-gray-400">{t('فيديو عالي الجودة · 12:30 دقيقة', 'HD video · 12:30 min')}</p>
                    </div>
                    {playback < 100 && (
                      <button onClick={() => setPlaying(!playing)} className="flex h-10 w-10 items-center justify-center rounded-full bg-teal-500/10 text-teal-700 transition-colors hover:bg-teal-500/20" aria-label={playing ? t('إيقاف', 'Pause') : t('تشغيل', 'Play')}>
                        {playing ? <Pause className="h-4.5 w-4.5" /> : <Play className="h-4.5 w-4.5" />}
                      </button>
                    )}
                  </div>

                  <div>
                    <div className="flex justify-between text-[11px] text-gray-400 mb-1.5">
                      <span>{t('تقدم الدرس', 'Lesson progress')}</span>
                      <span className="font-bold text-teal-700">{Math.floor(playback)}%</span>
                    </div>
                    <div className="h-2.5 rounded-full bg-slate-100 overflow-hidden">
                      <div className={`h-full rounded-full transition-all duration-150 ${playback >= 100 ? 'bg-emerald-500' : 'bg-gradient-to-r from-teal-500 to-emerald-400 bar-striped'}`} style={{ width: `${playback}%` }} />
                    </div>
                  </div>

                  {playback >= 100 ? (
                    <div className="pop-in flex items-center gap-2 rounded-xl bg-emerald-50 border border-emerald-100 px-4 py-3 text-sm font-bold text-emerald-700">
                      <CheckCircle className="h-4 w-4 shrink-0" />{t('اكتمل الدرس! +10 نقاط — انتقل للاختبار', 'Lesson complete! +10 points — move to the quiz')}
                    </div>
                  ) : (
                    <p className="text-xs text-gray-400 flex items-center gap-1.5"><MousePointerClick className="h-3.5 w-3.5" />{t('اضغط على الشاشة للتشغيل والإيقاف', 'Click the screen to play / pause')}</p>
                  )}
                </div>
              )}

              {/* الخطوة 2: حل الاختبار */}
              {journeyStep === 1 && (
                <div>
                  <div className="flex items-center justify-between mb-6">
                    <div className="flex items-center gap-2.5">
                      <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/10"><ClipboardList className="h-5 w-5 text-violet-600" /></span>
                      <div>
                        <p className="font-bold text-sm">{t('اختبار سريع', 'Quick quiz')}</p>
                        <p className="text-[10px] text-gray-400">{t('سؤال 1 من 3', 'Question 1 of 3')}</p>
                      </div>
                    </div>
                    <span className="flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-[11px] font-bold text-amber-600">
                      <Timer className="h-3.5 w-3.5" />00:45
                    </span>
                  </div>

                  <h3 className="text-xl font-bold text-teal-950 mb-5">{QUIZ.question}</h3>

                  <div className="grid grid-cols-2 gap-3">
                    {QUIZ.options.map((opt, i) => {
                      const isPick = quizPick === i;
                      const isCorrect = i === QUIZ.correct;
                      const showState = quizState !== 'idle';
                      return (
                        <button
                          key={i}
                          onClick={() => pickOption(i)}
                          disabled={quizState === 'right'}
                          className={`relative rounded-2xl border-2 px-4 py-4 text-lg font-black transition-all duration-300 ${
                            showState && isCorrect
                              ? 'border-emerald-400 bg-emerald-50 text-emerald-700 scale-[1.03] shadow-lg shadow-emerald-500/20'
                              : showState && isPick && !isCorrect
                                ? 'border-rose-300 bg-rose-50 text-rose-600 shake-x'
                                : 'border-slate-200 bg-white text-slate-700 hover:border-teal-300 hover:bg-teal-50/50 hover:-translate-y-0.5 hover:shadow-md'
                          }`}
                        >
                          {opt}
                          {showState && isCorrect && (
                            <span className="absolute -top-2.5 -end-2.5 flex h-7 w-7 items-center justify-center rounded-full bg-emerald-500 text-white shadow-lg pop-in">
                              <CheckCheck className="h-4 w-4" />
                            </span>
                          )}
                          {showState && isPick && !isCorrect && (
                            <span className="absolute -top-2.5 -end-2.5 flex h-7 w-7 items-center justify-center rounded-full bg-rose-500 text-white shadow-lg pop-in">
                              <X className="h-4 w-4" />
                            </span>
                          )}
                        </button>
                      );
                    })}
                  </div>

                  <div className="mt-5 min-h-[3.5rem]">
                    {quizState === 'wrong' && (
                      <div className="pop-in flex items-center gap-2 rounded-xl bg-rose-50 border border-rose-100 px-4 py-3 text-sm font-semibold text-rose-600">
                        <AlertCircle className="h-4 w-4 shrink-0" />{QUIZ.wrong}
                      </div>
                    )}
                    {quizState === 'right' && (
                      <div className="pop-in relative flex items-center gap-2 rounded-xl bg-emerald-50 border border-emerald-100 px-4 py-3 text-sm font-bold text-emerald-700 overflow-hidden">
                        <Sparkles className="h-4 w-4 shrink-0 text-amber-400" />{QUIZ.right}
                        <span className="absolute -top-1 start-8 h-2 w-2 rounded-full bg-amber-400 sparkle" />
                        <span className="absolute top-1/2 start-16 h-1.5 w-1.5 rounded-full bg-teal-400 sparkle" style={{ animationDelay: '0.1s' }} />
                        <span className="absolute -bottom-1 start-24 h-2 w-2 rounded-full bg-emerald-400 sparkle" style={{ animationDelay: '0.2s' }} />
                        <span className="absolute top-2 end-10 h-1.5 w-1.5 rounded-full bg-amber-300 sparkle" style={{ animationDelay: '0.15s' }} />
                        <span className="absolute bottom-1 end-20 h-2 w-2 rounded-full bg-teal-300 sparkle" style={{ animationDelay: '0.05s' }} />
                      </div>
                    )}
                    {quizState === 'idle' && (
                      <p className="text-xs text-gray-400 flex items-center gap-1.5"><MousePointerClick className="h-3.5 w-3.5" />{t('اضغط على إجابة — التصحيح فوري', 'Click an answer — instant grading')}</p>
                    )}
                    {quizState === 'right' && (
                      <button onClick={() => setJourneyStep(2)} className="inline-flex items-center gap-2 mt-3 rounded-full bg-teal-600 text-white px-5 py-2.5 text-sm font-bold shadow-brand transition-all hover:scale-[1.03]">
                        {t('استلم شهادتك ←', 'Claim your certificate ←')}
                      </button>
                    )}
                  </div>
                </div>
              )}

              {/* الخطوة 3: الشهادة */}
              {journeyStep === 2 && (
                <div>
                  {!certIssued ? (
                    <div className="text-center py-10">
                      <div className="mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-full bg-amber-500/10 border-2 border-dashed border-amber-300 float-y">
                        <Award className="h-10 w-10 text-amber-500" />
                      </div>
                      <h3 className="text-xl font-bold text-teal-950 mb-2">{t('شهادتك بانتظارك', 'Your certificate awaits')}</h3>
                      <p className="text-sm text-gray-400 max-w-xs mx-auto mb-7">
                        {t('أكملت الدرس والاختبار — الآن تصدر شهادتك الموثقة تلقائياً برمز QR', 'You finished the lesson and quiz — your verified certificate is issued automatically with a QR code')}
                      </p>
                      <button
                        onClick={() => setCertIssued(true)}
                        className="inline-flex items-center gap-2 rounded-full px-8 py-3.5 text-white font-bold shadow-lg transition-all hover:scale-[1.03] cta-shine"
                        style={{ background: 'linear-gradient(135deg, #d97706, #f59e0b)' }}
                      >
                        <Award className="h-5 w-5" />{t('إصدار الشهادة', 'Issue certificate')}
                      </button>
                    </div>
                  ) : (
                    <div className="space-y-4">
                      <div className="relative overflow-hidden rounded-2xl p-[2px] cert-shine" style={{ background: 'linear-gradient(135deg, #d97706, #0d9488, #d97706)' }}>
                        <div className="relative overflow-hidden rounded-[calc(1rem-2px)] bg-white p-6 text-center">
                          <div className="absolute inset-0 opacity-[0.05]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #0d9488 1px, transparent 0)', backgroundSize: '16px 16px' }} />
                          <div className="relative">
                            <GraduationCap className="mx-auto h-10 w-10 text-amber-500 mb-2" />
                            <p className="text-[10px] font-black tracking-[0.25em] text-gray-400 uppercase">{t('شهادة إتمام', 'Certificate of completion')}</p>
                            <h4 className="text-2xl font-black text-teal-950 mt-2">{t('بنجاح', 'Successfully')}</h4>
                            <p className="text-xs text-gray-400 mt-3">{t('تشهد هذه الشهادة بأن', 'This certifies that')}</p>
                            <p className="text-2xl font-black text-amber-600 mt-1 min-h-[2.5rem]">{typedName}<span className="type-caret inline-block w-1 h-6 bg-amber-500 align-middle ms-1 rounded" /></p>
                            <p className="text-xs text-gray-400 mt-2">{t('أتم بنجاح دورة: أساسيات الجبر', 'has completed the course: Algebra basics')}</p>
                            <div className="flex items-center justify-between mt-6">
                              <div className="text-start">
                                <p className="text-[10px] text-gray-400">{t('المعلمة', 'Teacher')}</p>
                                <p className="text-sm font-bold text-slate-700">أ. محمد</p>
                              </div>
                              <div className="relative">
                                <div className="flex h-14 w-14 items-center justify-center rounded-full border-4 border-amber-400 bg-amber-50 stamp-in">
                                  <Award className="h-6 w-6 text-amber-500" />
                                </div>
                                <span className="absolute inset-0 rounded-full border-2 border-dashed border-amber-300 stamp-in" style={{ animationDelay: '0.12s' }} />
                              </div>
                              <div className="text-end">
                                <p className="text-[10px] text-gray-400">{t('التاريخ', 'Date')}</p>
                                <p className="text-sm font-bold text-slate-700">{new Date().toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US')}</p>
                              </div>
                            </div>
                            <div className="mx-auto mt-4 flex h-12 w-12 items-center justify-center rounded-lg border-2 border-dashed border-slate-200 bg-slate-50/50">
                              <QrCode className="h-6 w-6 text-slate-300" />
                            </div>
                            <p className="text-[10px] text-gray-400 mt-1.5">{t('موثقة برمز QR — تحقق فوري', 'Verified with QR — instant check')}</p>
                          </div>
                        </div>
                      </div>
                      <div className="flex gap-2.5">
                        <button
                          onClick={() => toast.success(t('تم التنزيل (تجريبي)', 'Downloaded (demo)'))}
                          className="flex-1 inline-flex items-center justify-center gap-2 rounded-full py-3 text-sm font-bold text-white shadow-brand transition-all hover:scale-[1.02]"
                          style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}
                        >
                          <Download className="h-4 w-4" />{t('تحميل PDF', 'Download PDF')}
                        </button>
                        <button
                          onClick={() => toast.success(t('تم نسخ رابط المشاركة (تجريبي)', 'Share link copied (demo)'))}
                          className="flex-1 inline-flex items-center justify-center gap-2 rounded-full border-2 border-teal-200 bg-teal-50 py-3 text-sm font-bold text-teal-700 transition-all hover:bg-teal-100"
                        >
                          <Share2 className="h-4 w-4" />{t('مشاركة', 'Share')}
                        </button>
                      </div>
                      <div className="pop-in flex items-center gap-2 rounded-xl bg-gradient-to-r from-amber-500 to-orange-400 text-white px-4 py-3 text-sm font-bold">
                        <Trophy className="h-4 w-4 shrink-0" />{t('+100 نقطة إتمام! — هذه تجربة طالبك الحقيقية', '+100 completion points! — this is your student\'s real experience')}
                      </div>
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>

        {/* CTA مكرر بعد الرحلة */}
        <div className="mt-10 text-center">
          <p className="text-sm text-gray-400 mb-4">{t('هكذا يتعلم طلابك — هكذا تكسب أنت', 'This is how your students learn — and how you earn')}</p>
          <Link href="/register/teacher">
            <button className="inline-flex items-center gap-2 rounded-full px-8 py-3.5 text-white font-bold shadow-brand transition-all hover:scale-[1.02] cta-shine" style={{ background: 'linear-gradient(135deg, #d97706, #f59e0b)' }}>
              {t('أنشئ منصتك الآن', 'Create your platform now')} {locale === 'ar' ? '←' : '→'}
            </button>
          </Link>
        </div>
      </section>

      {/* ─── حاسبة أرباحك ───────────────────────────────── */}
      <section id="earnings" className="relative py-20 overflow-hidden" style={{ background: 'linear-gradient(180deg, #ffffff, #f0fdfa)' }}>
        <div className="absolute top-10 -start-24 w-72 h-72 rounded-full bg-amber-300/15 blur-3xl" aria-hidden="true" />
        <div className="max-w-6xl mx-auto px-4 sm:px-6">
          <div className="text-center max-w-2xl mx-auto mb-12">
            <div className="inline-block px-4 py-1.5 rounded-full text-sm font-medium mb-4" style={{ backgroundColor: '#d9770615', color: '#d97706' }}>
              <Calculator className="h-4 w-4 inline me-1" />{t('قدّر دخلك من كورساتك', 'Estimate your course income')}
            </div>
            <h2 className="text-3xl sm:text-4xl font-bold text-teal-950">{t('كم يمكن أن تحقّق كورساتك؟', 'How much can your courses earn you?')}</h2>
            <p className="text-teal-900/60 mt-3 text-lg">{t('أنت تحدّد سعر كورسك، وأنت من يربح من طلابك — حرّك المؤشر لتقدير دخلك الشهري. المنصة لا تأخذ أي نسبة من مبيعاتك.', 'You set your course price and you earn from your students — move the slider to estimate your monthly income. The platform takes zero commission on your sales.')}</p>
          </div>

          <div ref={calcRef} className="grid lg:grid-cols-2 gap-10 items-center">
            {/* المدخلات */}
            <div className="rounded-[2rem] border border-teal-100 bg-white p-6 sm:p-8 shadow-xl" style={{ boxShadow: '0 24px 60px -24px rgba(13,148,136,0.22)' }}>
              <div className="space-y-8">
                <div>
                  <div className="flex items-center justify-between mb-3">
                    <label htmlFor="calc-students" className="font-bold text-sm text-teal-950">{t('عدد طلابك', 'Your students')}</label>
                    <span className="text-2xl font-black text-teal-700">{calcStudents.toLocaleString('en-US')}</span>
                  </div>
                  <input
                    id="calc-students"
                    aria-label={t('عدد طلابك', 'Your students')}
                    type="range" min={5} max={500} step={5} value={calcStudents}
                    onChange={(e) => setCalcStudents(Number(e.target.value))}
                    className="w-full h-2.5 rounded-full appearance-none cursor-pointer"
                    style={{ background: 'linear-gradient(90deg, #0d9488, #14b8a6)', accentColor: '#0d9488' }}
                  />
                  <div className="flex justify-between text-[10px] text-gray-400 mt-1.5"><span>5</span><span>250</span><span>500</span></div>
                </div>

                <div>
                  <div className="flex items-center justify-between mb-3">
                    <label htmlFor="calc-price" className="font-bold text-sm text-teal-950">{t('متوسط سعر الكورس', 'Average course price')}</label>
                    <span className="text-2xl font-black text-teal-700">{calcPrice} {currencyLabel(settings.currency, locale === 'ar')}</span>
                  </div>
                  <input
                    id="calc-price"
                    aria-label={t('متوسط سعر الكورس', 'Average course price')}
                    type="range" min={5} max={200} step={5} value={calcPrice}
                    onChange={(e) => setCalcPrice(Number(e.target.value))}
                    className="w-full h-2.5 rounded-full appearance-none cursor-pointer"
                    style={{ background: 'linear-gradient(90deg, #d97706, #f59e0b)', accentColor: '#d97706' }}
                  />
                  <div className="flex justify-between text-[10px] text-gray-400 mt-1.5"><span>5</span><span>100</span><span>200</span></div>
                </div>
              </div>
            </div>

            {/* النتائج */}
            <div className="space-y-4">
              <div className="flex items-center justify-between rounded-2xl border border-teal-100 bg-white p-5">
                <div>
                  <p className="text-sm text-gray-500">{t('دخلك الشهري المتوقع من كورساتك', 'Your expected monthly income from your courses')}</p>
                  <p className="text-3xl font-black text-teal-700 mt-1">{calcActive ? formatPrice(calcRevenue) : formatPrice(0)}</p>
                </div>
                <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10"><Wallet className="h-6 w-6 text-teal-600" /></span>
              </div>

              <div className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-5 opacity-90">
                <div>
                  <p className="text-sm text-gray-400">{t('رسوم المنصات الأخرى', 'Other platforms\' fees')} <span className="line-through">(20-30%)</span></p>
                  <p className="text-3xl font-black text-slate-300 mt-1 line-through">{calcActive ? formatPrice(calcCompetitorFee) : formatPrice(0)}</p>
                </div>
                <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100"><Ban className="h-6 w-6 text-slate-400" /></span>
              </div>

              <div className="relative overflow-hidden rounded-2xl p-[2px]" style={{ background: 'linear-gradient(135deg, #d97706, #0d9488, #d97706)' }}>
                <div className="relative overflow-hidden rounded-[calc(1rem-2px)] p-5" style={{ background: 'linear-gradient(135deg, #0f2e2b, #115e54)' }}>
                  <div className="absolute inset-0 opacity-[0.06]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '20px 20px' }} />
                  <div className="relative flex items-center justify-between">
                    <div>
                      <p className="text-sm text-white/60">{t('دخلك الصافي — يبقى لك بالكامل', 'Your net income — it all stays yours')}</p>
                      <p className="text-4xl font-black text-white mt-1">{calcActive ? formatPrice(calcRevenue) : formatPrice(0)}</p>
                      <p className="text-xs font-bold mt-1" style={{ color: '#fcd34d' }}>{t('تحقّقه من مبيعات كورساتك — دون أي عمولة من المنصة', 'Earned from your course sales — the platform takes no commission')}</p>
                    </div>
                    <div className="core-pulse flex h-16 w-16 items-center justify-center rounded-full text-center" style={{ background: 'radial-gradient(circle at 35% 30%, rgba(251,191,36,0.35), rgba(13,148,136,0.15))', border: '1px solid rgba(251,191,36,0.4)' }}>
                      <div>
                        <div className="text-lg font-black text-white leading-none">0%</div>
                        <div className="text-[9px] font-bold" style={{ color: '#fde68a' }}>{t('عمولة', 'commission')}</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <p className="text-xs text-gray-400 text-center">
                {t('لو كنت على منصة تأخذ 20% عمولة، كنت ستخسر', 'On a platform taking a 20% commission, you would lose')} <b className="text-teal-700">{calcActive ? formatPrice(calcSave) : formatPrice(0)}</b> {t('سنوياً — معنا يبقى لك كل شيء', 'per year — with us everything stays yours')}
              </p>

              <Link href="/register/teacher" className="block">
                <button className="w-full rounded-full py-4 text-white font-bold shadow-brand transition-all hover:scale-[1.01] cta-shine" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
                  {t('أنشئ كورسك وابدأ الربح', 'Create your course & start earning')} {locale === 'ar' ? '←' : '→'}
                </button>
              </Link>
            </div>
          </div>
        </div>
      </section>

      {/* ─── مقارنة القيمة ─────────────────────────────── */}
      <section className="py-20">
        <div className="max-w-6xl mx-auto px-4 sm:px-6">
          <div className="text-center max-w-2xl mx-auto mb-12">
            <div className="inline-block px-4 py-1.5 rounded-full text-sm font-medium mb-4" style={{ backgroundColor: '#0d948815', color: '#0d9488' }}>
              <BarChart3 className="h-4 w-4 inline me-1" />{t('لماذا نحن؟', 'Why us?')}
            </div>
            <h2 className="text-3xl sm:text-4xl font-bold text-teal-950">{t('مع المنصة… أم بدونها؟', 'With the platform… or without?')}</h2>
            <p className="text-teal-900/60 mt-3 text-lg">{t('قارن بنفسك كم سيوفر عليك الوقت ويضاعف إيرادك', 'Compare for yourself how much time it saves and revenue it multiplies')}</p>
          </div>

          <div className="grid md:grid-cols-2 gap-6 max-w-4xl mx-auto">
            {/* بدون منصة */}
            <div className="rounded-[2rem] border-2 border-slate-200 bg-white p-7 opacity-80">
              <div className="flex items-center gap-3 mb-6">
                <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-100"><X className="h-5 w-5 text-slate-400" /></span>
                <div>
                  <h3 className="font-bold text-slate-500">{t('بدون المنصة', 'Without the platform')}</h3>
                  <p className="text-[11px] text-gray-400">{t('الطريقة اليدوية', 'The manual way')}</p>
                </div>
              </div>
              <ul className="space-y-3.5">
                {[
                  t('متابعة تسجيل الطلاب يدوياً بالواتساب', 'Manually follow up student enrollment on WhatsApp'),
                  t('تحصيل المدفوعات يدوياً — تعب ومتابعة', 'Collect payments manually — tiring follow-up'),
                  t('لا موقع إلكتروني يبني علامتك', 'No website to build your brand'),
                  t('لا بطاقات تسجيل ولا تحليلات', 'No registration cards, no analytics'),
                  t('لا شهادات تلقائية لطلابك', 'No automatic certificates for your students'),
                ].map((x, i) => (
                  <li key={i} className="flex items-start gap-2.5 text-sm text-slate-400">
                    <X className="h-4 w-4 shrink-0 mt-0.5 text-red-400" />{x}
                  </li>
                ))}
              </ul>
            </div>

            {/* مع منصة */}
            <div className="relative rounded-[2rem] p-[2px] shadow-2xl" style={{ background: 'linear-gradient(135deg, #0d9488, #d97706, #0d9488)' }}>
              <div className="relative overflow-hidden rounded-[calc(2rem-2px)] p-7" style={{ background: 'linear-gradient(160deg, #0f2e2b, #115e54)' }}>
                <div className="absolute inset-0 opacity-[0.05]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '20px 20px' }} />
                <span className="absolute top-4 end-4 inline-flex items-center gap-1 rounded-full px-3 py-1 text-[10px] font-black text-white" style={{ background: 'linear-gradient(135deg, #d97706, #f59e0b)' }}>
                  <Sparkles className="h-3 w-3" />{t('اختيارك الصحيح', 'The right choice')}
                </span>
                <div className="relative flex items-center gap-3 mb-6">
                  <span className="flex h-11 w-11 items-center justify-center rounded-2xl" style={{ background: 'rgba(94,234,212,0.15)', border: '1px solid rgba(94,234,212,0.3)' }}><CheckCircle className="h-5 w-5 text-teal-300" /></span>
                  <div>
                    <h3 className="font-bold text-white">{t('مع المنصة', 'With the platform')}</h3>
                    <p className="text-[11px] text-white/50">{t('كل شيء تلقائي', 'Everything automated')}</p>
                  </div>
                </div>
                <ul className="relative space-y-3.5">
                  {[
                    t('الطلاب يسجلون بأنفسهم عبر بطاقات QR', 'Students enroll themselves via QR cards'),
                    t('اربط بوابتك وفواتير تلقائية', 'Connect your gateway & automatic invoices'),
                    t('موقعك الخاص بألوانك ودومينك', 'Your own site with your colors & domain'),
                    t('تحليلات أداء وإيرادات لحظية', 'Live performance & revenue analytics'),
                    t('شهادات موثقة تصل تلقائياً', 'Verified certificates issued automatically'),
                    t('أنت تحقّق الدخل — بدون عمولة على مبيعاتك', 'You earn the income — no commission on your sales'),
                  ].map((x, i) => (
                    <li key={i} className="flex items-start gap-2.5 text-sm text-white/85">
                      <CheckCircle className="h-4 w-4 shrink-0 mt-0.5 text-emerald-400" />{x}
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ─── الأسعار ─────────────────────────────────── */}
      <section id="pricing" className="py-20">
        <div className="max-w-7xl mx-auto px-4 sm:px-6">
          <div className="text-center max-w-2xl mx-auto mb-8">
            <div className="inline-block px-4 py-1.5 rounded-full text-sm font-medium mb-4" style={{ backgroundColor: '#0d948815', color: '#0d9488' }}>
              <Rocket className="h-4 w-4 inline me-1" />{t('خطط واضحة، بلا مفاجآت', 'Clear plans, no surprises')}
            </div>
            <h2 className="text-3xl sm:text-4xl font-bold text-teal-950">{t('اختر خطتك وابدأ اليوم', 'Pick your plan and start today')}</h2>
            <p className="text-teal-900/60 mt-3 text-lg">{t('كل ما تربحه من كورساتك يبقى لك — لا نأخذ أي عمولة على مبيعاتك. ابدأ بتجربة مجانية 7 أيام.', 'Everything your courses earn stays yours — we take zero commission on your sales. Start with a 7-day free trial.')}</p>
          </div>

          {/* شارات القيمة */}
          <div className="mb-8 grid grid-cols-2 gap-2 sm:mb-10 sm:flex sm:flex-wrap sm:justify-center sm:gap-2.5">
            {[
              t('بدون عمولة على مبيعاتك', 'No commission on your sales'),
              t('تجربة مجانية 7 أيام', '7-day free trial'),
              t('بدون بطاقة ائتمان', 'No credit card'),
              t('إلغاء في أي وقت', 'Cancel anytime'),
              t('دعم عربي 24/7', '24/7 Arabic support'),
            ].map((x, i) => (
              <span key={i} className={`inline-flex items-center justify-center gap-1.5 rounded-full border border-teal-200 bg-white px-3 py-2 text-center text-xs font-semibold text-teal-800 shadow-sm sm:justify-start sm:px-3.5 ${i === 4 ? 'col-span-2 sm:col-auto' : ''}`}>
                <CheckCircle className="h-3.5 w-3.5 shrink-0 text-teal-600" />{x}
              </span>
            ))}
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

          <div className="max-w-5xl mx-auto mb-4 flex justify-center sm:justify-end">
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
                  <h3 className="text-xl font-bold text-teal-950">{p.name}</h3>
                  <div className="mt-4 flex items-end gap-1">
                    <span className="text-4xl font-black">{formatPrice(price).split(' ')[0]}</span>
                    <span className="text-gray-500 pb-1.5">{currencyLabel(p.currency || 'USD', locale === 'ar')} / {cycle === 'yearly' ? t('سنة', 'year') : t('شهر', 'month')}</span>
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
                          <span className="text-gray-700 cursor-help">
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
                    className={`mt-7 w-full flex justify-center items-center gap-2 px-5 py-3.5 rounded-xl text-sm font-semibold transition-all ${popular ? 'text-white shadow-brand hover:opacity-90' : 'border border-teal-200 text-teal-800 hover:border-teal-400 hover:text-teal-700'}`}
                    style={popular ? { background: 'linear-gradient(135deg, #d97706, #f59e0b)' } : undefined}>
                    {t('ابدأ الآن', 'Start now')} {locale === 'ar' ? '←' : '→'}
                  </Link>
                </CardContent>
              </Card>
              );
            })}
          </div>
          <div className="mx-auto mt-8 flex max-w-2xl flex-wrap items-center justify-center gap-x-7 gap-y-2 text-sm text-gray-500">
            <span className="inline-flex items-center gap-1.5"><ShieldCheck className="h-4 w-4 text-emerald-500" />{t('جميع الخطط تشمل الدعم الفني والتحديثات المستمرة', 'All plans include support & continuous updates')}</span>
            <span className="inline-flex items-center gap-1.5"><BadgeCheck className="h-4 w-4 text-emerald-500" />{t('لا نأخذ أي عمولة على مبيعات كورساتك', 'We take no commission on your course sales')}</span>
          </div>




        </div>
      </section>

      {/* ─── الأسئلة الشائعة ────────────────────────────── */}
      <section id="faq" className="relative py-24 overflow-hidden" style={{ background: 'linear-gradient(180deg, #f0fdfa 0%, #e8f6f3 50%, #f0fdfa 100%)' }}>
        <div className="absolute top-20 -start-24 w-72 h-72 rounded-full bg-teal-300/20 blur-3xl" aria-hidden="true" />
        <div className="absolute bottom-20 -end-24 w-72 h-72 rounded-full bg-amber-300/15 blur-3xl" aria-hidden="true" />
        <div className="max-w-3xl mx-auto px-4 sm:px-6 relative">
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
                  className={`group relative overflow-hidden rounded-2xl border transition-all duration-300 ${
                    open
                      ? 'border-teal-300 bg-white shadow-xl shadow-teal-500/10'
                      : 'border-teal-100/80 bg-white/70 hover:border-teal-200 hover:shadow-md'
                  }`}
                >
                  <button
                    onClick={() => setOpenFaq(open ? null : i)}
                    className="w-full flex items-center gap-4 px-5 sm:px-6 py-4.5 sm:py-5 text-start"
                    aria-expanded={open}
                  >
                    <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-sm font-black transition-all duration-300 ${open ? 'bg-gradient-to-br from-teal-600 to-emerald-500 text-white shadow-brand rotate-3' : 'bg-teal-50 text-teal-600 group-hover:bg-teal-100/70'}`}>
                      {String(i + 1).padStart(2, '0')}
                    </span>
                    <span className={`flex-1 font-bold text-[15px] leading-relaxed transition-colors ${open ? 'text-teal-950' : 'text-teal-900/80'}`}>
                      {t(f[0], f[0])}
                    </span>
                    <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full transition-all duration-300 ${open ? 'rotate-180 bg-gradient-to-br from-teal-600 to-emerald-500 text-white shadow-brand' : 'bg-teal-50 text-teal-500 group-hover:bg-teal-100/70'}`}>
                      <ChevronDown className="h-4 w-4" />
                    </span>
                  </button>
                  <div className={`grid transition-all duration-300 ease-in-out ${open ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}>
                    <div className="overflow-hidden">
                      <div className="px-5 sm:px-6 pb-5 sm:pb-6 ps-[4.6rem] text-teal-900/60 text-sm leading-loose border-t border-teal-50 pt-4">
                        <span className="inline-block w-8 h-px bg-gradient-to-r from-teal-400 to-transparent align-middle me-2" />
                        {t(f[1], f[1])}
                      </div>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>

          {/* بطاقة تواصل */}
          <div className="mt-10 flex flex-wrap items-center justify-center gap-4 rounded-3xl border border-teal-100 bg-white/80 backdrop-blur px-6 py-5 shadow-sm">
            <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-500/15 to-emerald-500/15"><MessageCircle className="h-5 w-5 text-teal-600" /></span>
            <p className="text-sm text-teal-900/70">
              {t('لم تجد إجابتك؟', 'Didn\'t find your answer?')}{' '}
              <a href="mailto:support@fahimni.me" className="font-bold text-teal-700 underline underline-offset-4 hover:text-teal-600 transition-colors">
                {t('تواصل مع الدعم الفني', 'Contact support')}
              </a>
            </p>
          </div>

          {/* نموذج نرجع عليك — بدون التزام */}
          <div className="mt-6 overflow-hidden rounded-3xl border border-teal-100 bg-white shadow-lg">
            <div className="grid md:grid-cols-2">
              <div className="relative p-7 sm:p-8" style={{ background: 'linear-gradient(135deg, #0f2e2b, #115e54 60%, #0d9488)' }}>
                <div className="absolute inset-0 opacity-[0.06]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '20px 20px' }} />
                <div className="relative">
                  <span className="flex h-12 w-12 items-center justify-center rounded-2xl glass mb-4"><Phone className="h-6 w-6 text-teal-200" /></span>
                  <h3 className="text-xl font-bold text-white">{t('محتار؟ نرجع عليك', 'Unsure? We\'ll call you')}</h3>
                  <p className="text-white/60 text-sm mt-2 leading-relaxed">
                    {t('اترك اسمك ورقم واتساب — أحد خبرائنا يشرح لك كل شيء خلال ساعة. بدون أي التزام.', 'Leave your name and WhatsApp number — one of our experts explains everything within an hour. No commitment.')}
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
                    <label htmlFor="callback-name" className="text-xs font-bold text-gray-500">{t('اسمك', 'Your name')}</label>
                    <Input id="callback-name" value={callbackName} onChange={(e) => setCallbackName(e.target.value)} placeholder={t('مثال: أحمد محمد', 'e.g. Ahmed Mohammed')} className="h-12 mt-1.5 rounded-xl border-teal-100" />
                  </div>
                  <div>
                    <label htmlFor="callback-phone" className="text-xs font-bold text-gray-500">{t('رقم واتساب', 'WhatsApp number')}</label>
                    <Input id="callback-phone" value={callbackPhone} onChange={(e) => setCallbackPhone(e.target.value)} placeholder="+962 7X XXX XXXX" dir="ltr" className="h-12 mt-1.5 rounded-xl border-teal-100 text-end" />
                  </div>
                  <button type="submit" className="w-full rounded-full py-3.5 text-white font-bold shadow-brand transition-all hover:scale-[1.01] cta-shine" style={{ background: 'linear-gradient(135deg, #25d366, #128c7e)' }}>
                    <WhatsAppIcon className="h-4 w-4 inline me-1.5" />{t('يتواصل معي خبير', 'An expert will contact me')}
                  </button>
                  <p className="text-center text-[11px] text-gray-400">{t('بياناتك لا تُشارك مع أي طرف ثالث', 'Your data is never shared with third parties')}</p>
                </form>
              </div>
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
              <img src={resolveLogoUrl(settings.logo_url)} alt="logo" className="h-9 w-9 rounded-xl object-cover ring-1 ring-white/20" onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }} />
              <span className="text-lg font-bold text-white">{settings.platform_name || t('منصة فهمني', 'Fahimni')}</span>
            </div>
            <p className="text-white/50 text-sm leading-relaxed">{t('منصة تعليمية متكاملة تساعد المعلمين على بناء علامتهم التجارية وزيادة إيراداتهم.', 'An all-in-one platform helping teachers build their brand and grow revenue.')}</p>
          </div>
          <div>
            <h4 className="text-white font-semibold mb-4">{t('المنصة', 'Platform')}</h4>
            <ul className="space-y-2.5 text-sm">
              <li><button onClick={() => scrollTo('features')} className="text-white/50 hover:text-white transition-colors">{t('المميزات', 'Features')}</button></li>
              <li><button onClick={() => scrollTo('pricing')} className="text-white/50 hover:text-white transition-colors">{t('الأسعار', 'Pricing')}</button></li>
            </ul>
          </div>
          <div>
            <h4 className="text-white font-semibold mb-4">{t('حسابك', 'Your Account')}</h4>
            <ul className="space-y-2.5 text-sm">
              <li><Link href="/register/teacher" className="text-white/50 hover:text-white transition-colors">{t('إنشاء حساب معلم', 'Create Teacher Account')}</Link></li>
              <li><Link href="/teacher/login" className="text-white/50 hover:text-white transition-colors">{t('دخول المعلمين', 'Teacher Login')}</Link></li>
            </ul>
          </div>
          <div>
            <h4 className="text-white font-semibold mb-4">{t('تواصل معنا', 'Contact Us')}</h4>
            <ul className="space-y-2.5 text-sm text-white/50">
              <li className="flex items-center gap-2"><ShieldCheck className="h-4 w-4 text-emerald-400" />{t('دعم فني 24/7', '24/7 Support')}</li>
              <li className="flex items-center gap-2"><Clock className="h-4 w-4 text-teal-300" />{t('رد خلال ساعة', 'Reply within an hour')}</li>
              <li className="flex items-center gap-2"><span className="text-teal-300">@</span>support@fahimni.me</li>
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

      {/* ─── زر واتساب عائم ─────────────────────────── */}
      <a
        href={`https://wa.me/${SUPPORT_WHATSAPP}?text=${encodeURIComponent(t('مرحباً، لدي سؤال حول منصة التعليم', 'Hello, I have a question about the platform'))}`}
        target="_blank"
        rel="noreferrer"
        className="fixed bottom-24 left-5 z-50 flex items-center justify-center rounded-full bg-gradient-to-br from-emerald-500 to-green-600 text-white shadow-2xl shadow-emerald-500/40 transition-transform hover:scale-110"
        style={{ width: '52px', height: '52px' }}
        aria-label="WhatsApp"
      >
        <WhatsAppIcon className="h-7 w-7" />
      </a>

      {/* ─── شريط CTA سفلي ثابت للجوال ──────────────── */}
      <div className={`md:hidden fixed bottom-0 inset-x-0 z-50 border-t border-gray-100 bg-white/95 backdrop-blur-xl px-4 py-3 flex items-center gap-2.5 shadow-[0_-8px_24px_rgba(15,23,42,0.08)] transition-transform duration-300 ${scrolledPast ? 'translate-y-0' : 'translate-y-full'}`}>
        <Link href="/register/teacher" className="flex-1">
          <button className="w-full h-12 rounded-full text-white text-sm font-bold shadow-lg cta-shine" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
            {t('ابدأ الآن', 'Start now')}
          </button>
        </Link>
        <button onClick={() => scrollTo('pricing')} className="shrink-0 h-12 rounded-full px-4 text-sm font-bold" style={{ color: '#0d9488', border: '1.5px solid #99f6e4', background: '#f0fdfa' }}>
          {t('الأسعار', 'Pricing')}
        </button>
      </div>

    </div>
  );
}
