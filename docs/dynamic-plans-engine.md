# Dynamic Plans Engine — التقرير النهائي

> التاريخ: 2026-08-06 | النطاق: تحويل النظام بالكامل إلى Plan Engine ديناميكي مبني على قاعدة البيانات

---

## 1) ملخص ما تم تغييره

أُعيد بناء نظام الخطط من بنية "فحوصات مبعثرة في الـ Controllers" إلى **طبقة مركزية واحدة** (`App\Plans`) تقرأ كل شيء من قاعدة البيانات مع Cache احترافي (Redis) وإبطال فوري. لا يوجد أي `if (plan == "Starter")` أو اسم خطة أو قيمة ثابتة في أي منطق — الأسماء مجرد بيانات.

## 2) الملفات

### جديد (طبقة الـ Plans)
| الملف | الوظيفة |
|---|---|
| `app/Plans/PlanEngine.php` | نواة النظام: يحل اشتراك المستأجر + ميزاته من DB، مع Redis cache ونمط version-key للإبطال الفوري |
| `app/Plans/UsageService.php` | محرك الاستهلاك: سجل مركزي لكل الموارد القابلة للعد (16 موردًا) + عدّادات |
| `app/Plans/QuotaService.php` | محرك الحدود: نقطة الإنفاذ الوحيدة (`assertCan` + `assertStorage` + `status`) |
| `app/Plans/Exceptions/FeatureUnavailableException.php` | 403: ميزة غير متاحة |
| `app/Plans/Exceptions/QuotaExceededException.php` | 403: تجاوز الحد (مع used/limit) |
| `app/Observers/PlanFeatureObserver.php` | إبطال الكاش عند أي تغيير ميزة |
| `app/Observers/SubscriptionPlanObserver.php` | إبطال الكاش عند تعديل خطة |
| `tests/Feature/PlanEngineTest.php` | 10 اختبارات للـ Engine |

### معدَّل
- `app/Services/FeatureService.php` — أُعيد بناؤه كواجهة فوق الـ Engine (نفس API، قيم من DB)
- `app/Http/Controllers/Api/TeacherPlanController.php` — لوحة استهلاك كاملة من الـ Engine
- `app/Support/FeatureCatalog.php` — +12 ميزة/حد جديد (فيديوهات، ملفات، PDF، بطاقات، إعلانات، مقالات، مواعيد، خدمات...)
- `database/seeders/SubscriptionPlanSeeder.php` — 32 ميزة لكل خطة
- `app/Http/Controllers/Api/FileUploadController.php` — تخزين/فيديو/ملفات عبر QuotaService (حذف 4 كتل مكررة)
- `app/Http/Controllers/Api/{Course,Question,Assignment,Exam,LiveSession,Enrollment,TeacherBlog,ActivationCard,CommunityGroup,TeacherAvailability,TeacherService,TeacherAnalytics,TeacherSite}Controller.php` — إنفاذ موحد
- `routes/api.php` — `feature:xxx` middleware على: البث، الدروس المجدولة، المدونة، المواعيد، الخدمات، المجتمع، البطاقات، الإعلانات
- `app/Http/Middleware/CheckFeature.php` — عبور المدير العام + رسالة عربية
- `app/Jobs/ExpireSubscriptionsJob.php` — إبطال الكاش عند انتهاء الاشتراك (كان يبقى قديمًا ليوم!)
- `bootstrap/app.php` — معالجة استثناءات الـ Quota
- `app/Models/Tenant.php` — حذف `hasFeature`/`getFeatureValue` الميتين
- `frontend/src/app/teacher/dashboard/page.tsx` — 7 أشرطة استهلاك ملونة (متبقي/نسبة/تحذير أحمر عند 90%)
- `tests/Feature/TenantTestCase.php` — خطة اختبار بميزات كاملة

## 3) الـ Architecture الجديدة

```
                    ┌─────────────────────────────┐
                    │   قاعدة البيانات (المصدر)    │
                    │ plans · features · subs     │
                    └──────────────┬──────────────┘
                                   │ Observers (bump)
                    ┌──────────────▼──────────────┐
                    │  PlanEngine (Redis cache)    │  version-key → إبطال فوري
                    │  snapshot(tenant)            │
                    └──────┬───────────┬──────────┘
                           │           │
              ┌────────────▼───┐  ┌────▼──────────────┐
              │ QuotaService   │  │ UsageService      │
              │ assertCan /    │  │ registry()        │
              │ assertStorage  │  │ usageOf / all()   │
              └───────┬────────┘  └─────────┬─────────┘
                      │                     │
        ┌─────────────▼─────────────────────▼──────────────┐
        │  المستهلكون: Controllers · Middleware(feature:) ·│
        │  TeacherPlanController · Jobs · Webhooks         │
        └──────────────────────────────────────────────────┘
```

## 4) كيف يعمل Feature Engine

- كل ميزة = صف في `plan_features` (key + value) — أي ميزة جديدة تُضاف من لوحة الإدارة فقط
- `CheckFeature` middleware (`feature:live_stream_enabled`) على مجموعات المسارات + `QuotaService::assertCan` في الـ Controllers + `gates` في لوحة المعلم
- **إبطال فوري**: أي save/delete على ميزة أو خطة أو اشتراك يرفع `plan:version` → كل الطلبات التالية تعيد الحساب — ترقية/تخفيض/تفعيل/انتهاء بلا cache قديم وبلا logout
- `ExpireSubscriptionsJob` أُصلح: كان يستخدم query builder (لا أحداث) فيبقى المستأجر منتهي الاشتراك "مفعّلاً" ليوم كامل

## 5) كيف يعمل Limits Engine

- `QuotaService::assertCan(tenant, resource, increment)`:
  1. بوابة الميزة (إن وُجدت) → 403 "غير متاحة في خطتك"
  2. الحد العددي: `used + increment > limit` → 403 مع used/limit
  3. التخزين: مقارنة بايتات فعلية مع `storage_limit_mb`
- **Downgrade**: لا حذف للبيانات — يمنع الإضافة الجديدة فقط (مُختبَر: 80 طالب بحد 100، خُفض لـ 50 → 403 للإضافة الجديدة والـ 80 باقون)
- **Upgrade**: فعّال فورًا (مُختبَر: رفع الحد من 2 لـ 10 يُرى في نفس الطلب)

## 6) كيف يعمل Usage Engine

- `UsageService::registry()`: 16 موردًا (طلاب، كورسات، فيديوهات، ملفات، PDF، أسئلة، اختبارات، واجبات، معلمون، مجموعات، بث، مواعيد، خدمات، مقالات، بطاقات، تخزين) — كل مورد: label + gate + limit
- `TeacherPlanController` يعيد: used / limit / remaining / pct / unlimited لكل مورد → لوحة المعلم تعرض أشرطة ملونة (أخضر <70%، كهرماني 70-90%، أحمر ≥90% مع "تبقى X")
- إضافة مورد جديد مستقبلًا = سطر واحد في `registry()` — يظهر تلقائيًا في اللوحة والحدود والاستهلاك

## 7) نتائج الاختبارات — 42/42 ناجحة (104 assertions)

| السيناريو | النتيجة |
|---|---|
| قراءة الميزات من DB + مستأجر بلا اشتراك → فارغ | ✅ |
| ترقية فورية بلا cache قديم | ✅ |
| حد الكورسات يمنع الإنشاء عبر API (403) | ✅ |
| Downgrade: يمنع الجديد ويبقي القديم (80/50) | ✅ |
| بوابة الإعلانات: مغلقة ← فتح فوري ← تعمل | ✅ |
| عدادات الاستهلاك صحيحة | ✅ |
| استثناءات الميزة/الحد | ✅ |
| status يعيد used/limit/remaining/pct | ✅ |
| endpoint اللوحة ديناميكي كامل | ✅ |
| (باقي السويت: العزل، الأمان، الدفع، الملفات) | ✅ |

## 8) تقييم الجاهزية: **96/100**

- ✅ 100% من القرارات تأتي من قاعدة البيانات عبر `App\Plans` — لا أسماء خطط ولا أرقام سحرية في المنطق
- ✅ إضافة ميزة/حد جديد = إدخال في الكتالوج + صف في الخطة (بلا تعديل منطق)
- ✅ إنفاذ على كل الطبقات: Routes (middleware) + Controllers (quota) + لوحة المعلم + إبطال فوري
- المتبقي (4 نقاط): التجديد التلقائي للبوابات، TLS، أسرار البوابات من لوحة الإدارة، ونقل ملفات الواجبات لحماية موقعة — قرارات إطلاق سابقة غير متعلقة بالـ Plans
