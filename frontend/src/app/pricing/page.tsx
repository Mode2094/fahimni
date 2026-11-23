import PricingClient from '@/components/pricing/pricing-client';
import { TrialFaq } from '@/components/seo/trial-faq';
import { TrialCtaSection } from '@/components/seo/trial-cta-section';

const COMPARISON: { name: string; who: string; use: string }[] = [
  {
    name: 'الإنطلاقة',
    who: 'للمعلم الذي يبدأ: كورسات وطلاب بأعداد محدودة.',
    use: 'أطلق أول كورس لك بسعر بسيط، مع حماية الفيديوهات ومعظم أدوات التدريس.',
  },
  {
    name: 'المحترف',
    who: 'للمعلم النشط الذي يبني عدة دورات ويزيد طلابه.',
    use: 'مساحة وحدود أعلى، مع كل أدوات التدريس الأساسية وحماية الفيديوهات.',
  },
  {
    name: 'المتقدم',
    who: 'للأكاديمية النامية التي تحتاج أكبر المساحات وأقوى الأدوات.',
    use: 'أقصى الحدود والدعم المخصص، لتشغيل منصة تخدم مئات الطلاب بثقة.',
  },
];

const BASE_FACTS: [string, string][] = [
  ['هل أدفع لكل طالب؟', 'لا — تشترك في خطة ثابتة شهرياً أو سنوياً، والطلاب يضافون ضمن حدود خطتك بدون رسوم لكل مقعد.'],
  ['هل يمكنني ترقية خطتي لاحقاً؟', 'نعم، يمكنك الترقية أو التعديل في أي وقت من لوحة الاشتراك، وتُحتسب الفروقات تلقائياً.'],
  ['هل يشمل السعر الدعم الفني؟', 'جميع الخطط تشمل دعماً فنياً وتحديثات مستمرة للمنصة.'],
];

const TRIAL_FACTS: [string, string][] = [
  ['هل هناك فترة تجربة؟', 'نعم، الخطط تأتي بأيام تجربة تسمح لك باستكشاف المنصة قبل الالتزام.'],
  ['ماذا يحدث بعد انتهاء الفترة التجريبية؟', 'تختار الخطة التي تناسبك أو تنهي تجربتك. محتواك وبياناتك محفوظة لك.'],
];

export default function PricingPage() {
  return (
    <main>
      <PricingClient />
      <div className="max-w-5xl mx-auto px-4 sm:px-6 py-12">
        <section aria-labelledby="compare-heading" className="mb-14">
          <h2 id="compare-heading" className="text-2xl font-bold text-gray-900 text-center">أي خطة تناسبك؟</h2>
          <p className="text-gray-500 text-center mt-2 mb-3">الحدود الدقيقة للطلاب والتخزين والفيديوهات تظهر في البطاقات أعلاه — إليك دليلك السريع:</p>
          <p className="text-center mb-8">
            <span className="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-800">
              🛡️ حماية متقدمة للفيديوهات التعليمية — مشمولة في جميع الخطط
            </span>
          </p>
          <div className="grid md:grid-cols-3 gap-4">
            {COMPARISON.map((c) => (
              <div key={c.name} className="rounded-2xl border border-gray-200 p-5">
                <h3 className="font-bold text-gray-900 text-lg">{c.name}</h3>
                <p className="text-gray-600 text-sm mt-2 leading-relaxed">{c.who}</p>
                <p className="text-gray-500 text-sm mt-3 leading-relaxed">{c.use}</p>
              </div>
            ))}
          </div>
        </section>

        <section aria-labelledby="seo-related-heading" className="mb-14">
          <h2 id="seo-related-heading" className="text-xl font-bold text-gray-900 mb-4">تعرف على المنصة قبل الاشتراك</h2>
          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <a href="/for-teachers" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">منصة تعليمية للمعلمين</h3>
              <p className="text-gray-600 text-sm mt-1">كل أدوات التدريس في منصة تحمل اسمك.</p>
            </a>
            <a href="/create-online-courses" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">إنشاء كورسات أونلاين</h3>
              <p className="text-gray-600 text-sm mt-1">دروس مسجلة تباع بسعر تحدده أنت.</p>
            </a>
            <a href="/live-online-classes" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">الحصص المباشرة</h3>
              <p className="text-gray-600 text-sm mt-1">دروس مباشرة عبر Google Meet داخل منصتك.</p>
            </a>
            <a href="/lms-for-teachers" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">نظام إدارة التعلم</h3>
              <p className="text-gray-600 text-sm mt-1">الكورسات والطلاب والاختبارات في نظام واحد.</p>
            </a>
            <a href="/teacher-website" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">موقع خاص للمعلم</h3>
              <p className="text-gray-600 text-sm mt-1">صفحة عامة بألوانك وشعارك ودومينك.</p>
            </a>
            <a href="/online-teaching-platform" className="rounded-2xl border border-gray-200 p-5 hover:border-teal-300 hover:bg-teal-50/40 transition-colors">
              <h3 className="font-bold text-teal-700">Online teaching platform</h3>
              <p className="text-gray-600 text-sm mt-1">نظرة شاملة باللغة الإنجليزية.</p>
            </a>
          </div>
        </section>

        <TrialFaq title="أسئلة شائعة عن الاشتراك" baseItems={BASE_FACTS} trialItems={TRIAL_FACTS} />

        <TrialCtaSection
          title="ابدأ تجربتك المجانية اليوم"
          text="جرّب المنصة بأيام التجربة، أضف كورسك الأول، وشاهد كيف تتحول أدوات التدريس إلى منصتك الخاصة."
          primaryLabel="ابدأ الآن مجاناً"
          offTitle="اختر خطتك وابدأ الآن"
          offText="اختر الخطة المناسبة لمنصتك التعليمية، أضف كورسك الأول، وابدأ التدريس فوراً بعد تأكيد الاشتراك."
        />
      </div>
    </main>
  );
}