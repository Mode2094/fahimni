'use client';

import { useEffect, useMemo, useState } from 'react';
import { use } from 'react';
import { useLocale } from '@/store/locale';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Newspaper, Loader2, ArrowLeft, Calendar, Clock, Search, Tag, BookOpen,
  Sparkles, ArrowLeftCircle, ChevronLeft, GraduationCap, Menu, X, Users,
} from 'lucide-react';
import Link from 'next/link';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

export default function BlogListPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = use(params);
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [posts, setPosts] = useState<any[]>([]);
  const [site, setSite] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [category, setCategory] = useState('all');
  const [search, setSearch] = useState('');
  const [menuOpen, setMenuOpen] = useState(false);

  useEffect(() => {
    Promise.all([
      fetch(`${API}/public/${slug}`).then(r => r.json()).then(d => setSite(d.site || null)).catch(() => {}),
      fetch(`${API}/public/${slug}/posts`).then(r => r.json()).then(d => setPosts(d.posts || [])).catch(() => {}),
    ]).finally(() => setLoading(false));
  }, [slug]);

  const primary = site?.primary_color || '#0d9488';
  const dark = site?.secondary_color || '#0f172a';
  const accent = site?.accent_color || '#38bdf8';

  const categories = useMemo(() => {
    const set = new Set<string>();
    posts.forEach((p) => { if (p.category) set.add(p.category); });
    return Array.from(set);
  }, [posts]);

  const readingTime = (content: string) => Math.max(1, Math.round((content || '').split(/\s+/).length / 200));

  const filtered = posts.filter((p) => {
    if (category !== 'all' && p.category !== category) return false;
    if (search.trim()) {
      const q = search.trim().toLowerCase();
      if (!`${p.title} ${p.excerpt} ${(p.tags || []).join(' ')}`.toLowerCase().includes(q)) return false;
    }
    return true;
  });

  const featured = posts[0];
  const rest = filtered.slice(featured ? 1 : 0);

  if (loading) return <div className="min-h-screen flex items-center justify-center"><Loader2 className="h-8 w-8 animate-spin" style={{ color: primary }} /></div>;

  return (
    <div className="min-h-screen bg-white">
      {/* ─── Header ─────────────────────────────── */}
      <header className="sticky top-0 z-50 backdrop-blur-xl border-b border-gray-100 bg-white/90">
        <div className="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
          <Link href={`/t/${slug}`} className="flex items-center gap-2.5">
            {site?.logo_url ? <img src={site.logo_url} className="h-9 w-9 rounded-xl object-cover" alt="" /> :
              <span className="flex h-9 w-9 items-center justify-center rounded-xl shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}><GraduationCap className="h-5 w-5 text-white" /></span>}
            <span className="font-bold text-lg">{site?.name || slug}</span>
          </Link>
          <nav className="hidden md:flex items-center gap-6 text-sm font-medium">
            <Link href={`/t/${slug}`} className="text-gray-600 hover:text-gray-900 transition-colors">{t('الرئيسية', 'Home')}</Link>
            <Link href={`/t/${slug}/blog`} className="font-bold" style={{ color: primary }}>{t('المدونة', 'Blog')}</Link>
            <Link href={`/t/${slug}/register`} className="text-gray-600 hover:text-gray-900 transition-colors">{t('سجل الآن', 'Register')}</Link>
          </nav>
          <div className="flex items-center gap-2">
            <Link href={`/t/${slug}`}><Button variant="outline" size="sm" className="gap-1.5 rounded-full hidden sm:inline-flex" style={{ borderRadius: '1rem', color: primary, borderColor: `${primary}55` }}><ArrowLeft className="h-3.5 w-3.5" />{t('الرئيسية', 'Home')}</Button></Link>
            <button onClick={() => setMenuOpen(!menuOpen)} className="md:hidden flex h-9 w-9 items-center justify-center rounded-xl border border-gray-200 text-gray-600" aria-label="menu">
              {menuOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
            </button>
          </div>
        </div>
        {menuOpen && (
          <div className="md:hidden border-t border-gray-100 bg-white px-4 py-3 space-y-1 shadow-xl">
            {[
              { href: `/t/${slug}`, label: t('الرئيسية', 'Home') },
              { href: `/t/${slug}/register`, label: t('سجل الآن', 'Register') },
            ].map((l) => (
              <Link key={l.href} href={l.href} onClick={() => setMenuOpen(false)} className="block px-3 py-2.5 rounded-xl text-sm text-gray-700 hover:bg-gray-50">{l.label}</Link>
            ))}
          </div>
        )}
      </header>

      {/* ─── Banner ─────────────────────────────── */}
      <section className="relative overflow-hidden" style={{ background: `linear-gradient(135deg, ${dark} 0%, #101f2e 55%, ${dark} 100%)` }}>
        <div className="absolute inset-0 opacity-[0.05]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #fff 1px, transparent 0)', backgroundSize: '28px 28px' }} />
        <div className="absolute -top-24 right-1/4 w-80 h-80 rounded-full blur-3xl opacity-15" style={{ background: primary }} />
        <div className="relative max-w-6xl mx-auto px-4 py-16 sm:py-20 text-center">
          <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full glass text-white/80 text-sm mb-5"><Newspaper className="h-4 w-4" style={{ color: accent }} />{t('المدونة', 'Blog')}</div>
          <h1 className="text-4xl sm:text-5xl font-bold text-white mb-4">{t('أحدث المقالات', 'Latest articles')}</h1>
          <p className="text-white/60 max-w-xl mx-auto">{t('معلومات مفيدة ونصائح عملية من معلمك — اقرأ وتعلم في أي وقت', 'Useful tips from your teacher — read and learn anytime')}</p>
          <div className="relative max-w-md mx-auto mt-8">
            <Search className="absolute start-4 top-1/2 -translate-y-1/2 h-4 w-4 text-white/40" />
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t('ابحث في المقالات...', 'Search articles...')}
              className="w-full h-12 rounded-full glass ps-11 pe-5 text-white placeholder:text-white/40 text-sm focus:outline-none focus:ring-2"
              style={{ ['--tw-ring-color' as any]: accent }}
            />
          </div>
        </div>
      </section>

      <main className="max-w-6xl mx-auto px-4 py-12">
        {/* التصنيفات */}
        {categories.length > 0 && (
          <div className="flex flex-wrap gap-2 mb-10 justify-center">
            <button onClick={() => setCategory('all')} className={`px-4 py-2 rounded-full text-sm font-bold transition-all ${category === 'all' ? 'text-white shadow-lg' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'}`} style={category === 'all' ? { background: `linear-gradient(135deg, ${primary}, ${accent})` } : undefined}>
              {t('الكل', 'All')}
            </button>
            {categories.map((c) => (
              <button key={c} onClick={() => setCategory(c)} className={`px-4 py-2 rounded-full text-sm font-bold transition-all ${category === c ? 'text-white shadow-lg' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'}`} style={category === c ? { background: `linear-gradient(135deg, ${primary}, ${accent})` } : undefined}>
                {c}
              </button>
            ))}
          </div>
        )}

        {filtered.length === 0 ? (
          <div className="text-center py-16">
            <div className="mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-3xl bg-gray-50 border border-gray-100">
              <Newspaper className="h-10 w-10 text-gray-300" />
            </div>
            <h3 className="text-xl font-bold text-gray-700">{t('لا توجد مقالات بعد', 'No posts yet')}</h3>
            <p className="text-gray-400 text-sm mt-2 max-w-sm mx-auto">
              {t('المعلم لم ينشر أي مقال حتى الآن — عُد لاحقاً أو اكتشف الكورسات', 'Your teacher has not published any articles yet — check back later or explore the courses')}
            </p>
            <Link href={`/t/${slug}#courses`} className="inline-block mt-6">
              <Button className="gap-2 rounded-full text-white shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                <BookOpen className="h-4 w-4" /> {t('تصفح الكورسات', 'Browse courses')}
              </Button>
            </Link>
          </div>
        ) : (
          <>
            {/* المقال المميز */}
            {featured && !search && category === 'all' && (
              <Link href={`/t/${slug}/blog/${featured.slug}`} className="group block mb-12">
                <div className="grid lg:grid-cols-2 gap-0 overflow-hidden rounded-[2rem] shadow-xl bg-white border border-gray-100 transition-all hover:shadow-2xl">
                  <div className="relative h-64 lg:h-auto overflow-hidden" style={{ background: `linear-gradient(135deg, ${primary}22, ${accent}18)` }}>
                    {featured.cover_image ? <img src={featured.cover_image} className="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" alt="" /> : (
                      <div className="absolute inset-0 flex items-center justify-center">
                        <div className="absolute inset-0 opacity-[0.07]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #0d9488 1px, transparent 0)', backgroundSize: '22px 22px' }} />
                        <Newspaper className="h-20 w-20 opacity-40 transition-transform duration-500 group-hover:scale-110" style={{ color: primary }} />
                      </div>
                    )}
                    <span className="absolute top-4 start-4 inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-[10px] font-black text-white shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                      <Sparkles className="h-3 w-3" />{t('أحدث مقال', 'Latest post')}
                    </span>
                  </div>
                  <div className="p-8 lg:p-10 flex flex-col justify-center">
                    {featured.category && <Badge className="w-fit mb-4 rounded-full" style={{ backgroundColor: `${primary}12`, color: primary }}>{featured.category}</Badge>}
                    <h2 className="text-2xl sm:text-3xl font-bold leading-snug group-hover:underline decoration-2 underline-offset-8 transition-colors">{featured.title}</h2>
                    {featured.excerpt && <p className="text-gray-500 mt-3 leading-relaxed line-clamp-3">{featured.excerpt}</p>}
                    <div className="flex items-center gap-4 mt-5 text-xs text-gray-400">
                      <span className="flex items-center gap-1.5"><Calendar className="h-3.5 w-3.5" style={{ color: primary }} />{featured.published_at ? new Date(featured.published_at).toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US', { day: 'numeric', month: 'long', year: 'numeric' }) : ''}</span>
                      <span className="flex items-center gap-1.5"><Clock className="h-3.5 w-3.5" style={{ color: primary }} />{readingTime(featured.content)} {t('دقيقة', 'min')}</span>
                    </div>
                    <span className="inline-flex items-center gap-2 mt-6 font-bold text-sm" style={{ color: primary }}>
                      {t('اقرأ المقال', 'Read article')} <ArrowLeftCircle className="h-4.5 w-4.5 transition-transform group-hover:-translate-x-1" />
                    </span>
                  </div>
                </div>
              </Link>
            )}

            {/* بقية المقالات */}
            <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
              {rest.map((p) => (
                <Link key={p.id} href={`/t/${slug}/blog/${p.slug}`} className="group">
                  <div className="border-0 shadow-sm card-hover overflow-hidden h-full bg-white border border-gray-100 rounded-[1.5rem] flex flex-col">
                    <div className="h-44 relative overflow-hidden flex items-center justify-center" style={{ background: `linear-gradient(135deg, ${primary}22, ${accent}18)` }}>
                      {p.cover_image ? <img src={p.cover_image} className="absolute inset-0 w-full h-full object-cover transition-transform duration-500 group-hover:scale-105" alt="" /> : (
                        <>
                          <div className="absolute inset-0 opacity-[0.07]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, #0d9488 1px, transparent 0)', backgroundSize: '20px 20px' }} />
                          <Newspaper className="h-12 w-12 opacity-40 transition-transform duration-500 group-hover:scale-110" style={{ color: primary }} />
                        </>
                      )}
                      {p.category && <span className="absolute top-3 start-3 rounded-full bg-white/90 backdrop-blur px-3 py-1 text-[10px] font-bold" style={{ color: primary }}>{p.category}</span>}
                    </div>
                    <div className="p-5 flex-1 flex flex-col">
                      <h3 className="font-bold leading-snug line-clamp-2 group-hover:underline decoration-2 underline-offset-4 transition-colors">{p.title}</h3>
                      {p.excerpt && <p className="text-sm text-gray-500 mt-2 line-clamp-2 flex-1">{p.excerpt}</p>}
                      <div className="flex items-center gap-3 mt-4 pt-4 border-t text-[11px] text-gray-400">
                        <span className="flex items-center gap-1"><Calendar className="h-3 w-3" style={{ color: primary }} />{p.published_at ? new Date(p.published_at).toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US', { day: 'numeric', month: 'short' }) : ''}</span>
                        <span className="flex items-center gap-1"><Clock className="h-3 w-3" style={{ color: primary }} />{readingTime(p.content)} {t('دقيقة', 'min')}</span>
                        {(p.tags || []).length > 0 && <span className="ms-auto flex items-center gap-1"><Tag className="h-3 w-3" />{(p.tags || []).length}</span>}
                      </div>
                    </div>
                  </div>
                </Link>
              ))}
            </div>

            {/* إحصائية */}
            <div className="mt-12 flex flex-wrap items-center justify-center gap-x-10 gap-y-3 text-center">
              <div><p className="text-2xl font-black" style={{ color: primary }}>{filtered.length}</p><p className="text-xs text-gray-400">{t('مقال', 'articles')}</p></div>
              <div><p className="text-2xl font-black" style={{ color: primary }}>{filtered.reduce((a, p) => a + readingTime(p.content), 0)}</p><p className="text-xs text-gray-400">{t('دقيقة قراءة', 'min of reading')}</p></div>
              <div><p className="text-2xl font-black" style={{ color: primary }}>{categories.length}</p><p className="text-xs text-gray-400">{t('تصنيف', 'categories')}</p></div>
            </div>
          </>
        )}
      </main>

      {/* ─── CTA ────────────────────────────────── */}
      <section className="px-4 pb-14">
        <div className="max-w-5xl mx-auto relative overflow-hidden rounded-[2rem] px-6 py-12 text-center shadow-xl" style={{ background: `linear-gradient(135deg, ${dark} 0%, #101f2e 60%, ${dark} 100%)` }}>
          <div className="absolute -top-16 left-1/4 w-64 h-64 rounded-full blur-3xl opacity-20" style={{ background: primary }} />
          <div className="absolute -bottom-16 right-1/4 w-64 h-64 rounded-full blur-3xl opacity-20" style={{ background: accent }} />
          <div className="relative z-10">
            <h2 className="text-2xl sm:text-3xl font-bold text-white mb-3">{t('جاهز تبدأ رحلتك التعليمية؟', 'Ready to start learning?')}</h2>
            <p className="text-white/60 text-sm mb-6">{t('سجل الآن ووصلك كل جديد من معلمك', 'Register now to get the latest from your teacher')}</p>
            <Link href={`/t/${slug}/register`}>
              <Button size="lg" className="h-13 px-10 rounded-full text-white text-base shadow-xl hover:scale-105 transition-transform" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                {t('سجل الآن', 'Register now')} <Users className="h-5 w-5" />
              </Button>
            </Link>
          </div>
        </div>
      </section>

      {/* ─── Footer ─────────────────────────────── */}
      <footer className="py-10" style={{ background: dark }}>
        <div className="max-w-6xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-4">
          <div className="flex items-center gap-2.5">
            {site?.logo_url ? <img src={site.logo_url} className="h-8 w-8 rounded-lg object-cover" alt="" /> :
              <span className="flex h-8 w-8 items-center justify-center rounded-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}><GraduationCap className="h-4 w-4 text-white" /></span>}
            <span className="font-bold text-white text-sm">{site?.name || slug}</span>
          </div>
          <div className="flex items-center gap-5 text-white/40 text-xs">
            <Link href={`/t/${slug}`} className="hover:text-white transition-colors">{t('الرئيسية', 'Home')}</Link>
            <Link href={`/t/${slug}#courses`} className="hover:text-white transition-colors">{t('الكورسات', 'Courses')}</Link>
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
