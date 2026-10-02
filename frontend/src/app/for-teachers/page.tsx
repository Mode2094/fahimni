import type { Metadata } from 'next';
import { Breadcrumbs } from '@/components/seo/breadcrumbs';
import { Faq } from '@/components/seo/faq';
import { CtaSection } from '@/components/seo/cta-section';
import { SITE_NAME, absoluteUrl, DEFAULT_OG_IMAGE } from '@/lib/seo';

export const metadata: Metadata = {
  title: 'منصة تعليمية للمعلمين — منصتك الخاصة باسمك وشعارك',
  description:
    'منصة تعليمية متكاملة لكل معلم: أنشئ منصتك الخاصة، أدر طلابك وكورساتك، بثّ حصصك مباشرة، واجمع أرباحك — دون عمولات وبدون وسطاء.',
  alternates: { canonical: '/for-teachers' },
  openGraph: {
    title: 'منصة تعليمية للمعلمين — منصتك الخاصة باسمك وشعارك',
    description: 'منصة متكاملة: كورسات مسجلة، حصص مباشرة، إدارة طلاب، اختبارات وبطاقات تسجيل — بعلامتك التجارية.',
    type: 'website',
    siteName: SITE_NAME,
    url: absoluteUrl('/for-teachers'),
    locale: 'ar_AR',
    images: [{ url: absoluteUrl(DEFAULT_OG_IMAGE), width: 512, height: 512, alt: SITE_NAME }],
  },
  twitter: { card: 'summary', title: 'منصة تعليمية للمعلمين — Tadreesly', description: 'منصتك التعليمية الخاصة: كورسات، طلاب، حصص مباشرة.' },
};

const FEATURES: [string, string][] = [
  ['كورسات مسجلة كاملة', 'أقسام ودروس وفيديوهات وملفات — أنشئ كورساً احترافياً في دقائق وحدد سعره بنفسك.'],
  ['حصص مباشرة', 'دروس مباشرة مع طلابك عبر Google Meet من داخل منصتك، مع حجز وحضور وإشعارات.'],
  ['إدارة الطلاب', 'قوائم طلاب، تتبّع تقدمهم، نتائج اختباراتهم، وكل بياناتهم في لوحة واحدة.'],
  ['اختبارات وبطاقات تسجيل', 'اختبارات بأسئلة متعددة الأنواع، وبطاقات تسجيل برمز QR تُسجّل الطالب تلقائياً.'],
  ['موقعك الخاص بعلامتك', 'صفحة عامة بألوانك وشعارك، ودومين خاص بك لتبني هويتك لا هوية سوق مركزي.'],
  ['بوابات دفع وأرباحك كلها', 'مدى، باي بال وغيرها — مع فواتير تلقائية. أرباحك تذهب إليك، لا إلى وسطاء.'],
];

const FAQ: [string, string][] = [
  ['هل أحتاج خبرة تقنية لإنشاء منصتي؟', 'لا. تسجّل حساباً وتستلم موقعك التعليمي جاهزاً، ثم تضيف كورساتك وتحدد أسعارك — كل شيء من لوحة بسيطة.'],
  ['ما الفرق بين منصة Tadreesly والسوق المركزي (Marketplace)؟', 'في السوق المركزي أنت مستأجر في متجر غيرك؛ هنا تحصل على منصتك الخاصة باسمك وشعارك ودومينك، وتتحكم بأسعارك وعلاقتك بطلابك.'],
  ['هل يمكنني تحديد أسعار دوراتي بنفسي؟', 'نعم، سعر كل كورس تحدده أنت، والدفع يصل مباشرة عبر بوابات الدفع المربوطة بحسابك.'],
  ['هل يحتاج طلابي إلى تطبيق خاص؟', 'لا. طلابك يدخلون عبر رابط منصتك من أي متصفح، أو عبر تطبيق الطلاب الاختياري إن فعّلته في خطتك.'],
  ['هل أحصل على دعم أثناء الإعداد؟', 'نعم، فريق الدعم متاح 24/7 ويساعدك في تجهيز منصتك وإضافة أول كورساتك.'],
];

export default function ForTeachersPage() {
  return (
    <main className="bg-white">
      <div className="max-w-5xl mx-auto px-4 sm:px-6 py-12">
        <Breadcrumbs items={[{ name: SITE_NAME, href: '/' }, { name: 'منصة تعليمية للمعلمين', href: '/for-teachers' }]} />

        <header className="text-center mb-12">
          <h1 className="text-3xl sm:text-5xl font-black text-gray-900 leading-tight">
            منصة تعليمية متكاملة تُبنى حول أسلوبك في التدريس
          </h1>
          <p className="text-gray-500 text-lg mt-5 max-w-2xl mx-auto leading-relaxed">
            Tadreesly تمنحك منصتك التعليمية الخاصة: دورات مسجلة، حصص مباشرة، إدارة كاملة للطلاب،
            اختبارات وبطاقات تسجيل — كلها باسمك وشعارك ومن مكان واحد.
          </p>
          <div className="flex flex-wrap justify-center gap-3 mt-8">
            <a href="/register/teacher" className="px-8 py-3.5 rounded-2xl text-white font-bold shadow-lg hover:opacity-90 transition-opacity" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
              أنشئ منصتك مجاناً
            </a>
            <a href="/pricing" className="px-8 py-3.5 rounded-2xl font-bold border-2 border-teal-600 text-teal-700 hover:bg-teal-50 transition-colors">
              شاهد الأسعار
            </a>
          </div>
        </header>

        <section aria-labelledby="problem-heading" className="mb-14">
          <h2 id="problem-heading" className="text-2xl font-bold text-gray-900">المعلم المستقل يدفع ثمناً باهظاً مقابل لا شيء</h2>
          <p className="text-gray-600 leading-relaxed mt-4">
            اليوم، المعلم الناجح إما أن يدفع عمولات كبيرة على كل طالب في منصات الآخرين، أو يوزع
            محتواه على أدوات متفرقة: دروس في واتساب، ملفات في درايف، حصص في تطبيق منفصل، وطلابه
            يتوهون بينها. النتيجة: وقت ضائع، علامة تجارية لا تُبنى، وأرباح تتسرب للوسطاء.
          </p>
        </section>

        <section aria-labelledby="solution-heading" className="mb-14">
          <h2 id="solution-heading" className="text-2xl font-bold text-gray-900">الحل: منصة واحدة تحمل اسمك</h2>
          <p className="text-gray-600 leading-relaxed mt-4">
            مع Tadreesly تنتقل من «معلم يستأجر مساحة» إلى «مالك منصة». موقعك، محتواك، طلابك
            وأرباحك — كلها لك. كل ما يلي متاح فعلاً داخل المنصة:
          </p>
          <ul className="grid sm:grid-cols-2 gap-4 mt-8">
            {FEATURES.map(([title, desc]) => (
              <li key={title} className="rounded-2xl border border-gray-200 p-5">
                <h3 className="font-bold text-gray-900">{title}</h3>
                <p className="text-gray-600 text-sm leading-relaxed mt-1.5">{desc}</p>
              </li>
            ))}
          </ul>
        </section>

        <CtaSection
          title="جرّبها بنفسك — منصتك جاهزة خلال دقائق"
          text="سجّل مجاناً، أضف أول كورس لك، وشارك رابط موقعك مع طالب واحد. ستفهم فوراً لماذا يختار المعلمون بناء منصاتهم الخاصة."
          primaryLabel="ابدأ الآن مجاناً"
        />

        <section aria-labelledby="related-heading" className="mb-14">
          <h2 id="related-heading" className="text-xl font-bold text-gray-900 mb-4">استكشف ما تستطيع بناءه</h2>
          <div className="grid sm:grid-cols-2 gap-3">
            <a href="/create-online-courses" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">إنشاء وبيع الكورسات المسجلة</h3>
              <p className="text-gray-600 text-sm mt-1">حوّل خبرتك إلى دروس مسجلة تُباع أونلاين بسعر تحدده.</p>
            </a>
            <a href="/live-online-classes" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">الحصص المباشرة عبر Google Meet</h3>
              <p className="text-gray-600 text-sm mt-1">أدر دروسك المباشرة وحضور طلابك من داخل منصتك.</p>
            </a>
            <a href="/lms-for-teachers" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">نظام إدارة تعلم للمعلمين</h3>
              <p className="text-gray-600 text-sm mt-1">كل أدوات تنظيم الطلاب والدورات والاختبارات في مكان واحد.</p>
            </a>
            <a href="/teacher-website" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">موقع خاص للمعلم</h3>
              <p className="text-gray-600 text-sm mt-1">صفحة عامة بألوانك وشعارك ودومينك تبني علامتك التجارية.</p>
            </a>
          </div>
        </section>

        <Faq title="أسئلة شائعة عن منصة المعلمين" items={FAQ} />

        <CtaSection
          title="ابدأ اليوم — منصتك تنتظرك"
          text="لا تدع عمولات الوسطاء تلتهم أرباحك بعد الآن. أنشئ منصتك التعليمية الخاصة وادير طلابك وكورساتك بنفسك."
          primaryLabel="أنشئ حسابك مجاناً"
        />
      </div>
    </main>
  );
}