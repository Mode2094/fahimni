'use client';

import { useEffect, useRef, useState } from 'react';
import { use } from 'react';
import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
  GraduationCap, BookOpen, Users, Clock, Star, Sparkles, CheckCircle, ArrowLeft,
  ChevronLeft, ChevronRight, ChevronDown, CreditCard, LayoutDashboard, Loader2, Quote, MessageCircle, Phone, Mail, Menu, X,
  ShieldCheck, Zap, Headphones, Lock, CheckCheck, TrendingUp, Crown,
} from 'lucide-react';
import { toast } from 'sonner';
import Link from 'next/link';
import { TEMPLATES, FONTS, type TemplateId } from '@/components/site/templates';
import { BlocksRenderer } from '@/components/site/blocks-renderer';
import InstallButton from '@/components/teacher/install-button';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const DEFAULT_SECTIONS = ['stats', 'about', 'courses', 'card', 'testimonials', 'faq', 'cta'];

/* ── عدّاد تصاعدي للأرقام (يعزز الإحصائيات) ───────────── */
function useCountUp(target: number, active: boolean, duration = 1400) {
  const [val, setVal] = useState(0);
  useEffect(() => {
    if (!active) return;
    let raf: number;
    const start = performance.now();
    const tick = (now: number) => {
      const p = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - p, 3);
      setVal(Math.round(target * eased));
      if (p < 1) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, [active, target, duration]);
  return val;
}

function parseStat(raw: any): { prefix: string; num: number; suffix: string } {
  const str = String(raw ?? '');
  const m = str.match(/^([^\d]*)([\d.,]+)(.*)$/);
  if (!m) return { prefix: '', num: 0, suffix: '' };
  return { prefix: m[1], num: parseFloat(m[2].replace(/,/g, '')), suffix: m[3] };
}

/* عنصر إحصائية بعدّاد تصاعدي */
function StatItem({ raw, label, active, color, fontHeading, locale }: {
  raw: any; label: string; active: boolean; color: string; fontHeading: string; locale: string;
}) {
  const { prefix, num, suffix } = parseStat(raw);
  const val = useCountUp(num, active);
  return (
    <div className="text-center">
      <div className="text-3xl sm:text-4xl font-black" style={{ color, fontFamily: fontHeading }}>
        {prefix}{val.toLocaleString('en-US')}{suffix}
      </div>
      <div className="text-gray-500 text-sm mt-1.5">{label}</div>
    </div>
  );
}

export default function TeacherLanding({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = use(params);
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [site, setSite] = useState<any>(null);
  const [courses, setCourses] = useState<any[]>([]);
  const [slideIdx, setSlideIdx] = useState(0);
  const [loggedIn, setLoggedIn] = useState(false);
  const [cardCode, setCardCode] = useState('');
  const [redeeming, setRedeeming] = useState(false);
  const [redeemedMsg, setRedeemedMsg] = useState('');
  const [openFaq, setOpenFaq] = useState<number | null>(0);
  const [menuOpen, setMenuOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const [progress, setProgress] = useState(0);
  const [statsInView, setStatsInView] = useState(false);
  const timerRef = useRef<any>(null);
  const statsRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    const onScroll = () => {
      setScrolled(window.scrollY > 10);
      const h = document.documentElement;
      setProgress(h.scrollTop / Math.max(1, h.scrollHeight - h.clientHeight) * 100);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  /* كشف الأقسام عند التمرير */
  useEffect(() => {
    if (!site) return;
    const els = document.querySelectorAll('.reveal');
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting) { e.target.classList.add('revealed'); io.unobserve(e.target); }
      });
    }, { threshold: 0.1 });
    els.forEach((el) => io.observe(el));
    return () => io.disconnect();
  }, [site]);

  /* تفعيل العدّاد عند ظهور الإحصائيات */
  useEffect(() => {
    if (!statsRef.current) return;
    const io = new IntersectionObserver((entries) => {
      if (entries[0].isIntersecting) { setStatsInView(true); io.disconnect(); }
    }, { threshold: 0.3 });
    io.observe(statsRef.current);
    return () => io.disconnect();
  }, [site]);

  useEffect(() => {
    setLoggedIn(!!localStorage.getItem('lms_authed'));
    Promise.all([
      fetch(`${API}/public/${slug}`).then(r => r.json()).then(d => setSite(d.site || null)).catch(() => {}),
      fetch(`${API}/public/${slug}/courses`).then(r => r.json()).then(d => setCourses(d.courses || [])).catch(() => {}),
    ]);
  }, [slug]);

  const cfg = site?.site_config || {};
  const tpl = TEMPLATES[(cfg.template as TemplateId) || 'modern-dark'] || TEMPLATES['modern-dark'];
  const font = FONTS.find((f) => f.id === (cfg.font || 'tajawal')) || FONTS[0];
  const slides = cfg.hero_slides || [{ title: site?.name || slug, subtitle: '', button_text: 'ابدأ الآن' }];
  const primary = site?.primary_color || tpl.primary;
  const dark = site?.secondary_color || tpl.secondary;
  const accent = site?.accent_color || tpl.accent;
  const sectionOrder = (cfg.sections || DEFAULT_SECTIONS).filter((s: string) => DEFAULT_SECTIONS.includes(s));
  const isDarkHero = tpl.hero === 'slider-dark' || tpl.hero === 'luxury-dark';

  useEffect(() => {
    if (slides.length <= 1) return;
    timerRef.current = setInterval(() => setSlideIdx((i) => (i + 1) % slides.length), 5000);
    return () => clearInterval(timerRef.current);
  }, [slides.length]);

  const redeem = async () => {
    if (!cardCode.trim()) { toast.error(t('أدخل رمز البطاقة', 'Enter card code')); return; }
    setRedeeming(true);
    setRedeemedMsg('');
    try {
      const token = localStorage.getItem('lms_authed');
      let tid = localStorage.getItem('tenant_id');
      const tRes = await fetch(`${API}/tenants`, { headers: { Accept: 'application/json' } });
      if (tRes.ok) {
        const td = await tRes.json();
        if (td.tenants?.[0]?.id) { tid = String(td.tenants[0].id); localStorage.setItem('tenant_id', tid as string); }
      }
      const res = await fetch(`${API}/cards/redeem`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', ...(tid ? { 'X-Tenant-Id': tid } : {}), 'X-Device-Id': localStorage.getItem('lms_device_id') || '' },
        body: JSON.stringify({ code: cardCode }),
      });
      const d = await res.json();
      if (!res.ok) { toast.error(d.message || t('فشل التفعيل', 'Activation failed')); return; }
      setRedeemedMsg(d.message || t('تم تفعيل البطاقة — أنت الآن مسجل في الكورس!', 'Card activated — you are now enrolled!'));
      setCardCode('');
      toast.success(t('تم التفعيل بنجاح', 'Activated successfully'));
    } catch {
      toast.error(t('تعذر الاتصال', 'Cannot connect'));
    }
    setRedeeming(false);
  };

  if (!site) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="flex h-14 w-14 items-center justify-center rounded-2xl shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}><GraduationCap className="h-7 w-7 text-white" /></div>
      </div>
    );
  }

  const slide = slides[slideIdx] || slides[0];

  /* شريط الثقة تحت أزرار CTA — يرفع التحويل */
  const TrustRow = ({ light = true }: { light?: boolean }) => (
    <div className={`mt-7 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-xs ${light ? 'text-white/60' : 'text-gray-400'}`}>
      <span className="inline-flex items-center gap-1.5"><ShieldCheck className="h-3.5 w-3.5" style={{ color: light ? accent : primary }} />{t('دفع آمن', 'Secure payment')}</span>
      <span className="inline-flex items-center gap-1.5"><Zap className="h-3.5 w-3.5" style={{ color: light ? accent : primary }} />{t('تفعيل فوري', 'Instant activation')}</span>
      <span className="inline-flex items-center gap-1.5"><Headphones className="h-3.5 w-3.5" style={{ color: light ? accent : primary }} />{t('دعم 24/7', '24/7 support')}</span>
    </div>
  );

  /* ─── Hero variants ─────────────────────────────── */
  const HeroSliderDark = (
    <section className="relative overflow-hidden pt-16" style={{ background: `linear-gradient(135deg, ${dark} 0%, #101f2e 55%, ${dark} 100%)` }}>
      <div className="absolute inset-0 opacity-[0.05]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '30px 30px' }} />
      {slide.image && <img src={slide.image} className="absolute inset-0 w-full h-full object-cover opacity-15" alt="" />}
      <div className="absolute inset-0" style={{ background: `radial-gradient(800px 400px at 70% 20%, ${primary}33, transparent)` }} />
      <div className="relative max-w-7xl mx-auto px-4 py-24 sm:py-32 text-center animate-in">
        <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/10 border border-white/10 text-white/80 text-sm mb-6"><Sparkles className="h-4 w-4" style={{ color: accent }} />{cfg.badge_text || t('منصة تعليمية', 'Educational Platform')}</div>
        <h1 className="text-4xl sm:text-6xl font-bold text-white leading-tight mb-5 max-w-3xl mx-auto" style={{ fontFamily: font.heading }}>{slide.title || site.name}</h1>
        {slide.subtitle && <p className="text-lg text-white/70 max-w-2xl mx-auto mb-8" style={{ fontFamily: font.body }}>{slide.subtitle}</p>}
        <div className="flex flex-wrap items-center justify-center gap-3">
          <a href={loggedIn ? '#card' : `/t/${slug}/register`}>
            <Button size="lg" className="h-14 px-10 rounded-full text-white text-base shadow-2xl hover:scale-105 transition-transform" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: tpl.radius }}>
              {slide.button_text || t('ابدأ الآن', 'Start now')} <ArrowLeft className="h-5 w-5" />
            </Button>
          </a>
          <a href="#courses">
            <Button size="lg" variant="outline" className="h-14 px-8 rounded-full text-base border-2 border-white/30 bg-white/10 text-white backdrop-blur-sm hover:bg-white/20" style={{ borderRadius: tpl.radius }}>{t('تصفح الكورسات', 'Browse courses')}</Button>
          </a>
        </div>
        {!loggedIn && (
          <p className="mt-5 text-sm text-white/60">
            {t('لديك حساب؟', 'Have an account?')}{' '}
            <Link href={`/student/login?tenant_slug=${slug}`} className="font-bold underline underline-offset-4 hover:opacity-80 transition-opacity" style={{ color: accent }}>{t('تسجيل دخول', 'Login')}</Link>
          </p>
        )}
        <TrustRow />
        {slides.length > 1 && (
          <div className="mt-10 flex items-center justify-center gap-3">
            <button onClick={() => setSlideIdx((slideIdx - 1 + slides.length) % slides.length)} className="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 transition-colors"><ChevronRight className="h-4 w-4" /></button>
            <div className="flex gap-2">{slides.map((_: any, i: number) => <button key={i} onClick={() => setSlideIdx(i)} className={`flex items-center justify-center p-1 rounded-full transition-all ${i === slideIdx ? 'w-8' : 'w-4'}`} aria-label={`Slide ${i + 1}`}><span className={`block rounded-full transition-all ${i === slideIdx ? 'h-2 w-full' : 'h-2 w-2 bg-white/30'}`} style={i === slideIdx ? { background: accent } : undefined} /></button>)}</div>
            <button onClick={() => setSlideIdx((slideIdx + 1) % slides.length)} className="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 transition-colors"><ChevronLeft className="h-4 w-4" /></button>
          </div>
        )}
      </div>
    </section>
  );

  const HeroGradientLight = (
    <section className="relative overflow-hidden pt-16" style={{ background: `linear-gradient(135deg, ${primary}12 0%, #ffffff 50%, ${accent}14 100%)` }}>
      <div className="absolute inset-0 opacity-[0.04]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #0d9488 1px, transparent 0)', backgroundSize: '28px 28px' }} />
      <div className="absolute -top-24 -right-24 w-96 h-96 rounded-full blur-3xl opacity-30" style={{ background: primary }} />
      <div className="absolute -bottom-24 -left-24 w-96 h-96 rounded-full blur-3xl opacity-25" style={{ background: accent }} />
      <div className="relative max-w-7xl mx-auto px-4 py-24 sm:py-32">
        <div className="grid lg:grid-cols-2 gap-12 items-center">
          <div className="animate-in">
            <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full mb-6 text-sm font-medium" style={{ backgroundColor: `${primary}15`, color: primary }}><Sparkles className="h-4 w-4" />{cfg.badge_text || t('منصة تعليمية', 'Educational Platform')}</div>
            <h1 className="text-4xl sm:text-5xl font-bold leading-tight mb-5" style={{ fontFamily: font.heading, color: dark }}>{slide.title || site.name}</h1>
            {slide.subtitle && <p className="text-lg text-gray-500 max-w-xl mb-8" style={{ fontFamily: font.body }}>{slide.subtitle}</p>}
            <div className="flex flex-wrap gap-3">
              <a href={loggedIn ? '#card' : `/t/${slug}/register`}><Button size="lg" className="h-14 px-10 rounded-full text-white text-base shadow-xl hover:scale-105 transition-transform cta-shine" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: tpl.radius }}>{slide.button_text || t('ابدأ الآن', 'Start now')} <ArrowLeft className="h-5 w-5" /></Button></a>
              {loggedIn && <Link href="/my-learning"><Button size="lg" variant="outline" className="h-14 px-8 rounded-full text-base" style={{ borderRadius: tpl.radius }}><LayoutDashboard className="h-5 w-5" /> {t('لوحة التحكم', 'Dashboard')}</Button></Link>}
            </div>
            <TrustRow light={false} />
            {slides.length > 1 && (
              <div className="flex items-center gap-2 mt-8">
            {slides.map((_: any, i: number) => <button key={i} onClick={() => setSlideIdx(i)} className={`flex items-center justify-center p-1 rounded-full transition-all ${i === slideIdx ? 'w-8' : 'w-4'}`} aria-label={`Slide ${i + 1}`}><span className={`block h-2 rounded-full transition-all ${i === slideIdx ? 'w-full' : 'w-2 bg-gray-300'}`} style={i === slideIdx ? { background: primary } : undefined} /></button>)}
              </div>
            )}
          </div>
          <div className="relative">
            <div className="relative rounded-[2rem] shadow-2xl overflow-hidden aspect-[4/3]" style={{ background: `linear-gradient(135deg, ${dark}, #1e293b)` }}>
              {slide.image ? <img src={slide.image} className="absolute inset-0 w-full h-full object-cover opacity-40" alt="" /> : (
                <div className="absolute inset-0 flex items-center justify-center" style={{ background: `radial-gradient(400px 250px at 50% 30%, ${primary}40, transparent)` }}>
                  <BookOpen className="h-24 w-24" style={{ color: `${primary}88` }} />
                </div>
              )}
              <div className="absolute bottom-4 start-4 end-4 grid grid-cols-3 gap-2">
                {(cfg.stats || []).slice(0, 3).map((s: any, i: number) => (
                  <div key={i} className="bg-white/10 backdrop-blur rounded-xl p-3 text-center">
                    <p className="font-bold text-white" style={{ fontFamily: font.heading }}>{s.value}</p><p className="text-[10px] text-white/60">{s.label}</p>
                  </div>
                ))}
              </div>
            </div>
            <div className="absolute -top-4 -end-4 float-y flex items-center gap-1.5 px-4 py-2 rounded-full bg-white shadow-xl text-sm font-bold" style={{ color: primary }}><Star className="h-4 w-4 fill-yellow-400 text-yellow-400" />4.9 {t('تقييم', 'rating')}</div>
            <div className="absolute -bottom-5 -start-4 float-y-delay flex items-center gap-1.5 px-4 py-2 rounded-full bg-white shadow-xl text-sm font-bold" style={{ color: primary }}><Users className="h-4 w-4" style={{ color: accent }} />{t('طلاب سعداء', 'happy students')}</div>
          </div>
        </div>
      </div>
    </section>
  );

  const HeroLuxury = (
    <section className="relative overflow-hidden pt-16" style={{ background: `linear-gradient(160deg, ${dark} 0%, #14100a 60%, ${dark} 100%)` }}>
      <div className="absolute inset-0 opacity-[0.06]" style={{ backgroundImage: 'radial-gradient(#fff 1px, transparent 1px)', backgroundSize: '24px 24px' }} />
      <div className="absolute top-1/4 left-1/2 -translate-x-1/2 w-[700px] h-[300px] rounded-full blur-3xl opacity-20" style={{ background: primary }} />
      <div className="relative max-w-7xl mx-auto px-4 py-24 sm:py-32 text-center animate-in">
        <div className="mx-auto mb-8 flex items-center justify-center gap-3">
          <span className="h-px w-10" style={{ background: `${accent}66` }} />
          <div className="flex h-9 w-9 items-center justify-center rounded-full border" style={{ borderColor: `${accent}55`, color: accent }}><Sparkles className="h-4 w-4" /></div>
          <span className="h-px w-10" style={{ background: `${accent}66` }} />
        </div>
        <p className="tracking-[0.3em] uppercase text-xs mb-5" style={{ color: `${accent}cc`, fontFamily: font.body }}>{cfg.badge_text || t('منصة تعليمية', 'Educational Platform')}</p>
        <h1 className="text-4xl sm:text-6xl font-bold leading-tight mb-6" style={{ fontFamily: font.heading, background: `linear-gradient(135deg, ${accent}, ${primary}, ${accent})`, WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}>{slide.title || site.name}</h1>
        {slide.subtitle && <p className="text-lg max-w-2xl mx-auto mb-9" style={{ color: 'rgba(255,255,255,0.65)', fontFamily: font.body }}>{slide.subtitle}</p>}
        <a href={loggedIn ? '#card' : `/t/${slug}/register`}><Button size="lg" className="h-14 px-12 rounded-full text-base font-bold shadow-2xl hover:scale-105 transition-transform cta-shine" style={{ background: `linear-gradient(135deg, ${accent}, ${primary})`, color: dark, borderRadius: tpl.radius }}>{slide.button_text || t('ابدأ الآن', 'Start now')}</Button></a>
        <TrustRow />
        {slides.length > 1 && (
          <div className="mt-10 flex items-center justify-center gap-3">
            <button onClick={() => setSlideIdx((slideIdx - 1 + slides.length) % slides.length)} className="flex h-11 w-11 items-center justify-center rounded-full border transition-colors" style={{ borderColor: `${accent}44`, color: accent }}><ChevronRight className="h-4 w-4" /></button>
            <div className="flex gap-2">{slides.map((_: any, i: number) => <button key={i} onClick={() => setSlideIdx(i)} className={`flex items-center justify-center p-1 rounded-full transition-all ${i === slideIdx ? 'w-8' : 'w-4'}`} aria-label={`Slide ${i + 1}`}><span className={`block rounded-full transition-all ${i === slideIdx ? 'h-1.5 w-full' : 'h-1.5 w-1.5'}`} style={{ background: i === slideIdx ? accent : `${accent}33` }} /></button>)}</div>
            <button onClick={() => setSlideIdx((slideIdx + 1) % slides.length)} className="flex h-11 w-11 items-center justify-center rounded-full border transition-colors" style={{ borderColor: `${accent}44`, color: accent }}><ChevronLeft className="h-4 w-4" /></button>
          </div>
        )}
      </div>
    </section>
  );

  const HeroCleanLight = (
    <section className="relative overflow-hidden pt-16 bg-white">
      <div className="absolute inset-0 opacity-[0.05]" style={{ backgroundImage: 'linear-gradient(#64748b 1px, transparent 1px), linear-gradient(90deg, #64748b 1px, transparent 1px)', backgroundSize: '48px 48px' }} />
      <div className="relative max-w-4xl mx-auto px-4 py-24 sm:py-32 text-center animate-in">
        <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full border mb-6 text-sm font-medium" style={{ borderColor: `${primary}33`, color: primary, fontFamily: font.body }}>{cfg.badge_text || t('منصة تعليمية', 'Educational Platform')}</div>
        <h1 className="text-4xl sm:text-6xl font-bold leading-tight mb-5" style={{ fontFamily: font.heading, color: dark }}>{slide.title || site.name}</h1>
        {slide.subtitle && <p className="text-lg text-gray-500 max-w-2xl mx-auto mb-9">{slide.subtitle}</p>}
        <div className="flex flex-wrap justify-center gap-3">
          <a href={loggedIn ? '#card' : `/t/${slug}/register`}><Button size="lg" className="h-14 px-10 rounded-full text-white text-base shadow-lg cta-shine" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: tpl.radius }}>{slide.button_text || t('ابدأ الآن', 'Start now')}</Button></a>
          {loggedIn && <Link href="/my-learning"><Button size="lg" variant="outline" className="h-14 px-8 rounded-full text-base" style={{ borderRadius: tpl.radius }}><LayoutDashboard className="h-5 w-5" /> {t('لوحة التحكم', 'Dashboard')}</Button></Link>}
        </div>
        <TrustRow light={false} />
        <div className="mt-12 grid grid-cols-2 sm:grid-cols-4 gap-4 max-w-2xl mx-auto">
          {(cfg.stats || []).slice(0, 4).map((s: any, i: number) => (
            <div key={i} className="p-4 rounded-2xl border border-gray-100 shadow-sm" style={{ borderRadius: tpl.radius }}>
              <p className="text-2xl font-bold" style={{ color: primary, fontFamily: font.heading }}>{s.value}</p>
              <p className="text-xs text-gray-400 mt-0.5">{s.label}</p>
            </div>
          ))}
        </div>
        {slides.length > 1 && (
          <div className="flex items-center justify-center gap-2 mt-10">
            {slides.map((_: any, i: number) => <button key={i} onClick={() => setSlideIdx(i)} className={`h-2 rounded-full transition-all ${i === slideIdx ? 'w-8' : 'w-2 bg-gray-300'}`} style={i === slideIdx ? { background: primary } : undefined} />)}
          </div>
        )}
      </div>
    </section>
  );

  const hero = tpl.hero === 'gradient-light' ? HeroGradientLight : tpl.hero === 'luxury-dark' ? HeroLuxury : tpl.hero === 'clean-light' ? HeroCleanLight : HeroSliderDark;

  /* ─── Sections ─────────────────────────────────── */
  const StatsBar = (
    <section className="relative overflow-hidden border-b border-gray-100 bg-white">
      <div className="absolute inset-x-0 top-0 h-1" style={{ background: `linear-gradient(90deg, ${primary}, ${accent})` }} />
      <div className="max-w-5xl mx-auto px-4 py-12" ref={statsRef}>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-8">
          {(cfg.stats || []).slice(0, 4).map((s: any, i: number) => (
            <StatItem key={i} raw={s.value} label={s.label} active={statsInView} color={primary} fontHeading={font.heading} locale={locale} />
          ))}
        </div>
      </div>
    </section>
  );

  const bestSeller = courses.length > 1
    ? courses.reduce((best, c) => ((c.enrollments_count ?? 0) > (best.enrollments_count ?? 0) ? c : best), courses[0])
    : null;

  const Courses = (
    <section id="courses" className="py-20" style={{ background: tpl.hero === 'gradient-light' ? '#ffffff' : '#f8fafc' }}>
      <div className="max-w-7xl mx-auto px-4">
        <div className="text-center mb-12 reveal">
          <div className="inline-block px-4 py-1.5 rounded-full text-sm font-medium mb-4" style={{ backgroundColor: `${primary}15`, color: primary }}>{t('كورساتنا', 'Our courses')}</div>
          <h2 className="text-3xl sm:text-4xl font-bold" style={{ fontFamily: font.heading }}>{t('اختر كورسك وابدأ', 'Choose your course and start')}</h2>
          <p className="text-gray-500 mt-3 max-w-xl mx-auto">{t('محتوى عملي بشرح مبسط — تقدمك محفوظ تلقائياً وشهادتك بانتظارك', 'Practical content with simple explanations — progress saved automatically')}</p>
        </div>
        {courses.length === 0 ? (
          <Card><CardContent className="text-center py-12 text-gray-400"><BookOpen className="h-12 w-12 mx-auto mb-4 opacity-40" /><p>{t('لا توجد كورسات بعد', 'No courses yet')}</p></CardContent></Card>
        ) : (
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            {courses.map((c, idx) => (
              <Card key={c.id} className="border-0 shadow-sm overflow-hidden card-hover bg-white group" style={{ borderRadius: tpl.radius }}>
                <div className="h-40 relative flex items-center justify-center overflow-hidden" style={{ background: `linear-gradient(135deg, ${primary}22, ${accent}18)` }}>
                  <div className="absolute inset-0 opacity-[0.06]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #0d9488 1px, transparent 0)', backgroundSize: '18px 18px' }} />
                  <BookOpen className="h-16 w-16 opacity-50 transition-transform duration-500 group-hover:scale-110" style={{ color: primary }} />
                  <div className="absolute top-3 start-3 flex flex-col items-start gap-1.5">
                    {Number(c.price) > 0 && <Badge className="text-white border-0 shadow-lg px-3 py-1" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>{c.price} {c.currency}</Badge>}
                  </div>
                  {bestSeller?.id === c.id && (
                    <span className="absolute top-3 end-3 inline-flex items-center gap-1 rounded-full bg-amber-400 px-2.5 py-1 text-[10px] font-black text-amber-950 shadow-lg">
                      <Crown className="h-3 w-3" />{t('الأكثر طلباً', 'Best seller')}
                    </span>
                  )}
                </div>
                <CardContent className="p-5">
                  <div className="flex items-center gap-1 mb-1.5">
                    {[1, 2, 3, 4, 5].map((s) => <Star key={s} className="h-3.5 w-3.5 fill-amber-400 text-amber-400" />)}
                    <span className="text-xs font-bold text-gray-600 ms-1">{(c.rating ?? 4.8).toFixed(1)}</span>
                    {(c.enrollments_count ?? 0) > 0 && <span className="text-[11px] text-gray-400 ms-auto inline-flex items-center gap-1"><Users className="h-3 w-3" />{(c.enrollments_count ?? 0).toLocaleString()} {t('طالب', 'students')}</span>}
                  </div>
                  <h3 className="font-bold" style={{ fontFamily: font.heading }}>{c.title}</h3>
                  <p className="text-sm text-gray-500 mt-1.5 line-clamp-2">{c.short_description || c.description}</p>
                  <div className="flex items-center gap-4 mt-3 text-xs text-gray-500">
                    <span className="inline-flex items-center gap-1"><Clock className="h-3.5 w-3.5" style={{ color: primary }} />{c.duration_hours || 0} {t('س', 'h')}</span>
                    <span className="inline-flex items-center gap-1"><BookOpen className="h-3.5 w-3.5" style={{ color: primary }} />{c.sections_count ?? 0} {t('أقسام', 'sections')}</span>
                    <span className="inline-flex items-center gap-1"><TrendingUp className="h-3.5 w-3.5" style={{ color: primary }} />{t('شامل', 'complete')}</span>
                  </div>
                  <div className="flex gap-2 mt-4">
                    <Link href={`/t/${slug}/register?course=${c.id}`} className="flex-1">
                      <Button className="w-full rounded-full text-white shadow-lg hover:scale-[1.02] transition-transform" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: tpl.radius }}>{t('سجل الآن', 'Enroll now')}</Button>
                    </Link>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </div>
    </section>
  );

  /* ─── قسم تفعيل البطاقة — تصميم احترافي جديد ─────── */
  const CardSection = (
    <section id="card" className="py-20 relative overflow-hidden" style={{ background: `linear-gradient(160deg, ${dark} 0%, #101f2e 60%, ${dark} 100%)` }}>
      <div className="absolute inset-0 opacity-[0.05]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '26px 26px' }} />
      <div className="absolute -top-24 left-1/4 w-96 h-96 rounded-full blur-3xl opacity-15" style={{ background: primary }} />
      <div className="absolute -bottom-24 right-1/4 w-96 h-96 rounded-full blur-3xl opacity-15" style={{ background: accent }} />

      <div className="relative max-w-6xl mx-auto px-4 grid lg:grid-cols-2 gap-12 items-center">
        {/* العرض الترويجي */}
        <div className="reveal">
          <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-sm font-medium mb-5" style={{ backgroundColor: `${accent}1f`, color: accent }}>
            <Zap className="h-4 w-4" />{t('طريقة أسرع للبدء', 'The fastest way to start')}
          </div>
          <h2 className="text-3xl sm:text-4xl font-bold text-white mb-4" style={{ fontFamily: font.heading }}>
            {t('عندك بطاقة تسجيل؟', 'Got an activation card?')}
          </h2>
          <p className="text-white/60 max-w-md mb-7">
            {t('أدخل رمز البطاقة وستسجل في كورسك فوراً — بدون تعبئة بيانات طويلة، وتتابع تقدمك من لوحة التحكم.', 'Enter the card code to enroll instantly — no long forms, and track your progress from your dashboard.')}
          </p>
          <div className="space-y-3.5">
            {[
              { icon: Zap, text: t('تفعيل فوري بدون تعبئة نماذج', 'Instant activation — no forms to fill') },
              { icon: CheckCheck, text: t('تسجيل تلقائي في الكورس المخصص', 'Auto-enrollment into the assigned course') },
              { icon: LayoutDashboard, text: t('متابعة تقدمك وشهادتك من لوحة التحكم', 'Track progress & certificates from your dashboard') },
              { icon: Lock, text: t('بياناتك محمية بشكل كامل', 'Your data is fully protected') },
            ].map((b, i) => (
              <div key={i} className="flex items-center gap-3">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/10 border border-white/10">
                  <b.icon className="h-4.5 w-4.5" style={{ color: accent }} />
                </span>
                <span className="text-sm text-white/80">{b.text}</span>
              </div>
            ))}
          </div>
          {!loggedIn && (
            <p className="text-white/40 text-xs mt-7">
              {t('لديك حساب؟', 'Have an account?')}{' '}
              <Link href={`/student/login?tenant_slug=${slug}`} className="font-bold underline underline-offset-4 transition-colors" style={{ color: accent }}>{t('سجل دخول', 'Login')}</Link>
            </p>
          )}
        </div>

        {/* بطاقة التفعيل */}
        <div className="reveal">
          <div className="relative rounded-[2rem] p-[2px]" style={{ background: `linear-gradient(135deg, ${primary}, ${accent}, ${primary})` }}>
            <div className="rounded-[calc(2rem-2px)] bg-[#0b1a26] p-6 sm:p-8">
              {!loggedIn ? (
                <div className="text-center py-6">
                  <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-white/10 border border-white/10"><CreditCard className="h-8 w-8" style={{ color: accent }} /></div>
                  <h3 className="text-xl font-bold text-white mb-2" style={{ fontFamily: font.heading }}>{t('فعّل بطاقتك', 'Activate your card')}</h3>
                  <p className="text-white/60 text-sm max-w-xs mx-auto mb-7">{t('أنشئ حسابك أولاً ثم فعّل بطاقة التسجيل', 'Create your account first, then activate your registration card')}</p>
                  <div className="space-y-3">
                    <Link href={`/t/${slug}/register`} className="block">
                      <Button size="lg" className="w-full h-13 rounded-2xl text-white shadow-xl cta-shine" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                        {t('سجل حساباً', 'Create an account')}
                      </Button>
                    </Link>
                    <Link href={`/student/login?tenant_slug=${slug}`} className="block">
                      <Button variant="outline" size="lg" className="w-full h-13 rounded-2xl border-2 border-white/25 bg-white/10 text-white backdrop-blur-sm hover:bg-white/20">
                        {t('لديك حساب؟ سجل دخول', 'Have an account? Login')}
                      </Button>
                    </Link>
                  </div>
                </div>
              ) : redeemedMsg ? (
                <div className="pop-in text-center py-6">
                  <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-500/15 border border-emerald-400/30">
                    <CheckCircle className="h-8 w-8 text-emerald-400" />
                  </div>
                  <h3 className="text-xl font-bold text-white mb-2" style={{ fontFamily: font.heading }}>{t('تم التفعيل بنجاح!', 'Activated successfully!')}</h3>
                  <p className="text-emerald-300/90 text-sm leading-relaxed mb-7">{redeemedMsg}</p>
                  <Link href="/my-learning" className="block">
                    <Button size="lg" className="w-full h-13 rounded-2xl text-white shadow-xl" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                      <LayoutDashboard className="h-5 w-5" /> {t('الذهاب للوحة التحكم', 'Go to my dashboard')}
                    </Button>
                  </Link>
                </div>
              ) : (
                <div>
                  <div className="flex items-center justify-between mb-6">
                    <div className="flex items-center gap-3">
                      <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-white/10 border border-white/10"><CreditCard className="h-5.5 w-5.5" style={{ color: accent }} /></div>
                      <div>
                        <p className="font-bold text-white text-sm">{t('بطاقة تسجيل', 'Registration card')}</p>
                        <p className="text-[10px] text-white/40">{t('رمز واحد = تسجيل فوري', 'One code = instant enrollment')}</p>
                      </div>
                    </div>
                    <div className="h-8 w-11 rounded-md" style={{ background: `linear-gradient(135deg, ${accent}, ${primary})`, opacity: 0.85 }} />
                  </div>

                  <label className="text-[11px] font-bold text-white/50 mb-2 block">{t('أدخل رمز البطاقة', 'Enter the card code')}</label>
                  <div className="relative mb-4">
                    <Input
                      value={cardCode}
                      onChange={(e) => setCardCode(e.target.value)}
                      onKeyDown={(e) => e.key === 'Enter' && redeem()}
                      placeholder="XXXX-XXXX-XXXX"
                      className="h-14 bg-white/8 border-white/15 text-white placeholder:text-white/25 rounded-2xl font-mono text-center text-lg tracking-[0.25em] uppercase focus-visible:ring-2 ps-4 pe-4"
                      style={{ borderColor: cardCode ? `${accent}66` : undefined }}
                      dir="ltr"
                    />
                    <div className="pointer-events-none absolute inset-x-8 top-0 h-px" style={{ background: `linear-gradient(90deg, transparent, ${accent}88, transparent)` }} />
                  </div>

                  <Button
                    className="w-full h-13 rounded-2xl text-white text-base font-bold shadow-xl hover:scale-[1.01] transition-transform cta-shine disabled:opacity-60"
                    style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}
                    onClick={redeem}
                    disabled={redeeming || cardCode.trim().length < 4}
                  >
                    {redeeming ? <Loader2 className="h-5 w-5 animate-spin" /> : <Zap className="h-5 w-5" />}
                    {redeeming ? t('جاري التفعيل...', 'Activating...') : t('تفعيل البطاقة', 'Activate card')}
                  </Button>

                  <div className="mt-5 grid grid-cols-3 gap-2 text-center">
                    {[t('أدخل الرمز', 'Enter code'), t('اضغط تفعيل', 'Hit activate'), t('ابدأ التعلم', 'Start learning')].map((s, i) => (
                      <div key={i} className="rounded-xl bg-white/5 border border-white/5 py-2.5">
                        <div className="mx-auto mb-1 flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-black text-white" style={{ background: primary }}>{i + 1}</div>
                        <p className="text-[10px] text-white/50">{s}</p>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* شارة عائمة */}
          <div className="absolute -bottom-5 start-8 float-y flex items-center gap-2 rounded-full bg-white px-4 py-2.5 shadow-2xl">
            <span className="flex h-6 w-6 items-center justify-center rounded-full" style={{ background: `${primary}15` }}><CheckCircle className="h-3.5 w-3.5" style={{ color: primary }} /></span>
            <span className="text-xs font-bold" style={{ color: dark }}>{t('آلاف الطلاب فعّلوا بطاقاتهم', 'Thousands already activated')}</span>
          </div>
        </div>
      </div>
    </section>
  );

  const About = (
    <section id="about" className="py-20" style={{ background: tpl.hero === 'gradient-light' ? '#f8fafc' : '#ffffff' }}>
      <div className="max-w-6xl mx-auto px-4 grid md:grid-cols-2 gap-10 items-center reveal">
        <div>
          <div className="inline-block px-4 py-1.5 rounded-full text-sm font-medium mb-4" style={{ backgroundColor: `${primary}15`, color: primary }}>{t('من نحن', 'About us')}</div>
          <h2 className="text-3xl font-bold mb-4" style={{ fontFamily: font.heading }}>{cfg.about_title || t('قصتنا', 'Our story')}</h2>
          <p className="text-gray-500 leading-relaxed">{cfg.about_text || t('نحن ملتزمون بتقديم تعليم عالي الجودة', 'We are committed to delivering high-quality education')}</p>
        </div>
        {cfg.about_image && <img src={cfg.about_image} className="rounded-3xl shadow-2xl w-full object-cover aspect-[4/3]" style={{ borderRadius: tpl.radius }} alt="" />}
      </div>
    </section>
  );

  const Testimonials = (
    <section className="py-20" style={{ background: tpl.hero === 'gradient-light' ? '#ffffff' : '#f8fafc' }}>
      <div className="max-w-5xl mx-auto px-4">
        <div className="text-center mb-12 reveal">
          <div className="inline-block px-4 py-1.5 rounded-full text-sm font-medium mb-4" style={{ backgroundColor: `${primary}15`, color: primary }}>{t('آراء الطلاب', 'Testimonials')}</div>
          <h2 className="text-3xl font-bold" style={{ fontFamily: font.heading }}>{t('ماذا يقول طلابنا', 'What our students say')}</h2>
        </div>
        <div className="grid md:grid-cols-3 gap-6">
          {(cfg.testimonials || []).map((tm: any, i: number) => (
            <Card key={i} className="border-0 shadow-sm p-6 bg-white card-hover" style={{ borderRadius: tpl.radius }}>
              <CardContent className="p-0">
                <div className="flex gap-1 mb-3">
                  {[1, 2, 3, 4, 5].map((s) => <Star key={s} className="h-3.5 w-3.5 fill-amber-400 text-amber-400" />)}
                </div>
                <Quote className="h-6 w-6 mb-3" style={{ color: primary }} />
                <p className="text-gray-600 text-sm leading-relaxed mb-5">{tm.text}</p>
                <div className="flex items-center gap-2.5">
                  <div className="flex h-10 w-10 items-center justify-center rounded-full text-white text-xs font-bold shadow-md" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>{tm.name?.[0] || '?'}</div>
                  <div>
                    <span className="font-bold text-sm">{tm.name}</span>
                    {tm.role && <p className="text-[11px] text-gray-400">{tm.role}</p>}
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    </section>
  );

  const Faq = (
    <section id="faq" className="py-20" style={{ background: tpl.hero === 'gradient-light' ? '#ffffff' : '#f8fafc' }}>
      <div className="max-w-3xl mx-auto px-4">
        <h2 className="text-3xl font-bold text-center mb-10 reveal" style={{ fontFamily: font.heading }}>{t('الأسئلة الشائعة', 'FAQ')}</h2>
        <div className="space-y-3">
          {(cfg.faq || []).map((f: any, i: number) => (
            <div key={i} className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden" style={{ borderRadius: tpl.radius }}>
              <button onClick={() => setOpenFaq(openFaq === i ? null : i)} className="w-full flex items-center justify-between gap-4 p-5 text-right">
                <span className="font-semibold" style={{ fontFamily: font.heading }}>{f.q}</span>
                <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full transition-all ${openFaq === i ? 'rotate-180' : ''}`} style={{ backgroundColor: openFaq === i ? primary : `${primary}12`, color: openFaq === i ? '#fff' : primary }}>
                  <ChevronDown className="h-4 w-4" />
                </span>
              </button>
              {openFaq === i && <div className="px-5 pb-5 text-gray-500 text-sm leading-relaxed">{f.a}</div>}
            </div>
          ))}
        </div>
      </div>
    </section>
  );

  const Cta = (
    <section className="py-20 px-4">
      <div className="max-w-5xl mx-auto relative overflow-hidden rounded-[2.5rem] px-6 py-16 sm:py-20 text-center shadow-2xl reveal" style={{ background: `linear-gradient(135deg, ${dark} 0%, #101f2e 55%, ${dark} 100%)` }}>
        <div className="absolute -top-20 left-1/4 w-72 h-72 rounded-full blur-3xl opacity-20" style={{ background: primary }} />
        <div className="absolute -bottom-20 right-1/4 w-72 h-72 rounded-full blur-3xl opacity-20" style={{ background: accent }} />
        <div className="relative z-10">
          <h2 className="text-3xl sm:text-4xl font-bold text-white mb-4" style={{ fontFamily: font.heading }}>{cfg.cta_title || t('جاهز تبدأ رحلتك التعليمية؟', 'Ready to start your learning journey?')}</h2>
          <p className="text-white/60 mb-8 max-w-xl mx-auto">{cfg.cta_text || t('سجل الآن وابدأ التعلم', 'Register now and start learning')}</p>
          <div className="flex flex-wrap justify-center gap-3">
            <a href={loggedIn ? '#card' : `/t/${slug}/register`}><Button size="lg" className="h-14 px-12 rounded-full text-white text-base shadow-2xl hover:scale-105 transition-transform cta-shine" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: tpl.radius }}>{t('سجل الآن', 'Register now')}</Button></a>
            {!loggedIn && <Link href={`/student/login?tenant_slug=${slug}`}><Button size="lg" variant="outline" className="h-14 px-10 rounded-full text-base border-2 border-white/30 bg-white/10 text-white backdrop-blur-sm hover:bg-white/20" style={{ borderRadius: tpl.radius }}>{t('تسجيل دخول', 'Login')}</Button></Link>}
          </div>
          <TrustRow />
        </div>
      </div>
    </section>
  );

  const sections: Record<string, React.ReactNode> = { stats: StatsBar, about: About, courses: Courses, card: CardSection, testimonials: Testimonials, faq: Faq, cta: Cta };
  const visible = (sid: string) => {
    if (sid === 'courses' || sid === 'card' || sid === 'cta') return true;
    if (sid === 'stats') return !!cfg.show_stats;
    if (sid === 'about') return !!cfg.show_about;
    if (sid === 'testimonials') return !!cfg.show_testimonials;
    if (sid === 'faq') return !!cfg.show_faq;
    return false;
  };

  const whatsapp = cfg.contact_whatsapp;

  return (
    <div className="min-h-screen bg-white" style={{ fontFamily: font.body }}>
      {/* شريط تقدم التمرير */}
      <div className="fixed top-0 inset-x-0 z-[70] h-[3px]" style={{ background: `linear-gradient(90deg, ${primary}, ${accent})`, width: `${progress}%` }} />

      {/* ─── Navbar ─────────────────────────────── */}
      <header className={`fixed top-0 left-0 right-0 z-50 backdrop-blur-xl transition-all ${scrolled ? 'bg-white/95 shadow-lg shadow-gray-900/5 border-b border-gray-100' : 'bg-white/80 border-b border-transparent'}`}>
        <div className="max-w-7xl mx-auto px-4 h-16 flex items-center justify-between">
          <div className="flex items-center gap-2.5">
            {site.logo_url ? <img src={site.logo_url} className="h-9 w-9 rounded-xl object-cover" alt="logo" /> :
              <div className="flex h-9 w-9 items-center justify-center rounded-xl shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}><GraduationCap className="h-5 w-5 text-white" /></div>}
            <span className="font-bold text-lg" style={{ fontFamily: font.heading }}>{site.name}</span>
          </div>
          <nav className="hidden md:flex items-center gap-6 text-sm font-medium">
            <a href="#courses" className="text-gray-600 hover:text-gray-900 transition-colors">{t('الكورسات', 'Courses')}</a>
            <a href={`/t/${slug}/book`} className="text-gray-600 hover:text-gray-900 transition-colors">{t('حجز موعد', 'Book')}</a>
            <a href="#card" className="text-gray-600 hover:text-gray-900 transition-colors">{t('تفعيل بطاقة', 'Activate card')}</a>
            {cfg.show_about && <a href="#about" className="text-gray-600 hover:text-gray-900 transition-colors">{t('من نحن', 'About')}</a>}
            {cfg.show_faq && <a href="#faq" className="text-gray-600 hover:text-gray-900 transition-colors">{t('الأسئلة', 'FAQ')}</a>}
            <a href={`/t/${slug}/blog`} className="text-gray-600 hover:text-gray-900 transition-colors">{t('المدونة', 'Blog')}</a>
          </nav>
          <div className="flex items-center gap-2">
            <InstallButton variant="nav" name={site.name} logoUrl={site.logo_url} primary={primary} accent={accent} className="hidden md:inline-flex" />
            {/* زر تسجيل الدخول — ظاهر دائماً */}
            <Link href={`/student/login?tenant_slug=${slug}`}>
              <Button size="sm" variant="outline" className="rounded-full font-bold shadow-sm" style={{ borderRadius: tpl.radius, color: primary, borderColor: `${primary}55`, background: `${primary}08` }}>
                {t('تسجيل دخول', 'Login')}
              </Button>
            </Link>
            {loggedIn ? (
              <Link href="/my-learning"><Button size="sm" className="rounded-full text-white shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: tpl.radius }}><LayoutDashboard className="h-4 w-4" /> {t('لوحة التحكم', 'Dashboard')}</Button></Link>
            ) : (
              <Link href={`/t/${slug}/register`}><Button size="sm" className="rounded-full text-white shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: tpl.radius }}>{t('سجل الآن', 'Register')}</Button></Link>
            )}
            {/* Mobile menu toggle */}
            <button onClick={() => setMenuOpen(!menuOpen)} className="md:hidden flex h-11 w-11 items-center justify-center rounded-xl border border-gray-200 text-gray-600" aria-label="menu">
              {menuOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
            </button>
          </div>
        </div>
        {/* Mobile menu */}
        {menuOpen && (
          <div className="md:hidden border-t border-gray-100 bg-white px-4 py-4 space-y-1 shadow-xl">
            {[
              { href: '#courses', label: t('الكورسات', 'Courses') },
              { href: `/t/${slug}/book`, label: t('حجز موعد', 'Book') },
              { href: '#card', label: t('تفعيل بطاقة', 'Activate card') },
              ...(cfg.show_about ? [{ href: '#about', label: t('من نحن', 'About') }] : []),
              { href: `/t/${slug}/blog`, label: t('المدونة', 'Blog') },
            ].map((l) => (
              <a key={l.href} href={l.href} onClick={() => setMenuOpen(false)} className="block px-3 py-3 rounded-xl text-sm text-gray-700 hover:bg-gray-50">{l.label}</a>
            ))}
            <div className="flex gap-2 pt-2">
              {!loggedIn && <Link href={`/student/login?tenant_slug=${slug}`} className="flex-1 text-center px-4 py-3 rounded-xl text-sm font-medium border border-gray-200" style={{ color: primary, borderColor: `${primary}55` }} onClick={() => setMenuOpen(false)}>{t('تسجيل دخول', 'Login')}</Link>}
              <Link href={`/t/${slug}/register`} className="flex-1 text-center px-4 py-3 rounded-xl text-sm font-semibold text-white" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }} onClick={() => setMenuOpen(false)}>{t('سجل الآن', 'Register')}</Link>
            </div>
          </div>
        )}
      </header>

      {(cfg.blocks && cfg.blocks.length > 0) ? (
        <BlocksRenderer
          blocks={cfg.blocks}
          site={site}
          slug={slug}
          primary={primary}
          accent={accent}
          dark={dark}
          font={font.body}
          headingFont={font.heading}
          loggedIn={loggedIn}
          courses={courses}
          onRedeem={redeem}
          cardCode={cardCode}
          setCardCode={setCardCode}
          redeeming={redeeming}
          redeemedMsg={redeemedMsg}
        />
      ) : (
        <>
          {hero}
          {sectionOrder.map((sid: string) => visible(sid) ? <div key={sid} className="reveal">{sections[sid]}</div> : null)}
        </>
      )}

      {/* ─── Footer ─────────────────────────────── */}
      <footer className="py-14 pb-28 md:pb-14" style={{ background: dark }}>
        <div className="max-w-6xl mx-auto px-4">
          <div className="flex flex-col md:flex-row items-center justify-between gap-8 mb-10">
            <div className="flex items-center gap-3">
              {site.logo_url ? <img src={site.logo_url} className="h-10 w-10 rounded-xl object-cover" alt="" /> :
                <div className="flex h-10 w-10 items-center justify-center rounded-xl" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}><GraduationCap className="h-5 w-5 text-white" /></div>}
              <div><p className="font-bold text-white" style={{ fontFamily: font.heading }}>{site.name}</p><p className="text-white/50 text-xs">{t('منصة تعليمية', 'Educational Platform')}</p></div>
            </div>
            <div className="flex items-center gap-4 text-white/60 text-sm flex-wrap justify-center">
              {whatsapp && <a href={`https://wa.me/${whatsapp}`} className="flex items-center gap-1.5 hover:text-white transition-colors"><MessageCircle className="h-4 w-4 text-green-400" />WhatsApp</a>}
              {cfg.contact_phone && <span className="flex items-center gap-1.5"><Phone className="h-4 w-4 text-teal-400" />{cfg.contact_phone}</span>}
              {cfg.contact_email && <span className="flex items-center gap-1.5"><Mail className="h-4 w-4 text-red-400" />{cfg.contact_email}</span>}
            </div>
          </div>
          <div className="flex flex-col sm:flex-row items-center justify-between gap-4 pt-8 border-t border-white/10">
            <p className="text-white/40 text-xs">© {new Date().getFullYear()} {site.name}</p>
            <div className="flex items-center gap-4 text-white/40 text-xs">
              <Link href="/terms" className="hover:text-white transition-colors">{t('الشروط', 'Terms')}</Link>
              <Link href="/privacy" className="hover:text-white transition-colors">{t('الخصوصية', 'Privacy')}</Link>
              <Link href="/refund-policy" className="hover:text-white transition-colors">{t('استرداد الأموال', 'Refunds')}</Link>
              <Link href="/contact" className="hover:text-white transition-colors">{t('اتصل بنا', 'Contact')}</Link>
            </div>
          </div>
        </div>
      </footer>

      {/* ─── زر واتساب عائم ──────────────────────── */}
      {whatsapp && (
        <a
          href={`https://wa.me/${whatsapp}`}
          target="_blank"
          rel="noreferrer"
          className="fixed bottom-24 md:bottom-6 left-5 z-50 flex h-13 w-13 items-center justify-center rounded-full bg-gradient-to-br from-emerald-500 to-green-600 text-white shadow-2xl shadow-emerald-500/40 transition-transform hover:scale-110"
          aria-label="WhatsApp"
          style={{ width: '52px', height: '52px' }}
        >
          <MessageCircle className="h-6 w-6" />
        </a>
      )}

      {/* ─── زر تثبيت التطبيق العائم (PWA) ────────── */}
      <div className="fixed bottom-24 md:bottom-6 right-5 z-50">
        <InstallButton name={site.name} logoUrl={site.logo_url} primary={primary} accent={accent} />
      </div>

      {/* ─── شريط CTA سفلي للجوال — يرفع التحويل ──── */}
      <div className="md:hidden fixed bottom-0 inset-x-0 z-50 border-t border-gray-100 bg-white/95 backdrop-blur-xl px-4 py-3 flex items-center gap-3 shadow-[0_-8px_24px_rgba(15,23,42,0.08)]">
        <Link href={loggedIn ? '#card' : `/t/${slug}/register`} className="flex-1">
          <Button className="w-full h-11 rounded-full text-white text-sm font-bold shadow-lg cta-shine" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
            {loggedIn ? t('فعّل بطاقة', 'Activate card') : t('سجل الآن', 'Register now')}
          </Button>
        </Link>
        {loggedIn ? (
          <Link href="/my-learning">
            <Button variant="outline" className="h-11 w-11 rounded-full shrink-0" style={{ borderColor: `${primary}44`, color: primary }} aria-label={t('لوحة التحكم', 'Dashboard')}>
              <LayoutDashboard className="h-5 w-5" />
            </Button>
          </Link>
        ) : (
          <Link href={`/student/login?tenant_slug=${slug}`}>
            <Button variant="outline" className="h-11 rounded-full shrink-0 px-4 text-sm font-bold" style={{ borderColor: `${primary}55`, color: primary, background: `${primary}08` }} aria-label={t('تسجيل دخول', 'Login')}>
              {t('تسجيل دخول', 'Login')}
            </Button>
          </Link>
        )}
      </div>
    </div>
  );
}
