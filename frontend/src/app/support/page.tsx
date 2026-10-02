'use client';

import { useLocale } from '@/store/locale';
import { useRouter } from 'next/navigation';
import { ArrowLeft, ArrowRight, Mail, MessageCircle, Headset, Clock, ShieldCheck } from 'lucide-react';

const SUPPORT_WHATSAPP = '962793519944';
const SUPPORT_EMAIL = 'support@tadreesly.com';

export default function SupportPage() {
  const { locale } = useLocale();
  const router = useRouter();
  const t = (ar: string, en: string) => (locale === 'ar' ? ar : en);
  const isRTL = locale === 'ar';

  return (
    <div className="min-h-screen bg-gradient-to-b from-slate-50 to-white py-10 px-4 sm:py-16">
      <div className="max-w-3xl mx-auto">
        <button
          onClick={() => router.back()}
          className="inline-flex items-center gap-1.5 text-sm text-gray-400 hover:text-gray-700 transition-colors mb-6"
        >
          {isRTL ? <ArrowRight className="h-4 w-4" /> : <ArrowLeft className="h-4 w-4" />}
          {t('رجوع', 'Back')}
        </button>

        <div className="text-center mb-10">
          <div className="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-600 to-teal-500 mb-5">
            <Headset className="h-8 w-8 text-white" />
          </div>
          <h1 className="text-3xl sm:text-4xl font-bold tracking-tight">
            {t('تواصل مع الدعم', 'Contact support')}
          </h1>
          <p className="text-gray-500 mt-2 max-w-md mx-auto">
            {t('فريقنا جاهز لمساعدتك — اختر الطريقة الأنسب لك وسنرد عليك في أقرب وقت.', 'Our team is here to help — pick the channel that works best for you and we will get back to you shortly.')}
          </p>
        </div>

        <div className="grid sm:grid-cols-2 gap-5">
          <a
            href={`https://wa.me/${SUPPORT_WHATSAPP}?text=${encodeURIComponent(t('مرحباً، لدي استفسار حول المنصة', 'Hello, I have a question about the platform'))}`}
            target="_blank"
            rel="noopener noreferrer"
            className="group rounded-2xl border border-emerald-200 bg-white p-7 shadow-sm transition-all hover:-translate-y-1 hover:shadow-lg hover:border-emerald-300"
          >
            <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-[#25d366] to-[#128c7e] mb-5">
              <MessageCircle className="h-7 w-7 text-white" />
            </div>
            <h2 className="text-lg font-bold text-slate-900">{t('واتساب', 'WhatsApp')}</h2>
            <p className="text-sm text-gray-500 mt-1 mb-5">
              {t('أسرع طريقة للتواصل — محادثة مباشرة مع فريق الدعم.', 'The fastest way to reach us — chat directly with our support team.')}
            </p>
            <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-emerald-600 group-hover:gap-2.5 transition-all">
              {t('ابدأ المحادثة', 'Start chat')}
              {isRTL ? <ArrowLeft className="h-4 w-4" /> : <ArrowRight className="h-4 w-4" />}
            </span>
          </a>

          <a
            href={`mailto:${SUPPORT_EMAIL}?subject=${encodeURIComponent(t('استفسار / دعم', 'Inquiry / Support'))}`}
            className="group rounded-2xl border border-slate-200 bg-white p-7 shadow-sm transition-all hover:-translate-y-1 hover:shadow-lg hover:border-teal-300"
          >
            <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-slate-700 to-slate-900 mb-5">
              <Mail className="h-7 w-7 text-white" />
            </div>
            <h2 className="text-lg font-bold text-slate-900">{t('البريد الإلكتروني', 'Email')}</h2>
            <p className="text-sm text-gray-500 mt-1 mb-5" dir="ltr">
              {SUPPORT_EMAIL}
            </p>
            <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-teal-600 group-hover:gap-2.5 transition-all">
              {t('أرسل بريدًا', 'Send email')}
              {isRTL ? <ArrowLeft className="h-4 w-4" /> : <ArrowRight className="h-4 w-4" />}
            </span>
          </a>
        </div>

        <div className="mt-10 grid sm:grid-cols-2 gap-4">
          <div className="flex items-center gap-3 rounded-xl bg-white border border-slate-200 p-4">
            <Clock className="h-5 w-5 shrink-0 text-teal-600" />
            <div>
              <p className="text-sm font-bold text-slate-800">{t('أوقات الدعم', 'Support hours')}</p>
              <p className="text-xs text-gray-500">{t('يوميًا من 9 صباحًا حتى 9 مساءً', 'Daily from 9am to 9pm')}</p>
            </div>
          </div>
          <div className="flex items-center gap-3 rounded-xl bg-white border border-slate-200 p-4">
            <ShieldCheck className="h-5 w-5 shrink-0 text-teal-600" />
            <div>
              <p className="text-sm font-bold text-slate-800">{t('بياناتك محمية', 'Your data is protected')}</p>
              <p className="text-xs text-gray-500">{t('جميع المحادثات سرية وآمنة', 'All conversations are private and secure')}</p>
            </div>
          </div>
        </div>

        <p className="text-center text-xs text-gray-400 mt-10">
          © {new Date().getFullYear()} {t('Tadreesly · جميع الحقوق محفوظة', 'Tadreesly · All rights reserved')}
        </p>
      </div>
    </div>
  );
}
