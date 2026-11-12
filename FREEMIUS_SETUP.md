# إعداد Freemius — بوابة اشتراكات المنصة الوحيدة

> هذا الدليل يشرح خطوة بخطوة كيف تُنشئ منتج Freemius، وتُعرّف الخطط، وتضبط المفاتيح والـWebhooks، وتُفعّل الدفع.
>
> **القاعدة الذهبية:** اشتراكات المعلمين (المدفوعة للمنصة) تُحصَّل **حصريًا** عبر Freemius و**بالدولار الأمريكي (USD)**. أما مدفوعات الطلاب وحجوزاتهم (PayTabs/PayPal) فهي **منفصلة تمامًا** ولا تمر أبدًا عبر Freemius.

---

## 1. لماذا Freemius؟

Freemius هو **Merchant of Record (MoR)** — أي أنه يتولى عنك:
- تحصيل المدفوعات، الضرائب العالمية (VAT)، والفواتير الرسمية.
- إدارة التجديد التلقائي، الفشل في الدفع (dunning)، والاسترداد.
- بوابة عملاء (Customer Portal) لإدارة الاشتراكات ووسائل الدفع.

نظامنا يحتفظ بمصدر الحقيقة للـ**ميزات والحصص والخطط** (PlanEngine/QuotaService)، بينما Freemius يتولى **الفوترة**. الـ**ترخيص (License)** هو ما يحدد صلاحية الوصول، لا الاشتراك نفسه — لذلك يستمر الوصول بعد الإلغاء حتى نهاية الفترة المدفوعة.

---

## 2. إنشاء المنتج والخطط

1. سجّل في [Freemius Dashboard](https://dashboard.freemius.com/register/).
2. أنشئ منتجًا جديدًا من نوع **SaaS**:  
   `Products → Add New → SaaS`.
3. اذهب إلى **Plans** وأنشئ خططك (شهرية وسنوية) **بعملة USD**:

   | خطتنا المحلية | السعر المحلي (USD) | مُعرّف Freemius (مثال) |
   |---|---|---|
   | Starter | 49 / 490 | Plan ID: `12345` (شهري) / `12346` (سنوي) |
   | Professional | 99 / 990 | Plan ID: `12347` / `12348` |
   | Enterprise | 199 / 1990 | Plan ID: `12349` / `12350` |

4. **العملة:** أسعار اشتراكات المنصة **USD حصريًا** (الواجهة والـAPI وقاعدة البيانات). Freemius لا يدعم الدينار الأردني (JOD)، ولا يوجد أي عرض بـJOD للاشتراكات. السعر المعروض = السعر المُحصَّل (USD)، ويجب أن **يطابق** السعر المضبوط في خطة Freemius تمامًا.
5. (مستحسن) اترك خيار **Restrict Single Subscription Per User** مُفعّلاً — يمنع اشتراكين متعارضين لنفس المستخدم.

### التمييز بين Plan ID وPricing ID ودورة الفوترة
- **Plan ID** = الخطة نفسها (مثلاً "Starter"). وهو **ما نستخدمه للشراء**.
- **Pricing ID** = خيار تسعير محدد داخل الخطة (يفيد عند وجود عدة أسعار لنفس الخطة). اختياري؛ يُخزَّن للدقة ولا يُستخدم مباشرة في رابط الشراء.
- **دورة الفوترة (billing cycle)** = شهري/سنوي، وهي **خاصية داخل الخطة**: لكل دورة خطة Freemius مستقلة بمعرّف مختلف. لذلك نربط `freemius_monthly_plan_id` و`freemius_yearly_plan_id` (وليس معرّفًا واحدًا)، ونمرّر الدورة عبر رابط الـCheckout الصحيح.

> **للحصول على المعرّفات:** Plan ID يظهر بجانب اسم الخطة في صفحة **Plans**. Pricing ID يظهر عند فتح الخطة بجانب كل خيار تسعير.

---

## 3. ضبط متغيرات البيئة على الخادم

أضف ما يلي إلى ملف `.env` على الخادم (لا تُضعه في git أبدًا):

```env
FREEMIUS_ENABLED=true
FREEMIUS_PRODUCT_ID=12345
FREEMIUS_API_TOKEN=xxxxxxxxxxxxxxxxxxxxxxxx
FREEMIUS_PRODUCT_SECRET_KEY=xxxxxxxxxxxxxxxxxxxxxxxx
FREEMIUS_PUBLIC_KEY=pk_xxxxxxxxxxxxxxxx
FREEMIUS_API_URL=https://api.freemius.com
FREEMIUS_CHECKOUT_URL=https://checkout.freemius.com
FREEMIUS_SANDBOX=false
```

- **FREEMIUS_API_TOKEN**: من `Dashboard → Settings → API & Keys → API Bearer Authorization Token`.
- **FREEMIUS_PRODUCT_SECRET_KEY**: من `Settings → API & Keys → Secret Key` (يُستخدم للتحقق من توقيع الـWebhook).
- **FREEMIUS_PUBLIC_KEY**: من نفس الصفحة (يُستخدم من الـJS SDK في الواجهة).
- **FREEMIUS_PRODUCT_ID**: يظهر في نفس قسم `API & Keys`.

> الأسرار تُقرأ **من البيئة فقط** ولا تُخزَّن في قاعدة البيانات ولا تُعاد عبر أي API. الواجهة تعرض فقط مؤشرات `*_set` (موجود/غير موجود).

### ربط الخطط (افتراضي عبر البيئة)
إن لم تُضبط المعرّفات من لوحة الأدمن، تُقرأ من البيئة بهذه الصيغة:

```env
FREEMIUS_PLAN_STARTER_MONTHLY_ID=12345
FREEMIUS_PLAN_STARTER_YEARLY_ID=12346
FREEMIUS_PLAN_PROFESSIONAL_MONTHLY_ID=12347
FREEMIUS_PLAN_PROFESSIONAL_YEARLY_ID=12348
FREEMIUS_PLAN_ENTERPRISE_MONTHLY_ID=12349
FREEMIUS_PLAN_ENTERPRISE_YEARLY_ID=12350
```

الصيغة: `FREEMIUS_PLAN_{SLUG}_{MONTHLY|YEARLY}_ID` (حيث `SLUG` هو slug الخطة بحروف كبيرة).

---

## 4. ربط الخطط من لوحة الأدمن (تجاوز اختياري)

كل خطة محلية يمكن ربطها بـ Freemius من الواجهة (للمدير العام فقط):

`Admin → Settings → Plans → (اختر خطة) → Freemius Plan mapping`

- **Freemius Plan ID — شهري/سنوي**: الأهم للشراء.
- **Pricing ID — شهري/سنوي**: اختياري (للدقة عند تعدد الأسعار لنفس الخطة).

القيمة في لوحة الأدمن **تتجاوز** قيمة البيئة. الربط يُخزَّن داخل `subscription_plans.metadata` (لا أعمدة جديدة). **لا يستطيع المعلم** تغيير الأسعار أو المعرّفات — مسارات `admin/plans` محمية بـ`middleware('admin')` (super-admin) + Policy، وقيمة `currency` تُفرض من الخادم كـUSD.

---

## 5. إعداد الـWebhook (إلزامي للتفعيل)

بدون الـWebhook لن يُفعَّل أي اشتراك — لأن التفعيل **لا يحدث أبدًا** من متصفح العميل، بل فقط من إشعار موقّع من Freemius (أو إعادة جلب خادمية).

1. اذهب إلى `Dashboard → Settings → Webhooks` وأضف الرابط:

   ```
   https://api.<نطاقك>/api/v1/payments/freemius-webhook
   ```

   (عبر nginx في الإنتاج: `https://<نطاقك>/api/v1/payments/freemius-webhook`)

2. اختر الأحداث التالية **حصريًا**:

   ```
   license.created
   license.extended
   license.shortened
   license.updated
   license.cancelled
   license.expired
   license.plan.changed
   license.deleted
   ```

   > **مهم:** لا تُستخدم أحداث `subscription.*` لتزامن الصلاحيات (وفق توثيق Freemius الرسمي). تستخدم فقط لسير عمل اشتراكي خاص. نظامنا يتجاهلها بخصوص الصلاحيات.

3. انسخ **Secret Key** إلى `FREEMIUS_PRODUCT_SECRET_KEY`.

### كيف يتحقق النظام من التوقيع
- يقرأ ترويسة `x-signature` ويحسب `hash_hmac('sha256', rawBody, secret_key)`.
- يقارن باستخدام `hash_equals` (مقارنة ثابتة الزمن).
- يستخدم جدول `webhook_events` (قيد فريد على `provider+event_id`) لمنع تكرار الأحداث وإعادة التشغيل (replay).
- **لا يثق بمحتوى الإشعار**: يعيد جلب الترخيص من Freemius API ويعتمد الرد الرسمي — لذلك الأحداث غير المرتبة تُصحّح تلقائيًا، والتوقيع يُتحقق منه **قبل** أي قراءة أو كتابة.

---

## 6. اختبار التكامل (Sandbox)

Freemius يوفّر بيئة اختبار (Sandbox) مطابقة للإنتاج:

1. في `Dashboard → Settings → Sandbox` فعّل بيئة الاختبار.
2. اضبط `FREEMIUS_SANDBOX=true` في `.env` (أو من لوحة الأدمن).
3. استخدم بطاقات الاختبار المتاحة في Freemius لإتمام عملية شراء تجريبية.
4. تأكد أن:
   - الـWebhook يصل ويُسجَّل في `webhook_events`.
   - يظهر صف في `fs_entitlements` للمستأجر الصحيح.
   - تظهر ميزات الخطة (PlanEngine) للطالب.
5. بعد التأكد، عطّل Sandbox واستخدم مفاتيح الإنتاج.

> **ملاحظة:** اختباراتنا الآلية تحاكي (mock) واجهة Freemius ولا تحتاج حسابًا حقيقيًا. التحقق النهائي من التكامل الحقيقي (Checkout + Webhook فعليان) **يتطلب حساب Freemius حقيقيًا/بيئة Sandbox فعلية** — لا يمكن إثباته بأي اختبار آلي وحده.

---

## 7. تجربة الشراء من طرف المعلم

1. المعلم يسجّل ويذهب إلى `/pricing`.
2. يختار خطة ودورة فوترة → زر الشراء.
3. يُحوَّل إلى صفحة `/checkout` (المبنية على Freemius فقط).
4. يضغط "المتابعة للدفع" → يُحوَّل إلى **Hosted Checkout** الخاص بـ Freemius (البريد مُعبّأ مسبقًا للقراءة فقط لضمان تطابق هوية العميل).
5. بعد الدفع:
   - Freemius يرسل Webhook → يتحقق النظام من التوقيع → يعيد جلب الترخيص → ينشئ `fs_entitlements`.
   - صفحة النجاح (`/checkout/success`) تتحقق خادميًا وتُظهر الحالة (لا تعتمد على الـRedirect).

---

## 8. إدارة الاشتراك

- **بوابة العملاء:** الباك إند يُنشئ رابط دخول سحري عبر Freemius:
  `POST /api/v1/subscriptions/freemius/portal` → `portal_url`.
- **الإلغاء:** `POST /api/v1/subscriptions/freemius/cancel` — يُلغي التجديد في Freemius مع **إبقاء الوصول حتى نهاية الفترة المدفوعة** (الاعتماد على `expiration` في الترخيص).
- **الترقية/التخفيض:** عند وجود ترخيص فعّال، يُنشئ الباك إند رابط ترقية عبر Freemius API (Freemius يحسب الفروق/proration تلقائيًا)؛ لا نحسب الفروق محليًا.
- **الاسترداد:** يُنفَّذ من لوحة Freemius؛ يتزامن الأثر عبر الـWebhook.

---

## 9. الترحيل من البوابات القديمة

تم **قطع كامل فوري** لتكاملات اشتراكات المنصة القديمة (Paddle / Lemon Squeezy / الدفع اليدوي / استخدام PayTabs+PayPal لاشتراكات المنصة):

- **البيانات التاريخية محفوظة بالكامل:** لم تُحذف أي جداول أو سجلات مالية (`payments`, `invoices`, `transactions`, `tenant_subscriptions`). البوابات القديمة أُزيلت من **مسار التحصيل فقط**.
- **الاشتراكات القائمة:** لا توجد اشتراكات فعلية عند المزودين القدامى وقت القطع. أي اشتراك محلي قديم (legacy) يبقى مقروءًا عبر `SubscriptionState` (مصدر موحّد) ويُحترم حتى انتهائه.
- **المدفوعات السابقة:** تعرض كما هي في `Admin → Payments/Invoices`.

### للتحويل اليدوي (إن وُجد عميل قديم)
نظرًا لأن Freemius MoR، لا يمكن "استيراد" وسيلة دفع قديمة. الخطوات الموصى بها لأي عميل اشتراكه سارٍ عند مزود قديم:
1. منحه فترة مجانية مكافئة للأيام المتبقية من الخطة القديمة عند اشتراكه في Freemius (يدويًا من لوحة Freemius أو عبر كوبون).
2. عدم خصم مبالغ مكررة خلال تداخل الفترات.
3. توثيق كل حالة ترحيل يدويًا.

---

## 10. قائمة البوابات المُزالة من اشتراكات المنصة

| البوابة | الملفات المُزالة | ما بقي |
|---|---|---|
| Paddle | `PaddleGateway`, `PaddleWebhookController`, `AutoRenewalService`, `AutoChargeGateway` | — |
| Lemon Squeezy | `LemonSqueezyGateway`, `LemonSqueezyWebhookController`, `docs/payments/lemonsqueezy.md` | — |
| الدفع اليدوي (اشتراكات) | مسار `payments/initiate` للاشتراكات + `manual-proof` + إعداداته | — |
| PayTabs/PayPal (اشتراكات المنصة) | منطق الاشتراكات داخل `PaymentInitiateController` | **بقي للحجوزات فقط** + اختبار اتصال للمدير |

> **لم يُمس:** مدفوعات الحجوزات/الطلاب (`PublicBookingController`, `BookingService`) عبر PayTabs/PayPal — تعمل بشكل منفصل تمامًا. وبما أنه لم تُعد بوابة تحصيل اشتراكات بديلة غير Freemius، فإن أي وظيفة مستقبلية تحتاج بوابة (غير اشتراكات المنصة) يجب بناؤها بمسار مخصص.

---

## 11. استكشاف الأخطاء

| المشكلة | السبب المحتمل | الحل |
|---|---|---|
| زر الدفع لا يظهر | Freemius غير مضبوط | تأكد من `FREEMIUS_ENABLED` + الأربعة مفاتيح الأساسية |
| 503 عند الشراء | `configured()` تُرجع false | تحقق من `FREEMIUS_API_TOKEN` و`FREEMIUS_PRODUCT_SECRET_KEY` و`FREEMIUS_PRODUCT_ID` |
| الخطة غير قابلة للشراء | لا يوجد Plan ID | اضبطه من لوحة الأدمن أو من `FREEMIUS_PLAN_*` |
| الاشتراك لا يُفعَّل | الـWebhook لا يصل / توقيع خاطئ | تحقق من رابط الـWebhook و`FREEMIUS_PRODUCT_SECRET_KEY` |
| "You already have an active subscription" | اشتراك قديم فعّال | ألغِه أولاً عبر بوابة العملاء |

---

## 12. اختبارات آلية

يشمل النظام اختبارات شاملة في `tests/Feature/FreemiusBillingTest.php`:
- شراء شهري/سنوي، التفعيل عبر إعادة الجلب الخادمية.
- رفض التوقيع غير الصحيح، منع التكرار (replay)، تصحيح الأحداث غير المرتبة.
- الإلغاء مع إبقاء الوصول، بوابة العملاء، عزل المستأجرين.
- رفض تفعيل الاشتراك عبر Redirect/Query مزيف.
- فرض USD على أسعار الخطط، ومنع المعلم من تغيير معرّفات/أسعار Freemius.

تشغيلها: `vendor/bin/phpunit --filter FreemiusBillingTest`
