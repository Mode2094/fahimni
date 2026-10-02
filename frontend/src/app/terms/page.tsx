'use client';

import { LegalPage, type LegalSection } from '@/components/legal/legal-page';

const SECTIONS: LegalSection[] = [
  {
    title: { ar: 'قبول الشروط', en: 'Acceptance of Terms' },
    body: {
      ar: 'باستخدامك منصة Tadreesly («المنصة») فإنك تقر بأنك قرأت وفهمت ووافقت على هذه الشروط والأحكام («الشروط») بشكل كامل. إذا لم توافق عليها، يرجى عدم استخدام المنصة. يحق لإدارة المنصة تعديل هذه الشروط في أي وقت، ويُعتبر استمرارك في الاستخدام بعد الإعلان عن التعديل موافقة ملزمة على الشروط المحدثة.',
      en: 'By using the Tadreesly Platform ("the Platform") you acknowledge that you have read, understood and fully agreed to these Terms & Conditions ("Terms"). If you do not agree, please do not use the Platform. The management may amend these Terms at any time; continued use after notice of the amendment constitutes binding acceptance of the updated Terms.',
    },
  },
  {
    title: { ar: 'وصف الخدمة', en: 'Description of Service' },
    body: {
      ar: 'توفر المنصة نظامًا تعليميًا متكاملًا يتيح للمعلمين إنشاء منصاتهم التعليمية (دورات، دروس، اختبارات، واجبات، بث مباشر، مواعيد، مجتمعات) وتسجيل الطلاب وإدارة المحتوى والمدفوعات. تخضع الخدمة لما هو متاح فعليًا ضمن خطة الاشتراك المختارة.',
      en: 'The Platform provides a complete educational system allowing teachers to create their own teaching platforms (courses, lessons, exams, assignments, live streaming, appointments, communities), enroll students, manage content and payments. The service is subject to what is actually available within the chosen subscription plan.',
    },
  },
  {
    title: { ar: 'الحسابات والتسجيل', en: 'Accounts & Registration' },
    items: [
      { ar: 'أنت مسؤول عن سرية بيانات دخولك وعن كل النشاطات التي تحدث من حسابك.', en: 'You are responsible for the confidentiality of your login credentials and for all activity occurring under your account.' },
      { ar: 'يجب أن تكون معلومات التسجيل صحيحة وكاملة، ويحق لنا تعليق أي حساب ببيانات مضللة.', en: 'Registration information must be accurate and complete; we may suspend any account with misleading information.' },
      { ar: 'لا يُسمح بإنشاء أكثر من حساب واحد لكل معلم دون موافقة مسبقة، ولا بنقل الحساب لأي طرف آخر.', en: 'Creating more than one account per teacher without prior approval is prohibited, as is transferring an account to another party.' },
      { ar: 'أنت المسؤول الوحيد عن المحتوى الذي تنشره في منصتك وعن إدارتها وعلاقاتك مع طلابك.', en: 'You are solely responsible for the content you publish on your platform, its management, and your relationships with your students.' },
    ],
  },
  {
    title: { ar: 'الاشتراكات والدفع', en: 'Subscriptions & Payments' },
    items: [
      { ar: 'تفعّل الخدمة فور إتمام الدفع أو قبول إثبات الدفع اليدوي من إدارة المنصة.', en: 'The service activates immediately upon completed payment or upon the management approving a manual payment proof.' },
      { ar: 'الأسعار والخطط كما هي معروضة في صفحة الأسعار، وقد تتغير للاشتراكات الجديدة مع إشعار مسبق.', en: 'Prices and plans are as displayed on the pricing page and may change for new subscriptions with prior notice.' },
      { ar: 'كود الخصم صالح وفق شروطه (المدة، الحد الأقصى للاستخدام) ولا يمكن استبداله نقدًا.', en: 'A coupon is valid per its terms (duration, usage limit) and cannot be exchanged for cash.' },
      { ar: 'الاسترداد: عند الإلغاء قبل نهاية الدورة، تُلغى التجديدات التلقائية دون استرداد مبالغ الدورات المنقضية، ويُترك قرار أي استرداد خاص لتقدير إدارة المنصة ووفق ما يقتضيه القانون.', en: 'Refunds: on cancellation before the end of a period, auto-renewals are stopped without refunding elapsed periods; any exceptional refund is at the management\'s sole discretion and subject to applicable law.' },
      { ar: 'للإدارة الحق في إلغاء الاشتراك أو تعليق الحساب عند وجود دفع مرفوض أو محاولة احتيال.', en: 'The management may cancel the subscription or suspend the account upon a rejected payment or attempted fraud.' },
    ],
  },
  {
    title: { ar: 'الملكية الفكرية', en: 'Intellectual Property' },
    items: [
      { ar: 'المحتوى الذي تنشئه أنت (دورات، دروس، اختبارات، مواد) يبقى ملكك الفكري بالكامل.', en: 'Content you create (courses, lessons, exams, materials) remains fully your intellectual property.' },
      { ar: 'تمنحنا ترخيصًا محدودًا لاستضافة وعرض هذا المحتوى لتشغيل الخدمة فقط، ولا نملكه ولا نستخدمه لأي غرض آخر.', en: 'You grant us a limited license to host and display this content solely to operate the service; we do not own it nor use it for any other purpose.' },
      { ar: 'المنصة وواجهاتها وبرمجياتها وعلاماتها التجارية مملوكة للمنصة أو لمرخصيها، ويحظر نسخها أو إعادة إنتاجها دون إذن كتابي.', en: 'The Platform, its interfaces, software and trademarks belong to the Platform or its licensors; copying or reproducing them without written permission is prohibited.' },
      { ar: 'تُحذف بياناتك ومحتواك عند إنهاء اشتراكك وفق سياسة الخصوصية.', en: 'Your data and content are deleted upon termination of your subscription per the Privacy Policy.' },
    ],
  },
  {
    title: { ar: 'قواعد الاستخدام والسلوك', en: 'Usage Rules & Conduct' },
    body: { ar: 'يحظر صراحة على جميع المستخدمين:', en: 'The following are strictly prohibited for all users:' },
    items: [
      { ar: 'نشر أي محتوى مخالف للقانون أو للآداب العامة أو محرضًا على الكراهية أو العنف.', en: 'Publishing any content that violates the law or public morals, or incites hatred or violence.' },
      { ar: 'محاولة اختراق المنصة أو تعطيلها أو استغلال ثغراتها أو جمع بيانات الآخرين دون إذن.', en: 'Attempting to hack, disrupt or exploit the Platform, or collecting others\' data without permission.' },
      { ar: 'إعادة بيع أو مشاركة محتوى الدروس والاختبارات المسجلة فيها دون إذن، أو كسر حماية الفيديو والمحتوى.', en: 'Reselling or sharing enrolled course content and exams without permission, or bypassing video and content protection.' },
      { ar: 'استخدام المنصة في غسيل الأموال أو أي نشاط مالي غير قانوني.', en: 'Using the Platform for money laundering or any illegal financial activity.' },
      { ar: 'الانتحال أو إنشاء حسابات وهمية أو التلاعب ببيانات الطلاب والتقدم والنتائج.', en: 'Impersonation, creating fake accounts, or tampering with student data, progress and results.' },
      { ar: 'المضايقات أو التنمر أو التهديد لأي مستخدم آخر بأي شكل.', en: 'Harassing, bullying or threatening any other user in any form.' },
    ],
  },
  {
    title: { ar: 'إخلاء المسؤولية', en: 'Disclaimer' },
    body: {
      ar: 'تُقدَّم المنصة «كما هي» (AS-IS) دون أي ضمانات صريحة أو ضمنية، بما فيها ضمانات القابلية للتسويق أو الملاءمة لغرض معين أو عدم التعدي. لا نضمن استمرارية الخدمة دون انقطاع أو خلوها من الأخطاء. النتائج التعليمية لطلابك تعتمد على جودة محتواك وجهودهم، والمنصة ليست مسؤولة عن النتائج الأكاديمية بأي شكل. كما لا نتحمل مسؤولية أي خسائر تنتج عن القرارات التعليمية أو التجارية التي تتخذها بناءً على استخدامك للمنصة.',
      en: 'The Platform is provided "AS-IS" without any warranties, express or implied, including merchantability, fitness for a particular purpose or non-infringement. We do not guarantee uninterrupted or error-free service. Your students\' educational outcomes depend on your content quality and their effort; the Platform is not responsible for academic results in any way. We are not liable for any losses arising from educational or business decisions you make based on your use of the Platform.',
    },
  },
  {
    title: { ar: 'حدود المسؤولية', en: 'Limitation of Liability' },
    body: {
      ar: 'إلى أقصى حد يسمح به القانون: (1) لا تتحمل المنصة أو إدارتها أو موظفوها أي مسؤولية عن أضرار غير مباشرة أو تبعية أو خاصة أو تأديبية أو عقابية، بما فيها فقدان الأرباح أو البيانات أو الفرص، الناشئة عن استخدامك أو عدم قدرتك على استخدام المنصة. (2) في جميع الأحوال، لا تتجاوز المسؤولية الإجمالية للمنصة تجاهك قيمة الاشتراكات التي دفعتَها خلال الأشهر الثلاثة السابقة للحدث المسبب للمسؤولية. (3) المنصة وسيلة تقنية لإدارة التعليم، وهي غير مسؤولة عن محتوى المعلمين أو علاقاتهم مع طلابهم أو نزاعاتهم المالية أو التعليمية.',
      en: 'To the maximum extent permitted by law: (1) The Platform, its management and staff bear no liability for indirect, incidental, special, consequential or punitive damages, including loss of profits, data or opportunities, arising from your use of or inability to use the Platform. (2) In all cases, the Platform\'s total liability to you shall not exceed the subscription fees you paid in the three months preceding the event giving rise to liability. (3) The Platform is a technical educational-management tool; it is not responsible for teachers\' content, their relationships with students, or their financial or educational disputes.',
    },
  },
  {
    title: { ar: 'التعويض', en: 'Indemnification' },
    body: {
      ar: 'توافق على تعويض المنصة وإدارتها وموظفيها والدفاع عنهم وإبقائهم في مأمن من أي مطالبات أو خسائر أو أضرار أو تكاليف (بما فيها أتعاب المحاماة) تنشأ عن: مخالفتك لهذه الشروط، أو محتواك، أو استخدامك غير القانوني للمنصة، أو انتهاكك لحقوق أي طرف ثالث (بما فيها حقوق الطلاب والملكية الفكرية).',
      en: 'You agree to indemnify, defend and hold harmless the Platform, its management and staff from any claims, losses, damages or costs (including attorneys\' fees) arising from: your breach of these Terms, your content, your unlawful use of the Platform, or your violation of any third-party rights (including students\' rights and intellectual property).',
    },
  },
  {
    title: { ar: 'تعليق وإنهاء الحسابات', en: 'Account Suspension & Termination' },
    items: [
      { ar: 'يحق لنا تعليق أو إنهاء أي حساب يخالف هذه الشروط أو يشكل خطرًا على المنصة أو مستخدميها، دون إشعار مسبق.', en: 'We may suspend or terminate any account that violates these Terms or poses a risk to the Platform or its users, without prior notice.' },
      { ar: 'يحق لك إلغاء اشتراكك وإنهاء حسابك في أي وقت من صفحة الإعدادات.', en: 'You may cancel your subscription and terminate your account at any time from the settings page.' },
      { ar: 'الاشتراكات المدفوعة لا تُسترد عند الإنهاء بسبب المخالفة.', en: 'Paid subscriptions are not refunded upon termination for breach.' },
      { ar: 'تستمر البنود التي تقتضي بطبيعتها الاستمرار بعد الإنهاء (الملكية الفكرية، التعويض، حدود المسؤولية، القانون الواجب التطبيق).', en: 'Clauses that by their nature survive termination (IP, indemnification, limitation of liability, governing law) remain in effect.' },
    ],
  },
  {
    title: { ar: 'تعديل الخدمة', en: 'Service Changes' },
    body: {
      ar: 'تسعى المنصة دائمًا لتحسين خدماتها، ويحق لها تعديل الميزات أو إيقاف بعضها أو تحديثها في أي وقت مع بذل جهد معقول لإشعار المستخدمين. لا يُعد أي تحسين أو تعديل في الخدمة أساسًا لأي مطالبة بالتعويض.',
      en: 'The Platform strives to continuously improve; it may modify, suspend or update features at any time while making reasonable efforts to notify users. No improvement or change constitutes grounds for any compensation claim.',
    },
  },
  {
    title: { ar: 'القانون الواجب التطبيق والتحكيم', en: 'Governing Law & Dispute Resolution' },
    body: {
      ar: 'تخضع هذه الشروط والعلاقة بينك وبين المنصة للقوانين الأردنية. يُحل أي نزاع نشأ عن هذه الشروط أو الخدمة وديًا أولًا عبر التواصل مع الدعم؛ وإن تعذر الحل خلال 30 يومًا، يُحال النزاع إلى التحكيم في عمان، الأردن، وفق قواعد الهيئة المختصة، ويكون حكم التحكيم نهائيًا وملزمًا للطرفين.',
      en: 'These Terms and the relationship between you and the Platform are governed by the laws of Jordan. Any dispute arising from these Terms or the service shall first be resolved amicably via support contact; if unresolved within 30 days, the dispute shall be referred to arbitration in Amman, Jordan, under the rules of the competent authority, and the arbitration award shall be final and binding on both parties.',
    },
  },
  {
    title: { ar: 'الإشعارات', en: 'Notices' },
    body: {
      ar: 'تُرسل الإشعارات الرسمية عبر البريد الإلكتروني المسجل في حسابك أو عبر الإشعارات داخل المنصة، وتُعتبر مقروءة بعد 24 ساعة من الإرسال أو فور اطلاعك عليها أيهما أسبق.',
      en: 'Official notices are sent to your registered email or via in-app notifications and are deemed received 24 hours after sending or upon your viewing, whichever is earlier.',
    },
  },
  {
    title: { ar: 'اتصل بنا', en: 'Contact Us' },
    body: {
      ar: 'لأي استفسار حول هذه الشروط أو الخدمة، تواصل معنا عبر صفحة الدعم الفني داخل المنصة.',
      en: 'For any question about these Terms or the service, contact us through the in-app support page.',
    },
  },
];

export default function TermsPage() {
  return (
    <LegalPage
      icon="file"
      title={{ ar: 'الشروط والأحكام', en: 'Terms & Conditions' }}
      subtitle={{ ar: 'بنود استخدام منصة Tadreesly — تقرأها مرة وتحميك دائمًا', en: 'Terms of using the Tadreesly Platform' }}
      updated="06/08/2026"
      sections={SECTIONS}
    />
  );
}
