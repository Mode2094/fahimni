# Lemon Squeezy Integration (Merchant of Record)

دليل الربط الكامل بين المنصة و Lemon Squeezy — إعداد الحساب، ربط الخطط،
الويبهوك، الاختبار، والانتقال إلى الإنتاج.

> **مبدأ أساسي:** Laravel هو مصدر الحقيقة الداخلي لحالة الاشتراك. لا يتم
> تفعيل أي اشتراك بناءً على صفحة النجاح (redirect) — فقط عبر ويبهوك موقّع.
> الأسعار والخطط تأتي دائماً من الخادم ولا يُقبل أي سعر/خطة من المتصفح.

---

## 1. إنشاء حساب Lemon Squeezy

1. سجّل في [lemonsqueezy.com](https://www.lemonsqueezy.com) وافتح متجرك.
2. يجب تفعيل **"Sell Worldwide"** وحساب **"Demo store"** (وضع اختبار) من
   Settings → Stores.
3. للحصول على وضع الإنتاج (live) تحتاج موافقة Lemon Squeezy على حساب
   البائع (يستغرق عادة أياماً قليلة).

## 2. إنشاء Store

- Settings → Stores → Store Settings.
- انسخ **Store ID** (رقم) — ستُدخله في لوحة الأدمن أو في
  `LEMON_SQUEEZY_STORE_ID`.

## 3. إنشاء Product

- Products → New Product.
- أنشئ منتجاً لكل خطة من خطط المنصة (مثال: "LMS – النخبة").
- **لا يُستخدم سعر المنتج هنا كمصدر للسعر** — المنصة تفرض السعر النهائي
  (خطة ± كوبون ± تقسيط ± رسوم خدمة) عبر `custom_price` عند إنشاء الـ checkout.

## 4. إنشاء Variants

- داخل المنتج أنشئ **Variant** لكل دورة فوترة:
  - `Monthly` — اشتراك شهري (recurring).
  - `Yearly` — اشتراك سنوي (recurring).
- انسخ **Variant ID** لكل منهما (رقم يظهر في رابط الـ variant).

## 5. ربط Variant بالخطة

من لوحة أدمن المنصة (Frontend → Admin → Plans):

| الخطة الداخلية | حقل الإدخال |
|---|---|
| النخبة (شهري) | `Lemon Squeezy Variant ID — شهري` |
| النخبة (سنوي) | `Lemon Squeezy Variant ID — سنوي` |

تُحفظ المعرّفات داخل `plan.metadata.lemon_variant_id_monthly/yearly`
(JSONB) — الخطة نفسها تبقى مصدر السعر.

> إذا تُرك الحقلان فارغين لن تظهر Lemon Squeezy كخيار دفع لهذه الخطة.

## 6. إنشاء API Key

- Settings → API Keys → New API key.
- امنح الصلاحيات: `Checkouts (Read/Write)` + `Subscriptions (Read/Write)` +
  `Orders (Read)` + `Refunds (Write)` + `Variants (Read)` (لاختبار الاتصال).
- ضع القيمة في `LEMON_SQUEEZY_API_KEY`.

## 7. إنشاء Webhook

- Settings → Webhooks → New webhook.
- **URL:** `https://<دومينك>/api/v1/payments/lemonsqueezy-webhook`
- **Events:** فعّل:
  - `order_created`
  - `order_refunded`
  - `subscription_created`
  - `subscription_updated`
  - `subscription_cancelled`
  - `subscription_resumed`
  - `subscription_expired`
  - `subscription_paused`
  - `subscription_unpaused`
- **Signing secret:** أنشئ قيمة عشوائية (6–40 حرفاً) — هذه هي
  `LEMON_SQUEEZY_SIGNING_SECRET`.

## 8. الحصول على Signing Secret

عند إنشاء الويبهوك في لوحة Lemon Squeezy تظهر لك الـ secret مرة واحدة —
انسخها فوراً إلى البيئة. لا يمكن استرجاعها لاحقاً (تُنشأ من جديد).

## 9. ضبط ENV

أضف إلى `.env` (ولاحقاً إلى بيئة الإنتاج):

```dotenv
LEMON_SQUEEZY_ENABLED=true
LEMON_SQUEEZY_API_KEY=el_xxxxxxxxxxxxxxxxxxxxxxxx
LEMON_SQUEEZY_STORE_ID=123456
LEMON_SQUEEZY_SIGNING_SECRET=your-random-signing-secret
LEMON_SQUEEZY_API_URL=https://api.lemonsqueezy.com
```

ثم من لوحة الأدمن (Settings → Payments → Lemon Squeezy):

1. فعّل المفتاح `Enable`.
2. أدخل Store ID (إن لم يكن في env).
3. تأكد أن مؤشري `API Key` و `Signing Secret` خضراوان (مضبوطان من البيئة —
   لا تُخزن الأسرار في قاعدة البيانات إطلاقاً).
4. اضغط **Test connection**.

ثم أعد بناء الكاش: `php artisan config:cache`

## 10. اختبار الـ Checkout

1. سجّل دخول كمعلم في المتجر (واجهة الويب).
2. افتح صفحة الأسعار → اختر خطة → اختر "Lemon Squeezy".
3. ستُنشأ معاملة في Lemon Squeezy (وضع اختبار = شريط Test Mode في الـ
   checkout) وتُعاد إلى الصفحة بعد الدفع.
4. **لن يُفعَّل الاشتراك حتى يصلك `order_created`** عبر الويبهوك.

## 11. اختبار الويبهوك

في وضع Test Mode داخل Lemon Squeezy:

- Settings → Webhooks → **Send test request** لكل حدث.
- أو قم بعملية شراء تجريبية ببطاقة اختبار
  (`4242 4242 4242 4242`، أي تاريخ مستقبلي، أي CVV).

تحقق من المعالجة:

```bash
# حالة الأحداث المستلمة
docker exec lms-app php artisan tinker --execute="dump(\App\Models\WebhookEvent::latest()->limit(5)->get(['event_id','event_type','processing_status'])->toArray());"
```

- `processed` = عولج بنجاح.
- `failed` = حدث خطأ أثناء المعالجة (افحص `failure_reason` — لن يحتوي أسراراً).
- توقيع خاطئ = 401، حدث مكرر = acknowledged بدون إعادة معالجة.

## 12. الانتقال إلى الإنتاج

1. أكمل موافقة حساب البائع (live) في Lemon Squeezy.
2. أنشئ منتجات/Variants حقيقية (بأسعار فعلية) — ستحتاج Variant IDs جديدة
   لكل خطة.
3. حدّث ربط الخطط في لوحة الأدمن بالمعرّفات الجديدة.
4. أنشئ مفتاح API جديد للإنتاج + ويبهوك جديد بسرّ توقيع جديد.
5. حدّث `.env` الإنتاجي بالأسرار الجديدة.
6. أعد `php artisan config:cache` و `php artisan migrate --force`.
7. جرّب عملية شراء صغيرة ثم ارجعها (refund) للتحقق من `order_refunded`.

---

## كيف يعمل كل شيء (بنية التكامل)

```
User/Tenant
    ↓ (خطة + دورة فوترة من الخادم فقط)
Laravel initiate()  →  يتحقق من تفعيل البوابة وربط الـ variant
    ↓  custom_price = السعر المحسوب خادمياً (كوبون/تقسيط/رسوم خدمة)
Lemon Squeezy Checkout (مستضاف — لا يوجد UI بطاقة في المنصة)
    ↓  يدفع العميل
Webhook POST /api/v1/payments/lemonsqueezy-webhook
    ↓  1) تحقق X-Signature (HMAC-SHA256 للجسم الخام، hash_equals)
    ↓  2) claim في webhook_events (unique provider+event_id → منع التكرار/إعادة الإرسال)
    ↓  3) معالجة داخل DB::transaction
    ↓  4) تحديث payment + subscription + invoice + ledger
Laravel = مصدر الحقيقة الداخلي للاشتراك
```

### الأحداث المدعومة

| حدث Lemon Squeezy | الأثر الداخلي |
|---|---|
| `order_created` (شراء أولي) | إتمام الدفعة عبر `custom_data.payment_id` + تطابق `expected_subtotal_minor` + تفعيل الاشتراك |
| `order_created` (تجديد) | دفعة تجديد جديدة (فريدة بـ order id) + تمديد `ends_at` |
| `order_refunded` | `completed → refunded` (مرة واحدة) + سجل Ledger + إلغاء الفاتورة + إلغاء الاشتراك |
| `subscription_created` | ربط `lemon_subscription_id/customer_id/variant_id` + حالة trial/active |
| `subscription_updated` | مزامنة الحالة (`active/past_due/cancelled/expired/trialing`) |
| `subscription_cancelled` | `auto_renew=false` + `cancelled_at` + خدمة حتى `ends_at` |
| `subscription_resumed` / `subscription_unpaused` | `auto_renew=true` + `active` |
| `subscription_expired` | `status=expired` |
| `subscription_paused` | `auto_renew=false` (لا توجد حالة `paused` داخلية — الخدمة تستمر) |

### خريطة الحالات

| Lemon Squeezy | داخلي |
|---|---|
| `on_trial` | `trialing` |
| `active` | `active` |
| `cancelled` | `cancelled` (مع `auto_renew=false` وخدمة حتى `ends_at`) |
| `expired` | `expired` |
| `past_due` | `past_due` |
| `paused` | الحالة الحالية تُحفظ + `auto_renew=false` |

### الأمان

- **التوقيع:** `X-Signature` = `hex(HMAC-SHA256(signing_secret, rawBody))` —
  مقارنة `hash_equals` على الجسم الخام قبل أي معالجة. لا يوجد timestamp في
  الرأس → الحماية من إعادة الإرسال عبر جدول `webhook_events` الفريد.
- **Idempotency:** `unique(provider, event_id)` + `insertOrIgnore` + قفل صف
  الدفعة `lockForUpdate` + تحديثات guarded + فهرس `unique(gateway, transaction_id)`.
- **Tenant isolation:** الشراء الأولي يُوجَّه عبر `custom_data.payment_id`
  (يُنشأ خادمياً) مع تحقق `tenant_id`، والتجديدات عبر الاشتراك المرتبط.
- **لا ثقة بالمتصفح:** السعر عبر `custom_price` (يُفرض في initiate)، الـ variant
  من ربط الأدمن، والتفعيل فقط عبر ويبهوك موقّع.
- **الأسرار:** API key + signing secret من البيئة فقط (`config/services.php`)
  — لا تُخزن في DB ولا تُرسل للواجهة ولا تُسجَّل في السجلات.

### الملفات

| الملف | الدور |
|---|---|
| `app/Services/LemonSqueezyGateway.php` | عميل API + التحقق من التوقيع |
| `app/Http/Controllers/Api/LemonSqueezyWebhookController.php` | نقطة الويبهوك + معالجة الأحداث |
| `app/Models/WebhookEvent.php` | سجل idempotency |
| `database/migrations/...create_webhook_events_table.php` | جدول `webhook_events` |
| `config/services.php` | إعدادات `lemonsqueezy` (من env) |
| `routes/api.php` | `POST payments/lemonsqueezy-webhook` |
| `PaymentInitiateController` | `initiate` + `gateways` + `testConnection` |
| `SubscriptionPlan*` | ربط `lemon_variant_id_*` |
| `PlatformSettingController` | إعدادات الأدمن |
| `tests/Feature/LemonSqueezyTest.php` | 33 اختباراً (مُحاكاة HTTP) |

### ملاحظة بنيوية (تحسين مقترح لاحقاً)

الخدمات `PaddleGateway / PayPalGateway / PayTabsGateway / LemonSqueezyGateway`
تتبع نفس النمط (static methods) لكن بدون واجهة موحدة مشتركة كاملة (يوجد فقط
`AutoChargeGateway` للخصم التلقائي). إنشاء `PaymentProviderInterface` موحدة
وإعادة استخدامها عبر البوابات الأربع هو تحسين بنيوي مقترح لمرحلة لاحقة —
تعمّدنا عدم إعادة هيكلة البوابات الموجودة في هذا التكامل حفاظاً على
backward compatibility.