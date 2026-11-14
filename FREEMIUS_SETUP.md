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

### ضبط Redirect الشراء الموقّع (إلزامي)
في `Plans → Customization`:
1. فعّل **Redirect Checkout to a custom URL**.
2. أدخل رابط صفحة النجاح على نطاقك: `https://app.<نطاقك>/checkout/success`
   (يجب أن يكون HTTPS، **وألا يعيد توجيهًا على الخادم** — وإلا يفشل التحقق من التوقيع).
3. بعد الشراء سيعيد Freemius التوجيه إلى هذا الرابط مع `license_id`, `email`, `plan_id`, `signature`, … ويقوم backend بالتحقق والربط.

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

Freemius يوفّر بيئة اختبار (Sandbox) مطابقة للإنتاج.

> **مهم (وفق التوثيق الرسمي):** `FREEMIUS_SANDBOX=true` **وحده لا يفتح الـCheckout في وضع Sandbox**. Freemius يتطلب تمرير **Sandbox Token + Context** المولّدين رسميًا إلى الـCheckout.

> **عناوين Staging الفعلية** (من `deploy/env/staging.env.example`):
> - Webhook: `https://api-staging.fahimni.me/api/v1/payments/freemius-webhook`
> - Redirect موقّع: `https://staging.fahimni.me/checkout/success`
>
> لا تُستخدم مفاتيح الإنتاج على Staging، ولا تُفعّل Production قبل اجتياز
> القائمة أدناه بالكامل.

### الآلية الرسمية للـSandbox (مطبَّقة تلقائيًا في النظام)
عند تفعيل `freemius_sandbox` (من `.env` أو لوحة الأدمن)، يضيف النظام تلقائيًا إلى رابط Hosted Checkout الوسيطين الرسميين:
- `sandbox` = الرمز
- `s_ctx_ts` = السياق

ووفق التوثيق الرسمي:
```
ctx           = time()  (أو أي سلسلة عشوائية فريدة)
sandbox_token = md5(ctx . product_id . secret_key . public_key . 'checkout')
```
هذا مطبَّق في `FreemiusGateway::getSandboxParams()`، ويُدمج في `hostedCheckoutLink()` — فلا حاجة لبناء الرابط يدويًا. (الرمز مشتق وغير سرّي، ولا يُسجَّل في اللوجز.)

### خطوات الاختبار
1. في `Dashboard → Settings → Sandbox` فعّل بيئة الاختبار.
2. اضبط `FREEMIUS_SANDBOX=true` في `.env` (أو من لوحة الأدمن) — سيُمرَّر الـSandbox token تلقائيًا.
3. افتح الشراء من `/checkout` بشخصية Sandbox، وأكمل الدفع ببطاقة اختبار رسمية:
   `4242 4242 4242 4242` (Visa)، أو `5555 5555 5555 4444` (Mastercard)، أو باستخدام زر **Prefill Form** الظاهر في Sandbox.
4. تأكد أن:
   - الـWebhook يصل ويُسجَّل في `webhook_events`.
   - يظهر صف في `fs_entitlements` للمستأجر الصحيح.
   - تظهر ميزات الخطة (PlanEngine) للطالب.
5. بعد التأكد، عطّل Sandbox واستخدم مفاتيح الإنتاج.

> **ملاحظة:** اختباراتنا الآلية تحاكي (mock) واجهة Freemius ولا تحتاج حسابًا حقيقيًا، لكنها **لا تُثبت** التكامل الحيّ. التحقق النهائي (Checkout + Webhook + token/context فعليًا على HTTPS عام) **يتطلب حساب Freemius حقيقيًا/بيئة Sandbox فعلية ونطاقًا عامًا** — لا يمكن إثباته بأي اختبار آلي أو على بيئة محلية.

### ✅ قائمة متطلبات اختبار Sandbox الحقيقي (يجب اجتيازها قبل الإنتاج)

**حساب/إعدادات مطلوبة من صاحب المشروع:**
- [ ] حساب Freemius (Sandbox) + منتج SaaS مُنشأ.
- [ ] `FREEMIUS_PRODUCT_ID` (منتج Sandbox).
- [ ] `FREEMIUS_API_TOKEN` (Bearer token).
- [ ] `FREEMIUS_PRODUCT_SECRET_KEY` (توقيع الـWebhook).
- [ ] `FREEMIUS_PUBLIC_KEY`.
- [ ] `FREEMIUS_SANDBOX=true` (أو من لوحة الأدمن).
- [ ] ربط كل خطة محلية بـ Plan IDs الخاصة بـ Sandbox.
- [ ] Webhook على `https://<نطاق-التجربة>/api/v1/payments/freemius-webhook` بأحداث `license.*`.
- [ ] **Redirect URL موقّع** في `Plans → Customization` → `https://<نطاق-التجربة>/checkout/success` (HTTPS، بلا إعادة توجيه على الخادم).
- [ ] نطاق/رابط عام يمكن لـFreemius الوصول إليه (أو نفق مؤقت مثل ngrok/cloudflared للاختبار).

**سيناريوهات يجب التحقق منها فعليًا (لا بالتخمين):**
- [ ] شراء شهري: Checkout → **redirect موقّع** → `complete` يتحقق ويُفعّل + Webhook.
- [ ] شراء سنوي: نفس الشيء بدورة `yearly`.
- [ ] **التحقق من التوقيع الموقّع:** تعديل `signature` أو أي param → `unverified` (422) بلا تفعيل.
- [ ] **إثبات الملكية:** فتح رابط ناجح بجلسة مستخدم آخر (بريد مختلف) → `mismatch` (403).
- [ ] الـWebhook يصل فعليًا ويُسجَّل في `webhook_events` بحالة `processed`.
- [ ] التوقيع: إرسال طلب بتوقيع خاطئ → 401.
- [ ] إلغاء التجديد (Cancel) → **يبقى الوصول فعالًا حتى `expiration`** ثم ينتهي تلقائيًا.
- [ ] التجديد (Renewal) → تمديد `expiration` وتحديث الحالة عبر Webhook.
- [ ] ترقية/تخفيض خطة (Upgrade/Downgrade) → رابط ترقية يعمل والفروق صحيحة.
- [ ] استرداد (Refund) → الأثر يعود عبر Webhook.
- [ ] حذف الترخيص (`license.deleted`) → إزالة `fs_entitlements` وإبطال الصف المحلي.
- [ ] الحالة المؤقتة: قطع الاتصال/تعطيل المفتاح مؤقتًا → الحدث يبقى غير processed ثم ينجح عبر المصالحة.
- [ ] عميل غير معروف (بريد بدون مستأجر) → `no_tenant` للمراجعة (لا تفعيل، لا فقدان).
- [ ] **مستخدم يملك منصتين:** كل ترخيص يُفعّل منصته عبر redirect موقّع في جلستها.
- [ ] تأكيد أن محتوى المعلم **لا يُحذف** إطلاقًا عند انتهاء الاشتراك.

> لا يُعتقد الدفع جاهزًا للإنتاج حتى تُوثَّق نتائج كل سيناريو أعلاه على بيئة Sandbox. **الـMocks لا تُعد دليلًا كافيًا** على التكامل الحقيقي.

---

## 7. تجربة الشراء من طرف المعلم

1. المعلم يسجّل ويذهب إلى `/pricing`.
2. يختار خطة ودورة فوترة → زر الشراء.
3. يُحوَّل إلى صفحة `/checkout` (المبنية على Freemius فقط).
4. يضغط "المتابعة للدفع" → يُحوَّل إلى **Hosted Checkout** الخاص بـ Freemius (البريد مُعبّأ مسبقًا للقراءة فقط لضمان تطابق هوية العميل).
5. بعد الدفع:
   - Freemius يعيد التوجيه إلى `/checkout/success` مع بيانات موقّعة (`signature`).
   - الصفحة تستدعي `complete` الذي **يتحقق من التوقيع + الجلسة + البريد + الـintent** ثم يربط ويُفعّل.
   - Freemius يرسل Webhook → يتحقق النظام من التوقيع → يعيد جلب الترخيص → يُثبّت `fs_entitlements`.
   - الصفحة لا تعرض النجاح إلا بعد رد backend بحالة `active`.

---

## 8. إدارة الاشتراك

- **بوابة العملاء:** الباك إند يُنشئ رابط دخول سحري عبر Freemius:
  `POST /api/v1/subscriptions/freemius/portal` → `portal_url`.
- **الإلغاء:** `POST /api/v1/subscriptions/freemius/cancel` — يُلغي **التجديد التلقائي** في Freemius.
  - **مهم:** `is_canceled` في Freemius تعني «إيقاف التجديد» فقط، **وليست إلغاءً فوريًا للوصول**. لذلك يبقى وصول المعلم وجميع ميزات خطته فعّالة حتى `expiration` (نهاية الفترة المدفوعة). يُوقف الوصول فقط عند انتهاء `expiration` فعليًا أو عند سحب الترخيص (`revoked`/`deactivated`/`deleted`).
- **الترقية/التخفيض:** عند وجود ترخيص فعّال، يُنشئ الباك إند رابط ترقية عبر Freemius API (Freemius يحسب الفروق/proration تلقائيًا)؛ لا نحسب الفروق محليًا.
- **الاسترداد:** يُنفَّذ من لوحة Freemius؛ يتزامن الأثر عبر الـWebhook.
- **الكوبونات:** تُدار داخل Freemius (عبر لوحته أو رابط الشراء)؛ لا يوجد حقل كوبون محلي في صفحة الدفع حتى لا يُعرض خصم غير مطبَّق.

---

## 8.1 موثوقية الـWebhook والتعافي (Recovery)

التفعيل لا يعتمد أبدًا على متصفح العميل، بل على إشعار موقّع **أو** إعادة جلب خادمية. لمنع فقدان أي حدث دفع حقيقي:

1. **لا يُعتبر الحدث ناجحًا إلا بعد نجاح المزامنة فعليًا.** إن فشلت إعادة جلب الترخيص من Freemius (timeout/شبكة/5xx)، يبقى الحدث **غير مُعالَج** ويُعاد المحاولة، ولا يُسجَّل «processed».
2. **قفل آمن لكل حدث:** جدول `webhook_events` يمنع معالجة نفس الحدث مرتين بالتزامن (قفل `processing` + `locked_until`). إعادة الإرسال أثناء القفل تُؤكَّد دون إعادة المعالجة.
3. **إعادة المحاولة تلقائيًا:** عند إعادة إرسال Freemius لنفس الحدث (أو عبر مهمة المصالحة)، يُعاد ربط الحدث (`reclaim`) ومعالجته بأمان — بلا مضاعفة للأثر المالي أو الصلاحيات (المزامنة idempotent عبر `fs_license_id`).
4. **مهمة مصالحة دورية:** `ReconcileFreemiusWebhooksJob` تعمل **كل 5 دقائق** وتعيد معالجة الأحداث العالقة (failed/stale) حتى `MAX_ATTEMPTS` (12). الأحداث المستنفدة تبقى `failed` وتُسجَّل في اللوجز للتنبيه.
   - البحث في اللوجز: `freemius.reconcile.done` و`freemius.reconcile.retry_failed` و`freemius.webhook.processing_error`.

### متى تتصرف يدويًا
- إن ظل حدث `failed` بعد 12 محاولة: تحقق من صحة `FREEMIUS_API_TOKEN` واتصال الشبكة، ثم استدعِ `php artisan tinker` أو أعد تشغيل المهمة يدويًا:
  `php artisan queue:dispatch` أو `(new App\Jobs\ReconcileFreemiusWebhooksJob)->handle(app(App\Services\FsEntitlementSyncService::class))`.

---

## 8.2 ربط الترخيص بالمستأجر (Tenant) — المسار الرسمي الموقّع

**آلية الإثبات المعتمدة (Hosted Checkout Signed Redirect):** بعد الشراء، يعيد Freemius توجيه المشتري إلى URL نضبطه في `Plans → Customization → Redirect Checkout to a custom URL` (نطاقنا: `/checkout/success`) مع بيانات الشراء + **`signature`** = `HMAC-SHA256(fullUrlWithoutSignature, PRODUCT_SECRET_KEY)`.

عند العودة، يستدعي الأمان الخادمي (`GET /api/v1/subscriptions/freemius/complete`) ويكون الربط مقبولًا **فقط** عند تحقق **كل** ما يلي:
1. **توقيع الـredirect صحيح** (يثبت أن البيانات أصيلة من Freemius وغير مزوّرة) — يُتحقق عبر `hash_equals`.
2. **جلسة مستأجر مُصادَقة** (نفس المتصفح الذي بدأ الدفع).
3. **بريد الـredirect = بريد المستخدم الحالي** — وهذا ما **يثبت ملكية الشراء لهذا المستخدم/المستأجر**؛ ترخيص عميل آخر يحمل بريدًا مختلفًا → **403 mismatch**.
4. **وجود Checkout Intent خادمي** لهذا المستأجر (أُنشئ عند `initiate`) — يمنع ربط ترخيص صحيح لم يبدأه هذا المستأجر → **422 no_intent**.

ثم يُحفظ الربط `license_id → tenant_id` **بشكل دائم**، ويُرفض (409) نقل ترخيص مرتبط بمستأجر آخر. الترتيب في `resolveTenant` (للـWebhook):
1. ربط قائم `license_id → tenant_id` (لا يُنقل أبدًا)،
2. intent مرتبط فعليًا بالترخيص/مرجع الدفع،
3. intent فريد بالبريد فقط — وإلا **`no_tenant`** (مراجعة، لا تفعيل).

> **لا** يُستخدم البريد وحده ولا `fs_user_id` كإثبات ملكية. عميل Freemius واحد قد يملك عدة تراخيص لعدة منصات، وكل ترخيص يُربط بمنصته عبر توقيعه الموقّع + جلسته.

### ⚠️ قيود رسمية مهمة
- **Hosted Checkout لا يُرجع مرجع تكامل مخصّص في webhooks الترخيص**؛ الربط الموثوق يعتمد على **الـredirect الموقّع + الجلسة + الـintent** (أعلاه).
- **`signature` متاح فقط في Hosted Checkout** (Redirect موقّع). أما Overlay Checkout فيعيد `purchase.license_id` عبر callback client-side — دون دليل ملكية خادمي — لذلك **لا يُعتمد كإثبات**.
- عند أي غموض يبقى الترخيص **قيد المراجعة** دون تفعيل.

### ردود `complete` (حالات مستقلة)
| الحالة | المعنى |
|---|---|
| `active` | تم التحقق والمطابقة والربط، والاشتراك فعّال |
| `pending` | لم يصل التأكيد أو فشل مؤقت — أعد الاستعلام لاحقًا |
| `unverified` | توقيع غائب/غير صحيح (422) |
| `mismatch` | بريد الـredirect لا يطابق المستخدم الحالي (403) |
| `no_intent` | لا يوجد intent لهذا المستأجر (422) |
| `unknown` | الترخيص غير موجود (404) |
| `inactive` | الترخيص موجود لكنه غير فعّال (مسحوب/منتهٍ) |

---

## 8.3 حماية محتوى المعلمين (سياسة صريحة)

**لا يُحذف محتوى تعليمي أبدًا بسبب انتهاء الاشتراك.** الدورات والدروس والامتحانات والواجبات والجلسات وتسجيلات الطلاب تبقى محفوظة بالكامل.

- `PurgeExpiredTenantContentJob` أُوقف تمامًا (no-op) وأُزيلت جدولته من `routes/console.php`.
- `ExpiryFollowUpJob` تحوّل إلى **تذكير تجديد ودّي** فقط، ويعتمد على الحالة الفعلية من `SubscriptionState` (لا يرسل تنبيهًا لمستأجر تجدّد بالفعل، ولا يهدد بالحذف).
- أي حذف للمحتوى يجب أن يكون **عملية يدوية مقصودة** من المشغّل، لا آلية.

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
| وصول المعلم توقف بعد الإلغاء | خطأ متوقع: الإلغاء يوقف التجديد فقط لا الوصول | تأكد من أن `expiration` ما زال مستقبليًا؛ لا يحتاج إجراءً |
| الاشتراك انتهى لكن الوصول ما زال مفتوحًا | صف محلي (mirror) قديم فعّال | النظام يستبعد صفوف Freemius المحلية تلقائيًا؛ شغّل `ReconcileFreemiusWebhooksJob` وتحقق من `fs_entitlements` |

---

## 12. اختبارات آلية

يشمل النظام اختبارات شاملة في `tests/Feature/FreemiusBillingTest.php` (37 اختبارًا):
- شراء شهري/سنوي، التفعيل عبر إعادة الجلب الخادمية.
- رفض التوقيع غير الصحيح، منع التكرار (replay)، تصحيح الأحداث غير المرتبة.
- **الإلغاء يبقي الوصول (`PlanEngine::hasFeature` قبل/بعد/حتى الانتهاء) + سحب الوصول عند الانتهاء/الإبطال.**
- **منع إحياء الوصول من صف محلي (mirror) منتهٍ/محذوف + تحديث الـCache.**
- **تعافي الـWebhook: فشل API مؤقت ثم نجاح إعادة المحاولة بلا مضاعفة.**
- **مهمة المصالحة `ReconcileFreemiusWebhooksJob` تعالج الأحداث العالقة بلا تكرار.**
- **منع المعالجة المتزامنة لنفس الحدث (قفل).**
- **ربط Tenant: لا إعادة ربط ترخيص عند تغيير البريد، والربط عبر Checkout Intent.**
- بوابة العملاء، عزل المستأجرين، رفض redirect/query مزيف، فرض USD، ومنع المعلم من تغيير معرّفات/أسعار Freemius.

تشغيلها: `vendor/bin/phpunit --filter FreemiusBillingTest`

> **تنبيه:** هذه الاختبارات تحاكي واجهة Freemius (HTTP mocks) ولا تُثبت التكامل الحيّ. الاختبار النهائي **يتطلب Freemius Sandbox فعليًا** (Checkout + Webhook حقيقيان) قبل أي نشر للإنتاج.
