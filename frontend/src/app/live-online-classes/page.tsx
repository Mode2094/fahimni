import type { Metadata } from 'next';
import { Breadcrumbs } from '@/components/seo/breadcrumbs';
import { Faq } from '@/components/seo/faq';
import { CtaSection } from '@/components/seo/cta-section';
import { SITE_NAME, absoluteUrl, DEFAULT_OG_IMAGE } from '@/lib/seo';

export const metadata: Metadata = {
  title: 'منصة دروس مباشرة للمعلمين — حصص أونلاين عبر Google Meet',
  description:
    'أدر حصصك المباشرة من منصة Tadreesly: دروس عبر Google Meet، حجز المواعيد، حضور وإشعارات تلقائية، وكل ذلك ضمن منصتك التعليمية الخاصة.',
  alternates: { canonical: '/live-online-classes' },
  openGraph: {
    title: 'منصة دروس مباشرة للمعلمين — حصص عبر Google Meet',
    description: 'دروس مباشرة، حجز، حضور وإشعارات — من داخل منصتك التعليمية.',
    type: 'website',
    siteName: SITE_NAME,
    url: absoluteUrl('/live-online-classes'),
    locale: 'ar_AR',
    images: [{ url: absoluteUrl(DEFAULT_OG_IMAGE), width: 512, height: 512, alt: SITE_NAME }],
  },
  twitter: { card: 'summary', title: 'منصة دروس مباشرة — Tadreesly', description: 'أدر حصصك المباشرة عبر Google Meet من منصتك الخاصة.' },
};

const LIVE_FEATURES: [string, string][] = [
  ['حصص مباشرة عبر Google Meet', 'أنشئ درساً مباشراً من داخل منصتك، وينضم طلابك عبر رابط واحد — بدون تطبيقات إضافية.'],
  ['حجز المواعيد', 'حدد أوقات حصصك ودع طلابك يحجزون مواعيدهم بأنفسهم وفقاً لتوفرك.'],
  ['حضور وإشعارات تلقائية', 'سجل حضور الطلاب تلقائياً وأرسل لهم تذكيرات قبل بدء الحصة.'],
  ['مكملة لكورساتك المسجلة', 'اجمع بين الدروس المسجلة والحصص المباشرة في نفس الكورس — تجربة تعليمية متكاملة.'],
  ['إدارة داخل منصتك', 'كل الحصص والطلاب والحضور في لوحة واحدة، جنباً إلى جنب مع دوراتك واختباراتك.'],
  ['بعلامتك التجارية', 'طلابك يفتحون رابط منصتك التي تحمل اسمك، لا رابط طرف ثالث مشتت.'],
];

const FAQ: [string, string][] = [
  ['كيف أنشئ حصة مباشرة؟', 'من لوحة التحكم تختار إنشاء درس مباشر، تحدّد الوقت، ويُرسل رابط Google Meet تلقائياً لطلابك.'],
  ['هل يحتاج طلابي لإنشاء حساب على Google؟', 'لا، ينضمون عبر الرابط الذي تصدره المنصة دون الحاجة لحسابات إضافية.'],
  ['هل يمكنني دمج الحصص المباشرة مع الكورسات المسجلة؟', 'نعم، أضف دروساً مباشرة ضمن هيكل الكورس نفسه، فيجمع طلابك بين المتابعة المسجلة والحضور الحي.'],
  ['كيف أتتبع حضور الطلاب؟', 'المنصة تسجل الحضور تلقائياً وتُظهر لك ملخصاً لكل طالب في لوحة التحكم.'],
  ['هل تعمل الحصص المباشرة على الهاتف؟', 'نعم، طلابك يحضرون من المتصفح على الهاتف أو الحاسوب مباشرة.'],
];

export default function LiveOnlineClassesPage() {
  return (
    <main className="bg-white">
      <div className="max-w-5xl mx-auto px-4 sm:px-6 py-12">
        <Breadcrumbs items={[{ name: SITE_NAME, href: '/' }, { name: 'منصة دروس مباشرة', href: '/live-online-classes' }]} />

        <header className="text-center mb-12">
          <h1 className="text-3xl sm:text-5xl font-black text-gray-900 leading-tight">
            أدر حصصك المباشرة من منصة تحمل اسمك
          </h1>
          <p className="text-gray-500 text-lg mt-5 max-w-2xl mx-auto leading-relaxed">
            دروس مباشرة عبر Google Meet، حجز مواعيد، حضور وإشعارات تلقائية — كلها داخل منصتك
            التعليمية، بجوار دوراتك المسجلة وطلابك واختباراتك.
          </p>
          <div className="flex flex-wrap justify-center gap-3 mt-8">
            <a href="/register/teacher" className="px-8 py-3.5 rounded-2xl text-white font-bold shadow-lg hover:opacity-90 transition-opacity" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
              جرّب منصتك مجاناً
            </a>
            <a href="/pricing" className="px-8 py-3.5 rounded-2xl font-bold border-2 border-teal-600 text-teal-700 hover:bg-teal-50 transition-colors">
              شاهد الأسعار
            </a>
          </div>
        </header>

        <section aria-labelledby="problem-heading" className="mb-14">
          <h2 id="problem-heading" className="text-2xl font-bold text-gray-900">لماذا تُدار الحصص المباشرة داخل منصتك؟</h2>
          <p className="text-gray-600 leading-relaxed mt-4">
            عندما تعطي دروساً مباشرة عبر تطبيقات منفصلة، يتشتت طلابك بين رابط الحصة وملفات الدرس
            وتواريخ المواعيد. النتيجة: حضور متأخر، مواعيد متضاربة، ومعلم يرسل الروابط يدوياً قبل كل
            حصة. مع Tadreesly، الحصة المباشرة جزء من منصتك: تنشئها، يحجز طلابك، ويصلهم التذكير
            تلقائياً.
          </p>
        </section>

        <section aria-labelledby="features-heading" className="mb-14">
          <h2 id="features-heading" className="text-2xl font-bold text-gray-900">كل ما تحتاجه للحصص المباشرة</h2>
          <ul className="grid sm:grid-cols-2 gap-4 mt-8">
            {LIVE_FEATURES.map(([title, desc]) => (
              <li key={title} className="rounded-2xl border border-gray-200 p-5">
                <h3 className="font-bold text-gray-900">{title}</h3>
                <p className="text-gray-600 text-sm leading-relaxed mt-1.5">{desc}</p>
              </li>
            ))}
          </ul>
        </section>

        <CtaSection
          title="أنشئ أول حصة مباشرة خلال دقائق"
          text="سجّل مجاناً، حدد موعد حصة، وأرسل رابطها لطلابك. لاحظ الفرق بين تنظيم الحصص داخل منصتك وتنظيمها عبر رسائل مبعثرة."
          primaryLabel="ابدأ مجاناً"
        />

        <section aria-labelledby="related-heading" className="mb-14">
          <h2 id="related-heading" className="text-xl font-bold text-gray-900 mb-4">الحصص المباشرة بجوار أدواتك الأخرى</h2>
          <div className="grid sm:grid-cols-2 gap-3">
            <a href="/create-online-courses" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">إنشاء كورسات مسجلة</h3>
              <p className="text-gray-600 text-sm mt-1">اجمع الحصص المباشرة مع دروس مسجلة تباع أونلاين.</p>
            </a>
            <a href="/lms-for-teachers" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">نظام إدارة التعلم</h3>
              <p className="text-gray-600 text-sm mt-1">الحصص، الكورسات، الطلاب والاختبارات في نظام واحد.</p>
            </a>
            <a href="/for-teachers" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">منصة تعليمية للمعلمين</h3>
              <p className="text-gray-600 text-sm mt-1">كل أدوات التدريس تحت علامتك التجارية.</p>
            </a>
            <a href="/pricing" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">خطط الأسعار</h3>
              <p className="text-gray-600 text-sm mt-1">قارن الخطط واختر ما يناسب عدد طلابك وحصصك.</p>
            </a>
          </div>
        </section>

        <Faq title="أسئلة شائعة عن الحصص المباشرة" items={FAQ} />

        <CtaSection
          title="حصة مباشرة اليوم، منصة دائمة بعدها"
          text="ابدأ بتجربة حصة واحدة عبر Google Meet من منصتك، وسترى كيف تتجمع كل أدوات التدريس لديك في مكان واحد."
          primaryLabel="أنشئ حسابك مجاناً"
        />
      </div>
    </main>
  );
}