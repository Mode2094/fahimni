import type { Metadata } from 'next';
import { Breadcrumbs } from '@/components/seo/breadcrumbs';
import { Faq } from '@/components/seo/faq';
import { CtaSection } from '@/components/seo/cta-section';
import { SITE_NAME, absoluteUrl, DEFAULT_OG_IMAGE } from '@/lib/seo';

export const metadata: Metadata = {
  title: 'موقع خاص للمعلم — منصة باسمك وشعارك ودومينك',
  description:
    'انشئ موقعك التعليمي الخاص: صفحة عامة بألوانك وشعارك، دومين خاص، دوراتك وطلابك وحصصك في مكان واحد — تبني علامتك التجارية لا علامة سوق مركزي.',
  alternates: { canonical: '/teacher-website' },
  openGraph: {
    title: 'موقع خاص للمعلم — منصة باسمك وشعارك',
    description: 'موقع تعليمي يحمل اسمك: كورسات، طلاب، حصص مباشرة واختبارات بعلامتك التجارية.',
    type: 'website',
    siteName: SITE_NAME,
    url: absoluteUrl('/teacher-website'),
    locale: 'ar_AR',
    images: [{ url: absoluteUrl(DEFAULT_OG_IMAGE), width: 512, height: 512, alt: SITE_NAME }],
  },
  twitter: { card: 'summary', title: 'موقع خاص للمعلم — Tadreesly', description: 'منصتك التعليمية باسمك وشعارك ودومينك.' },
};

const BRAND_POINTS: [string, string][] = [
  ['صفحة عامة بألوانك وشعارك', 'موقع يظهر بهويتك الحقيقية: شعارك، ألوانك، ورسالتك — تفتحه في ثوانٍ وتربطه باسمك.'],
  ['دومين خاص بك', 'اربط دومينك الخاص واجعل طلابك يزورون عنوانك، لا عنوان منصة غريبة.'],
  ['دوراتك وطلابك في مكان واحد', 'كل كورس وحصة وطالب على موقعك، فيظهر عملك كأكاديمية مكتملة لا كملف شخصي.'],
  ['اختبارات وبطاقات تسجيل', 'قيم طلابك واسمح لهم بالتسجيل ببطاقة QR من نفس صفحتك.'],
  ['بدون عمولات وسطاء', 'أنت من تملك العلاقة مع الطلاب والأرباح — موقعك بوابة لا وسيط.'],
  ['قابل للتثبيت كتطبيق', 'صفحتك تعمل من المتصفح ويمكن تثبيتها على أجهزة طلابك لتجربة أقرب للتطبيق.'],
];

const FAQ: [string, string][] = [
  ['هل أحتاج مصمم مواقع؟', 'لا. منشئ المنصة يعطيك صفحة جاهزة بألوانك وشعارك خلال دقائق، ويمكنك تعديل الأقسام والتصميم بنفسك.'],
  ['هل يمكنني ربط دومين خاص بي؟', 'نعم، اربط دومينك عبر إعدادات منصتك، ويصبح عنوان طلابك عنواناً خاصاً بك.'],
  ['ماذا يظهر للزوار على صفحتي؟', 'معلومات عنك، دوراتك، حصصك، بطاقات التسجيل، وشهادات طلابك — حسب الأقسام التي تفعلها.'],
  ['هل يظهر شعار Tadreesly على صفحتي؟', 'صفحتك تحمل هويتك أنت؛ يمكنك استخدام شعارك وألوانك وتفعيل الوضع الأبيض (White Label) حسب خطتك.'],
  ['كيف أبدأ؟', 'سجّل حساب معلم، اختر قالباً لصفحتك، أضف شعارك ومعلوماتك، ثم انشر رابط موقعك.'],
];

export default function TeacherWebsitePage() {
  return (
    <main className="bg-white">
      <div className="max-w-5xl mx-auto px-4 sm:px-6 py-12">
        <Breadcrumbs items={[{ name: SITE_NAME, href: '/' }, { name: 'موقع خاص للمعلم', href: '/teacher-website' }]} />

        <header className="text-center mb-12">
          <h1 className="text-3xl sm:text-5xl font-black text-gray-900 leading-tight">
            موقعك التعليمي الخاص — باسمك، شعارك، ودومينك
          </h1>
          <p className="text-gray-500 text-lg mt-5 max-w-2xl mx-auto leading-relaxed">
            بدلاً من صفحة مجهولة داخل سوق مركزي، احصل على موقع يحمل هويتك: شعارك وألوانك ودومينك،
            مع كل أدوات التدريس خلف صفحة واحدة أنيقة.
          </p>
          <div className="flex flex-wrap justify-center gap-3 mt-8">
            <a href="/register/teacher" className="px-8 py-3.5 rounded-2xl text-white font-bold shadow-lg hover:opacity-90 transition-opacity" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
              أنشئ موقعك مجاناً
            </a>
            <a href="/pricing" className="px-8 py-3.5 rounded-2xl font-bold border-2 border-teal-600 text-teal-700 hover:bg-teal-50 transition-colors">
              خطط الأسعار
            </a>
          </div>
        </header>

        <section aria-labelledby="problem-heading" className="mb-14">
          <h2 id="problem-heading" className="text-2xl font-bold text-gray-900">هويتك هي أثمن ما تملك</h2>
          <p className="text-gray-600 leading-relaxed mt-4">
            المعلم الذي يبني اسمه على منصة أخرى يستيقظ يوماً ليكتشف أن الطلاب، الأرباح، وحتى
            السمعة كلها مرتبطة بمنصة قد تغيّر شروطها في أي لحظة. الموقع الخاص هو استثمارك في هويتك:
            عنوان ثابت، علامة تتذكرها طلابك، وعلاقة مباشرة لا وسيط بينها.
          </p>
        </section>

        <section aria-labelledby="points-heading" className="mb-14">
          <h2 id="points-heading" className="text-2xl font-bold text-gray-900">ماذا يتضمن موقع المعلم؟</h2>
          <ul className="grid sm:grid-cols-2 gap-4 mt-8">
            {BRAND_POINTS.map(([title, desc]) => (
              <li key={title} className="rounded-2xl border border-gray-200 p-5">
                <h3 className="font-bold text-gray-900">{title}</h3>
                <p className="text-gray-600 text-sm leading-relaxed mt-1.5">{desc}</p>
              </li>
            ))}
          </ul>
        </section>

        <CtaSection
          title="موقعك ينشر خلال دقائق"
          text="سجّل، اختر قالباً، ارفع شعارك، وانشر. شارك الرابط مع طالب واحد وستشعر بالفرق بين صفحة بلا هوية وموقع يحمل اسمك."
          primaryLabel="أنشئ موقعك الآن"
        />

        <section aria-labelledby="related-heading" className="mb-14">
          <h2 id="related-heading" className="text-xl font-bold text-gray-900 mb-4">ما الذي يملأ موقعك؟</h2>
          <div className="grid sm:grid-cols-2 gap-3">
            <a href="/create-online-courses" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">كورسات مسجلة تُباع أونلاين</h3>
              <p className="text-gray-600 text-sm mt-1">اعرض دروسك المسجلة على موقعك بسعر تحدده.</p>
            </a>
            <a href="/live-online-classes" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">الحصص المباشرة</h3>
              <p className="text-gray-600 text-sm mt-1">أدر مواعيد حصصك عبر Google Meet من موقعك.</p>
            </a>
            <a href="/for-teachers" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">منصة تعليمية للمعلمين</h3>
              <p className="text-gray-600 text-sm mt-1">موقعك هو واجهة منصة متكاملة لإدارة التدريس.</p>
            </a>
            <a href="/online-teaching-platform" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">Online teaching platform</h3>
              <p className="text-gray-600 text-sm mt-1">لمحة كاملة عن منصة التدريس أونلاين بالإنجليزية.</p>
            </a>
          </div>
        </section>

        <Faq title="أسئلة شائعة عن موقع المعلم" items={FAQ} />

        <CtaSection
          title="ابدأ ببناء علامتك التجارية اليوم"
          text="كل يوم تتدرب فيه على منصة الآخرين هو يوم لا تعمل فيه لصالح اسمك. أنشئ موقعك الخاص وابدأ من اليوم."
          primaryLabel="أنشئ موقعك مجاناً"
        />
      </div>
    </main>
  );
}