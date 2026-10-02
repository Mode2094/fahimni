'use client';

import { useEffect, useMemo, useState } from 'react';
import { use } from 'react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  Loader2, ArrowLeft, Calendar, Clock, Tag, Link2, Check, MessageCircle, Send,
  Twitter, ChevronLeft, GraduationCap, Newspaper, BookOpen, Sparkles, Menu, X, Quote,
} from 'lucide-react';
import Link from 'next/link';
import { toast } from 'sonner';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function BlogPostPage({ params }: { params: Promise<{ slug: string; post: string }> }) {
  const { slug, post } = use(params);
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [postData, setPostData] = useState<any>(null);
  const [site, setSite] = useState<any>(null);
  const [allPosts, setAllPosts] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);

  useEffect(() => {
    Promise.all([
      fetch(`${API}/public/${slug}`).then(r => r.json()).then(d => setSite(d.site || null)).catch(() => {}),
      fetch(`${API}/public/${slug}/posts/${post}`).then(r => r.json()).then(d => setPostData(d.post || null)).catch(() => {}),
      fetch(`${API}/public/${slug}/posts`).then(r => r.json()).then(d => setAllPosts(d.posts || [])).catch(() => {}),
    ]).finally(() => setLoading(false));
  }, [slug, post]);

  const primary = site?.primary_color || '#0d9488';
  const dark = site?.secondary_color || '#0f172a';
  const accent = site?.accent_color || '#38bdf8';

  const readingTime = (content: string) => Math.max(1, Math.round((content || '').split(/\s+/).length / 200));

  const related = useMemo(() => {
    if (!postData) return [];
    return allPosts
      .filter((p) => p.id !== postData.id && (!postData.category || p.category === postData.category))
      .slice(0, 3);
  }, [allPosts, postData]);

  const copyLink = async () => {
    try {
      await navigator.clipboard.writeText(window.location.href);
      setCopied(true);
      toast.success(t('تم نسخ الرابط', 'Link copied'));
      setTimeout(() => setCopied(false), 2000);
    } catch { toast.error(t('تعذر النسخ', 'Cannot copy')); }
  };

  const share = (via: 'whatsapp' | 'telegram' | 'x') => {
    const url = encodeURIComponent(window.location.href);
    const text = encodeURIComponent(postData.title);
    if (via === 'whatsapp') window.open(`https://wa.me/?text=${text}%0A${url}`, '_blank');
    if (via === 'telegram') window.open(`https://t.me/share/url?url=${url}&text=${text}`, '_blank');
    if (via === 'x') window.open(`https://twitter.com/intent/tweet?url=${url}&text=${text}`, '_blank');
  };

  if (loading) return <div className="min-h-screen flex items-center justify-center"><Loader2 className="h-8 w-8 animate-spin" style={{ color: primary }} /></div>;

  if (!postData) {
    return (
      <div className="min-h-screen flex flex-col items-center justify-center gap-4 p-4">
        <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-gray-50 border border-gray-100"><Newspaper className="h-8 w-8 text-gray-300" /></div>
        <h1 className="text-2xl font-bold">{t('المقال غير موجود', 'Post not found')}</h1>
        <Link href={`/t/${slug}/blog`}><Button variant="outline" className="gap-1.5 rounded-full" style={{ color: primary, borderColor: `${primary}55` }}><ArrowLeft className="h-4 w-4" />{t('المدونة', 'Blog')}</Button></Link>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-white">
      {/* ─── Header ─────────────────────────────── */}
      <header className="sticky top-0 z-50 backdrop-blur-xl border-b border-gray-100 bg-white/90">
        <div className="max-w-4xl mx-auto px-4 h-16 flex items-center justify-between">
          <Link href={`/t/${slug}`} className="flex items-center gap-2.5">
            {site?.logo_url ? <img src={site.logo_url} className="h-9 w-9 rounded-xl object-cover" alt="" /> :
              <span className="flex h-9 w-9 items-center justify-center rounded-xl shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}><GraduationCap className="h-5 w-5 text-white" /></span>}
            <span className="font-bold text-lg">{site?.name || slug}</span>
          </Link>
          <div className="flex items-center gap-2">
            <Link href={`/t/${slug}/blog`}><Button variant="outline" size="sm" className="gap-1.5 rounded-full" style={{ borderRadius: '1rem', color: primary, borderColor: `${primary}55` }}><ChevronLeft className="h-3.5 w-3.5" />{t('المدونة', 'Blog')}</Button></Link>
            <button onClick={() => setMenuOpen(!menuOpen)} className="md:hidden flex h-9 w-9 items-center justify-center rounded-xl border border-gray-200 text-gray-600" aria-label="menu">
              {menuOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
            </button>
          </div>
        </div>
        {menuOpen && (
          <div className="md:hidden border-t border-gray-100 bg-white px-4 py-3 space-y-1 shadow-xl">
            {[
              { href: `/t/${slug}`, label: t('الرئيسية', 'Home') },
              { href: `/t/${slug}/blog`, label: t('المدونة', 'Blog') },
              { href: `/t/${slug}/register`, label: t('سجل الآن', 'Register') },
            ].map((l) => (
              <Link key={l.href} href={l.href} onClick={() => setMenuOpen(false)} className="block px-3 py-2.5 rounded-xl text-sm text-gray-700 hover:bg-gray-50">{l.label}</Link>
            ))}
          </div>
        )}
      </header>

      {/* ─── الغلاف ─────────────────────────────── */}
      <section className="relative overflow-hidden" style={{ background: `linear-gradient(135deg, ${dark} 0%, #101f2e 55%, ${dark} 100%)` }}>
        <div className="absolute inset-0 opacity-[0.05]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '28px 28px' }} />
        <div className="absolute -top-24 left-1/4 w-80 h-80 rounded-full blur-3xl opacity-15" style={{ background: primary }} />
        <div className="relative max-w-4xl mx-auto px-4 py-14 sm:py-20">
          <Link href={`/t/${slug}/blog`} className="inline-flex items-center gap-1.5 text-white/50 hover:text-white transition-colors text-sm mb-6">
            <ArrowLeft className="h-4 w-4" />{t('العودة للمدونة', 'Back to blog')}
          </Link>
          <div className="flex flex-wrap items-center gap-2 mb-5">
            {postData.category && (
              <span className="inline-flex items-center gap-1 rounded-full px-3.5 py-1.5 text-xs font-bold text-white" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                {postData.category}
              </span>
            )}
            {(postData.tags || []).slice(0, 3).map((tag: string) => (
              <span key={tag} className="inline-flex items-center gap-1 rounded-full glass px-3 py-1.5 text-[11px] text-white/70"><Tag className="h-3 w-3" />{tag}</span>
            ))}
          </div>
          <h1 className="text-3xl sm:text-5xl font-bold text-white leading-tight mb-6">{postData.title}</h1>
          {postData.excerpt && <p className="text-white/65 text-lg max-w-2xl leading-relaxed">{postData.excerpt}</p>}
          <div className="flex flex-wrap items-center gap-x-5 gap-y-2 mt-7 text-sm text-white/50">
            <span className="flex items-center gap-1.5"><Calendar className="h-4 w-4" style={{ color: accent }} />{postData.published_at ? new Date(postData.published_at).toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) : ''}</span>
            <span className="flex items-center gap-1.5"><Clock className="h-4 w-4" style={{ color: accent }} />{readingTime(postData.content)} {t('دقيقة قراءة', 'min read')}</span>
          </div>
        </div>
      </section>

      {/* ─── المحتوى ────────────────────────────── */}
      <main className="max-w-4xl mx-auto px-4 py-10">
        {postData.cover_image && (
          <div className="relative overflow-hidden rounded-3xl shadow-xl mb-10 -mt-6 z-10 border-4 border-white">
            <img src={postData.cover_image} className="w-full object-cover" style={{ maxHeight: 420 }} alt="" />
          </div>
        )}

        <article className="max-w-3xl mx-auto">
          <div className="text-lg leading-[2] text-gray-700 whitespace-pre-line">{postData.content}</div>

          {/* اقتباس ختامي */}
          <div className="mt-10 rounded-2xl p-6 flex items-start gap-4" style={{ backgroundColor: `${primary}0d` }}>
            <Quote className="h-8 w-8 shrink-0" style={{ color: primary }} />
            <p className="text-gray-600 leading-relaxed">
              {t('خالص التحية من معلمك — تعلم شيئاً جديداً كل يوم، ولو كان صفحة واحدة.', 'Best wishes from your teacher — learn something new every day, even one page.')}
            </p>
          </div>

          {/* المشاركة */}
          <div className="mt-8 flex flex-wrap items-center justify-between gap-4 pt-6 border-t">
            <div className="flex items-center gap-2 flex-wrap">
              <span className="text-sm font-bold text-gray-600">{t('شارك المقال:', 'Share:')}</span>
              <button onClick={() => share('whatsapp')} className="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 transition-transform hover:scale-110" aria-label="WhatsApp"><MessageCircle className="h-4 w-4" /></button>
              <button onClick={() => share('telegram')} className="flex h-9 w-9 items-center justify-center rounded-full bg-sky-500/10 text-sky-600 transition-transform hover:scale-110" aria-label="Telegram"><Send className="h-4 w-4" /></button>
              <button onClick={() => share('x')} className="flex h-9 w-9 items-center justify-center rounded-full bg-slate-500/10 text-slate-600 transition-transform hover:scale-110" aria-label="X"><Twitter className="h-4 w-4" /></button>
            </div>
            <button onClick={copyLink} className="inline-flex items-center gap-1.5 rounded-full border px-4 py-2 text-xs font-bold transition-all hover:shadow-sm" style={{ borderColor: `${primary}44`, color: primary }}>
              {copied ? <Check className="h-3.5 w-3.5 text-emerald-500" /> : <Link2 className="h-3.5 w-3.5" />}
              {copied ? t('تم النسخ', 'Copied!') : t('نسخ الرابط', 'Copy link')}
            </button>
          </div>
        </article>

        {/* مقالات ذات صلة */}
        {related.length > 0 && (
          <section className="mt-14">
            <h2 className="text-2xl font-bold mb-6 flex items-center gap-2"><Sparkles className="h-5 w-5" style={{ color: primary }} />{t('مقالات ذات صلة', 'Related articles')}</h2>
            <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
              {related.map((p) => (
                <Link key={p.id} href={`/t/${slug}/blog/${p.slug}`} className="group">
                  <div className="overflow-hidden rounded-2xl bg-white border border-gray-100 shadow-sm card-hover h-full flex flex-col">
                    <div className="h-32 relative flex items-center justify-center overflow-hidden" style={{ background: `linear-gradient(135deg, ${primary}22, ${accent}18)` }}>
                      {p.cover_image ? <img src={p.cover_image} className="absolute inset-0 w-full h-full object-cover transition-transform duration-500 group-hover:scale-105" alt="" /> : <Newspaper className="h-8 w-8 opacity-40" style={{ color: primary }} />}
                    </div>
                    <div className="p-4 flex-1 flex flex-col">
                      <h3 className="font-bold text-sm leading-snug line-clamp-2 group-hover:underline decoration-2 underline-offset-4">{p.title}</h3>
                      <p className="flex items-center gap-1.5 text-[11px] text-gray-400 mt-2 pt-2 border-t"><Clock className="h-3 w-3" style={{ color: primary }} />{readingTime(p.content)} {t('دقيقة', 'min')}</p>
                    </div>
                  </div>
                </Link>
              ))}
            </div>
          </section>
        )}

        {/* CTA */}
        <section className="mt-14">
          <div className="relative overflow-hidden rounded-[2rem] px-6 py-12 text-center shadow-xl" style={{ background: `linear-gradient(135deg, ${dark} 0%, #101f2e 60%, ${dark} 100%)` }}>
            <div className="absolute -top-16 left-1/4 w-64 h-64 rounded-full blur-3xl opacity-20" style={{ background: primary }} />
            <div className="absolute -bottom-16 right-1/4 w-64 h-64 rounded-full blur-3xl opacity-20" style={{ background: accent }} />
            <div className="relative z-10">
              <BookOpen className="mx-auto h-10 w-10 mb-4" style={{ color: accent }} />
              <h2 className="text-2xl font-bold text-white mb-3">{t('أعجبك هذا المقال؟', 'Enjoyed this article?')}</h2>
              <p className="text-white/60 text-sm mb-6 max-w-sm mx-auto">{t('اكتشف الكورسات الكاملة وابدأ رحلتك التعليمية', 'Explore the full courses and start your learning journey')}</p>
              <Link href={`/t/${slug}#courses`}>
                <Button size="lg" className="h-13 px-10 rounded-full text-white text-base shadow-xl hover:scale-105 transition-transform" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                  {t('تصفح الكورسات', 'Browse courses')}
                </Button>
              </Link>
            </div>
          </div>
        </section>
      </main>

      {/* ─── Footer ─────────────────────────────── */}
      <footer className="py-10" style={{ background: dark }}>
        <div className="max-w-4xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-4">
          <div className="flex items-center gap-2.5">
            {site?.logo_url ? <img src={site.logo_url} className="h-8 w-8 rounded-lg object-cover" alt="" /> :
              <span className="flex h-8 w-8 items-center justify-center rounded-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}><GraduationCap className="h-4 w-4 text-white" /></span>}
            <span className="font-bold text-white text-sm">{site?.name || slug}</span>
          </div>
          <div className="flex items-center gap-5 text-white/40 text-xs">
            <Link href={`/t/${slug}`} className="hover:text-white transition-colors">{t('الرئيسية', 'Home')}</Link>
            <Link href={`/t/${slug}/blog`} className="hover:text-white transition-colors">{t('المدونة', 'Blog')}</Link>
            <Link href={`/t/${slug}/register`} className="hover:text-white transition-colors">{t('سجل الآن', 'Register')}</Link>
            <Link href="/terms" className="hover:text-white transition-colors">{t('الشروط', 'Terms')}</Link>
            <Link href="/privacy" className="hover:text-white transition-colors">{t('الخصوصية', 'Privacy')}</Link>
            <Link href="/refund-policy" className="hover:text-white transition-colors">{t('استرداد الأموال', 'Refunds')}</Link>
            <Link href="/contact" className="hover:text-white transition-colors">{t('اتصل بنا', 'Contact')}</Link>
          </div>
          <p className="text-white/30 text-xs">© {new Date().getFullYear()} {site?.name || slug}</p>
        </div>
      </footer>
    </div>
  );
}
