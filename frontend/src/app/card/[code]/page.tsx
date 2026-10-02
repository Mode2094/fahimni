'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { useLocale } from '@/store/locale';
import { Loader2, QrCode, GraduationCap, CheckCircle2, XCircle, ArrowLeft, Sparkles, Zap, UserPlus, LogIn } from 'lucide-react';

const API = process.env.NEXT_PUBLIC_API_URL || '/api/v1';

type CardInfo = {
  code: string;
  status: string;
  course?: { id: string; title: string };
  tenant?: { id: string; slug: string; name: string };
};

export default function CardRedeemPage() {
  const { locale } = useLocale();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const params = useParams<{ code: string }>();
  const router = useRouter();
  const code = (params?.code || '').toUpperCase();

  const [card, setCard] = useState<CardInfo | null>(null);
  const [notFound, setNotFound] = useState(false);
  const [phase, setPhase] = useState<'loading' | 'idle' | 'redeeming' | 'done' | 'error'>('loading');
  const [message, setMessage] = useState('');
  const [isTeacher, setIsTeacher] = useState(false);

  const redeem = async (cd: string, tenantId: string) => {
    const token = localStorage.getItem('lms_authed');
    setPhase('redeeming');
    setMessage('');
    try {
      // The card belongs to the teacher's tenant — switch context so the
      // redeem call validates against the right tenant.
      localStorage.setItem('tenant_id', tenantId);
      const res = await fetch(`${API}/cards/redeem`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Tenant-Id': tenantId, Accept: 'application/json' },
        body: JSON.stringify({ code: cd }),
      });
      const d = await res.json().catch(() => null);
      if (res.ok) {
        setPhase('done');
        setMessage(d?.message || t('تم تفعيل البطاقة!', 'Card activated!'));
        return;
      }
      setPhase('error');
      setMessage(
        res.status === 409
          ? t('أنت مسجل بالفعل في هذا الكورس.', 'You are already enrolled in this course.')
          : (d?.message || t('تعذر تفعيل البطاقة', 'Could not activate the card')),
      );
    } catch {
      setPhase('error');
      setMessage(t('تعذر الاتصال بالخادم', 'Cannot reach the server'));
    }
  };

  useEffect(() => {
    const token = localStorage.getItem('lms_authed');
    let user: any = null;
    try { user = JSON.parse(localStorage.getItem('user') || 'null'); } catch { /* */ }
    setIsTeacher(!!user && !user.is_super_admin && (user.roles || []).includes('Teacher'));

    fetch(`${API}/public/cards/${encodeURIComponent(code)}`)
      .then(async (r) => {
        if (!r.ok) { setNotFound(true); setPhase('error'); return null; }
        return r.json();
      })
      .then((d) => {
        if (!d) return;
        setCard(d.card);
        if (!token) { setPhase('idle'); return; }

        // Logged in as a student → auto-redeem immediately.
        if (!user || user.is_super_admin || !(user.roles || []).includes('Teacher')) {
          if (d.card?.tenant?.id) redeem(d.card.code, d.card.tenant.id);
        } else {
          // Teacher/admin scanning a student card.
          setPhase('idle');
          setMessage(t('بطاقات التسجيل خاصة بالطلاب — سجّل الدخول بحساب طالب لتفعيلها.', 'Registration cards are for students — log in with a student account to activate.'));
        }
      })
      .catch(() => { setNotFound(true); setPhase('error'); });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [code]);

  const loginUrl = card?.tenant ? `/student/login?tenant_slug=${card.tenant.slug}&from=/card/${code}` : `/student/login?from=/card/${code}`;
  const registerUrl = card?.tenant ? `/register/student?tenant_slug=${card.tenant.slug}&from=/card/${code}` : `/register/student?from=/card/${code}`;

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-teal-950 flex items-center justify-center p-4">
      <div className="w-full max-w-md">
        {/* Logo */}
        <div className="text-center mb-6">
          <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-600 to-teal-500 shadow-xl shadow-teal-500/20">
            <QrCode className="h-7 w-7 text-white" />
          </div>
          <h1 className="text-xl font-bold text-white">{t('تفعيل بطاقة تسجيل', 'Registration card activation')}</h1>
        </div>

        <div className="bg-white rounded-3xl shadow-2xl overflow-hidden">
          {phase === 'loading' && (
            <div className="p-12 flex flex-col items-center gap-4">
              <Loader2 className="h-8 w-8 animate-spin text-teal-600" />
              <p className="text-sm text-gray-400">{t('جاري التحقق من البطاقة...', 'Verifying your card...')}</p>
            </div>
          )}

          {(phase === 'idle' || phase === 'error') && card && !notFound && (
            <div className="p-6 sm:p-8 space-y-5">
              {/* Card code chip */}
              <div className="flex items-center gap-3 p-4 rounded-2xl bg-slate-50 border border-slate-200">
                <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-600 text-white shrink-0"><GraduationCap className="h-5 w-5" /></span>
                <div className="min-w-0 flex-1">
                  <p className="text-xs text-gray-400">{t('كود البطاقة', 'Card code')}</p>
                  <p className="font-mono font-bold text-lg tracking-widest" dir="ltr">{card.code}</p>
                </div>
                <span className="text-[11px] px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-700 font-medium shrink-0">
                  {card.status === 'generated' ? t('جاهزة', 'Ready') : t(card.status, card.status)}
                </span>
              </div>

              {card.course && (
                <div className="text-center">
                  <p className="text-xs text-gray-400">{t('هذه البطاقة تفعّل اشتراكك في الكورس:', 'This card activates your enrollment in:')}</p>
                  <p className="text-xl font-bold mt-1">{card.course.title}</p>
                  {card.tenant?.name && <p className="text-xs text-gray-400 mt-1">{card.tenant.name}</p>}
                </div>
              )}

              {phase === 'error' && (
                <div className="flex items-start gap-3 p-4 rounded-2xl bg-rose-50 border border-rose-100 text-sm text-rose-700">
                  <XCircle className="h-5 w-5 shrink-0 mt-0.5" />
                  <div>
                    <p className="font-semibold">{t('تعذر التفعيل', 'Activation failed')}</p>
                    <p className="text-xs mt-1">{message}</p>
                  </div>
                </div>
              )}

              {/* Not logged in → auth required */}
              {!localStorage.getItem('lms_authed') ? (
                <>
                  <div className="flex items-start gap-3 p-4 rounded-2xl bg-teal-50 border border-teal-100 text-sm text-teal-700">
                    <Sparkles className="h-5 w-5 shrink-0 mt-0.5" />
                    <p>{t('أنشئ حسابك أو سجّل دخولك ثم ستفعَّل بطاقتك تلقائيًا.', 'Create an account or log in — your card will then be activated automatically.')}</p>
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <Link href={registerUrl} className="flex items-center justify-center gap-2 py-3.5 rounded-xl bg-gradient-to-r from-teal-600 to-teal-500 text-white text-sm font-bold shadow-lg shadow-teal-600/25 hover:opacity-95 transition-all">
                      <UserPlus className="h-4 w-4" />{t('إنشاء حساب', 'Sign up')}
                    </Link>
                    <Link href={loginUrl} className="flex items-center justify-center gap-2 py-3.5 rounded-xl border-2 border-slate-200 text-slate-700 text-sm font-bold hover:border-teal-400 hover:text-teal-700 transition-all">
                      <LogIn className="h-4 w-4" />{t('تسجيل دخول', 'Log in')}
                    </Link>
                  </div>
                  <p className="text-center text-[11px] text-gray-400 flex items-center justify-center gap-1">
                    <Zap className="h-3.5 w-3.5 text-amber-500" />
                    {t('بعد الدخول ستحوَّل تلقائيًا وتُفعَّل بطاقتك مباشرة', 'After logging in you will be redirected and your card activates instantly')}
                  </p>
                </>
              ) : (
                <>
                  {isTeacher && (
                    <div className="flex items-start gap-3 p-4 rounded-2xl bg-amber-50 border border-amber-100 text-sm text-amber-700">
                      <XCircle className="h-5 w-5 shrink-0 mt-0.5" />
                      <div>
                        <p className="font-semibold">{t('حساب معلم', 'Teacher account')}</p>
                        <p className="text-xs mt-1">{message || t('بطاقات التسجيل تخص الطلاب فقط.', 'Registration cards are for students only.')}</p>
                      </div>
                    </div>
                  )}
                  {!isTeacher && phase === 'error' && (
                    <div className="grid grid-cols-2 gap-3">
                      <Link href={loginUrl} className="flex items-center justify-center gap-2 py-3.5 rounded-xl bg-gradient-to-r from-teal-600 to-teal-500 text-white text-sm font-bold shadow-lg shadow-teal-600/25 hover:opacity-95 transition-all">
                        <LogIn className="h-4 w-4" />{t('إعادة تسجيل الدخول', 'Log in again')}
                      </Link>
                      <a href="/my-learning" className="flex items-center justify-center gap-2 py-3.5 rounded-xl border-2 border-slate-200 text-slate-700 text-sm font-bold hover:border-teal-400 transition-all">
                        <ArrowLeft className="h-4 w-4" />{t('دوراتي', 'My learning')}
                      </a>
                    </div>
                  )}
                </>
              )}
            </div>
          )}

          {phase === 'redeeming' && (
            <div className="p-12 flex flex-col items-center gap-4">
              <div className="relative">
                <Loader2 className="h-10 w-10 animate-spin text-teal-600" />
              </div>
              <p className="text-sm font-medium text-slate-700">{t('جاري تفعيل بطاقتك وتسجيلك في الكورس...', 'Activating your card and enrolling you...')}</p>
              {card?.course && <p className="text-xs text-gray-400">{card.course.title}</p>}
            </div>
          )}

          {phase === 'done' && (
            <div className="p-8 text-center space-y-5">
              <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-emerald-100 animate-in">
                <CheckCircle2 className="h-10 w-10 text-emerald-600" />
              </div>
              <div>
                <h2 className="text-xl font-bold">{t('تم تفعيل بطاقتك!', 'Your card is activated!')}</h2>
                <p className="text-gray-500 text-sm mt-1">{message}</p>
                {card?.course && (
                  <p className="mt-2 text-sm text-gray-600">
                    {t('أنت الآن مسجل في:', 'You are now enrolled in:')} <b className="text-teal-700">{card.course.title}</b>
                  </p>
                )}
              </div>
              <div className="grid gap-2.5">
                {card?.course && (
                  <Link href={`/course/${card.course.id}/learn`} className="flex items-center justify-center gap-2 py-3.5 rounded-xl bg-gradient-to-r from-teal-600 to-teal-500 text-white text-sm font-bold shadow-lg shadow-teal-600/25 hover:opacity-95 transition-all">
                    <Zap className="h-4 w-4" />{t('ابدأ التعلم الآن', 'Start learning now')}
                  </Link>
                )}
                <a href="/my-learning" className="flex items-center justify-center gap-2 py-3.5 rounded-xl border-2 border-slate-200 text-slate-700 text-sm font-bold hover:border-teal-400 transition-all">
                  <GraduationCap className="h-4 w-4" />{t('عرض دوراتي', 'My courses')}
                </a>
              </div>
            </div>
          )}

          {notFound && (
            <div className="p-10 text-center space-y-4">
              <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-rose-50">
                <XCircle className="h-8 w-8 text-rose-500" />
              </div>
              <div>
                <h2 className="text-lg font-bold">{t('بطاقة غير صالحة', 'Invalid card')}</h2>
                <p className="text-sm text-gray-400 mt-1">{t('تأكد من الكود أو تواصل مع معلمك.', 'Check the code or contact your teacher.')}</p>
              </div>
              <a href="/" className="inline-flex items-center gap-2 text-sm text-teal-700 font-medium hover:underline">
                <ArrowLeft className="h-4 w-4" />{t('العودة للرئيسية', 'Back to home')}
              </a>
            </div>
          )}
        </div>

        <p className="text-center text-xs text-white/40 mt-6">{t('محمية بنظام التفعيل الفوري — البطاقة تُستخدم مرة واحدة فقط', 'Protected with instant activation — each card works once')}</p>
      </div>
    </div>
  );
}
