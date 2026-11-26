'use client';

/**
 * Shared building blocks for the student-facing teacher storefront
 * (`/t/[slug]`, its courses page and the block-rendered pages).
 *
 * Everything here follows the SAME rules the redesign is built on:
 *  - Mobile-first: 44px touch targets, no horizontal scroll, safe-area aware.
 *  - Real data only: never invent ratings, student counts or "social proof".
 *  - Teacher identity: colors / fonts / radius always come from the tenant.
 *  - One design language: identical spacing, cards and buttons everywhere.
 */

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InstallButton from '@/components/teacher/install-button';
import { authFetch, API_BASE } from '@/lib/auth-client';
import { useTenantBasePath, useTenantLoginHref } from '@/lib/tenant-host';
import {
  BookOpen, Clock, Layers, CreditCard, CheckCircle2, Loader2, ArrowLeft,
  ChevronDown, Sparkles, Zap, LayoutDashboard, Menu, X, PlayCircle, Tag,
  ShieldCheck, Headphones, GraduationCap, MessageCircle,
} from 'lucide-react';

/** Shared vertical rhythm for storefront sections (mobile-first, calmer). */
export const SECTION_PAD = 'py-14 sm:py-20 lg:py-24';

/** Respect the OS "reduce motion" preference. */
export function useReducedMotion(): boolean {
  const [reduced, setReduced] = useState(false);
  useEffect(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return;
    const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
    setReduced(mq.matches);
    const onChange = () => setReduced(mq.matches);
    mq.addEventListener?.('change', onChange);
    return () => mq.removeEventListener?.('change', onChange);
  }, []);
  return reduced;
}

/**
 * The course ids the signed-in student actually has access to, so a course
 * card can offer "continue learning" instead of "enroll" — based on real
 * access, never a guess.
 */
export function useEnrolledCourseIds(enabled: boolean): Set<string> {
  const [ids, setIds] = useState<Set<string>>(new Set());
  useEffect(() => {
    if (!enabled) {
      setIds(new Set());
      return;
    }
    let alive = true;
    authFetch(`${API_BASE}/my-courses`, { headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : { enrollments: [] }))
      .then((d: any) => {
        if (!alive) return;
        const list = Array.isArray(d?.enrollments) ? d.enrollments : [];
        setIds(
          new Set(
            list
              .map((e: any) => String(e?.course_id ?? e?.course?.id ?? e?.id ?? ''))
              .filter(Boolean),
          ),
        );
      })
      .catch(() => {});
    return () => {
      alive = false;
    };
  }, [enabled]);
  return ids;
}

/* ─── Section heading ─────────────────────────────────────────────── */

export function SectionHeading({
  badge, title, subtitle, primary, headingFont, center = true,
}: {
  badge?: string;
  title: string;
  subtitle?: string;
  primary: string;
  headingFont: string;
  center?: boolean;
}) {
  return (
    <div className={center ? 'text-center' : ''}>
      {badge && (
        <span
          className="mb-4 inline-block rounded-full px-4 py-1.5 text-sm font-medium"
          style={{ backgroundColor: `${primary}15`, color: primary }}
        >
          {badge}
        </span>
      )}
      <h2 className="text-2xl font-bold leading-snug sm:text-3xl lg:text-4xl" style={{ fontFamily: headingFont }}>
        {title}
      </h2>
      {subtitle && (
        <p className="mx-auto mt-3 max-w-xl text-sm text-slate-500 sm:text-base">{subtitle}</p>
      )}
    </div>
  );
}

/* ─── Course card ─────────────────────────────────────────────────── */

export function CourseCard({
  course, slug, primary, accent, radius, headingFont, enrolled,
}: {
  course: any;
  slug: string;
  primary: string;
  accent: string;
  radius: string;
  headingFont: string;
  enrolled: boolean;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const base = useTenantBasePath(slug);

  const image = course?.cover_url || course?.thumbnail_url || null;
  const hasPrice = Number(course?.price) > 0;
  const hours = Number(course?.duration_hours) || 0;
  const sections = Number(course?.sections_count) || 0;
  const lessons = Number(course?.lessons_count) || 0;
  const hasMeta = hours > 0 || sections > 0 || lessons > 0;

  return (
    <article
      data-testid="course-card"
      className="group flex h-full flex-col overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-xl"
    >
      {/* Media — real cover when the teacher uploaded one, else a branded tile */}
      <div
        className="relative flex h-36 items-center justify-center overflow-hidden sm:h-40"
        style={{ background: `linear-gradient(135deg, ${primary}22, ${accent}18)` }}
      >
        {image ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={image}
            alt=""
            className="absolute inset-0 h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
          />
        ) : (
          <BookOpen className="h-14 w-14 opacity-50 transition-transform duration-500 group-hover:scale-110" style={{ color: primary }} />
        )}
        {hasPrice && (
          <span
            data-testid="course-price"
            className="absolute top-3 start-3 inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-bold text-white shadow-lg"
            style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}
          >
            <Tag className="h-3 w-3" />
            {course.price} {course.currency}
          </span>
        )}
      </div>

      <div className="flex flex-1 flex-col p-5">
        <h3 className="font-bold leading-snug" style={{ fontFamily: headingFont }}>{course.title}</h3>
        {(course.short_description || course.description) && (
          <p className="mt-1.5 line-clamp-2 text-sm text-slate-500">
            {course.short_description || course.description}
          </p>
        )}

        {hasMeta && (
          <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-slate-500">
            {hours > 0 && (
              <span className="inline-flex items-center gap-1">
                <Clock className="h-3.5 w-3.5" style={{ color: primary }} />
                {hours} {t('س', 'h')}
              </span>
            )}
            {sections > 0 && (
              <span className="inline-flex items-center gap-1">
                <Layers className="h-3.5 w-3.5" style={{ color: primary }} />
                {sections} {t('وحدات', 'units')}
              </span>
            )}
            {lessons > 0 && (
              <span className="inline-flex items-center gap-1">
                <BookOpen className="h-3.5 w-3.5" style={{ color: primary }} />
                {lessons} {t('درس', 'lessons')}
              </span>
            )}
          </div>
        )}

        <div className="mt-4 flex-1" />

        {enrolled ? (
          <Link href={`/course/${course.id}/learn`} className="block">
            <Button
              className="min-h-[44px] w-full gap-2 text-white shadow-lg"
              style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: radius }}
            >
              <PlayCircle className="h-4 w-4" />
              {t('تابع التعلم', 'Continue learning')}
            </Button>
          </Link>
        ) : (
          <Link href={`${base}/courses/${course.id}`} className="block">
            <Button
              className="min-h-[44px] w-full gap-1.5 text-white shadow-lg"
              style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: radius }}
            >
              {t('عرض التفاصيل والتسجيل', 'View details & enroll')}
              <ArrowLeft className="h-4 w-4" />
            </Button>
          </Link>
        )}
      </div>
    </article>
  );
}

/**
 * A teacher-defined course bundle on the public catalogue. Honest by design:
 * it shows the real member courses and the teacher's price only when one is set.
 * Bundles are enrolled by the teacher (a bundle seats the student in each member
 * course), so the call to action points at the teacher's WhatsApp when available
 * and otherwise at the course catalogue — never at a checkout we do not have.
 */
export function BundleCard({
  bundle, slug, primary, accent, radius, headingFont, whatsapp,
}: {
  bundle: any;
  slug: string;
  primary: string;
  accent: string;
  radius: string;
  headingFont: string;
  whatsapp?: string | null;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const base = useTenantBasePath(slug);

  const image = bundle?.thumbnail_url || null;
  const hasPrice = Number(bundle?.price) > 0;
  const count = Number(bundle?.courses_count) || (bundle?.courses?.length ?? 0);
  const members: any[] = Array.isArray(bundle?.courses) ? bundle.courses : [];
  const waNumber = String(bundle?.whatsapp || whatsapp || '').replace(/[^\d]/g, '');

  return (
    <article
      data-testid="bundle-card"
      className="group flex h-full flex-col overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-xl"
    >
      <div
        className="relative flex h-32 items-center justify-center overflow-hidden sm:h-36"
        style={{ background: `linear-gradient(135deg, ${primary}22, ${accent}18)` }}
      >
        {image ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={image} alt="" className="absolute inset-0 h-full w-full object-cover transition-transform duration-500 group-hover:scale-105" />
        ) : (
          <Layers className="h-12 w-12 opacity-50 transition-transform duration-500 group-hover:scale-110" style={{ color: primary }} />
        )}
        <span
          className="absolute top-3 start-3 inline-flex items-center gap-1 rounded-full bg-white/90 px-3 py-1 text-[11px] font-bold shadow"
          style={{ color: primary }}
        >
          <Layers className="h-3 w-3" />
          {t('حزمة', 'Bundle')}
        </span>
        {hasPrice && (
          <span
            data-testid="bundle-price"
            className="absolute top-3 end-3 inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-bold text-white shadow-lg"
            style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}
          >
            <Tag className="h-3 w-3" />
            {bundle.price} {bundle.currency}
          </span>
        )}
      </div>

      <div className="flex flex-1 flex-col p-5">
        <h3 className="font-bold leading-snug" style={{ fontFamily: headingFont }}>{bundle.title}</h3>
        {(bundle.short_description || bundle.description) && (
          <p className="mt-1.5 line-clamp-2 text-sm text-slate-500">
            {bundle.short_description || bundle.description}
          </p>
        )}

        <div className="mt-3 flex items-center gap-1 text-xs text-slate-500">
          <BookOpen className="h-3.5 w-3.5" style={{ color: primary }} />
          {count} {t('كورسات في الحزمة', 'courses in this bundle')}
        </div>

        {members.length > 0 && (
          <ul className="mt-2 space-y-1">
            {members.slice(0, 4).map((c) => (
              <li key={c.id} className="flex items-center gap-1.5 text-xs text-slate-600">
                <CheckCircle2 className="h-3.5 w-3.5 shrink-0" style={{ color: accent }} />
                <span className="truncate">{c.title}</span>
              </li>
            ))}
          </ul>
        )}

        <div className="mt-4 flex-1" />

        {waNumber ? (
          <a href={`https://wa.me/${waNumber}`} target="_blank" rel="noopener noreferrer" className="block">
            <Button
              className="min-h-[44px] w-full gap-2 text-white shadow-lg"
              style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: radius }}
            >
              <MessageCircle className="h-4 w-4" />
              {t('سجّل في الحزمة عبر واتساب', 'Enroll via WhatsApp')}
            </Button>
          </a>
        ) : (
          <Link href={`${base}/courses`} className="block">
            <Button
              variant="outline"
              className="min-h-[44px] w-full gap-1.5"
              style={{ borderRadius: radius }}
            >
              {t('تواصل مع المعلم للتسجيل', 'Contact the teacher to enroll')}
              <ArrowLeft className="h-4 w-4" />
            </Button>
          </Link>
        )}
      </div>
    </article>
  );
}

/* ─── Stats strip (real numbers only) ─────────────────────────────── */function parseStat(raw: any): { prefix: string; num: number; suffix: string } {
  const str = String(raw ?? '');
  const m = str.match(/^([^\d]*)([\d.,]+)(.*)$/);
  if (!m) return { prefix: '', num: 0, suffix: '' };
  return { prefix: m[1], num: parseFloat(m[2].replace(/,/g, '')), suffix: m[3] };
}

function useCountUp(target: number, active: boolean, duration = 1400) {
  const reduced = useReducedMotion();
  const [val, setVal] = useState(reduced ? target : 0);
  useEffect(() => {
    if (!active) return;
    if (reduced) {
      setVal(target);
      return;
    }
    let raf: number;
    const start = performance.now();
    const tick = (now: number) => {
      const p = Math.min(1, (now - start) / duration);
      setVal(Math.round(target * (1 - Math.pow(1 - p, 3))));
      if (p < 1) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, [active, target, duration, reduced]);
  return val;
}

function StatValue({ raw, label, active, color, headingFont }: {
  raw: any; label: string; active: boolean; color: string; headingFont: string;
}) {
  const { prefix, num, suffix } = parseStat(raw);
  const val = useCountUp(num, active);
  return (
    <div className="rounded-2xl border border-slate-100 bg-white px-3 py-4 text-center shadow-sm sm:px-4 sm:py-5">
      <div className="text-2xl font-black tabular-nums leading-none sm:text-4xl" style={{ color, fontFamily: headingFont }}>
        {prefix}{val.toLocaleString('en-US')}{suffix}
      </div>
      <div className="mt-2 text-xs text-slate-500 sm:text-sm">{label}</div>
    </div>
  );
}

export function StatStrip({ stats, primary, headingFont }: {
  stats: any[]; primary: string; headingFont: string;
}) {
  const ref = useRef<HTMLDivElement | null>(null);
  const [inView, setInView] = useState(false);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !ref.current) {
      setInView(true);
      return;
    }
    const io = new IntersectionObserver((entries) => {
      if (entries[0].isIntersecting) {
        setInView(true);
        io.disconnect();
      }
    }, { threshold: 0.3 });
    io.observe(ref.current);
    return () => io.disconnect();
  }, [reduced, stats.length]);

  if (!stats || stats.length === 0) return null;

  return (
    <section className="border-y border-slate-100 bg-white">
      <div ref={ref} className="mx-auto max-w-5xl px-4 py-10 sm:py-12">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-5">
          {stats.slice(0, 4).map((s: any, i: number) => (
            <StatValue key={i} raw={s.value} label={s.label} active={inView} color={primary} headingFont={headingFont} />
          ))}
        </div>
      </div>
    </section>
  );
}

/* ─── Card activation ─────────────────────────────────────────────── */

export function ActivationPanel({
  loggedIn, slug, primary, accent, headingFont, radius,
  value, onChange, submitting, successMessage, error, onSubmit, title, text,
}: {
  loggedIn: boolean;
  slug: string;
  primary: string;
  accent: string;
  headingFont: string;
  radius: string;
  value: string;
  onChange: (v: string) => void;
  submitting: boolean;
  successMessage: string;
  error: string;
  onSubmit: () => void;
  title?: string;
  text?: string;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const base = useTenantBasePath(slug);
  const loginHref = useTenantLoginHref(slug);

  return (
    <section id="card" className={`scroll-mt-20 ${SECTION_PAD}`} style={{ background: '#f8fafc' }}>
      <div className="mx-auto max-w-xl px-4">
        <div
          className="overflow-hidden rounded-3xl border bg-white shadow-sm"
          style={{ borderRadius: radius, borderColor: `${primary}26` }}
        >
          <div className="relative overflow-hidden px-5 py-6 sm:px-8 sm:py-7" style={{ background: `linear-gradient(135deg, ${primary}14, ${accent}10)` }}>
            <div className="flex items-center gap-3">
              <span
                className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-white shadow-lg"
                style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}
              >
                <CreditCard className="h-6 w-6" />
              </span>
              <div className="min-w-0">
                <h2 className="text-lg font-bold sm:text-xl" style={{ fontFamily: headingFont }}>
                  {title || t('عندك بطاقة تسجيل؟', 'Got an activation card?')}
                </h2>
                <p className="text-xs text-slate-500 sm:text-sm">
                  {text || t('أدخل الرمز وسجّل في كورسك فوراً', 'Enter the code to enroll instantly')}
                </p>
              </div>
            </div>
          </div>

          <div className="p-5 sm:p-7">
            {successMessage ? (
              <div className="pop-in text-center" role="status">
                <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50">
                  <CheckCircle2 className="h-8 w-8 text-emerald-600" />
                </div>
                <h3 className="mb-2 text-lg font-bold" style={{ fontFamily: headingFont }}>
                  {t('تم التفعيل بنجاح!', 'Activated successfully!')}
                </h3>
                <p className="mb-6 text-sm leading-relaxed text-slate-500">{successMessage}</p>
                <Link href="/my-learning" className="block">
                  <Button
                    className="min-h-[48px] w-full gap-2 text-white shadow-lg"
                    style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: radius }}
                  >
                    <LayoutDashboard className="h-5 w-5" />
                    {t('الذهاب إلى كورساتي', 'Go to my courses')}
                  </Button>
                </Link>
              </div>
            ) : !loggedIn ? (
              <div className="text-center">
                <p className="mx-auto mb-5 max-w-sm text-sm text-slate-500">
                  {t('أنشئ حسابك أولاً ثم فعّل بطاقة التسجيل لمتابعة كورسك.', 'Create your account first, then activate your card to start your course.')}
                </p>
                <div className="space-y-2.5">
                  <Link href={`${base}/register`} className="block">
                    <Button
                      className="min-h-[48px] w-full text-white shadow-lg"
                      style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: radius }}
                    >
                      {t('إنشاء حساب', 'Create an account')}
                    </Button>
                  </Link>
                  <Link href={loginHref} className="block">
                    <Button
                      variant="outline"
                      className="min-h-[48px] w-full border-2"
                      style={{ borderRadius: radius, color: primary, borderColor: `${primary}40` }}
                    >
                      {t('لدي حساب — تسجيل الدخول', 'I have an account — log in')}
                    </Button>
                  </Link>
                </div>
              </div>
            ) : (
              <div>
                <label htmlFor="storefront-card-code" className="mb-2 block text-xs font-semibold text-slate-600">
                  {t('أدخل رمز البطاقة', 'Enter the card code')}
                </label>
                <div className="flex flex-col gap-2.5 sm:flex-row">
                  <Input
                    id="storefront-card-code"
                    value={value}
                    onChange={(e) => onChange(e.target.value.toUpperCase())}
                    onKeyDown={(e) => e.key === 'Enter' && onSubmit()}
                    placeholder="XXXX-XXXX-XXXX"
                    autoComplete="off"
                    spellCheck={false}
                    dir="ltr"
                    aria-invalid={!!error}
                    className="h-12 flex-1 rounded-2xl border-slate-200 text-center font-mono text-base tracking-[0.2em] uppercase"
                    style={{ borderColor: error ? '#ef4444' : value ? `${primary}80` : undefined }}
                  />
                  <Button
                    onClick={onSubmit}
                    disabled={submitting || value.trim().length < 4}
                    className="min-h-[48px] shrink-0 gap-2 rounded-2xl px-7 text-base font-bold text-white shadow-lg disabled:opacity-60 sm:min-h-12"
                    style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: radius }}
                  >
                    {submitting ? <Loader2 className="h-5 w-5 animate-spin" /> : <Zap className="h-5 w-5" />}
                    {submitting ? t('جاري التفعيل...', 'Activating...') : t('تفعيل', 'Activate')}
                  </Button>
                </div>

                {error && (
                  <p role="alert" className="mt-3 flex items-start gap-2 rounded-xl bg-red-50 px-3 py-2.5 text-sm text-red-600">
                    <X className="mt-0.5 h-4 w-4 shrink-0" />
                    {error}
                  </p>
                )}

                <div className="mt-5 grid grid-cols-3 gap-2 text-center">
                  {[t('أدخل الرمز', 'Enter code'), t('اضغط تفعيل', 'Tap activate'), t('ابدأ التعلم', 'Start learning')].map((s, i) => (
                    <div key={i} className="rounded-xl border border-slate-100 bg-slate-50 py-2.5">
                      <div className="mx-auto mb-1 flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-black text-white" style={{ background: primary }}>{i + 1}</div>
                      <p className="text-[10px] text-slate-500">{s}</p>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>

        <p className="mt-4 flex items-center justify-center gap-1.5 text-center text-xs text-slate-400">
          <ShieldCheck className="h-3.5 w-3.5" />
          {t('رمزك يُستخدم مرة واحدة وبياناتك محمية', 'Your code is single-use and your data is protected')}
        </p>
      </div>
    </section>
  );
}

/* ─── Storefront header + mobile menu ─────────────────────────────── */

export function StorefrontHeader({
  site, slug, primary, accent, radius, font, loggedIn, links, showInstall = true,
}: {
  site: any;
  slug: string;
  primary: string;
  accent: string;
  radius: string;
  font: { heading: string; body: string };
  loggedIn: boolean;
  links: { href: string; label: string }[];
  showInstall?: boolean;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const base = useTenantBasePath(slug);
  const loginHref = useTenantLoginHref(slug);
  const [open, setOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open]);

  return (
    <header
      data-testid="storefront-header"
      className={`fixed inset-x-0 top-0 z-50 backdrop-blur-xl transition-all ${
        scrolled ? 'border-b border-slate-100 bg-white/95 shadow-sm' : 'border-b border-transparent bg-white/80'
      }`}
    >
      <div className="mx-auto flex h-16 max-w-7xl items-center justify-between gap-3 px-4">
        <Link href={base || '/'} className="flex min-w-0 items-center gap-2.5" aria-label={site?.name}>
          {site?.logo_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={site.logo_url} className="h-9 w-9 rounded-xl object-cover" alt="" />
          ) : (
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
              <GraduationCap className="h-5 w-5 text-white" />
            </div>
          )}
          <span className="truncate text-base font-bold sm:text-lg" style={{ fontFamily: font.heading }}>{site?.name}</span>
        </Link>

        <nav className="hidden items-center gap-6 text-sm font-medium md:flex" aria-label={t('التنقل الرئيسي', 'Main navigation')}>
          {links.map((l) => (
            <a key={l.href} href={l.href} className="text-slate-600 transition-colors hover:text-slate-900">{l.label}</a>
          ))}
        </nav>

        <div className="flex items-center gap-2">
          {showInstall && (
            <InstallButton
              variant="nav"
              name={site?.name || ''}
              logoUrl={site?.logo_url}
              primary={primary}
              accent={accent}
              className="min-h-[40px]"
            />
          )}
          {!loggedIn && (
            <Link href={loginHref} className="hidden sm:block">
              <Button size="sm" variant="outline" className="min-h-[40px] rounded-full font-bold shadow-sm" style={{ borderRadius: radius, color: primary, borderColor: `${primary}55`, background: `${primary}08` }}>
                {t('تسجيل دخول', 'Login')}
              </Button>
            </Link>
          )}
          {loggedIn ? (
            <Link href="/my-learning" className="hidden sm:block">
              <Button size="sm" className="min-h-[40px] rounded-full text-white shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: radius }}>
                <LayoutDashboard className="h-4 w-4" /> {t('كورساتي', 'My courses')}
              </Button>
            </Link>
          ) : (
            <Link href={`${base}/register`} className="hidden sm:block">
              <Button size="sm" className="min-h-[40px] rounded-full text-white shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})`, borderRadius: radius }}>
                {t('سجل الآن', 'Register')}
              </Button>
            </Link>
          )}

          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            aria-expanded={open}
            aria-controls="storefront-mobile-menu"
            aria-label={open ? t('إغلاق القائمة', 'Close menu') : t('فتح القائمة', 'Open menu')}
            className="flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 text-slate-600 transition-colors hover:bg-slate-50 md:hidden"
          >
            {open ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
          </button>
        </div>
      </div>

      {open && (
        <div
          id="storefront-mobile-menu"
          data-testid="storefront-mobile-menu"
          className="md:hidden"
          style={{ paddingBottom: 'max(0.75rem, env(safe-area-inset-bottom))' }}
        >
          <nav className="max-h-[70vh] space-y-1 overflow-y-auto border-t border-slate-100 bg-white px-4 pb-4 pt-3 shadow-xl" aria-label={t('قائمة التنقل', 'Navigation menu')}>
            {links.map((l) => (
              <a
                key={l.href}
                href={l.href}
                onClick={() => setOpen(false)}
                className="flex min-h-[44px] items-center rounded-xl px-3 text-sm font-medium text-slate-700 hover:bg-slate-50"
              >
                {l.label}
              </a>
            ))}
            <div className="flex gap-2 pt-2">
              {loggedIn ? (
                <Link href="/my-learning" onClick={() => setOpen(false)} className="flex min-h-[48px] flex-1 items-center justify-center rounded-xl text-sm font-semibold text-white" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                  {t('كورساتي', 'My courses')}
                </Link>
              ) : (
                <>
                  <Link href={loginHref} onClick={() => setOpen(false)} className="flex min-h-[48px] flex-1 items-center justify-center rounded-xl border px-4 text-sm font-medium" style={{ color: primary, borderColor: `${primary}55` }}>
                    {t('تسجيل دخول', 'Login')}
                  </Link>
                  <Link href={`${base}/register`} onClick={() => setOpen(false)} className="flex min-h-[48px] flex-1 items-center justify-center rounded-xl px-4 text-sm font-semibold text-white" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                    {t('سجل الآن', 'Register')}
                  </Link>
                </>
              )}
            </div>
          </nav>
        </div>
      )}
    </header>
  );
}

/* ─── Footer ──────────────────────────────────────────────────────── */

export function StorefrontFooter({
  site, slug, primary, accent, dark, font, links, whatsapp, phone, email,
}: {
  site: any;
  slug: string;
  primary: string;
  accent: string;
  dark: string;
  font: { heading: string; body: string };
  links: { href: string; label: string }[];
  whatsapp?: string | null;
  phone?: string | null;
  email?: string | null;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const contact = whatsapp
    ? { href: `https://wa.me/${String(whatsapp).replace(/[^\d]/g, '')}`, label: t('واتساب', 'WhatsApp'), icon: MessageCircle }
    : phone
      ? { href: `tel:${phone}`, label: phone, icon: Headphones }
      : email
        ? { href: `mailto:${email}`, label: email, icon: MessageCircle }
        : null;

  return (
    <footer className="pt-14" style={{ background: dark, paddingBottom: 'max(2.5rem, env(safe-area-inset-bottom))' }}>
      <div className="mx-auto max-w-6xl px-4">
        <div className="mb-10 flex flex-col items-start justify-between gap-8 sm:flex-row sm:items-center">
          <div className="flex items-center gap-3">
            {site?.logo_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={site.logo_url} className="h-10 w-10 rounded-xl object-cover" alt="" />
            ) : (
              <div className="flex h-10 w-10 items-center justify-center rounded-xl" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                <GraduationCap className="h-5 w-5 text-white" />
              </div>
            )}
            <div>
              <p className="font-bold text-white" style={{ fontFamily: font.heading }}>{site?.name}</p>
              <p className="text-xs text-white/50">{t('منصة تعليمية', 'Learning platform')}</p>
            </div>
          </div>

          <nav className="flex flex-wrap gap-x-5 gap-y-2 text-sm text-white/70" aria-label={t('روابط التذييل', 'Footer links')}>
            {links.map((l) => (
              <a key={l.href} href={l.href} className="transition-colors hover:text-white">{l.label}</a>
            ))}
          </nav>
        </div>

        <div className="flex flex-col-reverse items-start justify-between gap-6 border-t border-white/10 pt-6 sm:flex-row sm:items-center">
          <p className="text-xs text-white/40">© {new Date().getFullYear()} {site?.name} · {t('جميع الحقوق محفوظة', 'All rights reserved')}</p>
          <div className="flex flex-wrap items-center gap-3">
            {contact && (
              <a href={contact.href} target="_blank" rel="noreferrer" className="inline-flex min-h-[40px] items-center gap-2 rounded-full border border-white/20 px-4 text-sm text-white/80 transition-colors hover:bg-white/10">
                <contact.icon className="h-4 w-4" /> {contact.label}
              </a>
            )}
          </div>
        </div>
      </div>
    </footer>
  );
}

/* ─── Small trust row (reused under CTAs) ─────────────────────────── */

export function TrustRow({ accent, primary, light = true }: { accent: string; primary: string; light?: boolean }) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const items = [
    { icon: ShieldCheck, label: t('دفع آمن', 'Secure payment') },
    { icon: Zap, label: t('تفعيل فوري', 'Instant activation') },
    { icon: Headphones, label: t('دعم مباشر', 'Direct support') },
  ];
  return (
    <div className={`mt-7 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-xs ${light ? 'text-white/60' : 'text-slate-400'}`}>
      {items.map((it, i) => (
        <span key={i} className="inline-flex items-center gap-1.5">
          <it.icon className="h-3.5 w-3.5" style={{ color: light ? accent : primary }} />
          {it.label}
        </span>
      ))}
    </div>
  );
}

export { ChevronDown, Sparkles };
