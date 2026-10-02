'use client';

import { useLocale } from '@/store/locale';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { CheckCircle2, CreditCard, LayoutDashboard, Loader2, Quote, Star, Users, Clock, BookOpen, Sparkles, ChevronDown } from 'lucide-react';
import { Latex } from '@/components/exam/latex';
import { useState } from 'react';
import Link from 'next/link';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export function BlocksRenderer({ blocks, site, slug, primary, accent, dark, font, headingFont, loggedIn, courses, onRedeem, cardCode, setCardCode, redeeming, redeemedMsg }: {
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
  onRedeem: () => void;
  cardCode: string;
  setCardCode: (v: string) => void;
  redeeming: boolean;
  redeemedMsg: string;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const [openFaq, setOpenFaq] = useState<number | null>(0);

  const pad = (b: any) => b?.padding === 'lg' ? 'py-20' : b?.padding === 'sm' ? 'py-8' : 'py-14';
  const alignCls = (b: any) => b?.align === 'center' ? 'text-center' : b?.align === 'left' ? 'text-left' : 'text-center';
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
              {d.image && <img src={d.image} className="absolute inset-0 w-full h-full object-cover opacity-20" alt="" />}
              <div className={`relative max-w-7xl mx-auto px-4 ${pad(b)} ${alignCls(b)}`}>
                {d.badge && <div className={`inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/10 border border-white/10 text-white/80 text-sm mb-6 ${alignCls(b) === 'text-center' ? '' : ''}`}><Sparkles className="h-4 w-4" style={{ color: accent }} />{d.badge}</div>}
                <h1 className="text-4xl sm:text-6xl font-bold text-white leading-tight mb-5 max-w-3xl mx-auto" style={{ fontFamily: headingFont }}>{d.title || site.name}</h1>
                {d.subtitle && <p className="text-lg text-white/70 max-w-2xl mx-auto mb-8">{d.subtitle}</p>}
                <a href={loggedIn ? '#card' : `/t/${slug}/register`}><Button size="lg" className="h-14 px-10 rounded-full text-white text-base shadow-xl" style={{ background: primary }}>{d.button_text || t('ابدأ الآن', 'Start now')}</Button></a>
              </div>
            </section>
          );
        }

        // ── TEXT ──────────────────────────────
        if (b.type === 'text') {
          return (
            <section key={bi} className={`${pad(b)}`} style={style}>
              <div className={`max-w-4xl mx-auto px-4 ${alignCls(b)}`}>
                {d.heading && <h2 className="text-3xl sm:text-4xl font-bold mb-4" style={{ fontFamily: headingFont }}>{d.heading}</h2>}
                {d.paragraph && <p className="text-lg leading-relaxed opacity-80">{d.paragraph}</p>}
              </div>
            </section>
          );
        }

        // ── IMAGE ─────────────────────────────
        if (b.type === 'image') {
          return (
            <section key={bi} className={`${pad(b)}`} style={style}>
              <div className={`max-w-4xl mx-auto px-4 ${alignCls(b)}`}>
                {d.url ? <img src={d.url} className="w-full rounded-3xl shadow-2xl object-cover" style={{ maxHeight: d.height || 420 }} alt="" /> : <div className="h-40 bg-gray-100 rounded-3xl flex items-center justify-center text-gray-300">🖼️</div>}
                {d.caption && <p className="text-sm opacity-60 mt-3">{d.caption}</p>}
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
            <section key={bi} className={`${pad(b)}`} style={style}>
              <div className="max-w-4xl mx-auto px-4">
                {d.title && <h2 className="text-3xl font-bold mb-6 text-center" style={{ fontFamily: headingFont }}>{d.title}</h2>}
                <div className="aspect-video rounded-3xl overflow-hidden shadow-2xl">
                  {embed ? <iframe src={embed} className="w-full h-full" allow="autoplay; fullscreen; picture-in-picture" allowFullScreen /> : <div className="w-full h-full bg-gray-100 flex items-center justify-center text-gray-300">🎬</div>}
                </div>
              </div>
            </section>
          );
        }

        // ── STATS ─────────────────────────────
        if (b.type === 'stats') {
          const stats = d.stats || [{ value: '+100', label: 'طالب' }, { value: '+20', label: 'كورس' }, { value: '4.9', label: 'تقييم' }, { value: '24/7', label: 'دعم' }];
          return (
            <section key={bi} className={`${pad(b)}`} style={style}>
              <div className="max-w-5xl mx-auto px-4 grid grid-cols-2 sm:grid-cols-4 gap-6">
                {stats.map((s: any, i: number) => (
                  <div key={i} className="text-center">
                    <div className="text-3xl sm:text-4xl font-bold" style={{ color: primary, fontFamily: headingFont }}>{s.value}</div>
                    <div className="opacity-60 text-sm mt-1">{s.label}</div>
                  </div>
                ))}
              </div>
            </section>
          );
        }

        // ── ABOUT ─────────────────────────────
        if (b.type === 'about') {
          return (
            <section key={bi} className={`${pad(b)}`} style={style}>
              <div className="max-w-6xl mx-auto px-4 grid md:grid-cols-2 gap-10 items-center">
                <div>
                  <div className="inline-block px-4 py-1.5 rounded-full text-sm font-medium mb-4" style={{ backgroundColor: `${primary}15`, color: primary }}>{t('من نحن', 'About us')}</div>
                  <h2 className="text-3xl font-bold mb-4" style={{ fontFamily: headingFont }}>{d.title || t('قصتنا', 'Our story')}</h2>
                  <p className="opacity-70 leading-relaxed">{d.text || ''}</p>
                </div>
                {d.image && <img src={d.image} className="rounded-3xl shadow-2xl w-full object-cover aspect-[4/3]" alt="" />}
              </div>
            </section>
          );
        }

        // ── COURSES ───────────────────────────
        if (b.type === 'courses') {
          return (
            <section key={bi} id="courses" className={`${pad(b)}`} style={style}>
              <div className="max-w-7xl mx-auto px-4">
                <h2 className="text-3xl sm:text-4xl font-bold text-center mb-12" style={{ fontFamily: headingFont }}>{d.title || t('كورساتنا', 'Our courses')}</h2>
                {courses.length === 0 ? (
                  <Card><CardContent className="text-center py-12 opacity-50"><BookOpen className="h-12 w-12 mx-auto mb-4" /><p>{t('لا توجد كورسات بعد', 'No courses yet')}</p></CardContent></Card>
                ) : (
                  <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    {courses.map((c) => (
                      <Card key={c.id} className="border-0 shadow-sm overflow-hidden card-hover bg-white" style={{ color: '#0f172a' }}>
                        <div className="h-32 relative flex items-center justify-center" style={{ background: `linear-gradient(135deg, ${primary}22, ${accent}18)` }}>
                          <BookOpen className="h-12 w-12 opacity-40" style={{ color: primary }} />
                          {Number(c.price) > 0 && <Badge className="absolute top-3 start-3 text-white border-0 shadow-lg" style={{ background: primary }}>{c.price} {c.currency}</Badge>}
                        </div>
                        <CardContent className="p-5">
                          <h3 className="font-semibold">{c.title}</h3>
                          <p className="text-sm text-gray-500 mt-1 line-clamp-2">{c.short_description || c.description}</p>
                          <div className="flex items-center gap-4 mt-3 text-xs text-gray-500">
                            <span className="flex items-center gap-1"><Clock className="h-3 w-3" />{c.duration_hours || 0} {t('س', 'h')}</span>
                            <span className="flex items-center gap-1"><Users className="h-3 w-3" />{c.sections_count ?? 0} {t('أقسام', 'sections')}</span>
                            <span className="flex items-center gap-1"><Star className="h-3 w-3 text-yellow-400" />4.8</span>
                          </div>
                          <Link href={`/t/${slug}/register?course=${c.id}`}><Button className="w-full rounded-full mt-4 text-white" style={{ background: primary }}>{t('سجل الآن', 'Enroll now')}</Button></Link>
                        </CardContent>
                      </Card>
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
            <section key={bi} id="card" className={`${pad(b)}`} style={style}>
              <div className="max-w-3xl mx-auto px-4 text-center">
                <div className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-2xl bg-white/10 border border-white/10"><CreditCard className="h-7 w-7" style={{ color: accent }} /></div>
                <h2 className="text-2xl sm:text-3xl font-bold mb-3" style={{ fontFamily: headingFont }}>{d.title || t('عندك بطاقة تسجيل؟', 'Got an activation card?')}</h2>
                <p className="opacity-70 max-w-md mx-auto mb-8">{d.text || t('أدخل رمز البطاقة وستسجل في كورسك فوراً', 'Enter the card code to enroll instantly')}</p>
                {!loggedIn ? (
                  <Link href={`/t/${slug}/register`}><Button size="lg" className="h-12 px-8 rounded-full text-white shadow-xl" style={{ background: primary }}>{t('سجل حساباً أولاً', 'Create an account first')}</Button></Link>
                ) : (
                  <div className="max-w-md mx-auto space-y-3">
                    <div className="flex gap-2">
                      <Input value={cardCode} onChange={(e) => setCardCode(e.target.value)} placeholder={t('رمز البطاقة', 'Card code')} className="h-12 bg-white/10 border-white/20 text-white placeholder:text-white/40 rounded-xl font-mono uppercase text-center" dir="ltr" />
                      <Button className="h-12 px-6 rounded-xl text-white" style={{ background: primary }} onClick={onRedeem} disabled={redeeming}>{redeeming ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}{t('تفعيل', 'Activate')}</Button>
                    </div>
                    {redeemedMsg && <div className="flex items-center gap-2 p-3 rounded-xl bg-green-500/15 text-green-300 text-sm text-right"><CheckCircle2 className="h-4 w-4 shrink-0" />{redeemedMsg}</div>}
                    <Link href="/my-learning" className="block"><Button variant="outline" className="w-full rounded-xl border-white/20 text-white hover:bg-white/10 gap-2"><LayoutDashboard className="h-4 w-4" />{t('لوحة التحكم', 'Dashboard')}</Button></Link>
                  </div>
                )}
              </div>
            </section>
          );
        }

        // ── TESTIMONIALS ──────────────────────
        if (b.type === 'testimonials') {
          const items = d.items || [];
          return (
            <section key={bi} className={`${pad(b)}`} style={style}>
              <div className="max-w-5xl mx-auto px-4">
                <h2 className="text-3xl font-bold text-center mb-12" style={{ fontFamily: headingFont }}>{d.title || t('ماذا يقول طلابنا', 'What our students say')}</h2>
                <div className="grid md:grid-cols-3 gap-6">
                  {items.map((tm: any, i: number) => (
                    <Card key={i} className="border-0 shadow-sm p-6 bg-white" style={{ color: '#0f172a' }}>
                      <CardContent className="p-0">
                        <Quote className="h-6 w-6 mb-3" style={{ color: primary }} />
                        <p className="text-gray-600 text-sm leading-relaxed mb-5">{tm.text}</p>
                        <div className="flex items-center gap-2">
                          <div className="flex h-9 w-9 items-center justify-center rounded-full text-white text-xs font-bold" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>{tm.name?.[0] || '?'}</div>
                          <span className="font-semibold text-sm">{tm.name}</span>
                        </div>
                      </CardContent>
                    </Card>
                  ))}
                </div>
              </div>
            </section>
          );
        }

        // ── FAQ ───────────────────────────────
        if (b.type === 'faq') {
          const items = d.items || [];
          return (
            <section key={bi} id="faq" className={`${pad(b)}`} style={style}>
              <div className="max-w-3xl mx-auto px-4">
                <h2 className="text-3xl font-bold text-center mb-10" style={{ fontFamily: headingFont }}>{d.title || t('الأسئلة الشائعة', 'FAQ')}</h2>
                <div className="space-y-3">
                  {items.map((f: any, i: number) => (
                    <div key={i} className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden" style={{ color: '#0f172a' }}>
                      <button onClick={() => setOpenFaq(openFaq === i ? null : i)} className="w-full flex items-center justify-between gap-4 p-5 text-right">
                        <span className="font-semibold">{f.q}</span>
                        <ChevronDown className={`h-5 w-5 transition-transform shrink-0 ${openFaq === i ? 'rotate-180' : ''}`} style={{ color: primary }} />
                      </button>
                      {openFaq === i && <div className="px-5 pb-5 text-gray-500 text-sm leading-relaxed">{f.a}</div>}
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
            <section key={bi} className={`${pad(b)}`} style={style}>
              <div className="max-w-4xl mx-auto px-4 text-center">
                <h2 className="text-3xl sm:text-4xl font-bold mb-4" style={{ fontFamily: headingFont }}>{d.title || t('جاهز تبدأ؟', 'Ready to start?')}</h2>
                {d.text && <p className="opacity-70 mb-8">{d.text}</p>}
                <a href={loggedIn ? '#card' : `/t/${slug}/register`}><Button size="lg" className="h-14 px-12 rounded-full text-white text-base shadow-xl" style={{ background: primary }}>{d.button_text || t('سجل الآن', 'Register now')}</Button></a>
              </div>
            </section>
          );
        }

        // ── DIVIDER ───────────────────────────
        if (b.type === 'divider') {
          return (
            <div key={bi} className="px-4" style={{ background: style.background }}>
              <div className="max-w-5xl mx-auto" style={{ height: 1, background: d.color || '#e2e8f0', margin: 0 }} />
            </div>
          );
        }

        return null;
      })}
    </div>
  );
}
