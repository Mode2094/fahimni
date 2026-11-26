'use client';

import { useState } from 'react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { Quote, BookOpen, Sparkles, ChevronDown, LayoutDashboard } from 'lucide-react';
import Link from 'next/link';
import { CourseCard, ActivationPanel, SectionHeading } from '@/components/site/storefront';
import { useTenantBasePath } from '@/lib/tenant-host';

/**
 * Renders a teacher-built page (block builder). Shares the exact same
 * CourseCard / activation panel / headings as the default landing so the
 * two paths can never drift apart, and — like the landing — shows only
 * real data (no invented ratings or stats).
 */
export function BlocksRenderer({
  blocks, site, slug, primary, accent, dark, font, headingFont, loggedIn, courses,
  enrolledIds, onRedeem, cardCode, setCardCode, redeeming, redeemedMsg, cardError,
}: {
  blocks: any[];
  site: any;
  slug: string;
  primary: string;
  accent: string;
  dark: string;
  font: string;
  headingFont: string;
  loggedIn: boolean;
  courses: any[];
  enrolledIds: Set<string>;
  onRedeem: () => void;
  cardCode: string;
  setCardCode: (v: string) => void;
  redeeming: boolean;
  redeemedMsg: string;
  cardError: string;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const base = useTenantBasePath(slug);
  const [openFaq, setOpenFaq] = useState<number | null>(0);

  const pad = (b: any) => (b?.padding === 'lg' ? 'py-20' : b?.padding === 'sm' ? 'py-8' : 'py-14 sm:py-20');
  const alignCls = (b: any) => (b?.align === 'left' ? 'text-left' : 'text-center');
  const bgCls = (b: any) => {
    if (b?.bg === 'dark') return { background: dark, color: '#fff' };
    if (b?.bg === 'light') return { background: '#f8fafc', color: '#0f172a' };
    if (b?.bg === 'accent') return { background: `linear-gradient(135deg, ${primary}, ${accent})`, color: '#fff' };
    return { background: '#ffffff', color: '#0f172a' };
  };

  return (
    <div style={{ fontFamily: font }}>
      {blocks.map((b, bi) => {
        const d = b.data || {};
        const style = bgCls(b);

        // ── HERO ──────────────────────────────
        if (b.type === 'hero') {
          return (
            <section key={bi} className="relative overflow-hidden" style={{ background: `linear-gradient(135deg, ${d.bg_dark ? d.bg_dark : dark}, ${dark})` }}>
              {d.image && <img src={d.image} className="absolute inset-0 h-full w-full object-cover opacity-20" alt="" />}
              <div className={`relative mx-auto max-w-7xl px-4 ${pad(b)} ${alignCls(b)}`}>
                {d.badge && (
                  <div className={`mb-6 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/10 px-4 py-1.5 text-sm text-white/80`}>
                    <Sparkles className="h-4 w-4" style={{ color: accent }} />{d.badge}
                  </div>
                )}
                <h1 className="mx-auto mb-5 max-w-3xl text-3xl font-bold leading-tight text-white sm:text-5xl lg:text-6xl" style={{ fontFamily: headingFont }}>{d.title || site.name}</h1>
                {d.subtitle && <p className="mx-auto mb-8 max-w-2xl text-base text-white/70 sm:text-lg">{d.subtitle}</p>}
                <div className="flex flex-wrap items-center justify-center gap-3">
                  <a href="#courses" className="inline-flex">
                    <Button size="lg" className="h-14 min-h-[48px] rounded-full px-8 text-white shadow-xl" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                      {t('استكشف الكورسات', 'Browse courses')} <BookOpen className="h-5 w-5" />
                    </Button>
                  </a>
                  <a href={loggedIn ? '#card' : `${base}/register`} className="inline-flex">
                    <Button size="lg" variant="outline" className="h-14 min-h-[48px] rounded-full border-2 border-white/30 bg-white/10 px-8 text-white backdrop-blur-sm hover:bg-white/20">
                      {d.button_text || t('سجل الآن', 'Register now')}
                    </Button>
                  </a>
                </div>
              </div>
            </section>
          );
        }

        // ── TEXT ──────────────────────────────
        if (b.type === 'text') {
          return (
            <section key={bi} className={pad(b)} style={style}>
              <div className={`mx-auto max-w-4xl px-4 ${alignCls(b)}`}>
                {d.heading && <h2 className="mb-4 text-2xl font-bold sm:text-3xl lg:text-4xl" style={{ fontFamily: headingFont }}>{d.heading}</h2>}
                {d.paragraph && <p className="text-base leading-relaxed opacity-80 sm:text-lg">{d.paragraph}</p>}
              </div>
            </section>
          );
        }

        // ── IMAGE ─────────────────────────────
        if (b.type === 'image') {
          return (
            <section key={bi} className={pad(b)} style={style}>
              <div className={`mx-auto max-w-4xl px-4 ${alignCls(b)}`}>
                {d.url ? <img src={d.url} className="w-full rounded-3xl object-cover shadow-2xl" style={{ maxHeight: d.height || 420 }} alt="" /> : <div className="flex h-40 items-center justify-center rounded-3xl bg-slate-100 text-slate-300">🖼️</div>}
                {d.caption && <p className="mt-3 text-sm opacity-60">{d.caption}</p>}
              </div>
            </section>
          );
        }

        // ── VIDEO ─────────────────────────────
        if (b.type === 'video') {
          const vurl = d.url || '';
          const yt = vurl.match(/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([\w-]{6,})/);
          const vimeo = vurl.match(/vimeo\.com\/(\d+)/);
          const embed = yt ? `https://www.youtube.com/embed/${yt[1]}` : vimeo ? `https://player.vimeo.com/video/${vimeo[1]}` : vurl;
          return (
            <section key={bi} className={pad(b)} style={style}>
              <div className="mx-auto max-w-4xl px-4">
                {d.title && <h2 className="mb-6 text-center text-2xl font-bold sm:text-3xl" style={{ fontFamily: headingFont }}>{d.title}</h2>}
                <div className="aspect-video overflow-hidden rounded-3xl shadow-2xl">
                  {embed ? <iframe src={embed} className="h-full w-full" allow="autoplay; fullscreen; picture-in-picture" allowFullScreen /> : <div className="flex h-full w-full items-center justify-center bg-slate-100 text-slate-300">🎬</div>}
                </div>
              </div>
            </section>
          );
        }

        // ── STATS (real, teacher-entered numbers only) ──
        if (b.type === 'stats') {
          const stats = Array.isArray(d.stats) ? d.stats : [];
          if (stats.length === 0) return null;
          return (
            <section key={bi} className={pad(b)} style={style}>
              <div className="mx-auto grid max-w-5xl grid-cols-2 gap-3 px-4 sm:grid-cols-4 sm:gap-5">
                {stats.slice(0, 4).map((s: any, i: number) => (
                  <div key={i} className="rounded-2xl border border-slate-100 bg-white p-4 text-center shadow-sm">
                    <div className="text-2xl font-black tabular-nums sm:text-4xl" style={{ color: primary, fontFamily: headingFont }}>{s.value}</div>
                    <div className="mt-1.5 text-xs opacity-60 sm:text-sm">{s.label}</div>
                  </div>
                ))}
              </div>
            </section>
          );
        }

        // ── ABOUT ─────────────────────────────
        if (b.type === 'about') {
          return (
            <section key={bi} className={pad(b)} style={style}>
              <div className="mx-auto grid max-w-6xl items-center gap-10 px-4 md:grid-cols-2">
                <div>
                  <div className="mb-4 inline-block rounded-full px-4 py-1.5 text-sm font-medium" style={{ backgroundColor: `${primary}15`, color: primary }}>{t('من نحن', 'About us')}</div>
                  <h2 className="mb-4 text-2xl font-bold sm:text-3xl" style={{ fontFamily: headingFont }}>{d.title || t('قصتنا', 'Our story')}</h2>
                  <p className="leading-relaxed opacity-70">{d.text || ''}</p>
                </div>
                {d.image && <img src={d.image} className="aspect-[4/3] w-full rounded-3xl object-cover shadow-2xl" alt="" />}
              </div>
            </section>
          );
        }

        // ── COURSES ───────────────────────────
        if (b.type === 'courses') {
          return (
            <section key={bi} id="courses" className={`scroll-mt-20 ${pad(b)}`} style={style}>
              <div className="mx-auto max-w-7xl px-4">
                <div className="mb-10 sm:mb-12">
                  <SectionHeading title={d.title || t('كورساتنا', 'Our courses')} primary={primary} headingFont={headingFont} />
                </div>
                {courses.length === 0 ? (
                  <div className="mx-auto flex max-w-md flex-col items-center gap-3 rounded-3xl border border-dashed border-slate-200 bg-slate-50/60 px-6 py-14 text-center">
                    <BookOpen className="h-12 w-12 text-slate-300" />
                    <p className="text-lg font-bold">{t('لا توجد كورسات منشورة بعد', 'No published courses yet')}</p>
                  </div>
                ) : (
                  <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {courses.map((c: any) => (
                      <CourseCard key={c.id} course={c} slug={slug} primary={primary} accent={accent} radius="1rem" headingFont={headingFont} enrolled={enrolledIds.has(String(c.id))} />
                    ))}
                  </div>
                )}
              </div>
            </section>
          );
        }

        // ── CARD (activation) ─────────────────
        if (b.type === 'card') {
          return (
            <ActivationPanel
              key={bi}
              loggedIn={loggedIn}
              slug={slug}
              primary={primary}
              accent={accent}
              headingFont={headingFont}
              radius="1rem"
              value={cardCode}
              onChange={setCardCode}
              submitting={redeeming}
              successMessage={redeemedMsg}
              error={cardError}
              onSubmit={onRedeem}
              title={d.title}
              text={d.text}
            />
          );
        }

        // ── TESTIMONIALS (teacher-entered, never fabricated) ──
        if (b.type === 'testimonials') {
          const items = Array.isArray(d.items) ? d.items.filter((x: any) => x?.text) : [];
          if (items.length === 0) return null;
          return (
            <section key={bi} className={pad(b)} style={style}>
              <div className="mx-auto max-w-5xl px-4">
                <div className="mb-10">
                  <SectionHeading title={d.title || t('ماذا يقول طلابنا', 'What our students say')} primary={primary} headingFont={headingFont} />
                </div>
                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                  {items.map((tm: any, i: number) => (
                    <figure key={i} className="flex h-full flex-col rounded-3xl border border-slate-100 bg-white p-6 shadow-sm" style={{ color: '#0f172a' }}>
                      <Quote className="mb-3 h-6 w-6" style={{ color: primary }} />
                      <blockquote className="mb-5 flex-1 text-sm leading-relaxed text-slate-600">{tm.text}</blockquote>
                      <figcaption className="flex items-center gap-2">
                        <div className="flex h-9 w-9 items-center justify-center rounded-full text-xs font-bold text-white" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>{tm.name?.[0] || '?'}</div>
                        <span className="text-sm font-semibold">{tm.name}</span>
                      </figcaption>
                    </figure>
                  ))}
                </div>
              </div>
            </section>
          );
        }

        // ── FAQ ───────────────────────────────
        if (b.type === 'faq') {
          const items = Array.isArray(d.items) ? d.items.filter((x: any) => x?.q) : [];
          if (items.length === 0) return null;
          return (
            <section key={bi} id="faq" className={`scroll-mt-20 ${pad(b)}`} style={style}>
              <div className="mx-auto max-w-3xl px-4">
                <div className="mb-8">
                  <SectionHeading title={d.title || t('الأسئلة الشائعة', 'FAQ')} primary={primary} headingFont={headingFont} />
                </div>
                <div className="space-y-3">
                  {items.map((f: any, i: number) => (
                    <div key={i} className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm" style={{ color: '#0f172a' }}>
                      <button onClick={() => setOpenFaq(openFaq === i ? null : i)} aria-expanded={openFaq === i} className="flex min-h-[56px] w-full items-center justify-between gap-4 p-5 text-start">
                        <span className="font-semibold">{f.q}</span>
                        <ChevronDown className={`h-5 w-5 shrink-0 transition-transform ${openFaq === i ? 'rotate-180' : ''}`} style={{ color: primary }} />
                      </button>
                      {openFaq === i && <div className="px-5 pb-5 text-sm leading-relaxed text-slate-500">{f.a}</div>}
                    </div>
                  ))}
                </div>
              </div>
            </section>
          );
        }

        // ── CTA ───────────────────────────────
        if (b.type === 'cta') {
          return (
            <section key={bi} className={pad(b)} style={style}>
              <div className="mx-auto max-w-4xl px-4 text-center">
                <h2 className="mb-4 text-2xl font-bold sm:text-3xl lg:text-4xl" style={{ fontFamily: headingFont }}>{d.title || t('جاهز تبدأ؟', 'Ready to start?')}</h2>
                {d.text && <p className="mb-8 opacity-70">{d.text}</p>}
                <div className="flex flex-wrap justify-center gap-3">
                  <a href={loggedIn ? '#card' : `${base}/register`} className="inline-flex">
                    <Button size="lg" className="h-14 min-h-[48px] rounded-full px-10 text-white shadow-xl" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                      {d.button_text || t('سجل الآن', 'Register now')}
                    </Button>
                  </a>
                  {loggedIn && (
                    <Link href="/my-learning" className="inline-flex">
                      <Button size="lg" variant="outline" className="h-14 min-h-[48px] rounded-full px-8">
                        <LayoutDashboard className="h-5 w-5" /> {t('كورساتي', 'My courses')}
                      </Button>
                    </Link>
                  )}
                </div>
              </div>
            </section>
          );
        }

        // ── DIVIDER ───────────────────────────
        if (b.type === 'divider') {
          return (
            <div key={bi} className="px-4" style={{ background: style.background }}>
              <div className="mx-auto max-w-5xl" style={{ height: 1, background: d.color || '#e2e8f0', margin: 0 }} />
            </div>
          );
        }

        return null;
      })}
    </div>
  );
}
