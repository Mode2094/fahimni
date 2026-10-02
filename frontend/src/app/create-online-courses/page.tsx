import type { Metadata } from 'next';
import { Breadcrumbs } from '@/components/seo/breadcrumbs';
import { Faq } from '@/components/seo/faq';
import { CtaSection } from '@/components/seo/cta-section';
import { SITE_NAME, absoluteUrl, DEFAULT_OG_IMAGE } from '@/lib/seo';

export const metadata: Metadata = {
  title: 'إنشاء كورسات أونلاين وبيعها — دروس مسجلة بأرباح متكررة',
  description:
    'أنشئ كورساتك المسجلة مرة واحدة وبعها مراراً: منشئ كورسات، فيديو وملفات، تحديد سعرك، وبوابات دفع تصلك مباشرة — منصة إنشاء وبيع الدورات أونلاين للمعلمين.',
  alternates: { canonical: '/create-online-courses' },
  openGraph: {
    title: 'إنشاء كورسات أونلاين وبيعها — دروس مسجلة',
    description: 'أنشئ كورسك المسجل مرة واحدة، بعْه مراراً، واجمع أرباحك مباشرة عبر بوابات الدفع.',
    type: 'website',
    siteName: SITE_NAME,
    url: absoluteUrl('/create-online-courses'),
    locale: 'ar_AR',
    images: [{ url: absoluteUrl(DEFAULT_OG_IMAGE), width: 512, height: 512, alt: SITE_NAME }],
  },
  twitter: { card: 'summary', title: 'إنشاء كورسات أونلاين — Tadreesly', description: 'أنشئ دروسك المسجلة وبعها أونلاين بسعر تحدده أنت.' },
};

const STEPS: [string, string][] = [
  ['سجّل واحصل على منصتك', 'حسابك يمنحك منصة تعليمية جاهزة تبدأ منها فوراً، بدون خبرة تقنية.'],
  ['ابنِ هيكل الكورس', 'أقسام، دروس، فيديو وملفات — رتب المحتوى بالطريقة التي يفهمها طلابك.'],
  ['حدد السعر وانشر', 'سعر كل كورس بيدك. اختر بيعه مباشرة أو عبر بطاقات تسجيل برمز QR.'],
  ['اجمع أرباحك مباشرة', 'بوابات دفع مربوطة بحسابك (مدى، باي بال وغيرها) وفواتير تلقائية لكل عملية دفع.'],
];

const BENEFITS: [string, string][] = [
  ['تُباع مرة وتعمل لك دائماً', 'الدروس المسجلة لا تستهلك وقتك عند البيع — أنشئها مرة وقدمها لأي عدد من الطلاب.'],
  ['سعر تتحكم فيه بالكامل', 'لا عمولات ولا تسعير من طرف ثالث — أنت من يضع الثمن ويستلم الأرباح.'],
  ['محتوى منظم يحترم طلابك', 'أقسام ودروس مرتبة، مع اختبارات بعد كل جزء لقياس الفهم.'],
  ['بطاقات تسجيل ذكية', 'رمز QR يُسجّل الطالب تلقائياً في الكورس — اطبعها أو أرسلها واتساب.'],
];

const FAQ: [string, string][] = [
  ['هل أحتاج كاميرا احترافية لإنشاء كورس؟', 'لا. سجل دروسك بهاتفك أو جهازك، ارفعها، ونظمها في أقسام. الجودة تأتي من وضوح شرحك، لا من المعدات.'],
  ['كيف أبيع الكورس للطلاب؟', 'يمكنك تحديد سعر وربطه ببوابة دفع، أو استخدام بطاقات تسجيل برمز QR، أو مشاركة رابط منصتك مباشرة.'],
  ['هل يمكنني تحديث الكورس بعد نشره؟', 'نعم، عدّل الأقسام والدروس والملفات متى شئت، ويظهر التحديث لطلابك فوراً.'],
  ['من يملك المحتوى الذي أرفعه؟', 'أنت. المحتوى، الطلاب والعلاقات معهم كلها ملكك — نحن نوفر المنصة فقط.'],
  ['هل أستطيع إضافة اختبارات داخل الكورس؟', 'نعم، أضف اختبارات بأسئلة متعددة الأنواع بعد الأقسام لقياس تقدم الطلاب.'],
];

export default function CreateOnlineCoursesPage() {
  return (
    <main className="bg-white">
      <div className="max-w-5xl mx-auto px-4 sm:px-6 py-12">
        <Breadcrumbs items={[{ name: SITE_NAME, href: '/' }, { name: 'إنشاء كورسات أونلاين', href: '/create-online-courses' }]} />

        <header className="text-center mb-12">
          <h1 className="text-3xl sm:text-5xl font-black text-gray-900 leading-tight">
            أنشئ كورسك المسجل مرة واحدة وبعه مراراً
          </h1>
          <p className="text-gray-500 text-lg mt-5 max-w-2xl mx-auto leading-relaxed">
            حوّل خبرتك إلى دروس مسجلة تُباع أونلاين بسعر تحدده أنت — عبر منشئ كورسات متكامل
            يجمع الفيديو والملفات والاختبارات في مكان واحد.
          </p>
          <div className="flex flex-wrap justify-center gap-3 mt-8">
            <a href="/register/teacher" className="px-8 py-3.5 rounded-2xl text-white font-bold shadow-lg hover:opacity-90 transition-opacity" style={{ background: 'linear-gradient(135deg, #0d9488, #14b8a6)' }}>
              ابدأ إنشاء كورسك مجاناً
            </a>
            <a href="/pricing" className="px-8 py-3.5 rounded-2xl font-bold border-2 border-teal-600 text-teal-700 hover:bg-teal-50 transition-colors">
              خطط الأسعار
            </a>
          </div>
        </header>

        <section aria-labelledby="steps-heading" className="mb-14">
          <h2 id="steps-heading" className="text-2xl font-bold text-gray-900 text-center">من فكرة إلى كورس يُباع في 4 خطوات</h2>
          <ol className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-8">
            {STEPS.map(([title, desc], i) => (
              <li key={title} className="rounded-2xl border border-gray-200 p-5">
                <span className="flex h-9 w-9 items-center justify-center rounded-full text-white font-bold text-sm" style={{ background: '#0d9488' }}>{i + 1}</span>
                <h3 className="font-bold text-gray-900 mt-3">{title}</h3>
                <p className="text-gray-600 text-sm leading-relaxed mt-1.5">{desc}</p>
              </li>
            ))}
          </ol>
        </section>

        <section aria-labelledby="benefits-heading" className="mb-14">
          <h2 id="benefits-heading" className="text-2xl font-bold text-gray-900">لماذا تبيع كورسات مسجلة؟</h2>
          <ul className="grid sm:grid-cols-2 gap-4 mt-8">
            {BENEFITS.map(([title, desc]) => (
              <li key={title} className="rounded-2xl border border-gray-200 p-5">
                <h3 className="font-bold text-gray-900">{title}</h3>
                <p className="text-gray-600 text-sm leading-relaxed mt-1.5">{desc}</p>
              </li>
            ))}
          </ul>
        </section>

        <CtaSection
          title="ابدأ بدرس واحد اليوم"
          text="سجّل حسابك، ارفع درساً واحداً، وشارك رابطه مع طالب. ستلمس بنفسك كيف تعمل حلقة إنشاء وبيع الكورسات كاملة."
          primaryLabel="أنشئ حسابك مجاناً"
        />

        <section aria-labelledby="related-heading" className="mb-14">
          <h2 id="related-heading" className="text-xl font-bold text-gray-900 mb-4">مقالات ومرتبطة</h2>
          <div className="grid sm:grid-cols-2 gap-3">
            <a href="/lms-for-teachers" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">LMS لإدارة الكورسات والطلاب</h3>
              <p className="text-gray-600 text-sm mt-1">نظم دوراتك وطلابك واختباراتك في نظام إدارة تعلم واحد.</p>
            </a>
            <a href="/live-online-classes" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">الحصص المباشرة عبر Google Meet</h3>
              <p className="text-gray-600 text-sm mt-1">أضف دروساً مباشرة مكمّلة لكورساتك المسجلة.</p>
            </a>
            <a href="/for-teachers" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">منصة تعليمية للمعلمين</h3>
              <p className="text-gray-600 text-sm mt-1">كل أدوات التدريس في منصة واحدة تحمل اسمك.</p>
            </a>
            <a href="/teacher-website" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">موقع خاص للمعلم</h3>
              <p className="text-gray-600 text-sm mt-1">اعرض كورساتك على صفحة عامة بألوانك وشعارك.</p>
            </a>
          </div>
        </section>

        <Faq title="أسئلة شائعة عن إنشاء الكورسات" items={FAQ} />

        <CtaSection
          title="كورسك القادم يبدأ الآن"
          text="كل درس مسجل ترفعه اليوم يعمل لصالحك غداً. أنشئ منصتك وابدأ أول كورس دون أي تكلفة مسبقة."
          primaryLabel="ابدأ مجاناً"
        />
      </div>
    </main>
  );
}