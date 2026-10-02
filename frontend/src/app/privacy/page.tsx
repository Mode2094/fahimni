'use client';

import { LegalPage, type LegalSection } from '@/components/legal/legal-page';

const SECTIONS: LegalSection[] = [
  {
    title: { ar: 'مقدمة ومن نحن', en: 'Introduction & Who We Are' },
    body: {
      ar: 'هذه السياسة توضح كيفية جمع واستخدام وحماية بياناتك عند استخدامك لمنصة Tadreesly («المنصة»). باستخدامك للمنصة فإنك توافق على ما ورد في هذه السياسة. المنصة هي معالج البيانات الأساسي لجميع المعلومات المقدمة عبر خدماتها، وجميع بياناتك تُعالج وفق القوانين المعمول بها في دولة الأردن وقانون حماية البيانات الساري.',
      en: 'This policy explains how Tadreesly ("the Platform") collects, uses and protects your data. By using the Platform you agree to this policy. The Platform is the primary data controller for all information provided through its services, and your data is processed in accordance with the laws of Jordan and applicable data protection legislation.',
    },
  },
  {
    title: { ar: 'البيانات التي نجمعها', en: 'Data We Collect' },
    items: [
      { ar: 'بيانات الحساب: الاسم، البريد الإلكتروني، رقم الهاتف، كلمة المرور (مشفّرة)، الصورة الشخصية، والدولة.', en: 'Account data: name, email, phone number, encrypted password, profile photo and country.' },
      { ar: 'بيانات الاستخدام: المحتوى الذي تنشئه (دورات، دروس، اختبارات، واجبات، تعليقات)، تفاعلاتك مع المنصة، تقدم الطلاب، ونتائج الاختبارات.', en: 'Usage data: content you create (courses, lessons, exams, assignments, comments), your interactions, student progress and exam results.' },
      { ar: 'البيانات التقنية: عنوان IP، نوع المتصفح والجهاز، نظام التشغيل، معرّف الجهاز، وسجلات الوصول والوقت.', en: 'Technical data: IP address, browser and device type, operating system, device identifier, and access logs.' },
      { ar: 'بيانات الدفع: تُعالج بطاقات الدفع والتحويلات عبر بوابات دفع آمنة (PayPal، PayTabs وغيرها) ولا نخزن بيانات بطاقتك الكاملة على خوادمنا إطلاقًا.', en: 'Payment data: card and transfer payments are processed via secure payment gateways (PayPal, PayTabs, etc.); we never store your full card details on our servers.' },
      { ar: 'إثباتات الدفع اليدوي: صور الوصل وبيانات التحويل التي ترفعها، وتُستخدم حصرًا للتحقق من الدفع.', en: 'Manual payment proofs: receipt images and transfer details you upload, used solely to verify payments.' },
    ],
  },
  {
    title: { ar: 'كيف نستخدم بياناتك', en: 'How We Use Your Data' },
    items: [
      { ar: 'تشغيل المنصة: تقديم الدروس والاختبارات والمحتوى وتسجيل الطلاب وتتبع التقدم.', en: 'Operating the Platform: delivering lessons, exams, content, student enrollment and progress tracking.' },
      { ar: 'المدفوعات والاشتراكات: إنشاء الفواتير، تفعيل الخطط، والتحقق من إثباتات الدفع.', en: 'Payments and subscriptions: invoicing, plan activation and payment proof verification.' },
      { ar: 'الأمان: حماية الحسابات من الوصول غير المصرح به، كشف الاحتيال، وتوثيق الجهاز.', en: 'Security: protecting accounts from unauthorized access, fraud detection and device verification.' },
      { ar: 'التواصل: إرسال الإشعارات والرسائل الهامة المتعلقة بالخدمة وتحديثاتها.', en: 'Communication: sending notifications and important service-related messages.' },
      { ar: 'التحسين: تحليل الاستخدام بشكل مجمع لإصلاح الأخطاء وتحسين التجربة — دون تحديد الهوية.', en: 'Improvement: aggregate analysis of usage to fix bugs and improve experience — without identifying individuals.' },
    ],
  },
  {
    title: { ar: 'مشاركة البيانات', en: 'Data Sharing' },
    body: {
      ar: 'لا نبيع بياناتك ولا نؤجرها لأي طرف ثالث إطلاقًا. نشارك بياناتك فقط في الحالات التالية:',
      en: 'We never sell or rent your data to any third party. We only share your data in the following cases:',
    },
    items: [
      { ar: 'مزودو البنية التحتية: شركات الاستضافة، قواعد البيانات، والتخزين السحابي لضمان تشغيل الخدمة.', en: 'Infrastructure providers: hosting, database and cloud storage companies necessary to run the service.' },
      { ar: 'بوابات الدفع: لمعالجة مدفوعاتك (PayPal، PayTabs) — وهم ملتزمون بمعايير PCI-DSS.', en: 'Payment gateways: to process your payments (PayPal, PayTabs) — they are PCI-DSS compliant.' },
      { ar: 'الجهات الرسمية: عند طلب قضائي أو قانوني ملزم من السلطات المختصة وفق القانون.', en: 'Authorities: upon a binding judicial or legal request from competent authorities under law.' },
      { ar: 'داخل المنصة: يرى المعلمون بيانات طلابهم المسجلين في منصاتهم فقط (الاسم، البريد، التقدم).', en: 'Within the Platform: teachers only see data of students enrolled in their own platform (name, email, progress).' },
    ],
  },
  {
    title: { ar: 'أمن البيانات', en: 'Data Security' },
    items: [
      { ar: 'تشفير كلمات المرور بمعايير bcrypt — لا تُخزَّن كنص صريح أبدًا.', en: 'Passwords are hashed with bcrypt — never stored in plain text.' },
      { ar: 'تشفير الاتصالات عبر HTTPS (TLS) بين متصفحك وخوادمنا.', en: 'All communications are encrypted over HTTPS (TLS) between your browser and our servers.' },
      { ar: 'تقييد الوصول: لا يمكن لأي موظف أو نظام الوصول لبياناتك إلا عند الحاجة وبصلاحيات محددة.', en: 'Access control: no employee or system accesses your data unless strictly necessary, with role-based permissions.' },
      { ar: 'نسخ احتياطي منتظم للبيانات لحمايتها من الفقدان والكوارث.', en: 'Regular data backups protect against loss and disasters.' },
      { ar: 'مراجعات أمنية مستمرة ومراقبة للأنشطة المشبوهة.', en: 'Continuous security reviews and monitoring of suspicious activity.' },
    ],
  },
  {
    title: { ar: 'حقوقك', en: 'Your Rights' },
    items: [
      { ar: 'الاطلاع: طلب نسخة من بياناتك الشخصية المخزنة لدينا.', en: 'Access: request a copy of your personal data we hold.' },
      { ar: 'التصحيح: تعديل أي معلومات غير دقيقة في حسابك.', en: 'Rectification: correct any inaccurate information in your account.' },
      { ar: 'الحذف: طلب حذف حسابك وبياناتك (مع الإبقاء على ما يلزمه القانون كالسجلات المالية).', en: 'Deletion: request deletion of your account and data (while retaining what the law requires, such as financial records).' },
      { ar: 'تصدير البيانات: الحصول على بياناتك بصيغة منظمة قابلة للقراءة.', en: 'Portability: obtain your data in a structured, readable format.' },
      { ar: 'لتقديم أي طلب: راسلنا عبر صفحة الدعم أو البريد الرسمي للمنصة، ونرد خلال 30 يومًا.', en: 'To exercise any right: contact us via the support page or official email — we respond within 30 days.' },
    ],
  },
  {
    title: { ar: 'الاحتفاظ بالبيانات', en: 'Data Retention' },
    body: {
      ar: 'نحتفظ ببياناتك طوال فترة استخدامك للمنصة. بعد حذف حسابك تُحذف بياناتك الشخصية خلال 30 يومًا، باستثناء ما يلزمه القانون (السجلات المالية والضريبية تُحتفظ بها وفق المدد القانونية المقررة).',
      en: 'We retain your data for as long as you use the Platform. After account deletion, your personal data is removed within 30 days, except what the law requires (financial and tax records are kept for the legally prescribed periods).',
    },
  },
  {
    title: { ar: 'ملفات تعريف الارتباط (Cookies)', en: 'Cookies' },
    items: [
      { ar: 'نستخدم ملفات تعريف الارتباط الأساسية لتشغيل المنصة (جلسات الدخول، الإعدادات، الأمان).', en: 'We use essential cookies to operate the Platform (sessions, settings, security).' },
      { ar: 'لا نستخدم ملفات تتبع إعلانية تبيع بياناتك لأطراف ثالثة.', en: 'We do not use advertising trackers that sell your data to third parties.' },
      { ar: 'يمكنك تعطيل ملفات تعريف الارتباط من متصفحك، مع العلم أن بعض الميزات قد تتأثر.', en: 'You may disable cookies in your browser, though some features may be affected.' },
    ],
  },
  {
    title: { ar: 'بيانات القاصرين', en: 'Minors' },
    body: {
      ar: 'المنصة مخصصة للمعلمين والبالغين. إذا كان عمرك أقل من 18 عامًا، يتطلب استخدامك موافقة ولي الأمر أو المؤسسة التعليمية المسؤولة عنك. عند علمنا بجمع بيانات قاصر دون موافقة، نحذفها فورًا.',
      en: 'The Platform is intended for teachers and adults. If you are under 18, your use requires consent of a parent/guardian or the responsible educational institution. If we learn we have collected a minor\'s data without consent, we delete it immediately.',
    },
  },
  {
    title: { ar: 'النقل الدولي للبيانات', en: 'International Transfers' },
    body: {
      ar: 'قد تُعالج بياناتك على خوادم خارج بلدك لضمان سرعة الخدمة واستمراريتها. عند حدوث ذلك نلتزم بآليات حماية معادلة للمستوى المطلوب في بلدك.',
      en: 'Your data may be processed on servers outside your country to ensure service speed and continuity. When this happens, we maintain protection mechanisms equivalent to the required level in your country.',
    },
  },
  {
    title: { ar: 'تعديلات هذه السياسة', en: 'Changes to This Policy' },
    body: {
      ar: 'قد نحدّث هذه السياسة من وقت لآخر. عند إجراء تغييرات جوهرية سنخطرك عبر البريد أو داخل المنصة، ويعتبر استمرارك في الاستخدام بعد التحديث موافقة على السياسة المحدثة.',
      en: 'We may update this policy from time to time. Material changes will be announced via email or in-app notice; continued use after the update constitutes acceptance of the updated policy.',
    },
  },
  {
    title: { ar: 'تواصل معنا', en: 'Contact Us' },
    body: {
      ar: 'لأي استفسار أو طلب بخصوص بياناتك، تواصل معنا عبر صفحة الدعم الفني داخل المنصة أو عبر البريد الإلكتروني الرسمي، وسنرد خلال مدة لا تتجاوز 30 يومًا.',
      en: 'For any question or request regarding your data, contact us through the in-app support page or the official email — we respond within no more than 30 days.',
    },
  },
];

export default function PrivacyPage() {
  return (
    <LegalPage
      icon="shield"
      title={{ ar: 'سياسة الخصوصية', en: 'Privacy Policy' }}
      subtitle={{ ar: 'كيف نجمع ونستخدم ونحمي بياناتك', en: 'How we collect, use and protect your data' }}
      updated="06/08/2026"
      sections={SECTIONS}
    />
  );
}
