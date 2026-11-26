'use client';

import { useEffect, useState } from 'react';
import { use } from 'react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import {
  GraduationCap, BookOpen, Sparkles, ArrowLeft,
  ChevronDown, LayoutDashboard, Quote, Zap,
} from 'lucide-react';
import { toast } from 'sonner';
import Link from 'next/link';
import { TEMPLATES, FONTS, type TemplateId } from '@/components/site/templates';
import { BlocksRenderer } from '@/components/site/blocks-renderer';
import {
  StorefrontHeader, StorefrontFooter, SectionHeading, CourseCard, ActivationPanel,
  StatStrip, TrustRow, SECTION_PAD, useReducedMotion, useEnrolledCourseIds,
} from '@/components/site/storefront';
import { useTenantBasePath, useTenantLoginHref } from '@/lib/tenant-host';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

const DEFAULT_SECTIONS = ['stats', 'about', 'courses', 'card', 'testimonials', 'faq', 'cta'];

export default function TeacherLanding({
  params,
  initialSite = null,
  initialCourses = [],
}: {
  params: Promise<{ slug: string }> | { slug: string };
  /** Server-rendered tenant payload: prevents the duplicate client fetch + spinner flash. */
  initialSite?: any;
  initialCourses?: any[];
}) {
  // Next.js hands pages a promise; tests may pass the resolved object directly.
  const paramsOrPromise = params as any;
  const { slug } = paramsOrPromise && typeof paramsOrPromise.then === 'function' ? use(paramsOrPromise) : paramsOrPromise;
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const base = useTenantBasePath(slug);
  const loginHref = useTenantLoginHref(slug);

  const [site, setSite] = useState<any>(initialSite);
  const [courses, setCourses] = useState<any[]>(initialCourses);
  // The server already rendered the page when it handed us the payload, so a
  // spinner here would be a second, pointless loading state.
  const [loading, setLoading] = useState(!initialSite);
  const [slideIdx, setSlideIdx] = useState(0);
  const [loggedIn, setLoggedIn] = useState(false);
  const [cardCode, setCardCode] = useState('');
  const [redeeming, setRedeeming] = useState(false);
  const [redeemedMsg, setRedeemedMsg] = useState('');
  const [cardError, setCardError] = useState('');
  const [openFaq, setOpenFaq] = useState<number | null>(0);
  const [progress, setProgress] = useState(0);

  const reduced = useReducedMotion();
  const enrolledIds = useEnrolledCourseIds(loggedIn);

  useEffect(() => {
    const onScroll = () => {
      const h = document.documentElement;
      setProgress(h.scrollTop / Math.max(1, h.scrollHeight - h.clientHeight) * 100);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  /* Reveal-on-scroll (skipped entirely when the visitor prefers no motion). */
  useEffect(() => {
    if (!site || reduced) return;
    const els = document.querySelectorAll('.reveal');
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting) { e.target.classList.add('revealed'); io.unobserve(e.target); }
      });
    }, { threshold: 0.1 });
    els.forEach((el) => io.observe(el));
    return () => io.disconnect();
  }, [site, reduced]);

  useEffect(() => {
    setLoggedIn(!!localStorage.getItem('lms_authed'));
    // Server payload present → no duplicate client fetch, no spinner flash.
    if (initialSite) return;

    let alive = true;
    let gotSite = false;
    Promise.all([
      fetch(`${API}/public/${slug}`)
        .then((r) => r.json())
        .then((d) => { if (alive && d?.site) { gotSite = true; setSite(d.site); } })
        .catch(() => {}),
      fetch(`${API}/public/${slug}/courses`)
        .then((r) => r.json())
        .then((d) => { if (alive) setCourses(Array.isArray(d?.courses) ? d.courses : []); })
        .catch(() => {}),
    ]).finally(() => {
      if (!alive) return;
      setLoading(false);
      // Unknown slug: stop the pulsing logo and say so instead of hanging.
      if (!gotSite) setSite(null);
    });
    return () => { alive = false; };
  }, [slug]);

  const cfg = site?.site_config || {};
  const tpl = TEMPLATES[(cfg.template as TemplateId) || 'modern-dark'] || TEMPLATES['modern-dark'];
  const font = FONTS.find((f) => f.id === (cfg.font || 'tajawal')) || FONTS[0];
  const slides = cfg.hero_slides?.length ? cfg.hero_slides : [{ title: '', subtitle: '', button_text: '' }];
  const primary = site?.primary_color || tpl.primary;
  const dark = site?.secondary_color || tpl.secondary;
  const accent = site?.accent_color || tpl.accent;
  const sectionOrder = (cfg.sections || DEFAULT_SECTIONS).filter((s: string) => DEFAULT_SECTIONS.includes(s));
  const isDarkHero = tpl.hero === 'slider-dark' || tpl.hero === 'luxury-dark';

  const stats = Array.isArray(cfg.stats) ? cfg.stats : [];
  const testimonials = Array.isArray(cfg.testimonials) ? cfg.testimonials : [];
  const faq = Array.isArray(cfg.faq) ? cfg.faq : [];

  /* Auto-advance slides — never when the visitor asked for reduced motion. */
  useEffect(() => {
    if (reduced || slides.length <= 1) return;
    const id = setInterval(() => setSlideIdx((i) => (i + 1) % slides.length), 6000);
    return () => clearInterval(id);
  }, [reduced, slides.length]);

  const redeem = async () => {
    setCardError('');
    if (!cardCode.trim()) { setCardError(t('أدخل رمز البطاقة', 'Enter the card code')); return; }
    setRedeeming(true);
    setRedeemedMsg('');
    try {
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
      const d = await res.json().catch(() => ({}));
      if (!res.ok) {
        const msg = d?.message || t('تعذّر تفعيل البطاقة — تأكد من الرمز وحاول مرة أخرى.', 'Could not activate the card — check the code and try again.');
        setCardError(msg);
        toast.error(msg);
        return;
      }
      setRedeemedMsg(d?.message || t('تم تفعيل البطاقة — أنت الآن مسجل في الكورس!', 'Card activated — you are now enrolled!'));
      setCardCode('');
      toast.success(t('تم التفعيل بنجاح', 'Activated successfully'));
    } catch {
      setCardError(t('تعذّر الاتصال بالخادم، حاول مرة أخرى.', 'Could not reach the server, please try again.'));
      toast.error(t('تعذّر الاتصال', 'Cannot connect'));
    }
    setRedeeming(false);
  };

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className="flex h-14 w-14 animate-pulse items-center justify-center rounded-2xl shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
          <GraduationCap className="h-7 w-7 text-white" />
        </div>
      </div>
    );
  }

  if (!site) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-4 px-6 text-center">
        <div className="flex h-16 w-16 items-center justify-center rounded-2xl" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
          <GraduationCap className="h-8 w-8 text-white" />
        </div>
        <h1 className="text-2xl font-bold">{t('لم نعثر على هذه المنصة', 'Platform not found')}</h1>
        <p className="max-w-sm text-sm text-muted-foreground">
          {t('تحقق من الرابط، أو اطلب الرابط الصحيح من معلمك.', 'Check the link, or ask your teacher for the correct one.')}
        </p>
        <Link href="/">
          <Button className="h-12 min-h-[48px] rounded-full px-8 text-white" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
            {t('الصفحة الرئيسية', 'Home')}
          </Button>
        </Link>
      </div>
    );
  }

  const slide = slides[slideIdx] || slides[0];
  const heroTitle = slide.title || site.name;
  const hasSlides = slides.length > 1;

  const SlideNav = ({ tone }: { tone: 'light' | 'dark' }) => {
    if (!hasSlides) return null;
    const dot = tone === 'dark' ? 'rgba(255,255,255,0.35)' : '#cbd5e1';
    const active = tone === 'dark' ? accent : primary;
    return (
      <div className="mt-9 flex items-center justify-center gap-2">
        {slides.map((_: any, i: number) => (
          <button
            key={i}
            onClick={() => setSlideIdx(i)}
            aria-label={t(`الشريحة ${i + 1}`, `Slide ${i + 1}`)}
            aria-current={i === slideIdx}
            className="flex h-11 w-8 items-center justify-center"
          >
            <span className={`block h-2 rounded-full transition-all ${i === slideIdx ? 'w-7' : 'w-2'}`} style={{ background: i === slideIdx ? active : dot }} />
          </button>
        ))}
      </div>
    );
  };

  /* ─── Hero ─────────────────────────────────────── */
  const heroPrimary = (
    <a href="#courses" className="inline-flex">
      <Button size="lg" className="h-14 min-h-[48px] rounded-full px-8 text-base text-white shadow-2xl transition-transform hover:scale-105" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: tpl.radius }}>
        {t('استكشف الكورسات', 'Browse courses')} <ArrowLeft className="h-5 w-5" />
      </Button>
    </a>
  );
  const heroSecondary = (
    <a href="#card" className="inline-flex">
      <Button
        size="lg"
        variant="outline"
        className="h-14 min-h-[48px] rounded-full px-8 text-base"
        style={isDarkHero
          ? { borderRadius: tpl.radius, borderWidth: 2, borderColor: 'rgba(255,255,255,0.3)', background: 'rgba(255,255,255,0.1)', color: '#fff' }
          : { borderRadius: tpl.radius, color: dark, borderColor: '#e2e8f0' }}
      >
        {t('كيف تبدأ؟', 'How to start?')}
      </Button>
    </a>
  );
  const heroDashboard = loggedIn ? (
    <Link href="/my-learning" className="inline-flex">
      <Button size="lg" variant="outline" className="h-14 min-h-[48px] rounded-full px-8 text-base" style={{ borderRadius: tpl.radius, color: primary, borderColor: `${primary}55` }}>
        <LayoutDashboard className="h-5 w-5" /> {t('كورساتي', 'My courses')}
      </Button>
    </Link>
  ) : null;

  const HeroSliderDark = (
    <section className="relative overflow-hidden pt-16" style={{ background: `linear-gradient(135deg, ${dark} 0%, #101f2e 55%, ${dark} 100%)` }}>
      <div className="absolute inset-0 opacity-[0.05]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '30px 30px' }} />
      {slide.image && <img src={slide.image} className="absolute inset-0 h-full w-full object-cover opacity-15" alt="" />}
      <div className="absolute inset-0" style={{ background: `radial-gradient(800px 400px at 70% 20%, ${primary}33, transparent)` }} />
      <div className="relative mx-auto max-w-7xl px-4 py-16 text-center sm:py-24 lg:py-28">
        <div className="mb-5 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/10 px-4 py-1.5 text-sm text-white/80">
          <Sparkles className="h-4 w-4" style={{ color: accent }} />{cfg.badge_text || t('منصة تعليمية', 'Educational Platform')}
        </div>
        <h1 className="mx-auto mb-4 max-w-3xl text-3xl font-bold leading-tight text-white sm:text-5xl lg:text-6xl" style={{ fontFamily: font.heading }}>{heroTitle}</h1>
        {slide.subtitle && <p className="mx-auto mb-7 max-w-2xl text-base text-white/70 sm:text-lg" style={{ fontFamily: font.body }}>{slide.subtitle}</p>}
        <div className="flex flex-wrap items-center justify-center gap-3">
          {heroPrimary}
          {heroDashboard || heroSecondary}
        </div>
        {!loggedIn && (
          <p className="mt-4 text-sm text-white/60">
            {t('لديك حساب؟', 'Have an account?')}{' '}
            <Link href={loginHref} className="font-bold underline underline-offset-4 transition-opacity hover:opacity-80" style={{ color: accent }}>{t('تسجيل دخول', 'Login')}</Link>
          </p>
        )}
        <TrustRow accent={accent} primary={primary} />
        <SlideNav tone="dark" />
      </div>
    </section>
  );

  const HeroGradientLight = (
    <section className="relative overflow-hidden pt-16" style={{ background: `linear-gradient(135deg, ${primary}12 0%, #ffffff 50%, ${accent}14 100%)` }}>
      <div className="absolute -top-24 -end-24 h-80 w-80 rounded-full opacity-20 blur-3xl" style={{ background: primary }} />
      <div className="absolute -bottom-24 -start-24 h-80 w-80 rounded-full opacity-20 blur-3xl" style={{ background: accent }} />
      <div className="relative mx-auto max-w-7xl px-4 py-16 sm:py-24 lg:py-28">
        <div className="grid items-center gap-10 lg:grid-cols-2 lg:gap-12">
          <div className="animate-in text-center lg:text-start">
            <div className="mb-5 inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-medium" style={{ backgroundColor: `${primary}15`, color: primary }}>
              <Sparkles className="h-4 w-4" />{cfg.badge_text || t('منصة تعليمية', 'Educational Platform')}
            </div>
            <h1 className="mb-4 text-3xl font-bold leading-tight sm:text-5xl" style={{ fontFamily: font.heading, color: dark }}>{heroTitle}</h1>
            {slide.subtitle && <p className="mx-auto mb-7 max-w-xl text-base text-slate-500 sm:text-lg lg:mx-0" style={{ fontFamily: font.body }}>{slide.subtitle}</p>}
            <div className="flex flex-wrap items-center justify-center gap-3 lg:justify-start">{heroPrimary}{heroSecondary}{heroDashboard}</div>
            <TrustRow accent={accent} primary={primary} light={false} />
          </div>
          <div className="relative mx-auto w-full max-w-md">
            <div className="relative aspect-[4/3] overflow-hidden rounded-[2rem] shadow-2xl" style={{ background: `linear-gradient(135deg, ${dark}, #1e293b)` }}>
              {slide.image ? (
                <img src={slide.image} className="absolute inset-0 h-full w-full object-cover opacity-60" alt="" />
              ) : (
                <div className="absolute inset-0 flex items-center justify-center" style={{ background: `radial-gradient(400px 250px at 50% 30%, ${primary}40, transparent)` }}>
                  <BookOpen className="h-20 w-20" style={{ color: `${primary}aa` }} />
                </div>
              )}
              <div className="absolute inset-x-4 bottom-4 flex items-center gap-2 rounded-2xl bg-white/10 p-3 backdrop-blur">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                  <Zap className="h-5 w-5" />
                </span>
                <div className="min-w-0 text-start">
                  <p className="text-sm font-bold text-white">{t('تعلم بالخطوات', 'Learn step by step')}</p>
                  <p className="truncate text-[11px] text-white/60">
                    {courses.length > 0
                      ? `${courses.length} ${t('كورس متاح لتبدأ الآن', 'courses ready to start')}`
                      : t('دروس عملية ومسارات واضحة', 'Practical lessons and clear paths')}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
        <SlideNav tone="light" />
      </div>
    </section>
  );

  const HeroLuxury = (
    <section className="relative overflow-hidden pt-16" style={{ background: `linear-gradient(160deg, ${dark} 0%, #14100a 60%, ${dark} 100%)` }}>
      <div className="absolute inset-0 opacity-[0.06]" style={{ backgroundImage: 'radial-gradient(#fff 1px, transparent 1px)', backgroundSize: '24px 24px' }} />
      <div className="absolute left-1/2 top-1/4 h-[300px] w-[700px] -translate-x-1/2 rounded-full opacity-20 blur-3xl" style={{ background: primary }} />
      <div className="relative mx-auto max-w-7xl animate-in px-4 py-16 text-center sm:py-24 lg:py-28">
        <div className="mx-auto mb-7 flex items-center justify-center gap-3">
          <span className="h-px w-10" style={{ background: `${accent}66` }} />
          <div className="flex h-9 w-9 items-center justify-center rounded-full border" style={{ borderColor: `${accent}55`, color: accent }}><Sparkles className="h-4 w-4" /></div>
          <span className="h-px w-10" style={{ background: `${accent}66` }} />
        </div>
        <p className="mb-5 text-xs uppercase tracking-[0.3em]" style={{ color: `${accent}cc`, fontFamily: font.body }}>{cfg.badge_text || t('منصة تعليمية', 'Educational Platform')}</p>
        <h1 className="mb-6 bg-clip-text text-3xl font-bold leading-tight sm:text-5xl lg:text-6xl" style={{ fontFamily: font.heading, backgroundImage: `linear-gradient(135deg, ${accent}, ${primary}, ${accent})`, WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent' }}>{heroTitle}</h1>
        {slide.subtitle && <p className="mx-auto mb-8 max-w-2xl text-base sm:text-lg" style={{ color: 'rgba(255,255,255,0.65)', fontFamily: font.body }}>{slide.subtitle}</p>}
        <div className="flex flex-wrap items-center justify-center gap-3">{heroPrimary}{heroDashboard || heroSecondary}</div>
        <TrustRow accent={accent} primary={primary} />
        <SlideNav tone="dark" />
      </div>
    </section>
  );

  const HeroCleanLight = (
    <section className="relative overflow-hidden bg-white pt-16">
      <div className="absolute inset-0 opacity-[0.04]" style={{ backgroundImage: 'linear-gradient(#64748b 1px, transparent 1px), linear-gradient(90deg, #64748b 1px, transparent 1px)', backgroundSize: '48px 48px' }} />
      <div className="relative mx-auto max-w-4xl animate-in px-4 py-16 text-center sm:py-24 lg:py-28">
        <div className="mb-5 inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-medium" style={{ borderColor: `${primary}33`, color: primary, fontFamily: font.body }}>{cfg.badge_text || t('منصة تعليمية', 'Educational Platform')}</div>
        <h1 className="mb-4 text-3xl font-bold leading-tight sm:text-5xl lg:text-6xl" style={{ fontFamily: font.heading, color: dark }}>{heroTitle}</h1>
        {slide.subtitle && <p className="mx-auto mb-8 max-w-2xl text-base text-slate-500 sm:text-lg">{slide.subtitle}</p>}
        <div className="flex flex-wrap items-center justify-center gap-3">{heroPrimary}{heroSecondary}{heroDashboard}</div>
        <TrustRow accent={accent} primary={primary} light={false} />
        <SlideNav tone="light" />
      </div>
    </section>
  );

  const hero = tpl.hero === 'gradient-light' ? HeroGradientLight : tpl.hero === 'luxury-dark' ? HeroLuxury : tpl.hero === 'clean-light' ? HeroCleanLight : HeroSliderDark;

  /* ─── Sections ─────────────────────────────────── */
  const Courses = (
    <section id="courses" className={`scroll-mt-20 ${SECTION_PAD}`} style={{ background: isDarkHero ? '#f8fafc' : '#ffffff' }}>
      <div className="mx-auto max-w-7xl px-4">
        <div className="mb-10 reveal sm:mb-12">
          <SectionHeading
            badge={t('كورساتنا', 'Our courses')}
            title={t('اختر كورسك وابدأ', 'Choose your course and start')}
            subtitle={t('محتوى عملي بشرح مبسط — تقدمك محفوظ تلقائياً.', 'Practical content with simple explanations — progress saved automatically.')}
            primary={primary}
            headingFont={font.heading}
          />
        </div>
        {courses.length === 0 ? (
          <div className="mx-auto flex max-w-md flex-col items-center gap-3 rounded-3xl border border-dashed border-slate-200 bg-slate-50/60 px-6 py-14 text-center">
            <BookOpen className="h-12 w-12 text-slate-300" />
            <p className="text-lg font-bold">{t('لا توجد كورسات منشورة بعد', 'No published courses yet')}</p>
            <p className="text-sm text-slate-500">{t('تابعنا — سيظهر أول كورس هنا فور نشره.', 'Check back soon — the first course will appear here once published.')}</p>
          </div>
        ) : (
          <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {courses.map((c) => (
              <div key={c.id} className="reveal h-full">
                <CourseCard
                  course={c}
                  slug={slug}
                  primary={primary}
                  accent={accent}
                  radius={tpl.radius}
                  headingFont={font.heading}
                  enrolled={enrolledIds.has(String(c.id))}
                />
              </div>
            ))}
          </div>
        )}
      </div>
    </section>
  );

  const About = (
    <section id="about" className={`scroll-mt-20 ${SECTION_PAD}`} style={{ background: isDarkHero ? '#ffffff' : '#f8fafc' }}>
      <div className="mx-auto grid max-w-6xl items-center gap-10 px-4 md:grid-cols-2">
        <div className="reveal">
          <div className="mb-4 inline-block rounded-full px-4 py-1.5 text-sm font-medium" style={{ backgroundColor: `${primary}15`, color: primary }}>{t('من نحن', 'About us')}</div>
          <h2 className="mb-4 text-2xl font-bold sm:text-3xl" style={{ fontFamily: font.heading }}>{cfg.about_title || t('قصتنا', 'Our story')}</h2>
          <p className="leading-relaxed text-slate-500">{cfg.about_text}</p>
        </div>
        {cfg.about_image && (
          <img src={cfg.about_image} className="aspect-[4/3] w-full rounded-3xl object-cover shadow-2xl" style={{ borderRadius: tpl.radius }} alt="" />
        )}
      </div>
    </section>
  );

  const Testimonials = (
    <section className={`${SECTION_PAD}`} style={{ background: isDarkHero ? '#f8fafc' : '#ffffff' }}>
      <div className="mx-auto max-w-5xl px-4">
        <div className="mb-10 reveal">
          <SectionHeading badge={t('آراء الطلاب', 'Testimonials')} title={t('ماذا يقول طلابنا', 'What our students say')} primary={primary} headingFont={font.heading} />
        </div>
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {testimonials.map((tm: any, i: number) => (
            <figure key={i} className="reveal flex h-full flex-col rounded-3xl border border-slate-100 bg-white p-6 shadow-sm" style={{ borderRadius: tpl.radius }}>
              <Quote className="mb-3 h-6 w-6" style={{ color: primary }} />
              <blockquote className="mb-5 flex-1 text-sm leading-relaxed text-slate-600">{tm.text}</blockquote>
              <figcaption className="flex items-center gap-2.5">
                <div className="flex h-10 w-10 items-center justify-center rounded-full text-xs font-bold text-white shadow-md" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>{tm.name?.[0] || '?'}</div>
                <span className="text-sm font-bold">{tm.name}</span>
              </figcaption>
            </figure>
          ))}
        </div>
      </div>
    </section>
  );

  const Faq = (
    <section id="faq" className={`scroll-mt-20 ${SECTION_PAD}`} style={{ background: isDarkHero ? '#ffffff' : '#f8fafc' }}>
      <div className="mx-auto max-w-3xl px-4">
        <div className="mb-9 reveal">
          <SectionHeading title={t('الأسئلة الشائعة', 'FAQ')} primary={primary} headingFont={font.heading} />
        </div>
        <div className="space-y-3">
          {faq.map((f: any, i: number) => (
            <div key={i} className="reveal overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm" style={{ borderRadius: tpl.radius }}>
              <button onClick={() => setOpenFaq(openFaq === i ? null : i)} aria-expanded={openFaq === i} className="flex min-h-[56px] w-full items-center justify-between gap-4 p-5 text-start">
                <span className="font-semibold" style={{ fontFamily: font.heading }}>{f.q}</span>
                <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full transition-all ${openFaq === i ? 'rotate-180' : ''}`} style={{ backgroundColor: openFaq === i ? primary : `${primary}12`, color: openFaq === i ? '#fff' : primary }}>
                  <ChevronDown className="h-4 w-4" />
                </span>
              </button>
              {openFaq === i && <div className="px-5 pb-5 text-sm leading-relaxed text-slate-500">{f.a}</div>}
            </div>
          ))}
        </div>
      </div>
    </section>
  );

  const Cta = (
    <section className={`${SECTION_PAD} px-4`}>
      <div className="reveal relative mx-auto max-w-5xl overflow-hidden rounded-[2rem] px-6 py-14 text-center shadow-2xl sm:py-20" style={{ background: `linear-gradient(135deg, ${dark} 0%, #101f2e 55%, ${dark} 100%)` }}>
        <div className="absolute -top-20 start-1/4 h-72 w-72 rounded-full opacity-20 blur-3xl" style={{ background: primary }} />
        <div className="absolute -bottom-20 end-1/4 h-72 w-72 rounded-full opacity-20 blur-3xl" style={{ background: accent }} />
        <div className="relative z-10">
          <h2 className="mb-4 text-2xl font-bold text-white sm:text-3xl lg:text-4xl" style={{ fontFamily: font.heading }}>{cfg.cta_title || t('جاهز تبدأ رحلتك التعليمية؟', 'Ready to start your learning journey?')}</h2>
          <p className="mx-auto mb-8 max-w-xl text-white/60">{cfg.cta_text || t('سجل الآن وابدأ التعلم', 'Register now and start learning')}</p>
          <div className="flex flex-wrap justify-center gap-3">
            <a href={loggedIn ? '#card' : `${base}/register`} className="inline-flex">
              <Button size="lg" className="h-14 min-h-[48px] rounded-full px-10 text-base text-white shadow-2xl transition-transform hover:scale-105" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: tpl.radius }}>
                {loggedIn ? t('فعّل بطاقتك', 'Activate your card') : t('سجل الآن', 'Register now')}
              </Button>
            </a>
            {loggedIn && (
              <Link href="/my-learning" className="inline-flex">
                <Button size="lg" variant="outline" className="h-14 min-h-[48px] rounded-full border-2 border-white/30 bg-white/10 px-8 text-base text-white backdrop-blur-sm hover:bg-white/20" style={{ borderRadius: tpl.radius }}>
                  <LayoutDashboard className="h-5 w-5" /> {t('كورساتي', 'My courses')}
                </Button>
              </Link>
            )}
          </div>
          <TrustRow accent={accent} primary={primary} />
        </div>
      </div>
    </section>
  );

  const sections: Record<string, React.ReactNode> = {
    stats: stats.length > 0 ? <StatStrip stats={stats} primary={primary} headingFont={font.heading} /> : null,
    about: About,
    courses: Courses,
    card: (
      <ActivationPanel
        loggedIn={loggedIn}
        slug={slug}
        primary={primary}
        accent={accent}
        headingFont={font.heading}
        radius={tpl.radius}
        value={cardCode}
        onChange={setCardCode}
        submitting={redeeming}
        successMessage={redeemedMsg}
        error={cardError}
        onSubmit={redeem}
      />
    ),
    testimonials: Testimonials,
    faq: Faq,
    cta: Cta,
  };

  const visible = (sid: string) => {
    if (sid === 'courses' || sid === 'card' || sid === 'cta') return true;
    if (sid === 'stats') return !!cfg.show_stats && stats.length > 0;
    if (sid === 'about') return !!cfg.show_about && !!(cfg.about_text || cfg.about_image);
    if (sid === 'testimonials') return !!cfg.show_testimonials && testimonials.length > 0;
    if (sid === 'faq') return !!cfg.show_faq && faq.length > 0;
    return false;
  };

  const navLinks = [
    { href: '#courses', label: t('الكورسات', 'Courses') },
    { href: '#card', label: t('تفعيل بطاقة', 'Activate card') },
    ...(cfg.show_about && (cfg.about_text || cfg.about_image) ? [{ href: '#about', label: t('من نحن', 'About') }] : []),
    ...(cfg.show_faq && faq.length > 0 ? [{ href: '#faq', label: t('الأسئلة', 'FAQ') }] : []),
    { href: `${base}/courses`, label: t('كل الكورسات', 'All courses') },
  ];

  return (
    <div className="min-h-screen bg-white" style={{ fontFamily: font.body }}>
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:z-[80] focus:m-3 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:shadow-lg">
        {t('تخطَّ إلى المحتوى', 'Skip to content')}
      </a>

      <div className="fixed inset-x-0 top-0 z-[70] h-[3px]" style={{ background: `linear-gradient(90deg, ${primary}, ${accent})`, width: `${progress}%` }} />

      <StorefrontHeader
        site={site}
        slug={slug}
        primary={primary}
        accent={accent}
        radius={tpl.radius}
        font={{ heading: font.heading, body: font.body }}
        loggedIn={loggedIn}
        links={navLinks}
      />

      <div id="main">
        {cfg.blocks && cfg.blocks.length > 0 ? (
          <div className="pt-16">
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
              enrolledIds={enrolledIds}
              onRedeem={redeem}
              cardCode={cardCode}
              setCardCode={setCardCode}
              redeeming={redeeming}
              redeemedMsg={redeemedMsg}
              cardError={cardError}
            />
          </div>
        ) : (
          <>
            {hero}
            {sectionOrder.map((sid: string) => (visible(sid) ? <div key={sid} className="reveal">{sections[sid]}</div> : null))}
          </>
        )}
      </div>

      <StorefrontFooter
        site={site}
        slug={slug}
        primary={primary}
        accent={accent}
        dark={dark}
        font={{ heading: font.heading, body: font.body }}
        links={navLinks}
        whatsapp={cfg.contact_whatsapp}
        phone={cfg.contact_phone}
        email={cfg.contact_email}
      />
    </div>
  );
}
