# تقرير تدقيق المنصة — لبناء مساعد "اسألني"

> التاريخ: 2026-08-19 | الحالة: تدقيق فعلي على الكود الحالي (وليس وثيقة قديمة)
> النطاق: لوحة المعلم + Backend + قاعدة البيانات + الخطط + الوسائط + البث + Jitsi

---

## 0. الخلاصة التنفيذية

- **المساعد الموجود حالياً (`frontend/src/store/assistant.ts`) ليس "اسألني"** — إنه فقط "مساعد اختيار الخطة" (Plan Recommender) الذي يطرح أسئلة محفوظة ثم يعرض خطة مقترحة. وهو مُركّب **حصراً** في صفحتين عامتين (الرئيسية `/` و`/pricing`)، ولا يظهر داخل لوحة المعلم إطلاقاً.
- المنصة لا تحتوي أي نهاية (Endpoint) ذكاء اصطناعي أو قاعدة معرفة بالمنصة. "اسألني" يجب أن يُبنى من الصفر على نظام موجود فعلاً.
- صفحات قائمة طلبك مثل "Videos / PDFs / Audio / Storage / Subscription / Media Usage / Live Usage" **ليست صفحات مستقلة** — هي أنواع محتوى داخل الدروس أو أقسام داخل لوحة التحكم/الإعدادات. تم توثيق مكانها الحقيقي أدناه.

---

## 1. صفحات لوحة المعلم المكتشفة فعلياً (من Navigation + Routes + Controllers)

| الصفحة | المسار | البوابة (Plan gate) |
|---|---|---|
| لوحة التحكم | `/teacher/dashboard` | — |
| الكورسات (قائمة) | `/courses` | — |
| منشئ الكورس (Units/Lessons/Exams/Assignments) | `/courses/[id]` | — |
| الطلاب (إدارة الطلاب + الأجهزة) | `/students` | — |
| بطاقات التسجيل | `/teacher/cards` | `cards_enabled` |
| المواعيد والجلسات (حجوزات + أوقات توفر) | `/teacher/appointments` | `appointments_enabled` |
| الخدمات المدفوعة (استشارات/جلسات) | `/teacher/services` | `paid_services_enabled` |
| البث المباشر (قائمة) | `/teacher/live` | `live_stream_enabled` |
| ستوديو البث (Jitsi + كاميرا) | `/teacher/live/[id]/studio` | `live_stream_enabled` |
| الامتحانات والتصحيح (يدوي) | `/teacher/exams` | — |
| أسئلة الاختبارات (نسخة مبسطة) | `/teacher/questions` | `question_bank_enabled` |
| الدروس المجدولة | `/teacher/schedule` | `scheduled_lessons_enabled` |
| فريق العمل (تعدد المعلمين) | `/teacher/staff` | `multi_teacher_enabled` |
| الإعدادات (7 تبويبات) | `/teacher/settings` | — |
| الدعم الفني | `/teacher/support` | — |
| المدونة | `/blog` | `blog_enabled` |
| المجتمع | `/community` | `community_enabled` |
| الإعلانات | `/announcements` | `announcements_enabled` |
| التحليلات والتقارير | `/analytics` | `export_reports` |

**ملاحظات حقيقية:**
- الصفحات داخل لوحة المعلم ليست 4 فقط كما في بعض التوثيق القديم؛ الملاحة الفعلية 13 عنصراً + 5 صفحات عبر روابط مباشرة (exams, questions, schedule, services, studio).
- `/teacher/schedule` غير موجود في الشريط الجانبي (يُفتح برابط مباشر ويغذي "الدروس القادمة" في لوحة التحكم).
- لا توجد صفحة اشتراك/خطة داخل اللوحة — تظهر الخطة والاستخدام في 7 مواضع (انظر §6).

---

## 2. أهم الأزرار والإجراءات (لكل صفحة — ملخص)

- **لوحة التحكم**: 4 بطاقات KPI (روابط)، لوحة "استخدام خطتك" (أشرطة used/limit لكل الموارد + أقسام الوسائط والتكلفة التقديرية)، الدروس القادمة، المواعيد القادمة، الأداء (إكمال/ساعات/نسبة نجاح).
- **الكورسات**: إنشاء/تعديل/حذف/نشر/إلغاء نشر، معاينة (`/course/[id]/learn`)، بحث داخلي، سحب وإفلات للأقسام والدروس، إضافة محتوى (درس/اختبار/بث/واجب)، رفع فيديو (تذكرة Bunny + شريط تقدم)، رابط يوتيوب، رفع PDF، نص، محرر امتحانات (8 أنواع أسئلة + LaTeX + بنك أسئلة)، تصحيح واجبات.
- **الطلاب**: بحث، ملف الطالب (نشاط/إنجازات/كورسات/نتائج/أجهزة)، تحديد حد الأجهزة، إعادة تعيين الأجهزة، إزالة الصلاحية.
- **البطاقات**: توليد دفعات (حتى 1000)، استيراد Excel/CSV مع قالب، طباعة دفعة مع QR، إلغاء بطاقة، نسخ الكود.
- **المواعيد**: تأكيد/رفض/إنهاء الحجز، تعيين رابط اجتماع، إضافة أوقات توفر (يوم/من/إلى/مدة فتحة).
- **الخدمات**: إنشاء/تعديل/تفعيل/حذف خدمة (استشارة أو جلسة) بسعر ومدة.
- **البث**: جلسة جديدة (نوع البث: Jitsi داخلي أو خاص بالمنصة)، بدء/إنهاء/إلغاء، بث من الكاميرا (MediaRecorder → ffmpeg → Bunny HLS)، نسخ الرابط، مشاركة واتساب، صفحة انضمام، تعديل/حذف.
- **الامتحانات**: قائمة امتحانات + محاولات بانتظار التصحيح (مقالي)، تصحيح يدوي لكل إجابة.
- **الدروس المجدولة**: إنشاء (متكرر أسبوعياً حتى تاريخ)، إكمال/إلغاء/تعديل، رابط اجتماع.
- **فريق العمل**: دعوة معلم (12 صلاحية)، إلغاء دعوة، تعديل صلاحيات عضو، إزالة عضو.
- **الإعدادات (7 تبويبات)**: تصميم الصفحة، منشئ الصفحة (سحب وإفلات)، الدومين المخصص، سياسة أجهزة الطلاب، سياسة تنزيل ملفات الدروس، حماية الفيديو (علامة مائية — مرتبطة بالخطة)، مشاركة الصفحة (QR).
- **الدعم**: تذاكر (فئة/أولوية)، دردشة حية أو بريد حسب `support_level` في الخطة.
- **المدونة**: مقال جديد (نشر/مسودة)، رفع صورة غلاف، نشر/إلغاء.
- **المجتمع**: موضوعات (نقاش/سؤال/إعلان)، مجموعات، تعليقات مع إجابة مقبولة، إعجابات، تثبيت.
- **الإعلانات**: إنشاء بجمهور (الجميع/طلاب/معلمون/إدارة/كورس) وقنوات متعددة (قاعدة البيانات/بريد/push/SMS/واتساب...)، إرسال فوري.
- **التحليلات**: فترة 7/30/90، تصدير PDF (DomPDF) وExcel (PhpSpreadsheet)، 10 بطاقات KPI، رسوم بيانية، جداول أداء الكورسات/الامتحانات، أفضل الطلاب.

---

## 3. خريطة المعرفة (Knowledge Map) — من المشروع الحقيقي

```
SHAHEAD LMS (SaaS متعدد المستأجرين — Laravel 13 + Next.js 16 + PostgreSQL + Redis)
│
├── الإدارة المركزية (Admin) /admin/*
│   ├── لوحة الإدارة، الاشتراكات، الخطط، المعلمون، الطلاب، مدرسو القرآن
│   ├── المبيعات (مدفوعات/فواتير/كوبونات)، الدعم، النسخ الاحتياطي
│   └── ذكاء الأعمال (BI) + الوسائط والبنية التحتية (Media Infrastructure) + الإعدادات
│
├── المعلم (لوحة المعلم)  ← نطاق "اسألني" الأساسي
│   ├── Dashboard (لوحة التحكم + استخدام الخطة + الأداء)
│   ├── Courses ─ Course Builder /courses/[id]
│   │   ├── Sections (وحدات) ─ Lessons (دروس)
│   │   │   └── LessonContent (نوع واحد رئيسي للدرس):
│   │   │       ├── video   → VideoContent → MediaAsset (Bunny/Cloudflare) 
│   │   │       ├── text    → TextContent
│   │   │       ├── file    → FileContent (PDF/ملفات)
│   │   │       └── live    → LiveSession (Jitsi أو مزود اجتماعات)
│   │   ├── Exams (امتحانات + أقسام + محاولات + تصحيح)
│   │   ├── Question Bank (بنك أسئلة — 8 أنواع + LaTeX)
│   │   ├── Assignments (واجبات + تسليمات + تصحيح)
│   │   └── Student management / students
│   ├── Students (طلاب + أجهزة + حدود + ملف كامل)
│   ├── Cards (بطاقات تسجيل + QR + استيراد/طباعة)
│   ├── Teaching
│   │   ├── Live (بث مباشر: Jitsi داخلي / Bunny RTMPS / Zoom/Meet/Teams)
│   │   ├── Scheduled Lessons (دروس مجدولة متكررة)
│   │   ├── Appointments (حجوزات مواعيد) + Paid Services (خدمات مدفوعة)
│   │   └── Team (فريق عمل — تعدد معلمين)
│   ├── Content & Community: Blog / Community / Announcements
│   ├── Growth: Analytics + Reports (PDF/Excel)
│   └── Account: Settings (7 تبويبات) / Support
│
├── الطالب: My Learning / Exams / Results / Certificates / Leaderboard /
│           Community / Messages / Notifications / Announcements / My Bookings / Profile
│
├── الموقع العام لكل معلم: /t/[slug] + دومين مخصص + صفحات عامة (/pricing, /checkout, /card/[code]...)
```

**مصادر البيانات الحقيقية** (لا افتراض):
- `GET /teacher/plan` → `{ plan, features, limits, gates, feature_matrix, usage, registry }` — الخطة والاستخدام الفعلي.
- `GET /teacher/analytics?days=` → KPIs والرسوم وأداء الكورسات/الامتحانات + `monthly_infra_cost`.
- `GET /dashboard` → إحصائيات المعلم.
- `GET /teacher/scheduled-lessons`, `GET /teacher/bookings` → الدروس والمواعيد القادمة.
- `GET /teacher/media` + `/teacher/media/usage` → أصول الوسائط واستخدامها.
- `GET /subscriptions/current` → الاشتراك الحالي.
- `GET /settings` → إعدادات العلامة التجارية.
- `GET /teacher/plan-recommender/questions` + `POST .../recommend` → (المساعد الحالي للخطط فقط).

---

## 4. نظام الخطط والميزات (محرك حقيقي بالكامل — لا Hardcode)

- **البنية**: `SubscriptionPlan` → `PlanFeature` (key/value) → `TenantSubscription` (active/trialing). **محرك واحد**: `PlanEngine::snapshot(tenantId)` يقرأ من قاعدة البيانات مع كاش Redis مُبطَل بالنسخة (`plan:version`).
- **الكشف**: `QuotaService::assertCan(tenant, resource, increment)` — نقطة فرض واحدة لكل "هل أستطيع إنشاء X؟".
- **الاستخدام**: `UsageService::registry()` + `usageOf()` — 18 مورداً محسوباً فعلياً (طلاب/كورسات/فيديوهات/ملفات/PDF/أسئلة/اختبارات/واجبات/معلمون/مجموعات/بث/مواعيد/خدمات/مقالات/بطاقات/تخزين/تقارير/علامة تجارية).
- **قواعد التشخيص** (مهمة لـ "اسألني"):
  - "ليش ما بقدر أرفع فيديو؟" → تحقق `video_upload_enabled` ثم `videos_limit` ثم `storage_limit_mb` (حجز ذري).
  - "كم طالب بقدر أضيف؟" → 3 قيود: `students_limit` (الخطة، يُحسب طلاباً مميزين عبر Enrollment) + `Tenant.max_students` + `Course.max_students`.
  - "ليش الميزة مش ظاهرة؟" → `gates` في `GET /teacher/plan` (أغلب الملاحة مخفية حسب gate، مثل `blog_enabled`, `community_enabled`, `export_reports`, `multi_teacher_enabled`).
- **قيم الاستخدام**: استخدام/حد/متبقي/نسبة + `unlimited` (∞).

---

## 5. نظام الوسائط (Media Infrastructure — Bunny + Cloudflare)

- **المعمارية**: `MediaProviderManager` (سجل `bunny`/`cloudflare`، المزود النشط من DB أولاً ثم env) → `MediaService` (المنسق المركزي، لا يعرف المزود) → `MediaProviderInterface` (BunnyStreamProvider / CloudflareStreamProvider).
- **تدفق الرفع**: `createVideoUploadTicket` (تحقق خطة + حجز مساحة ذري + idempotency key) → تذكرة رفع مباشر (لا يمر الملف عبر السيرفر) → webhook `markAssetReady` → حالة `MediaAsset` (processing/ready/error/deleted/orphan) → تحديث `VideoContent.status`.
- **التشغيل الآمن**: روابط موقعة منتهية الصلاحية + علامة مائية + منع تنزيل/PIP + تذاكر ملفات موقعة.
- **البث الحي**: `createLiveStream` (RTMPS + مفتاح بث للمعلم فقط) + `startLiveStream/stopLiveStream` + تسجيلات (Recording) تُربط بالبث الأصلي كأصل جديد.
- **الاستخدام والتكلفة**: `MediaUsageLedger` (سجل إلحاقي append-only) → `MediaUsageMonthly`/`MediaUsageAggregate` → `ProviderUsageCost` + `ProviderPricing`/`ProviderCostRule` → `MediaCost` شهري لكل مستأجر (يظهر في لوحة التحكم كـ `monthly_infra_cost`).
- **الاعتمادات**: تُخزن مشفرة AES-256 في `media_providers`، وتُدار من لوحة الأدمن → Media Infrastructure.
- **جداول**: media_providers, media_assets, media_usage_ledger, media_usage_monthly, media_usage_aggregates, provider_pricing, provider_cost_rules, provider_usage_costs, media_costs, media_webhook_events, usage_warning_rules, plan_profitability.

---

## 6. نظام البث المباشر + Jitsi

- **مزودو البث**: `custom` (المنصة/Bunny)، `jitsi` (غرفة داخلية مضمّنة)، `zoom`/`google_meet`/`teams` (عبر محرك التكاملات)، `youtube`.
- **Jitsi**: `JitsiJwtService` يولد JWT (HS256) — المعلم `moderator: true`، الطالب `false`. يتطلب `JITSI_APP_ID` + `JITSI_APP_SECRET`؛ في الوضع التجريبي (meet.jit.si) تظهر رسالة "يُفصل بعد 5 دقائق".
- **تدفق الدخول**: `POST /teacher/live-sessions/{id}/join-ticket` هو **المصدر الوحيد** للصلاحية — المعلم = host (Moderator تلقائياً)، الطالب = participant (يجب أن يكون مسجلاً بالكورس، ينتظر Lobby).
- **الدور والصلاحيات**: المعلم (أي معلم مخوّل بنفس المستأجر) → host؛ الطالب المسجل بالكورس فقط → participant؛ غير ذلك 403.
- **علاقته بالدرس**: البث يُنشئ `LessonContent` من نوع `live` داخل الدرس، و`course_id` يُستنتج من الدرس/القسم.
- **WebRTC داخلي**: `webrtcSignal`/`webrtcPoll` — المعلم ينشر offer فقط، الطلاب answer/ice فقط.
- **التسجيل**: `record_enabled` + ميزة `live_recording_enabled`؛ عند الإنهاء يتوقف البث عند المزود (يحرر التكلفة) ويُحفظ التسجيل كأصل.
- **Webhook للأحداث**: بدء/إنهاء/حذف البث → مزامنة `LiveSession` القديمة.

---

## 7. نظام الصلاحيات (Roles + Permissions + Tenant)

- **أدوار**: Teacher / Student / Owner / Admin / Super Admin — عبر Spatie (roles) + `tenant_user` (pivot role/status) + `TenantUser.metadata['permissions']` (قائمة صلاحيات لكل عضو).
- **12 صلاحية** (من فريق العمل): courses.manage, students.manage, cards.manage, exams.manage, questions.manage, announcements.manage, live.manage, schedule.manage, blog.manage, community.manage, settings.manage, analytics.view.
- **الفرض**: `role:` middleware + `permission:` middleware + Policies (16 Policy) + `AuthorizesTenantAccess`.
- **العزل**: `BelongsToTenant` (global scope) — كل الجداول الأساسية تحمل `tenant_id`؛ المستأجر يُحل من `TenantContext` وليس من الهيدر مباشرة (يمنع توسيع العزل).
- **تناقض مكتشف**: `PaymentObserver` يبحث `role IN ('owner','admin')` (حروف صغيرة) بينما `TenantSubscriptionObserver` يبحث `role IN ('Teacher','Super Admin')` (حروف كبيرة) — نفس الجدول، صيغتان مختلفتان.

---

## 8. أبرز Workflows الحقيقية

1. **إنشاء كورس**: لوحة التحكم → "إنشاء كورس" → `/courses` → إضافة وحدة → إضافة درس → إضافة محتوى (فيديو/نص/PDF/بث) → نشر الدرس/الكورس.
2. **رفع فيديو**: تذكرة (`/videos/upload-ticket` — فحص خطة + حجز مساحة) → رفع مباشر XHR بشريط تقدم (يستمر بالخلفية) → webhook → processing → ready → يظهر للطالب عبر مشغّل محمي.
3. **امتحان**: منشئ الكورس → إضافة اختبار → أسئلة (بنك/جديد، 8 أنواع) → إعدادات (مدة/درجة/محاولات/خلط) → نشر → الطالب يحل → تصحيح تلقائي + تصحيح يدوي للمقالي → النتائج والتحليلات.
4. **بث مباشر**: جلسة جديدة (Jitsi داخلي أو خاص) → جدولة → عند الموعد: المعلم يفتح الاستوديو (join-ticket host) → الطلاب المسجلون يدخلون (participant) → إنهاء → (تسجيل اختياري).
5. **بطاقة تسجيل**: توليد دفعة → طباعة QR → الطالب يسترد (`/card/[code]`) → تسجيل تلقائي → يظهر في الطلاب.
6. **موعد/خدمة مدفوعة**: إعداد أوقات التوفر/خدمة → الطالب يحجز من الموقع العام → تأكيد (مع رابط اجتماع) → إتمام.
7. **فريق عمل**: دعوة معلم (صلاحيات) → قبول بالبريد → إدارة المحتوى معاً.
8. **إعلان**: إنشاء (جمهور + قنوات) → إرسال → يصل الطلاب حسب القنوات.
9. **تقرير**: التحليلات → اختيار الفترة → تصدير PDF/Excel.

---

## 9. المشاكل والتناقضات المكتشفة (للمساعد: لا تخترع — قل "غير متوفر")

1. `LessonContent::content()` (MorphTo) **معرّف لكنه معطوب** — لا يوجد morphMap، والكود يستخدم `video()/text()/file()/liveSession()`.
2. تناقض حالة الأدوار في الـ Observers (owner/admin صغيرة مقابل Teacher/Super Admin كبيرة).
3. التوثيق القديم (`docs/platform-audit.md`) متجاوز — لا يذكر محرك الوسائط/التكلفة/المواعيد/الاشتراك/الخطط الديناميكية.
4. لا توجد نهاية "اسألني" أو أي ذكاء اصطناعي — سيُبنى جديداً.
5. `/teacher/schedule` و`/teacher/exams` و`/teacher/questions` خارج الشريط الجانبي (روابط مباشرة فقط).
6. بعض الميزات في كتالوج الخطط (مثل `delivery_minutes_limit`, `recordings_limit`, `live_minutes_limit`) موجودة في الكتالوج لكن **لا تُحسب في `UsageService::registry()`** — لا يمكن الإجابة عن استهلاكها فعلياً الآن.

---

## 10. مصفوفة تغطية قائمة طلبك الأصلية (المكتشفة فعلياً)

| البند في طلبك | الحالة الفعلية |
|---|---|
| Dashboard | ✅ صفحة `/teacher/dashboard` |
| Courses / Course Management | ✅ `/courses` + منشئ `/courses/[id]` |
| Units / Lessons | ✅ أقسام ودروس داخل منشئ الكورس |
| Videos | ✅ نوع محتوى داخل الدرس (رفع Bunny + روابط يوتيوب) — لا صفحة منفصلة |
| PDFs / Files / Audio / Text | ✅ أنواع محتوى داخل الدرس (`file`/`text`) — لا صفحات منفصلة |
| Exams / Questions / Question Bank | ✅ منشئ الكورس + `/teacher/exams` + `/teacher/questions` + بنك أسئلة |
| Assignments | ✅ واجبات داخل منشئ الكورس + تصحيح |
| Students / Student Management | ✅ `/students` + ملف كامل + أجهزة |
| Cards | ✅ `/teacher/cards` (توليد/استيراد/طباعة/QR) |
| Teaching | ✅ مجموعة صفحات (بث/جدولة/مواعيد/خدمات) |
| Live Streaming / Jitsi | ✅ `/teacher/live` + الاستوديو + Jitsi مضمّن |
| Scheduled Lessons | ✅ `/teacher/schedule` (خارج الشريط الجانبي) |
| Appointments / Paid Sessions | ✅ `/teacher/appointments` + `/teacher/services` |
| Team | ✅ `/teacher/staff` (مقيّد بـ multi_teacher_enabled) |
| Community / Blog / Announcements | ✅ `/community` + `/blog` + `/announcements` |
| Growth / Analytics / Reports | ✅ `/analytics` + تصدير PDF/Excel (مقيّد بـ export_reports) |
| Account / Settings / Support | ✅ `/teacher/settings` (7 تبويبات) + `/teacher/support` |
| Subscription / Plan | ⚠️ لا صفحة منفصلة — PlanChip + لوحة استخدام الخطة في الـ Dashboard + `/pricing` + `/checkout` |
| Media Usage / Storage / Video Usage / Live Usage | ⚠️ أقسام داخل لوحة التحكم + `/teacher/media` + `/teacher/media/usage` |

---

## 11. نطاق الإجابات الموصى به (لمنع الهلوسة)

- **يجيب**: عن المنصة (صفحات/أزرار/ميزات/خطط/صلاحيات/استخدام/بيانات المعلم الفعلية/workflows/أخطاء شائعة مثل الفيديو/البث/الرفع/الطلاب).
- **لا يخترع**: لا معلومات خارج ما يُقرأ من (Code + DB + `GET /teacher/plan` + Context الصفحة الحالية + Feature Engine + Media Engine). عند غياب المعلومة: "المعلومة غير متوفرة" بوضوح.
- **أساس الدقة**: الحالة الفعلية من `GET /teacher/plan` (usage/gates/features) + `GET /teacher/analytics` + `GET /teacher/media/usage` + علاقات قاعدة البيانات المذكورة أعلاه.

---

## 12. إعادة البناء — نظام معرفة ديناميكي (2026-08-19)

بعد ملاحظة أن قاعدة المعرفة اليدوية محدودة (كانت تقول "لا أملك إجابة" لمواضيع موجودة فعلاً)، أُعيد البناء على مبدأ **معرفة مولّدة من الكود + سياق ذكي + إجابات مرتبطة ببيانات المعلم**.

### 12.1 الملفات الجديدة/المُعاد بناؤها

| الملف | الدور |
|---|---|
| `app/Support/PlatformIndex.php` | **فهرس المعرفة الديناميكي** — يولّد المواضيع من الكود الفعلي (صفحات اللوحة + `FeatureCatalog` + `UsageService::registry` + سير العمل + قواعد التشخيص) مع تطبيع عربي (همزات/تاء مربوطة/ألف مقصورة/حذف "ال") ومطابقة مرادفات موسّعة وتوجيه حسب نية السؤال (مشكلة→تشخيص، كم باقي→استخدام). أي ميزة/مورد جديد في الكود يظهر كموضوع تلقائياً. |
| `app/Services/AssistantContextService.php` | **منشئ السياق الذكي** — يبني شريحة بيانات مصغّرة حسب السؤال فقط (لا يُرسل قاعدة البيانات كاملة)، مقصورة على `current_tenant` (عزل تام)، ولا تُمرَّر أسرار. يتضمن تحليلات حقيقية وتشخيص فيديو/بث حقيقي. |
| `app/Support/AssistantKnowledge.php` | أعيد تنظيمه: محتوى ثابت عام فقط (خريطة المنصة، سير العمل، قواعد التشخيص التفصيلية، تسمية الصفحات) — لم يعد المصدر الوحيد. |
| `app/Services/AssistantService.php` | أعيد بناؤه: تحليل السؤال → سياق ذكي → LLM إن توفر مفتاح، وإلا **تراجع حتمي مرتبط بخريطة المنصة الحقيقية** (كل صفحة/ميزة/مورد/سير عمل/تشخيص له إجابة مُبنية من بيانات حقيقية + رابط آمن للصفحة). |
| `app/Http/Controllers/Api/AssistantController.php` | نقطة `POST /api/v1/teacher/assistant/ask` (داخل حماية tenant+subscription+role، مع حد معدل). |
| `tests/Feature/AssistantAskTest.php` | 39 اختباراً: أسئلة واقعية (28 حالة)، عزل مستأجر، صلاحيات، خطة منتهية، ميزة معطلة، خارج النطاق، منع تسريب الأسرار، تشخيص فيديو حقيقي. |

### 12.2 الأرقام النهائية

| المؤشر | القيمة |
|---|---|
| صفحات لوحة المعلم المكتشفة | 21 موضوع صفحة (18 صفحة فعلية + storage/subscription/assignments كمواضيع) |
| المواضيع الكلية القابلة للإجابة (PlatformIndex) | ~70+ (صفحات + ~40 ميزة + 18 مورد + 9 سير عمل + 8 تشخيص) |
| أزرار/إجراءات موثّقة | ~90 إجراءً عبر `pages()` |
| نقاط API | 200+ (الراوت الجديد `teacher/assistant/ask` أُضيف ضمنها) |
| سير عمل موثّق | 9 (كورس، رفع فيديو، اختبار، بث، بطاقة، موعد/خدمة، فريق، إعلان) |
| قواعد تشخيص | 8 (فيديو لا يظهر، رفع فاشل، بث لا يعمل، طالب لا يرى الدرس، ميزة مخفية، حد خطة، ترقية، مزود وسائط) |
| مصادر المعرفة | الكود + `PlanEngine` + `QuotaService` + `UsageService` + `MediaQuotaService` + `SaasCostService` + `FeatureCatalog` + بيانات `current_tenant` الحقيقية |
| عزل البيانات | كل الاستعلامات `withoutTenant()->where('tenant_id', current_tenant)` — لا توجد أرقام/بيانات لمستأجر آخر |
| ما لا يعرفه | أي شيء خارج لوحة المعلم/المنصة، أو بيانات غير مسجّلة (يقول "المعلومة غير متوفرة") |
| الاختبارات | 39 اختباراً — ✅ **39/39 ناجحة (173 تأكيداً)** |
| المجموعة الكاملة | ✅ **248 ناجحاً، 0 فشل (860 تأكيداً)** — لا انحدار |
| البناء | `php -l` ✅ · `tsc --noEmit` ✅ · `route:list` ✅ |

### 12.3 ملاحظات تشغيل محلية (Docker Desktop)

- `tests/TestCase.php` يفرض قاعدة sqlite ملفية على المسار `/var/www/html/tests/database.sqlite` (داخل bind mount). تحت **Docker Desktop على macOS** تتعرض الملفات داخل الـ mount لتلف/قفل عند التشغيل الثقيل.
- **الحل المؤكد** (أُثبت: 248/248): قبل التشغيل أنشئ الرابط داخل الحاوية واشغّل في نفس الجلسة:
  ```bash
  docker compose exec -T app sh -c 'rm -f /var/www/html/tests/database.sqlite /tmp/lms-tests.sqlite*; ln -s /tmp/lms-tests.sqlite /var/www/html/tests/database.sqlite; php artisan test --filter=AssistantAskTest'
  ```
- في CI (حاوية Linux أصلية) لا توجد هذه المشكلة إطلاقاً.

### 12.4 الخطوة التالية للمستخدم

أضف في `.env` (إن أردت إجابات LLM بدل التراجع الحتمي):
```env
ASSISTANT_PROVIDER=anthropic
ASSISTANT_API_KEY=sk-ant-...
ASSISTANT_MODEL=claude-sonnet-4-5
```
ثم `php artisan config:clear`. بدون المفتاح يعمل المساعد بالتراجع الحتمي (دقيق ببيانات حقيقية، بلا شبكة).

