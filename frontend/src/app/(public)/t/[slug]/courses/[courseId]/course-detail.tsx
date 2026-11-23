'use client';

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useLocale } from '@/store/locale';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from 'sonner';
import ProtectedVideoPlayer from '@/components/video/protected-video-player';
import {
  StorefrontHeader, StorefrontFooter, SectionHeading, ActivationPanel,
  TrustRow, SECTION_PAD, useEnrolledCourseIds,
} from '@/components/site/storefront';
import {
  BookOpen, Clock, Layers, Tag, PlayCircle, Lock, ArrowLeft, CheckCircle2,
  GraduationCap, MessageCircle, Loader2, X, ChevronDown, ShieldCheck,
} from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

type Lesson = {
  id: string;
  title: string;
  lesson_type?: string;
  duration_minutes?: number;
  is_free_preview?: boolean;
  locked?: boolean;
  position?: number;
};

type Section = { id: string; title: string; lessons: Lesson[] };

/** Build an official wa.me link (the user starts the chat themselves). */
function whatsappLink(number: string, message: string): string {
  return `https://wa.me/${String(number).replace(/[^\d]/g, '')}?text=${encodeURIComponent(message)}`;
}

export default function CourseDetail({
  slug, courseId, initialSite,
}: {
  slug: string;
  courseId: string;
  initialSite?: any;
}) {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);

  const [site, setSite] = useState<any>(initialSite ?? null);
  const [course, setCourse] = useState<any>(null);
  const [sections, setSections] = useState<Section[]>([]);
  const [loading, setLoading] = useState(true);
  const [missing, setMissing] = useState(false);
  const [loggedIn, setLoggedIn] = useState(false);
  const [openSections, setOpenSections] = useState<Record<string, boolean>>({});

  const [cardCode, setCardCode] = useState('');
  const [redeeming, setRedeeming] = useState(false);
  const [redeemedMsg, setRedeemedMsg] = useState('');
  const [cardError, setCardError] = useState('');

  const [preview, setPreview] = useState<{ lesson: Lesson; data: any; loading: boolean; error?: string } | null>(null);

  const enrolledIds = useEnrolledCourseIds(loggedIn);
  const enrolled = enrolledIds.has(String(courseId));

  useEffect(() => {
    setLoggedIn(!!localStorage.getItem('lms_authed'));
  }, []);

  useEffect(() => {
    let alive = true;
    setLoading(true);
    Promise.all([
      site ? Promise.resolve(null) : fetch(`${API}/public/${slug}`).then((r) => (r.ok ? r.json() : null)).then((d) => { if (alive && d?.site) setSite(d.site); }).catch(() => {}),
      fetch(`${API}/public/${slug}/courses/${courseId}`, { headers: { Accept: 'application/json' } })
        .then(async (r) => {
          if (!alive) return;
          if (!r.ok) { setMissing(true); return; }
          const d = await r.json();
          setCourse(d.course || null);
          const secs: Section[] = Array.isArray(d.sections) ? d.sections : [];
          setSections(secs);
          // Open the first section by default.
          setOpenSections(secs[0] ? { [secs[0].id]: true } : {});
        })
        .catch(() => { if (alive) setMissing(true); }),
    ]).finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [slug, courseId, site]);

  const cfg = site?.site_config || {};
  const primary = site?.primary_color || '#0d9488';
  const accent = site?.accent_color || '#14b8a6';
  const dark = site?.secondary_color || '#0f172a';
  const headingFont = "'Tajawal', sans-serif";

  const cover = course?.cover_url || course?.thumbnail_url || null;
  const hasPrice = Number(course?.price) > 0;
  const lessonTotal = useMemo(
    () => sections.reduce((sum, s) => sum + (Array.isArray(s.lessons) ? s.lessons.length : 0), 0),
    [sections],
  );

  const teacherWhatsapp = cfg.contact_whatsapp || null;
  const teacherName = site?.name || t('المعلم', 'the teacher');
  const courseUrl = typeof window !== 'undefined'
    ? `${window.location.origin}/t/${slug}/courses/${courseId}`
    : '';

  const waMessage = t(
    `مرحباً ${teacherName}، أرغب في الحصول على بطاقة تسجيل لكورس «${course?.title || ''}».\n${courseUrl}`,
    `Hello ${teacherName}, I would like an activation card for the course "${course?.title || ''}".\n${courseUrl}`,
  );

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
        headers: {
          'Content-Type': 'application/json',
          ...(tid ? { 'X-Tenant-Id': tid } : {}),
          'X-Device-Id': localStorage.getItem('lms_device_id') || '',
        },
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
      const msg = t('تعذّر الاتصال بالخادم، حاول مرة أخرى.', 'Could not reach the server, please try again.');
      setCardError(msg);
      toast.error(msg);
    }
    setRedeeming(false);
  };

  const openPreview = async (lesson: Lesson) => {
    setPreview({ lesson, data: null, loading: true });
    try {
      const r = await fetch(`${API}/public/courses/${courseId}/lessons/${lesson.id}`, { headers: { Accept: 'application/json' } });
      const d = await r.json().catch(() => ({}));
      if (!r.ok) {
        setPreview({ lesson, data: null, loading: false, error: d?.message || t('تعذّر تحميل المعاينة.', 'Could not load the preview.') });
        return;
      }
      setPreview({ lesson, data: d, loading: false });
    } catch {
      setPreview({ lesson, data: null, loading: false, error: t('تعذّر الاتصال بالخادم.', 'Could not reach the server.') });
    }
  };

  const previewVideo = useMemo(() => {
    const contents = Array.isArray(preview?.data?.contents) ? preview.data.contents : [];
    return contents.find((c: any) => c?.video)?.video ?? null;
  }, [preview?.data]);

  const previewText = useMemo(() => {
    const contents = Array.isArray(preview?.data?.contents) ? preview.data.contents : [];
    return contents.find((c: any) => c?.text)?.text?.body ?? null;
  }, [preview?.data]);

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50">
        <div className="mx-auto max-w-5xl space-y-5 px-4 py-24">
          <Skeleton className="h-8 w-2/3" />
          <Skeleton className="h-64 w-full rounded-3xl" />
          <Skeleton className="h-4 w-1/2" />
        </div>
      </div>
    );
  }

  if (missing || !course) {
    return (
      <div className="min-h-screen bg-slate-50">
        <StorefrontHeader
          site={site} slug={slug} primary={primary} accent={accent} radius="1rem"
          font={{ heading: headingFont, body: headingFont }} loggedIn={loggedIn}
          links={[{ href: `/t/${slug}/courses`, label: t('كل الكورسات', 'All courses') }]}
        />
        <div className="mx-auto flex max-w-md flex-col items-center gap-3 px-4 pt-32 text-center">
          <BookOpen className="h-12 w-12 text-slate-300" />
          <p className="text-lg font-bold">{t('هذه الدورة غير متوفرة', 'This course is not available')}</p>
          <p className="text-sm text-slate-500">{t('ربما تم حذفها أو إلغاء نشرها.', 'It may have been removed or unpublished.')}</p>
          <Link href={`/t/${slug}/courses`} className="mt-2">
            <Button variant="outline">{t('تصفّح الكورسات المتاحة', 'Browse available courses')}</Button>
          </Link>
        </div>
      </div>
    );
  }

  const navLinks = [
    { href: `/t/${slug}`, label: t('الرئيسية', 'Home') },
    { href: `/t/${slug}/courses`, label: t('كل الكورسات', 'All courses') },
    ...(teacherWhatsapp ? [{ href: 'https://wa.me/' + String(teacherWhatsapp).replace(/[^\d]/g, ''), label: t('تواصل', 'Contact') }] : []),
  ];

  return (
    <div className="min-h-screen bg-slate-50" style={{ fontFamily: headingFont }}>
      <StorefrontHeader
        site={site} slug={slug} primary={primary} accent={accent} radius="1rem"
        font={{ heading: headingFont, body: headingFont }} loggedIn={loggedIn} links={navLinks}
      />

      <main className="pt-16" id="main">
        {/* ── Hero ── */}
        <section className="relative overflow-hidden border-b border-slate-100 bg-white">
          <div className="mx-auto grid max-w-6xl gap-8 px-4 py-8 sm:py-12 lg:grid-cols-[1.15fr_1fr] lg:gap-12">
            <div className="order-2 lg:order-1">
              <Link href={`/t/${slug}/courses`} className="mb-5 inline-flex min-h-[44px] items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
                <ArrowLeft className="h-4 w-4" /> {t('كل الكورسات', 'All courses')}
              </Link>
              <h1 className="text-2xl font-bold leading-snug sm:text-4xl" style={{ fontFamily: headingFont }}>{course.title}</h1>
              {course.short_description && (
                <p className="mt-3 text-sm leading-relaxed text-slate-500 sm:text-base">{course.short_description}</p>
              )}

              <div className="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-slate-600">
                {Number(course.duration_hours) > 0 && (
                  <span className="inline-flex items-center gap-1.5"><Clock className="h-4 w-4" style={{ color: primary }} />{course.duration_hours} {t('ساعة', 'hours')}</span>
                )}
                <span className="inline-flex items-center gap-1.5"><Layers className="h-4 w-4" style={{ color: primary }} />{sections.length} {t('وحدة', 'units')}</span>
                {lessonTotal > 0 && (
                  <span className="inline-flex items-center gap-1.5"><BookOpen className="h-4 w-4" style={{ color: primary }} />{lessonTotal} {t('درس', 'lessons')}</span>
                )}
                {course.level && (
                  <span className="inline-flex items-center gap-1.5"><GraduationCap className="h-4 w-4" style={{ color: primary }} />{course.level}</span>
                )}
              </div>

              <div className="mt-6 flex flex-wrap items-center gap-3">
                {enrolled ? (
                  <Link href={`/course/${courseId}/learn`}>
                    <Button size="lg" className="min-h-[48px] gap-2 rounded-full px-7 text-white shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                      <PlayCircle className="h-5 w-5" /> {t('ابدأ التعلم', 'Start learning')}
                    </Button>
                  </Link>
                ) : (
                  <>
                    <a href="#card">
                      <Button size="lg" className="min-h-[48px] gap-2 rounded-full px-7 text-white shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                        <Tag className="h-5 w-5" /> {t('تفعيل بطاقة', 'Activate a card')}
                      </Button>
                    </a>
                    {teacherWhatsapp ? (
                      <a href={whatsappLink(teacherWhatsapp, waMessage)} target="_blank" rel="noreferrer">
                        <Button size="lg" variant="outline" className="min-h-[48px] gap-2 rounded-full px-6" style={{ color: primary, borderColor: `${primary}55` }}>
                          <MessageCircle className="h-5 w-5" /> {t('اطلب بطاقة عبر واتساب', 'Request a card on WhatsApp')}
                        </Button>
                      </a>
                    ) : (
                      <Link href={`/t/${slug}/register?course=${courseId}`}>
                        <Button size="lg" variant="outline" className="min-h-[48px] gap-2 rounded-full px-6" style={{ color: primary, borderColor: `${primary}55` }}>
                          {t('اطلب الاشتراك', 'Request enrollment')}
                        </Button>
                      </Link>
                    )}
                  </>
                )}
                {hasPrice && (
                  <span className="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-base font-bold text-white shadow" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                    {course.price} {course.currency}
                  </span>
                )}
              </div>

              {!teacherWhatsapp && !enrolled && (
                <p className="mt-4 text-xs text-slate-400">
                  {t('لم يُضف المعلم رقم واتساب بعد — يمكنك التسجيل مباشرة أو التواصل عبر صفحة الدورة.', 'The teacher has not added a WhatsApp number yet — you can register directly or use the course page.')}
                </p>
              )}
            </div>

            <div className="order-1 lg:order-2">
              <div className="relative aspect-video overflow-hidden rounded-3xl shadow-xl" style={{ background: `linear-gradient(135deg, ${primary}22, ${accent}18)` }}>
                {cover ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={cover} alt="" className="absolute inset-0 h-full w-full object-cover" />
                ) : (
                  <div className="flex h-full w-full items-center justify-center">
                    <BookOpen className="h-16 w-16 opacity-50" style={{ color: primary }} />
                  </div>
                )}
              </div>
            </div>
          </div>
        </section>

        {/* ── Description ── */}
        {(course.description || course.prerequisites) && (
          <section className={`bg-white ${SECTION_PAD}`}>
            <div className="mx-auto max-w-3xl px-4">
              <SectionHeading title={t('عن هذه الدورة', 'About this course')} primary={primary} headingFont={headingFont} center={false} />
              {course.description && (
                <p className="mt-4 whitespace-pre-line text-sm leading-relaxed text-slate-600 sm:text-base">{course.description}</p>
              )}
              {course.prerequisites && (
                <div className="mt-6 rounded-2xl border border-slate-100 bg-slate-50 p-5">
                  <h3 className="mb-2 flex items-center gap-2 text-sm font-bold"><CheckCircle2 className="h-4 w-4" style={{ color: primary }} />{t('المتطلبات المسبقة', 'Prerequisites')}</h3>
                  <p className="whitespace-pre-line text-sm text-slate-600">{course.prerequisites}</p>
                </div>
              )}
            </div>
          </section>
        )}

        {/* ── Curriculum ── */}
        <section className={`bg-slate-50 ${SECTION_PAD}`}>
          <div className="mx-auto max-w-3xl px-4">
            <div className="mb-8">
              <SectionHeading
                badge={t('محتوى الدورة', 'Curriculum')}
                title={t('ماذا ستتعلم', 'What you will learn')}
                subtitle={lessonTotal > 0 ? `${lessonTotal} ${t('درس في', 'lessons across')} ${sections.length} ${t('وحدة', 'units')}` : undefined}
                primary={primary}
                headingFont={headingFont}
              />
            </div>

            {sections.length === 0 ? (
              <div className="rounded-3xl border border-dashed border-slate-200 bg-white px-6 py-12 text-center text-sm text-slate-500">
                {t('لم يُضف محتوى لهذه الدورة بعد.', 'No content has been added to this course yet.')}
              </div>
            ) : (
              <div className="space-y-3">
                {sections.map((section) => {
                  const open = !!openSections[section.id];
                  return (
                    <div key={section.id} className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                      <button
                        type="button"
                        onClick={() => setOpenSections((s) => ({ ...s, [section.id]: !open }))}
                        aria-expanded={open}
                        className="flex min-h-[56px] w-full items-center justify-between gap-3 px-5 py-4 text-start"
                      >
                        <span className="font-bold" style={{ fontFamily: headingFont }}>{section.title}</span>
                        <span className="flex items-center gap-2 text-xs text-slate-400">
                          {section.lessons.length} {t('درس', 'lessons')}
                          <ChevronDown className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} />
                        </span>
                      </button>
                      {open && (
                        <ul className="divide-y divide-slate-100 border-t border-slate-100">
                          {section.lessons.map((lesson) => (
                            <li key={lesson.id} className="flex min-h-[52px] items-center gap-3 px-5 py-3">
                              {lesson.is_free_preview ? (
                                <PlayCircle className="h-4 w-4 shrink-0" style={{ color: primary }} />
                              ) : (
                                <Lock className="h-4 w-4 shrink-0 text-slate-300" />
                              )}
                              <span className="min-w-0 flex-1 truncate text-sm text-slate-700">{lesson.title}</span>
                              {Number(lesson.duration_minutes) > 0 && (
                                <span className="shrink-0 text-xs text-slate-400">{lesson.duration_minutes} {t('د', 'min')}</span>
                              )}
                              {lesson.is_free_preview && (
                                <button
                                  type="button"
                                  onClick={() => openPreview(lesson)}
                                  className="shrink-0 rounded-full px-3 py-1.5 text-xs font-semibold"
                                  style={{ backgroundColor: `${primary}15`, color: primary }}
                                >
                                  {t('معاينة مجانية', 'Free preview')}
                                </button>
                              )}
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  );
                })}
              </div>
            )}

            {/* ── Inline preview ── */}
            {preview && (
              <div className="mt-6 overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                  <p className="truncate text-sm font-bold">
                    {t('معاينة:', 'Preview:')} {preview.lesson.title}
                  </p>
                  <button
                    type="button"
                    onClick={() => setPreview(null)}
                    aria-label={t('إغلاق المعاينة', 'Close preview')}
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100"
                  >
                    <X className="h-4 w-4" />
                  </button>
                </div>
                <div className="p-4">
                  {preview.loading ? (
                    <div className="flex aspect-video items-center justify-center rounded-2xl bg-black">
                      <Loader2 className="h-8 w-8 animate-spin text-white/60" />
                    </div>
                  ) : preview.error ? (
                    <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600">{preview.error}</p>
                  ) : previewVideo?.type === 'external' && previewVideo?.provider_video_id ? (
                    <div className="aspect-video overflow-hidden rounded-2xl bg-black">
                      <iframe
                        className="h-full w-full"
                        src={previewVideo.provider === 'youtube'
                          ? `https://www.youtube.com/embed/${previewVideo.provider_video_id}`
                          : `https://player.vimeo.com/video/${previewVideo.provider_video_id}`}
                        title={preview.lesson.title}
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; fullscreen"
                        allowFullScreen
                        referrerPolicy="strict-origin-when-cross-origin"
                      />
                    </div>
                  ) : previewVideo?.requires_login && !loggedIn ? (
                    <div className="flex aspect-video flex-col items-center justify-center gap-4 rounded-2xl bg-slate-900 px-6 text-center">
                      <ShieldCheck className="h-12 w-12 text-white/60" />
                      <p className="text-sm text-white/80">{t('أنشئ حساباً مجانياً لمشاهدة هذه المعاينة.', 'Create a free account to watch this preview.')}</p>
                      <Link href={`/t/${slug}/register?course=${courseId}`}>
                        <Button className="min-h-[44px] rounded-full px-6 text-white" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                          {t('إنشاء حساب مجاني', 'Create a free account')}
                        </Button>
                      </Link>
                    </div>
                  ) : previewVideo?.id ? (
                    <ProtectedVideoPlayer videoId={String(previewVideo.id)} />
                  ) : previewText ? (
                    <p className="whitespace-pre-line text-sm leading-relaxed text-slate-600">{previewText}</p>
                  ) : (
                    <p className="text-sm text-slate-500">{t('لا يوجد فيديو في هذه المعاينة.', 'This preview has no video.')}</p>
                  )}
                </div>
              </div>
            )}
          </div>
        </section>

        {/* ── Teacher ── */}
        <section className="bg-white py-12">
          <div className="mx-auto flex max-w-3xl flex-col items-start gap-4 px-4 sm:flex-row sm:items-center">
            {site?.logo_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={site.logo_url} alt="" className="h-16 w-16 shrink-0 rounded-2xl object-cover shadow-lg" />
            ) : (
              <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                <GraduationCap className="h-8 w-8 text-white" />
              </div>
            )}
            <div className="min-w-0">
              <p className="text-xs text-slate-400">{t('المعلم', 'Instructor')}</p>
              <p className="text-lg font-bold" style={{ fontFamily: headingFont }}>{teacherName}</p>
              {cfg.about_text && <p className="mt-1 line-clamp-2 text-sm text-slate-500">{cfg.about_text}</p>}
            </div>
          </div>
        </section>

        {/* ── Activation ── */}
        <ActivationPanel
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
          onSubmit={redeem}
          title={t('عندك بطاقة لهذه الدورة؟', 'Got a card for this course?')}
          text={t('أدخل الرمز وسجّل في كورسك فوراً', 'Enter the code to enroll instantly')}
        />

        {/* ── Closing CTA ── */}
        <section className="bg-slate-50 px-4 py-14 text-center">
          <div className="mx-auto max-w-xl">
            <h2 className="text-xl font-bold sm:text-2xl" style={{ fontFamily: headingFont }}>
              {enrolled ? t('أنت مسجّل — أكمل رحلتك', 'You are enrolled — keep going') : t('جاهز تبدأ؟', 'Ready to start?')}
            </h2>
            <TrustRow accent={accent} primary={primary} light={false} />
            <div className="mt-4 flex flex-wrap justify-center gap-3">
              {enrolled ? (
                <Link href={`/course/${courseId}/learn`}>
                  <Button className="min-h-[48px] rounded-full px-7 text-white shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                    <PlayCircle className="h-5 w-5" /> {t('ابدأ التعلم', 'Start learning')}
                  </Button>
                </Link>
              ) : (
                <a href="#card">
                  <Button className="min-h-[48px] rounded-full px-7 text-white shadow-lg" style={{ background: `linear-gradient(135deg, ${primary}, ${accent})` }}>
                    {t('فعّل بطاقتك الآن', 'Activate your card')}
                  </Button>
                </a>
              )}
            </div>
          </div>
        </section>
      </main>

      <StorefrontFooter
        site={site} slug={slug} primary={primary} accent={accent} dark={dark}
        font={{ heading: headingFont, body: headingFont }} links={navLinks}
        whatsapp={teacherWhatsapp} phone={cfg.contact_phone} email={cfg.contact_email}
      />
    </div>
  );
}
