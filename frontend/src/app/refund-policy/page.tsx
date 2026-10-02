'use client';

import { LegalPage, type LegalSection } from '@/components/legal/legal-page';

const SECTIONS: LegalSection[] = [
  {
    title: { ar: 'نطاق هذه السياسة', en: 'Scope of This Policy' },
    body: {
      ar: 'تنطبق سياسة استرداد الأموال هذه («السياسة») على جميع عمليات الدفع التي تتم عبر منصة Tadreesly («المنصة»)، سواء كانت اشتراكات شهرية أو سنوية، أو مدفوعات بالبطاقات الإلكترونية، أو تحويلات بنكية، أو أي وسيلة دفع أخرى معتمدة. بموجب قيامك بأي عملية دفع عبر المنصة، فإنك تقر باطلاعك الكامل على هذه السياسة وقبولك المسبق والمُلزم لبنودها، ولا يُقبل أي استرداد إلا وفق الأحكام الواردة فيها حصرًا.',
      en: 'This Refund Policy ("Policy") applies to all payments made through the Tadreesly Platform ("Platform"), whether monthly or yearly subscriptions, card payments, bank transfers, or any other accepted payment method. By making any payment through the Platform, you acknowledge that you have fully read and unconditionally accept this Policy in advance, and no refund shall be granted except strictly under the provisions contained herein.',
    },
  },
  {
    title: { ar: 'قاعدة عامة: لا استرداد بعد الاستخدام', en: 'General Rule: No Refund After Use' },
    body: {
      ar: 'تُعتبر جميع المدفوعات عبر المنصة مقابل خدمات رقمية تُقدَّم فورًا عند إتمام الدفع أو قبول إثبات الدفع. ونظرًا لطبيعة الخدمات الرقمية التي لا يمكن إرجاعها بعد تسليمها واستهلاكها، فإن المنصة لا تقدم استردادًا كاملًا أو جزئيًا بأي حال من الأحوال بعد أن يبدأ المستخدم في استخدام الخدمة، مهما كانت المدة أو نسبة الاستخدام، إلا في الحالات الاستثنائية المحددة حصرًا في هذه السياسة.',
      en: 'All payments on the Platform are for digital services delivered immediately upon completed payment or approval of payment proof. Given the nature of digital services, which cannot be returned once delivered and consumed, the Platform does not offer full or partial refunds under any circumstances after the user begins using the service, regardless of duration or usage percentage, except in the exceptional cases strictly defined in this Policy.',
    },
  },
  {
    title: { ar: 'الحالات الاستثنائية للاسترداد', en: 'Exceptional Refund Cases' },
    items: [
      { ar: 'فشل تقني إثباتي من جانب المنصة: إذا تعذّر تشغيل الخدمة المشتراة بالكامل بسبب عطل تقني مثبت وموثق من جانب المنصة، ولم يُعالَج خلال 7 أيام عمل من إبلاغنا، يجوز النظر في استرداد نسبي وفق تقدير الإدارة.', en: 'Proven technical failure on the Platform\'s side: if the purchased service entirely fails to operate due to a documented technical fault on the Platform\'s side, not resolved within 7 business days of notification, a prorated refund may be considered at management\'s discretion.' },
      { ar: 'دفع مكرر (مزدوج): إذا تم خصم نفس المبلغ مرتين لنفس الاشتراك بسبب خطأ تقني موثق، يُسترَد المبلغ المكرر بالكامل خلال مدة لا تتجاوز 14 يومًا.', en: 'Duplicate payment: if the same amount is charged twice for the same subscription due to a documented technical error, the duplicate amount is fully refunded within no more than 14 days.' },
      { ar: 'خطأ من بوابة الدفع: إذا تم خصم المبلغ دون إنشاء أي اشتراك أو تفعيل أي خدمة فعلية، يُسترَد المبلغ بالكامل بعد التحقق.', en: 'Payment gateway error: if the amount is charged without creating any subscription or activating any actual service, the full amount is refunded after verification.' },
      { ar: 'فسخ خلال فترة التجربة: لا تُفرض رسوم على فترة التجربة المجانية أساسًا، ولا يُخصم أي مبلغ إلا بعد موافقتك الصريحة على الاشتراك المدفوع.', en: 'Cancellation during trial: no fees are charged for the free trial at all, and no amount is charged except upon your explicit consent to the paid subscription.' },
    ],
  },
  {
    title: { ar: 'لا يشملها الاسترداد إطلاقًا', en: 'Never Eligible for Refund' },
    body: {
      ar: 'لا تُقبل أي طلبات استرداد في الحالات التالية على سبيل الحصر لا المثال:',
      en: 'No refund requests are accepted in the following cases, which are exhaustive and not illustrative:',
    },
    items: [
      { ar: 'تغيّر الرأي أو عدم الحاجة للخدمة أو انشغال المستخدم.', en: 'Change of mind, no longer needing the service, or user unavailability.' },
      { ar: 'استخدام جزء من الخدمة مهما كان حجمه (دروس، اختبارات، بث مباشر، مواعيد، مجتمعات).', en: 'Using any part of the service regardless of extent (lessons, exams, live sessions, appointments, communities).' },
      { ar: 'عدم رضا المستخدم عن جودة المحتوى الذي أنشأه هو أو غيره من المعلمين — فالمنصة وسيلة تقنية ولا تضمن نتائج تعليمية.', en: 'User dissatisfaction with the quality of content created by them or other teachers — the Platform is a technical tool and does not guarantee educational outcomes.' },
      { ar: 'توقف المستخدم عن الدفع أو رفض بوابة الدفع لمعاملاته، أو محاولة الاحتيال أو إساءة استخدام الخدمة.', en: 'User stopping payments, payment gateway declining their transactions, or attempted fraud or service abuse.' },
      { ar: 'إلغاء أو تعليق الحساب بسبب مخالفة الشروط والأحكام.', en: 'Account cancellation or suspension due to violation of the Terms & Conditions.' },
      { ar: 'الاشتراكات التي تم تفعيلها والاستفادة منها ولو ليوم واحد.', en: 'Subscriptions that were activated and used even for a single day.' },
    ],
  },
  {
    title: { ar: 'كيفية تقديم طلب الاسترداد', en: 'How to Submit a Refund Request' },
    items: [
      { ar: 'يُقدَّم طلب الاسترداد كتابيًا عبر صفحة اتصل بنا أو البريد الإلكتروني الرسمي للمنصة خلال 7 أيام من تاريخ العملية.', en: 'Refund requests must be submitted in writing via the Contact page or the official Platform email within 7 days of the transaction date.' },
      { ar: 'يجب أن يتضمن الطلب: رقم المعاملة أو الاشتراك، تاريخ الدفع، المبلغ، وسبب الطلب مع أي مستندات داعمة.', en: 'The request must include: transaction or subscription number, payment date, amount, and the reason with any supporting documents.' },
      { ar: 'تفحص المنصة الطلب خلال مدة أقصاها 14 يومًا من استلامه، وتُخطرك بالقرار كتابيًا.', en: 'The Platform reviews the request within a maximum of 14 days of receipt and notifies you of the decision in writing.' },
      { ar: 'الطلبات الناقصة أو غير الموثقة أو المقدمة بعد المهلة لا تُنظر إطلاقًا.', en: 'Incomplete, undocumented, or late requests are not considered at all.' },
    ],
  },
  {
    title: { ar: 'آلية إرجاع المبالغ', en: 'Refund Processing Method' },
    items: [
      { ar: 'يُرجع المبلغ عبر نفس وسيلة الدفع الأصلية ما أمكن، أو عبر أي وسيلة تقبلها المنصة.', en: 'Refunds are returned via the original payment method where possible, or via any method accepted by the Platform.' },
      { ar: 'مدة الإرجاع الفعلية تعتمد على بوابة الدفع وقد تصل إلى 14 يومًا إضافيًا بعد الموافقة.', en: 'Actual return time depends on the payment gateway and may take up to 14 additional days after approval.' },
      { ar: 'لا تُحول الاستردادات إلى نقد أو رصيد قابل للتحويل لأي طرف آخر، ولا تُستبدل بأي خدمة.', en: 'Refunds are not converted to cash or transferable credit to any other party, nor exchanged for any service.' },
    ],
  },
  {
    title: { ar: 'قرار المنصة نهائي', en: 'Platform Decision Is Final' },
    body: {
      ar: 'تتمثل السلطة التقديرية الكاملة في تقييم طلبات الاسترداد وتطبيق هذه السياسة لإدارة المنصة وحدها، ويكون قرارها نهائيًا وملزمًا وغير قابل للطعن، وفق ما تقتضيه القوانين المعمول بها في دولة الأردن. أي استرداد يتم خارج نطاق هذه السياسة يُعد لطفًا من المنصة ولا يُنشئ أي حق مكتسب أو سابقة لأي طلبات لاحقة.',
      en: 'The Platform\'s management alone holds full discretionary authority to evaluate refund requests and apply this Policy, and its decision is final, binding, and not subject to appeal, subject to the laws of Jordan. Any refund granted outside this Policy is an act of goodwill that creates no vested right or precedent for any future requests.',
    },
  },
  {
    title: { ar: 'الاحتيال وإساءة الاستخدام', en: 'Fraud & Abuse' },
    body: {
      ar: 'يحتفظ النظام بسجلات تتبع كاملة لعمليات الدفع والاسترداد. أي محاولة احتيال، أو طلبات استرداد متكررة، أو استخدام وسائل دفع مسروقة، أو التلاعب ببوابة الدفع، تؤدي فورًا إلى: رفض الطلب، تعليق الحساب، إبلاغ السلطات المختصة، ومطالبة المستخدم بتعويض المنصة عن جميع الأضرار والأتعاب القانونية الناتجة.',
      en: 'The system maintains full traceability records of payments and refunds. Any fraud attempt, repeated refund requests, use of stolen payment methods, or manipulation of the payment gateway results immediately in: request denial, account suspension, reporting to competent authorities, and a claim against the user for all resulting damages and legal fees.',
    },
  },
  {
    title: { ar: 'الاشتراكات السنوية والتجديد التلقائي', en: 'Annual Subscriptions & Auto-Renewal' },
    items: [
      { ar: 'لا تُسترَد الاشتراكات السنوية بعد التفعيل أو الاستخدام، مهما كانت الفترة المتبقية.', en: 'Annual subscriptions are not refunded after activation or use, regardless of remaining period.' },
      { ar: 'يمكن إلغاء التجديد التلقائي في أي وقت من صفحة الإعدادات قبل موعد التجديد، ويوقف الإلغاء أي خصم قادم دون استرداد ما سبق دفعه.', en: 'Auto-renewal can be cancelled anytime from the settings page before the renewal date; cancellation stops future charges without refunding previously paid amounts.' },
      { ar: 'إذا فشل التجديد التلقائي بسبب انتهاء البطاقة أو رفض البوابة، تُعلَّق الخدمة لحين تسوية الدفع دون استرداد أو تعويض.', en: 'If auto-renewal fails due to card expiry or gateway rejection, the service is suspended until payment is settled, without refund or compensation.' },
    ],
  },
  {
    title: { ar: 'النزاعات', en: 'Disputes' },
    body: {
      ar: 'قبل اللجوء لأي جهة خارجية أو بنك أو بوابة دفع لفتح نزاع (Chargeback)، يجب عليك التواصل مع المنصة أولًا خلال 7 أيام من العملية لحل أي مشكلة. أي نزاع مفتوح دون هذا التواصل المسبق قد يؤدي إلى تعليق حسابك وإدراجك في قائمة المبالغات. تخضع النزاعات غير المحلولة وديًا للقوانين الأردنية والتحكيم في عمان، الأردن.',
      en: 'Before resorting to any external party, bank, or payment gateway to open a dispute (chargeback), you must contact the Platform first within 7 days of the transaction to resolve any issue. Any dispute opened without this prior contact may lead to account suspension and being placed on a disputes list. Unresolved disputes are governed by Jordanian law and arbitration in Amman, Jordan.',
    },
  },
  {
    title: { ar: 'تعديلات هذه السياسة', en: 'Changes to This Policy' },
    body: {
      ar: 'يحق للمنصة تعديل هذه السياسة في أي وقت دون إشعار مسبق، ويُعتبر استمرارك في استخدام المنصة أو إجراء أي عملية دفع بعد التعديل موافقة ملزمة على السياسة المحدثة. النسخة السارية هي المنشورة على هذه الصفحة دائمًا.',
      en: 'The Platform may amend this Policy at any time without prior notice; continued use of the Platform or making any payment after an amendment constitutes binding acceptance of the updated Policy. The version in effect is always the one published on this page.',
    },
  },
  {
    title: { ar: 'اتصل بنا', en: 'Contact Us' },
    body: {
      ar: 'لأي استفسار حول هذه السياسة أو طلبات الاسترداد، تواصل معنا عبر صفحة اتصل بنا أو البريد الإلكتروني الرسمي للمنصة، مع إرفاق كامل تفاصيل العملية.',
      en: 'For any question about this Policy or refund requests, contact us via the Contact page or the official Platform email, attaching full transaction details.',
    },
  },
];

export default function RefundPolicyPage() {
  return (
    <LegalPage
      icon="file"
      title={{ ar: 'سياسة استرداد الأموال', en: 'Refund Policy' }}
      subtitle={{ ar: 'الأحكام الحصرية للاسترداد — اقرأها قبل الدفع', en: 'The exclusive refund terms — read before you pay' }}
      updated="06/08/2026"
      sections={SECTIONS}
    />
  );
}