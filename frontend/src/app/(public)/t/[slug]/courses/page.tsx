'use client';

import { useEffect, useState } from 'react';
import { use } from 'react';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { BookOpen, ArrowLeft, GraduationCap } from 'lucide-react';
import Link from 'next/link';
import { CourseCard, BundleCard, useEnrolledCourseIds } from '@/components/site/storefront';
import { useTenantBasePath } from '@/lib/tenant-host';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

/**
 * Public course catalogue for a teacher's site. Uses the same course card and
 * the teacher's own colors/fonts as the landing page, and only ever shows
 * real data.
 */
export default function TeacherCoursesPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = use(params);
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const base = useTenantBasePath(slug);

  const [site, setSite] = useState<any>(null);
  const [courses, setCourses] = useState<any[]>([]);
  const [bundles, setBundles] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [loggedIn, setLoggedIn] = useState(false);
  const enrolledIds = useEnrolledCourseIds(loggedIn);

  useEffect(() => {
    setLoggedIn(!!localStorage.getItem('lms_authed'));
    Promise.all([
      fetch(`${API}/public/${slug}`).then((r) => r.json()).then((d) => setSite(d.site || null)).catch(() => {}),
      fetch(`${API}/public/${slug}/courses`).then((r) => r.json()).then((d) => setCourses(d.courses || [])).catch(() => {}),
      fetch(`${API}/public/${slug}/bundles`).then((r) => r.json()).then((d) => setBundles(d.bundles || [])).catch(() => {}),
    ]).finally(() => setLoading(false));
  }, [slug]);

  const primary = site?.primary_color || '#0d9488';
  const accent = site?.accent_color || '#14b8a6';
  const headingFont = "'Tajawal', sans-serif";

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="sticky top-0 z-40 border-b border-slate-100 bg-white/95 backdrop-blur-xl">
        <div className="mx-auto flex h-16 max-w-7xl items-center justify-between gap-3 px-4">
          <Link href={base || '/'} className="flex min-w-0 items-center gap-2.5">
            {site?.logo_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={site.logo_url} className="h-9 w-9 rounded-xl object-cover" alt="" />
            ) : (
              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                <GraduationCap className="h-5 w-5 text-white" />
              </div>
            )}
            <span className="truncate text-base font-bold sm:text-lg">{site?.name || slug}</span>
          </Link>
          <Link href={loggedIn ? '/my-learning' : `/student/login?tenant_slug=${slug}`}>
            <Button size="sm" className="min-h-[40px] rounded-full text-white shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
              {loggedIn ? t('كورساتي', 'My courses') : t('تسجيل دخول', 'Login')}
            </Button>
          </Link>
        </div>
      </header>

      <main className="mx-auto max-w-7xl px-4 py-8 sm:py-12">
        <Link href={base || '/'} className="mb-6 inline-flex min-h-[44px] items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
          <ArrowLeft className="h-4 w-4" /> {t('الرئيسية', 'Home')}
        </Link>

        <div className="mb-8">
          <h1 className="text-2xl font-bold sm:text-3xl" style={{ fontFamily: headingFont }}>{t('جميع الكورسات', 'All courses')}</h1>
          {!loading && courses.length > 0 && (
            <p className="mt-2 text-sm text-slate-500">
              {courses.length} {t('كورس متاح', 'courses available')}
            </p>
          )}
        </div>

        {!loading && bundles.length > 0 && (
          <section className="mb-12" data-testid="bundles-section">
            <h2 className="mb-4 text-xl font-bold sm:text-2xl" style={{ fontFamily: headingFont }}>
              {t('حزم الكورسات', 'Course bundles')}
            </h2>
            <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
              {bundles.map((b) => (
                <BundleCard
                  key={b.id}
                  bundle={b}
                  slug={slug}
                  primary={primary}
                  accent={accent}
                  radius="1rem"
                  headingFont={headingFont}
                  whatsapp={site?.whatsapp}
                />
              ))}
            </div>
          </section>
        )}

        {loading ? (
          <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {[0, 1, 2].map((i) => (
              <div key={i} className="space-y-3 rounded-3xl border border-slate-100 bg-white p-5">
                <Skeleton className="h-36 w-full rounded-2xl" />
                <Skeleton className="h-4 w-2/3" />
                <Skeleton className="h-3 w-1/2" />
              </div>
            ))}
          </div>
        ) : courses.length === 0 ? (
          <div className="mx-auto flex max-w-md flex-col items-center gap-3 rounded-3xl border border-dashed border-slate-200 bg-white px-6 py-16 text-center">
            <BookOpen className="h-12 w-12 text-slate-300" />
            <p className="text-lg font-bold">{t('لا توجد كورسات منشورة بعد', 'No published courses yet')}</p>
            <p className="text-sm text-slate-500">{t('تابعنا — سيظهر أول كورس هنا فور نشره.', 'Check back soon — courses will appear here once published.')}</p>
          </div>
        ) : (
          <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {courses.map((c) => (
              <CourseCard
                key={c.id}
                course={c}
                slug={slug}
                primary={primary}
                accent={accent}
                radius="1rem"
                headingFont={headingFont}
                enrolled={enrolledIds.has(String(c.id))}
              />
            ))}
          </div>
        )}
      </main>
    </div>
  );
}
