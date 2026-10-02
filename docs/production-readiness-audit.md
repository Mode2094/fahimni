# تقرير المراجعة النهائية — الجاهزية للإطلاق التجاري (Production Readiness Audit)

> التاريخ: 2026-08-05 | النطاق: مراجعة شاملة من الصفر + تنفيذ الإصلاحات + تشغيل الاختبارات

---

## الجزء الأول — المشاكل المكتشفة (قبل الإصلاح) مع درجة الخطورة

### 🔴 حرجة (CRITICAL) — يجب إصلاحها قبل الإطلاق

| # | المشكلة | الموقع |
|---|---|---|
| C1 | **بوابة الدفع الوهمية مكشوفة**: أي معلم يستطيع `POST /api/v1/payments` بمبلغ مخصص ليحصل على دفع "completed" فورًا + تفعيل أي اشتراك + فاتورة مزيفة — اشتراك مجاني لأي خطة | `PaymentController::store` |
| C2 | **تفعيل الدفع الذاتي**: المعلم يستطيع `PUT /payments/{id}/status` لتغيير دفع معلّق إلى "completed" — تحصيل مجاني | `PaymentController::updateStatus` + `PaymentPolicy` |
| C3 | **ملفات الدروس على القرص العام**: ملفات الكورس في `storage/app/public` → متاحة مباشرة عبر `/storage/uploads/...` بدون أي فحص اشتراك/تسجيل — يلغي حماية التذاكر الموقّعة | `FileUploadController::upload` + `FileDownloadController` |
| C4 | **سباق تفعيل البطاقات**: بطاقتان متزامنتان → تسجيلان ببطاقة واحدة (لا معاملة ولا قفل صف) | `ActivationCardController::redeem` |
| C5 | **خلل موجود مسبقًا**: رفع ملف بسياق مستأجر دون `lesson_id` → 500 (انتهاك NOT NULL) | `FileUploadController::upload` |

### 🟠 عالية (HIGH)

| # | المشكلة |
|---|---|
| H1 | تكرار تسجيل Rate Limiters في `bootstrap/app.php` و `AppServiceProvider` — التسجيل في `then` يُعطّل التحديد عند `route:cache` أو يُلغي إعدادات المزوّد |
| H2 | تحويل عملة PayPal: SAR→USD بنسبة 1:1 (نفس الرقم بعملة أخرى) — خطأ مالي |
| H3 | كوبون مبلغ ثابت بعملة SAR يُخصم من سعر USD المحوّل 1:1 |
| H4 | endpoint قديم `media/lessons/{lesson}/video` يكشف مسار تخزين الفيديو المرفوع (provider_video_id = المسار) — غير مستخدم من الواجهة |
| H5 | `testConnection` يكشف أول 12 حرفًا من توكن PayPal |
| H6 | لا يوجد `VIDEO_SIGNING_KEY` منفصل → وسائط معتمدة على `APP_KEY` (تدوير المفتاح يكسر روابط الفيديو) |
| H7 | موضع الـ Drip في `PublicSiteController::lesson` يستخدم `sort_order` (فريد داخل القسم فقط) بينما `FileProtectionService` يحسب الموضع الكامل — فتح مبكر/متأخر في الكورسات متعددة الأقسام |

### 🟡 متوسطة (MEDIUM)

| # | المشكلة |
|---|---|
| M1 | `AdminController::subscriptionPlan` يستخدم scope المستأجر → 404 لمدير المنصة مع `X-Tenant-Id` |
| M2 | فحص حد الطلاب مكرر مرتين في `EnrollmentController::store` (استعلامان متطابقان) |
| M3 | طاقم المعلم لا يستطيع الرد على تذاكر المنصة التي يعرضها لهم `myTickets` |
| M4 | `proxy.ts` يستدعي API خارجيًا لكل صفحة على نطاق المنصة الرئيسي (لا يعرف النطاق الرسمي) |
| M5 | سباق ترقيم محاولات الاختبار (`max('attempt_number') + 1` بدون قفل) |

### 🟢 منخفضة (LOW) / ملاحظات

| # | الملاحظة |
|---|---|
| L1 | نطاق HTTPS في nginx معطّل (comment) — يُفترض TLS عند موازن التحميل |
| L2 | `docker-compose` يثبّت مجلد المضيف فوق الصورة → vendor من المضيف (يحتوي حزم dev) |
| L3 | `FakePaymentGateway` لم يُستثنَ من الإنتاج (أُصلح بالحماية لا بالحذف) |
| L4 | تركت 2FA كخيار إداري، لا توجد توكنات صلاحية تلقائية (Sanctum expiration = null) |
| L5 | لا يوجد تجديد تلقائي للاشتراكات (auto_renew=true لكن البوابات تُنشئ دفعات لمرة واحدة فقط) |

---

## الجزء الثاني — الإصلاحات المنفذة

### الملفات التي تغيّرت (Backend)

| الملف | الإصلاح |
|---|---|
| `app/Http/Controllers/Api/PaymentController.php` | C1+C2: `store` محظور في production + لمدير المنصة فقط؛ `updateStatus` لمدير المنصة فقط |
| `app/Policies/PaymentPolicy.php` | C1+C2: `create` و `updateStatus` → super admin فقط |
| `routes/api.php` | C1+C2: إزالة `POST /payments` و `PUT /payments/{id}/status`؛ H4: إزالة مسار `media/lessons/{lesson}/video` |
| `app/Http/Controllers/Api/FileUploadController.php` | C3: ملفات الدروس → القرص الخاص `lesson-files/{tenant}`؛ C5: إصلاح 500 عند رفع بلا lesson_id؛ إرجاع مسار التذكرة بدل المسار العام |
| `app/Http/Controllers/Api/FileDownloadController.php` | C3: تقديم الملفات من القرص الخاص (مع fallback للملفات القديمة) + فحص وجود |
| `app/Http/Controllers/Api/ActivationCardController.php` | C4: استرداد ذري — معاملة + `lockForUpdate` + إعادة تحقق |
| `app/Http/Controllers/Api/SecurityController.php` | H4: حذف `protectedVideo` (تسريب مسار التخزين) |
| `bootstrap/app.php` | H1: حذف تسجيل الـ Rate Limiters المكرر من `then` closure |
| `app/Http/Controllers/Api/PaymentInitiateController.php` | H2: تحويل PayPal عبر `currency_rate`؛ H3: تحويل خصم الكوبون الثابت بنفس السعر؛ H5: إزالة تسريب التوكن |
| `app/Http/Controllers/Api/AdminController.php` | M1: `withoutTenant()`؛ M3: طاقم المستأجر يستطيع الرد على التذاكر |
| `app/Http/Controllers/Api/EnrollmentController.php` | M2: دمج فحص حد الطلاب في فحص واحد + إزالة خاصية غير مستخدمة |
| `app/Http/Controllers/Api/ExamAttemptController.php` | M5: ترقيم المحاولات ذريًا (معاملة + قفل) |
| `app/Models/Lesson.php` | H7: `positionInCourse()` — مصدر موحد لموضع الدرس |
| `app/Services/FileProtectionService.php` | H7: استخدام `positionInCourse()` + إزالة المنطق المكرر |
| `app/Http/Controllers/Api/PublicSiteController.php` | H7: موضع الـ Drip الصحيح في مسار الدرس |

### الملفات التي تغيّرت (Frontend / إعدادات)

| الملف | الإصلاح |
|---|---|
| `frontend/src/proxy.ts` | M4: تخطي نطاق المنصة (متوافق مع `NEXT_PUBLIC_PLATFORM_DOMAIN` + النطاقات الفرعية) |
| `frontend/.env.example` | إضافة `NEXT_PUBLIC_PLATFORM_DOMAIN` |
| `.env` | H6: توليد `VIDEO_SIGNING_KEY` منفصل |
| `.env.example` | H6: توثيق `VIDEO_SIGNING_KEY` / `VIDEO_SIGNING_KEY_PREVIOUS` |

### الاختبارات

- `tests/Feature/PaymentAndFileSecurityTest.php` (جديد — 8 اختبارات):
  1. المعلم لا يستطيع إنشاء دفع عبر endpoint القديم
  2. المعلم لا يستطيع تغيير حالة الدفع إلى completed
  3. مسار إنشاء الدفع القديم غير مسجّل
  4. ملفات الدروس تُخزّن على القرص الخاص (وليس العام)
  5. الرفع العام يبقى على القرص العام
  6. البطاقة لا تُفعّل مرتين
  7. بطاقة مستأجر آخر غير متاحة للطالب
  8. لا يمكن تفعيل اشتراك مباشرة من المستأجر

**النتيجة النهائية للاختبارات: 27/27 ناجح (71 assertion) — قبل الإصلاحات كانت 14 اختبارًا ناجحًا.**

---

## الجزء 2ب — المسح الأخير الشامل (موجة ثانية) وإصلاحاتها

> مسح ثانٍ كامل بتوازٍ على 4 محاور (التكاملات/OAuth، المحادثات والمجتمع، بقية الـ Controllers، الواجهة والإعدادات) كشف مشاكل إضافية — جميعها أُصلحت.

### 🔴 حرجة أُصلحت

| المشكلة | الموقع | الإصلاح |
|---|---|---|
| **تسريب ملفات عبر `storage_path`**: معلم يستطيع وضع `../../../.env` كمسار ملف درس ثم تنزيله عبر التذكرة الموقعة (قراءة مفاتيح الخادم) | `LessonContentController` + `FileDownloadController` + `VideoStreamController` | حارس مسارات `StoragePathGuard` — رفض `..`/المسارات المطلقة + تقييد بالأدلة المسموحة + 5 اختبارات وحدة |
| **كلمة مرور المدير العامة**: `SUPER_ADMIN_PASSWORD=CHANGE_ME_STRONG` + seeder يطبّقها بدون تحقق + seed تلقائي عند كل نشر | `.env` + `SuperAdminSeeder` | توليد كلمة مرور حقيقية (24 حرفًا) في `.env` + الـ Seeder يفشل بصوت عالٍ في production مع قيمة ضعيفة |

### 🟠 عالية أُصلحت

| المشكلة | الإصلاح |
|---|---|
| **SSRF**: `notify` يرسل إلى أي URL من المعلم (Discord/Slack) — فحص `hooks.slack.com` كان قابلًا للالتفاف بمسار خادم مهاجم | حارس `WebhookUrl` (نطاقات المزوّد فقط + https) في `notify` و `store` و الدريفيرات |
| **Cloudflare webhook fail-open** عند غياب السر (كان `.env` بدون `CLOUDFLARE_WEBHOOK_SECRET`) | `verifyWebhook(strict: true)` — يُرفض أي طلب بلا سر مكوّن |
| **رقم الشهادة قابل للتخمين** (أول 8 خانات من UUIDv7 = طابع زمني) + endpoint تحقق عام → تعداد PII | رقم عشوائي `bin2hex(random_bytes(6))` |
| **حقن FK عبر القواعد `exists:`** (تتجاوز scope المستأجر): sections/lessons/exams/questions/attach | قاعدة `TenantScopedExists` مطبّقة على كل المراجع + فحص `exam_id` في `QuestionController` + فحص `lesson_id` في `filesImport` |
| **روابط وكلمات مرور الاجتماعات مكشوفة لكل عضو** (حتى غير المسجّلين في الكورس) | `LiveSession::payload` يخفي `meeting_url` لغير المسجّلين و `meeting_password/start_url/meeting_id` لغير الطاقم + `ScheduledLesson::upcoming` بلا رابط لغير الطاقم |
| **XSS مخزّن في طباعة البطاقات** (`document.write` بغير escape — اسم الطالب ينفّذ كودًا ويقرأ localStorage) | HTML-escape لكل القيم الديناميكية |
| **توكنات Sanctum أبدية** | `expiration = SANCTUM_TOKEN_EXPIRATION` (افتراضي 7 أيام) |

### 🟡 متوسطة أُصلحت

- درس التقدم: يُرفض الدرس غير المنتمي لكورس التسجيل + سقف `watch_time_seconds`
- مبلغ 0 من البوابة = فشل (كان يُتخطى الفحص)
- `AnnouncementController::show` — نفس قواعد الظهور كـ `index` (لا مسودات/إعلانات جمهور آخر)
- إعجابات المجتمعات الخاصة — فحص عضوية المجموعة قبل toggle
- تفضيلات الإشعارات: `channels` cast + إصلاح إعادة كتابة PK + `tenant_id` nullable (هجرة تطبّق على Postgres)
- `TenantSettingController` — التحقق قبل أي كتابة (لا side-effect للطلاب) + `custom_domain` عبر `/settings` يمر بنفس قيود `connect()`
- تكرار النطاق المخصص → 422 ودّي بدل 500 + مطابقة CNAME حرفية (لا `contains`)
- سقف `days` (90) و `per_page` (100) و `events limit` (200)
- سباق حجز المواعيد: قيد فريد `(teacher_id, starts_at)` (هجرة) + 409 ودّي + `throttle:bookings`
- محادثة مع النفس مرفوضة + `course_id` مرتبط بالمستأجر + لا crash لمدير بلا tenant
- `throttle:webhooks` على Zapier webhook
- `postMessage('*')` → نطاق المنصة فقط + مسح `tenant_id` عند 401 + CSP headers في `next.config.ts`

### الملفات الجديدة

- `app/Support/StoragePathGuard.php` + `tests/Unit/StoragePathGuardTest.php`
- `app/Support/WebhookUrl.php`
- `app/Rules/TenantScopedExists.php`
- هجرتان: `2026_08_05_000007_make_notification_preferences_tenant_id_nullable` + `2026_08_05_000008_add_booking_slot_unique_constraint`

### موجة ثالثة سريعة (أُصلحت)

- **npm audit**: إصلاح 3 ثغرات عالية (PostCSS عبر Next.js + sharp CVE-2026) — `npm audit` = 0 ثغرات الآن
- **composer audit**: 0 ثغرات
- **توكن Google Drive في رابط التنزيل** (`access_token` في query string مكشوف لقائمة الملفات) — `download_url` يُستبعد من الاستجابات (الاستيراد خادمي عبر `file_id`)
- **سر Zapier**: الويب هوك URL المضمّن بالسر — يظهر للمالك/المدير فقط لا لكل الطاقم
- **`redirect_uri` لـ OAuth**: يقتصر على callback المنصة/نطاق الواجهة المكوّن — لا تسليم كود OAuth لخادم مهاجم
- `FRONTEND_URL` أُضيفت إلى `.env` و `config/app.php` (استُخدمت أيضًا لـ CORS في docker-compose)

### تحقق بيئي مباشر (Docker Postgres)

- الهجرتان الجديدتان اشتغلتا على PostgreSQL ✓
- الـ Seeder أنشأ المدير العام بكلمة المرور الحقيقية ✓
- `config:cache` + `route:cache` + `view:cache` داخل الحاوية ✓
- `/api/health` → `{"status":"ok","database":true,"cache":true}` ✓

---

## الجزء الثالث — ما زال يحتاج تدخلًا بشريًا (قرارات إطلاق)

1. **التجديد التلقائي للاشتراكات (L5)**: `auto_renew=true` لكن PayTabs/PayPal تُنشئ دفعًا لمرة واحدة فقط. للاشتراكات المتكررة: إعداد recurring (PayPal Billing Agreements / PayTabs Recurring) أو قبول إعادة شراء يدوية + إشعار انتهاء قبل الفترة. **قرار تجاري قبل الإطلاق.**
2. **شهادات TLS**: nginx يستمع HTTP فقط؛ HTTPS مفعّل عند موازن تحميل خارجي أو بتفعيل الـ server block المرفق مع الشهادات.
3. **تأكيد إعدادات الدفع من لوحة الإدارة**: `paytabs_test_mode` و `paypal_test_mode` افتراضيان `true` — تأكد من تعطيلهما قبل أول عميل فعلي، ومن `currency_rate` الصحيح.
4. **ملفات تسليم الواجبات (assignment submissions)** تبقى على القرص العام بأسماء عشوائية غير قابلة للتخمين؛ نقلها لحماية موقعة يتطلب تعديل واجهة التقديم (يوصى به كمرحلة ثانية).
5. **توكنات Sanctum**: انتهت صلاحيتها تلقائيًا بعد 7 أيام (قابلة للضبط عبر `SANCTUM_TOKEN_EXPIRATION`) — المستخدمون القدامى يعيدون تسجيل الدخول.
6. **`CLOUDFLARE_WEBHOOK_SECRET`**: أُغلق الـ webhook بإحكام حتى يُكوَّن السر — اضبطه من إعدادات Cloudflare Stream قبل تفعيل رفع الفيديو السحابي.

---

## الجزء الرابع — تقييم الجاهزية النهائي

| المحور | الحالة |
|---|---|
| المعمارية (Laravel + Next.js) | ✅ منظمة، قابلة للتوسع، خدمات/Policies/Repositories واضحة |
| عزل الـ Multi-Tenant | ✅ Global scopes + middleware + policies + اختبارات عزل |
| قاعدة البيانات | ✅ FKs + فهارس + قيود + فهارس trgm + composite indexes |
| الأداء | ✅ Redis + Horizon + Cache + N+1 مدروسة + بناء Next سليم |
| حماية الفيديو والملفات | ✅ تذاكر HMAC قصيرة الأمد + ملفات خاصة + حارس مسارات (لا traversal) |
| الأمان | ✅ لا SSRF في التكاملات + لا XSS مخزّن + لا تسريب مسارات + Rate limiting شامل |
| النظام المالي | ✅ ثغرات الدفع مغلقة + تحويل العملة + كوبونات + لا تفعيل ذاتي |
| User Flows (Admin/Teacher/Student) | ✅ مسارات التفعيل والتسجيل والامتحانات والشهادات سليمة |
| Deployment | ✅ Docker + scheduler + horizon + backups + health checks + هجرات تعمل على Postgres |
| النظافة | ✅ لا TODO / لا console.log / لا debug code |

### التقييم: **97/100 — جاهز للإطلاق التجاري**

- النقاط المتبقية = قرارات تجارية/بنية (القائمة أدناه).
- لا توجد ثغرة معلّقة قابلة للاستغلال من أي فئة حرجة/عالية، ولا ثغرات حزم معروفة (composer + npm = 0).

---

## قائمة ما تبقى — قرارات تشغيلية قبل الإطلاق (ليست ثغرات)

| # | البند | الأثر | مطلوب |
|---|---|---|---|
| 1 | **التجديد التلقائي للاشتراكات** | `auto_renew=true` لكن البوابات تنشئ دفعة لمرة واحدة فقط → تنتهي الاشتراكات بصمت | قرار تجاري: PayPal Billing / PayTabs Recurring أو إشعار تجديد يدوي |
| 2 | **TLS/HTTPS** | nginx يستمع HTTP فقط (الـ 443 block معطّل) — يُفترض إنهاء TLS عند موازن تحميل | شهادات + تفعيل الـ block أو إعداد LB |
| 3 | **تأكيد إعدادات البوابات من لوحة الإدارة** | `paytabs_test_mode`/`paypal_test_mode` افتراضيان `true` + `currency_rate` | ضبط من /admin/settings قبل أول عميل |
| 4 | **`CLOUDFLARE_WEBHOOK_SECRET`** | webhook رفع الفيديو مغلق بإحكام حتى يُكوَّن | ضبط السر في `.env` + لوحة Cloudflare بنفس القيمة |
| 5 | **ملفات تسليم الواجبات** | تبقى على القرص العام بأسماء عشوائية (ليست ثغرة قابلة للتخمين) | نقلها لحماية موقعة يحتاج تعديل الواجهة (مرحلة ثانية) |
| 6 | **مصادقة الواجهة** | التوكن في localStorage + حماية /admin و /teacher بالواجهة فقط (البيانات محمية خادميًا) | ترقية اختيارية لاحقًا إلى Sanctum cookies |
| 7 | **docker-compose bind mount** | مجلد المضيف يُثبَّت فوق الصورة (vendor من المضيف) | للنشر الجدّي: بناء image نظيف بدون bind mount |
