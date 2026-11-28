# تقرير النشر النهائي — ترقية منتج فهمني 2026-11

- التاريخ: 2026-11-27 (UTC)
- النطاق: الإنتاج (production)
- القرار: **تم النشر بنجاح ✅** — مع بقاء مسألة أمنية واحدة موثّقة (إبطال مفتاح R2 القديم) منفصلةً عن هذا الإصدار.
- التفويض: تفويض المالك الصريح بالنشر الفوري، مع قبول مخاطر تدوير المفاتيح الموثّقة.

---

## 1. الإصدار المنشور

| البند | القيمة |
|---|---|
| commit المنشور | `9f083cff45e417a9aaffdc50bc5361c1ab886654` |
| commit السابق (rollback) | `55179172a6bfb9efd39abba5106dcd20f488b348` |
| الفرع | `release/launch-2026-11-rc` |
| الدمج | PR #83 (13 commit) — دمج merge (لا squash) |
| فرع الميزة | `feat/product-upgrade-2026-11` @ `b21b98a` |
| شجرة الدمج | مطابقة بايتيّاً لشجرة رأس الفرع المُختبَر (`9697a860…`) |

## 2. CI (التكامل المستمر)

تشغيل CI مباشرةً على commit الدمج `9f083cff…` — **النتيجة: success** (run `45908875685`):

| الوظيفة | النتيجة |
|---|---|
| Laravel (PHP 8.4) | ✅ success |
| Next.js (typecheck + build) | ✅ success |
| Playwright (interactive-question sandbox) | ✅ success |
| Deploy assets (shellcheck + compose + Caddy) | ✅ success |
| Caddy edge (real Docker) | ✅ success |

## 3. النسخ الاحتياطي والاسترجاع

| البند | القيمة |
|---|---|
| نسخة مشفّرة خارج الموقع | `db-20261126T230325Z.dump.age` |
| sha256 | `84d6c1158bc0645c04eb46714ab86c585e15b170bbda778775eb608f1753346f` |
| تجربة استرجاع معزولة | ✅ OK (حاوية PostgreSQL مؤقتة، الإنتاج لم يُلمَس) |
| نتيجة التجربة | migrations=135 · users=22 · tenants=18 · الحجم=21MB |
| جاهزية rollback | ✅ أياميجة `aa4ef0e`/`5517917` الأربعة موجودة |

## 4. الترحيلات (Migrations)

نُفّذت عبر خدمة `migrate` لمرة واحدة — **exit=0**، ولا عملية هدّامة، وكلها إضافية ومتوافقة خلفياً:

| الترحيل | الحالة |
|---|---|
| `2026_11_27_000001_enable_video_watermark_on_paid_plans` | ✅ DONE (215ms) |
| `2026_11_27_000002_add_assessment_mode_to_exams_and_attempts` | ✅ DONE (31ms) |

## 5. حالة قاعدة البيانات على الإنتاج (تحقّق مباشر)

**استحقاقات العلامة المائية (`video_watermark`):**

| الخطة | القيمة |
|---|---|
| starter | false (غير متاحة) |
| professional | **true** (المحترف) |
| enterprise | **true** (المتقدم) |

**الحماية الأساسية للفيديو محفوظة:** `secure_playback_enabled = true` على الخطط الثلاث ✅

**أعمدة الميزة الجديدة:** `exams.assessment_mode` (default `'graded'`)، `exam_attempts.assessment_mode`، `exam_attempts.completed_at` ✅

**حفظ بيانات العملاء:** users=22 · tenants=18 · courses=5 · enrollments=3 (بلا تغيير) ✅

**الخطط والأسعار محفوظة:** starter 29/299 · professional 59/599 · enterprise 89/899 USD ✅

## 6. التحقق على الإنتاج (Containers + الصحة)

- `current -> releases/9f083cff…`، `.deployed-tag = 9f083cff…`
- الحاويات الخمس (app/frontend/scheduler/horizon/nginx) **healthy** بنفس وسم الإصدار.
- الأسطح العامة: `fahimni.me` 200 · `api.fahimni.me/up` 200 · `health/ready` 200 · `www.maryammath.com` 200
- اختبار smoke (TLS/HSTS/nosniff/redirect/health): ✅ OK

## 7. اختبار المتصفح الحقيقي (سطح المكتب + الجوال)

| الصفحة | سطح المكتب | الجوال (390×844) | RTL | تجاوز أفقي |
|---|---|---|---|---|
| fahimni.me (الهبوط) | ✅ (h1 سليم) | ✅ | ✅ | لا |
| fahimni.me/pricing | ✅ | ✅ | ✅ | لا |
| teacher/login | ✅ | — | ✅ | — |
| register/teacher | ✅ | — | ✅ | — |
| www.maryammath.com (دومين المعلّمة) | ✅ | ✅ | ✅ | لا |

لم تظهر أخطاء JS فعلية؛ الخطأ الوحيد (401) هو فحص جلسة الزائر غير المسجّل (متوقّع).

## 8. تحقق مصادَق عليه (توكن قصير العمر، حُذف فوراً)

**كمرجع رئيس المنصة:** `/api/v1/auth/me` 200 · `/dashboard` 200 · `/admin/plans` 200 · `/admin/subscriptions` 200 · `/admin/coupons` 200 · `/analytics` 200 · `/admin/live/providers` 200 · `/announcements` 200.

**كالمعلّمة الحقيقية (مريم — خطة professional، اشتراك نشط):**

| النقطة | النتيجة |
|---|---|
| `/teacher/video-security` | 200 — `feature_available:true, feature_key:video_watermark` |
| `/teacher/plan` | 200 — professional · `video_watermark:"true"` |
| `/teacher/analytics` | 200 — بيانات حقيقية (طالبان، كورسان، إيراد 59) |
| `/teacher/students` | 200 — الطلاب الفعليون |
| `/teacher/onboarding` | 200 |
| `/teacher/device-policy` | 200 |
| `/api/v1/courses` | 200 — كورس «رياضيات» |
| `/teacher/domain` | 200 — `maryammath.com` نشط ومُوثّق و HTTPS مُفعّل |

**الخلاصة:** علامة الفيديو المائية مُنحت تلقائياً للمعلّمة الحالية على خطة المحترف دون إعادة شراء أو تغيير في السعر/الخطة.

## 9. المسألة الأمنية المتبقية (منفصلة عن النشر)

- المفتاح المكشوف: **R2 Access Key ID يبدأ بـ `adfc4dad`** لا يزال فعّالاً (تحقق قراءة-فقط ناجح).
- المفتاح النشط البديل: يبدأ بـ `1ceeeeb3` (تعمل عليه النسخ الاحتياطية الليلية).
- الإجراء المتبقّي: إبطال المفتاح القديم من لوحة Cloudflare R2 → Manage R2 API Tokens → حذف الصف `adfc4dad`.
- هذا **لا يحجب** هذا الإصدار (بقرار المالك الصريح)، ويُتتبَّع في `docs/SECURITY_CREDENTIAL_ROTATION.md`.

## 10. ملاحظة صحّة (سابقة، غير مُتراجعة)

ثلاث نقاط معمّلية (`/teacher/video-security`, `/teacher/analytics`, `/teacher/device-policy`) تُرجع 500 لمرجع رئيس المنصة لأن لا سياق معلّم/مستأجر لديه؛ ملفاتها **مطابقة بايتيّاً** للإصدار السابق (لم تُلمس في هذا الإصدار)، ولم تظهر المشكلة مع معلّمة حقيقية (200). تحسين موصى به (لاحق): إرجاع 403 بدل 500 عند غياب سياق المستأجر.

## 11. الحكم النهائي

تم نشر ترقية منتج فهمني 2026-11 على الإنتاج، مع نجاح CI على commit الدمج، ونجاح الترحيلات دون فقد بيانات، وتحقّق فعلي من استحقاقات علامة الفيديو المائية وأسعار الخطط والدومين المخصّص. المسألة الأمنية الوحيدة المتبقية موثّقة ومنفصلة عن هذا الإصدار.
