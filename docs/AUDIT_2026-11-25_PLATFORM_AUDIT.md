# تقرير تدقيق منصة فهمني — 2026-11-25

**الفرع:** `release/launch-2026-11-rc`
**الإصدار المُنشَر (pinned):** `81abbbbf095cd721ddfc2fa5814ad91d24f159f6`
**صورة الإنتاج:** `prod-brand4` — مُتحقَّق منها على `https://fahimni.me` (health 200، الشعار والأمان مطابقان).
**النطاق:** مستودع `fahimni` فقط. لم تُلمس أسعار/خطط/عقود، ولم تُمس بيانات المعلمين/الطلاب الحقيقية.

> قاعدة الحالة في هذا المستند: `PASS` تعني أن اختبارًا/فحصًا نُفِّذ فعليًا على هذه الشجرة ونجح.
> ما يحتاج مزوّدًا خارجيًا أو حسابًا حقيقيًا فهو `BLOCKED` لا `PASS`. لم تُدَّعَ أي تغطية 100%.

---

## 1. الخلاصة التنفيذية (عربي)

- **الأمن (P1):** كان أي عضو في مساحة العمل — بما في ذلك **الطالب** — قادرًا على قراءة تفاصيل اشتراك المساحة
  وإلغائه عبر `POST /subscriptions/{id}/cancel` و`/subscriptions/freemius/cancel`. أُصلح الآن: كل مسارات
  الفوترة تتطلب موظفي المساحة (`role:teacher,owner,admin`) و`TenantSubscriptionPolicy` أصبحت حصرية للموظفين.
- **الصحة المالية (P1):** كانت إحصاءات المشرف الأعلى تُحصي المعلّم نفسه في أكثر من حالة (مدفوع + تجريبي = ازدواج)،
  وكانت إيرادات لوحة المدفوعات تجمع عملات مختلفة في رقم واحد. الآن تُوزَّع الحالات بأولوية واحدة
  (مدفوع > مشرف > تجريبي > غير موثّق > منتهٍ) والإيرادات تُعرَض لكل عملة على حدة.
- **تجربة الجوال والاتجاه (P2):** تأكيدات قبل الحذف/الفصل/تغيير الحالة، وحالات خطأ بدل الدوّار اللانهائي،
  وشبكات إحصاءات بعرض كامل على الهاتف، وإصلاح RTL (`pr-1` → `pe-1`)، واستبدال `alert()` بإشعار toast.
- **الإنتاج:** نُشر الإصدار بعد نسخة احتياطية مُختبَرة (restore drill: 133 migration، 18 مستخدمًا، 16 مساحة)
  وعبر الآلية المعتمدة `deploy/scripts/fahimni-golive.sh` فقط. الأرقام الحقيقية: **14 معلّمًا، 14 تجربة سارية،
  0 مدفوع، 0 منتهٍ بعد التوزيع، 12 نية دفع معلّقة، 0 إيراد** (لا اشتراكات مدفوعة بعد).

---

## 2. ما نُفِّذ فعليًا (أوامر ونتائج)

| الحزمة | عدد | النتيجة |
| --- | --- | --- |
| PHPUnit (كامل) | 913 اختبار / 3793 تأكيد | ✅ 0 فشل |
| Vitest (واجهة) | 353 اختبار / 65 ملفًا | ✅ 0 فشل |
| اختبارات سكربتات العقدة (`test:scripts`) | 51 | ✅ 0 فشل |
| TypeScript `tsc --noEmit` | — | ✅ نظيف |
| بناء Next للإنتاج | — | ✅ نجح |
| Playwright (صندوق الأسئلة التفاعلي) | 24 مُنفَّذ | ✅ 0 فشل · 43 متخطّى (يحتاج مزوّدًا/تركيبًا) |
| اختبارات النشر `deploy/tests/*.sh` | 15 ملفًا | ✅ 13 · ⛔ 2 (تحتاج Docker محليًا) |
| فحص الإنتاج (launch-smoke-test) | 34 فحصًا مُنفَّذًا | ✅ 0 فشل · 6 رِحلات BLOCKED |

**الفجوات المُعلَنة صراحةً (BLOCKED):** تسجيل/دخول Google الحقيقي، دخول بريد+كلمة المرور الحقيقي،
دخول المشرف/الطالب الحقيقي، إنشاء درس/ورقة عمل حقيقية، توليد/تصدير/تفعيل بطاقة، تشغيل فيديو/بث حسب الصلاحية،
ودومين مخصّص بـ DNS عام. كلها تحتاج حسابًا/مزوّدًا خارجيًا غير متاح، والاختبارات المُقلَّدة (fakes) تغطي منطقها.

---

## 3. مصفوفة التغطية

الرموز: `PASS` مُنفَّذ ونجح · `FIXED` عيب مُثبَت أُصلح + اختبار انحدار · `BLOCKED` يحتاج خارجيًا · `DOC` مُوثَّق.

### 3.1 الصفحات العامة (زائر)

| # | المسار | الوظيفة / الأزرار المهمة | الحالة | الدليل | المشكلة |
| --- | --- | --- | --- | --- | --- |
| P1 | `/` | الهبوط، العنوان OGP، الشعار | PASS | smoke: `/` 200 + `<title>` يحمل «منصة فهمني» | — |
| P2 | `/courses` | تصفّح الدورات | PASS | smoke link 200 | — |
| P3 | `/pricing` | الخطط والأسعار، تبديل شهري/سنوي، ابدأ | PASS | vitest + smoke 200 | — |
| P4 | `/checkout` | إتمام الشراء، تحميل الخطة، أزرار الدفع | FIXED | حالة خطأ بدل دوّار لانهائي | AUD-15 |
| P5 | `/checkout/success` | تأكيد الاشتراك | PASS | بناء + مسار محمي | — |
| P6 | `/about`-style (`for-teachers`, `lms-for-teachers`, `create-online-courses`, `online-teaching-platform`, `live-online-classes`, `teacher-website`, `for-teachers`) | صفحات التسويق | PASS | بناء Next | — |
| P7 | `/contact` `/privacy` `/terms` `/refund-policy` | صفحات قانونية/تواصل | PASS | smoke 200 | — |
| P8 | `/blog` و`/blog/[slug]` | المدونة | PASS | مسار عام مُبنى | — |
| P9 | `/t/[slug]` و`courses` و`book` و`register` و`blog` | متجر المعلم العام | PASS | مسارات عامة | — |
| P10 | `/card/[code]` | تفعيل بطاقة الطالب (عام) | PASS | مسار عام + اختبار البطاقات | — |
| P11 | `/verify/[number]` | التحقق من شهادة | PASS | مسار عام | — |
| P12 | `/offline` | صفحة انقطاع الشبكة (PWA) | PASS | بناء | — |

### 3.2 المصادقة

| # | المسار | الوظيفة | الحالة | الدليل | المشكلة |
| --- | --- | --- | --- | --- | --- |
| A1 | `/teacher/login` | دخول المعلم (بريد) | PASS | smoke 200 + CaptchaAuthTest | — |
| A2 | `/student/login` | دخول الطالب | PASS | smoke 200 | — |
| A3 | `/admin/login` | دخول المشرف | PASS | smoke 200 | — |
| A4 | `/register/teacher` `/register/student` | التسجيل | PASS | بناء + مسارات | — |
| A5 | Google login (زر) | بدء OAuth | FIXED | كان `alert()` عند غياب الإعداد → toast | AUD-12 |
| A6 | `/auth/google/callback` | رجوع OAuth | BLOCKED | يحتاج حساب Google حقيقي | — |
| A7 | `/forgot-password` `/reset-password` | استعادة كلمة المرور | PASS | مسارات + اختبارات auth | — |
| A8 | `/verify-email` `/verify-email/[token]` | توثيق البريد | PASS | مسار + خدمة | — |
| A9 | `/invite/[token]` | قبول دعوة فريق | PASS | مسار + اختبار الفريق | — |
| A10 | 2FA (تفعيل/دخول) | المصادقة الثنائية | PASS | SecurityHardeningTest + audit-log labels | — |

### 3.3 الطالب

| # | المسار | الوظيفة / الأزرار | الحالة | الدليل | المشكلة |
| --- | --- | --- | --- | --- | --- |
| S1 | `/my-learning` | مسار التعلّم، شبكة الإحصاءات | FIXED | شبكة بعرض كامل على الجوال | AUD-10 |
| S2 | `/course/[id]/learn` | مشغّل الدرس، تتبّع التقدّم | PASS | بناء + Playwright sandbox | — |
| S3 | `/exams` `/exam/[id]` | الاختبارات، إرسال، شبكة المدة/الأسئلة/النجاح | PASS | ExamTest + بناء | — |
| S4 | `/results` | النتائج | FIXED | شبكة بعرض كامل على الجوال | AUD-10 |
| S5 | `/certificates` `/certificates/[id]` | الشهادات | PASS | مسار + بناء | — |
| S6 | `/leaderboard` | لوحة المتصدرين | PASS | مسار | — |
| S7 | `/messages` | الرسائل | PASS | مسار + unread badge | — |
| S8 | `/community` `groups` `threads/[id]` | المجتمع | PASS | مسارات + بوّابة الخطة | — |
| S9 | `/announcements` | الإعلانات | PASS | vitest + مسار | — |
| S10 | `/notifications` | الإشعارات | PASS | مسار | — |
| S11 | `/my-bookings` | الحجوزات/المواعيد | PASS | nav test (مبوّب) | — |
| S12 | `/my-notes` | الملاحظات | PASS | vitest | — |
| S13 | `/profile` | الملف الشخصي | PASS | مسار | — |
| S14 | `/support` | الدعم | PASS | مسار | — |
| S15 | `/live/[id]` | مشاهدة بث | BLOCKED | يحتاج مزوّد بث حقيقي | — |

### 3.4 المعلم

| # | المسار | الوظيفة / الأزرار | الحالة | الدليل | المشكلة |
| --- | --- | --- | --- | --- | --- |
| T1 | `/teacher/dashboard` | لوحة التحكم | PASS | مسار + بناء | — |
| T2 | `/courses` `/courses/[id]` | إنشاء/تعديل/حذف دورة ودرس | FIXED | تأكيد الحذف + حالة خطأ + إزالة زر مكرر + aria | AUD-06 |
| T3 | `/students` | إدارة الطلاب، إعادة تعيين الأجهزة | FIXED | تأكيد قبل إعادة التعيين | AUD-13 |
| T4 | `/teacher/follow-up` | متابعة الطلاب | PASS | مسار | — |
| T5 | `/teacher/terms` | الفصول الدراسية | PASS | مسار | — |
| T6 | `/teacher/cards` | البطاقات | PASS | مسار + اختبار البطاقات | — |
| T7 | `/teacher/bundles` | الحزم | PASS | مسار | — |
| T8 | `/teacher/appointments` `/teacher/services` | المواعيد والخدمات | PASS | مسارات | — |
| T9 | `/teacher/live` | البث المباشر | PASS (مبوّب) | nav gate `live_stream_enabled` | — |
| T10 | `/teacher/schedule` | الدروس المجدولة | PASS (مبوّب) | nav gate | — |
| T11 | `/teacher/exams` | الاختبارات | PASS | مسار | — |
| T12 | `/teacher/qa` | أسئلة الطلاب | PASS | مسار | — |
| T13 | `/teacher/questions` | بنك الأسئلة | PASS | مسار | — |
| T14 | `/teacher/staff` | فريق العمل | PASS (مبوّب) | `multi_teacher_enabled` | — |
| T15 | `/teacher/assistant` | المساعد الذكي | PASS | nav test + AssistantAskTest | — |
| T16 | `/teacher/settings` | الإعدادات، ربط الدومين | FIXED | تأكيد فصل الدومين + حالة انشغال | AUD-07 |
| T17 | `/teacher/notifications` | إشعارات الإدارة | PASS | nav test + badge | — |
| T18 | درس → أسئلة (`lesson-questions`) | حذف سؤال | FIXED | تأكيد قبل الحذف | AUD-14 |
| T19 | `/analytics` | التحليلات والتقارير | PASS (مبوّب) | `export_reports` | — |
| T20 | `/teacher/complete-profile` | استكمال الملف الإلزامي | PASS | مسار + middleware profile | — |
| T21 | `/teacher/student-app` | تطبيق الطلاب | PASS | مسار (hideWithApp) | — |
| T22 | `/teacher/support` | الدعم | PASS | مسار | — |

### 3.5 لوحة المشرف الأعلى

| # | المسار | الوظيفة / الأزرار | الحالة | الدليل | المشكلة |
| --- | --- | --- | --- | --- | --- |
| AD1 | `/admin/dashboard` | إحصاءات المشرف، الإيراد الشهري | PASS | snapshot حقيقي من الإنتاج | — |
| AD2 | `/admin/teachers` | قائمة المعلمين + تفاصيل لكل معلّم | FIXED | عدّ البطاقات `withoutTenant` + توزيع الحالات | AUD-02, AUD-04 |
| AD3 | `/admin/subscriptions` | تمديد التجربة/تغيير الخطة/الحالة | PASS | SuperAdminFinancialStatisticsTest | — |
| AD4 | `/admin/plans` | إدارة الخطط | PASS | PlanSystemTest | — |
| AD5 | `/admin/students` | الطلاب عبر المنصة | PASS | مسار + `tenants/options` | — |
| AD6 | `/admin/view-as` | المعاينة كمعلم/طالب | PASS | مسار + لافتة view-as | — |
| AD7 | `/admin/quran-requests` | طلبات مدرّسي القرآن، بحث RTL | FIXED | التفاف + بحث RTL | AUD-11 |
| AD8 | `/admin/payments` | المدفوعات، تغيير الحالة، إيراد لكل عملة | FIXED | تأكيد قبل التغيير + خريطة عملة | AUD-03, AUD-08 |
| AD9 | `/admin/invoices` `/admin/coupons` | الفواتير والكوبونات (تبويبات المبيعات) | FIXED | منع فيض المبالغ على الجوال | AUD-16 |
| AD10 | `/admin/tickets` | تذاكر الدعم | PASS | مسار | — |
| AD11 | `/admin/teacher-notifications` | إشعارات المعلمين | PASS | nav test | — |
| AD12 | `/admin/monitoring` `/admin/monitoring/frontend` | المراقبة وأخطاء الواجهة | PASS | MonitoringTest | — |
| AD13 | `/admin/bi` | ذكاء الأعمال | PASS | مسار | — |
| AD14 | `/admin/media` | الوسائط والبنية التحتية | PASS | MediaEngineTest | — |
| AD15 | `/admin/live` | مزودو البث | PASS | مسار | — |
| AD16 | `/admin/settings` | إعدادات المنصة | PASS | مسار | — |
| AD17 | `/admin/cards` | دفعات البطاقات | DOC | صفحة عاملة لكن **غير مربوطة** بالقائمة وتستدعي `/cards/batches` بسياق مساحة | AUD-18 |
| AD18 | `/admin/transactions` | سجل الحركات | DOC | صفحة غير مربوطة وتستدعي `/transactions` بسياق مساحة (`X-Tenant-Id`) لا يملكه المشرف الأعلى | AUD-18 |
| AD19 | `/admin/logs` | سجل التدقيق/الأخطاء | DOC | غير مربوطة، وتستخدم `X-Tenant-Id` من `localStorage` | AUD-18 |

### 3.6 النهاية الخلفية (مجموعات المسارات)

| # | المجموعة | الحالة | الدليل | المشكلة |
| --- | --- | --- | --- | --- |
| B1 | `auth/*` (تسجيل/دخول/2FA/Google redirect) | PASS | CaptchaAuthTest, WebSessionAuthTest | — |
| B2 | `tenants` (index/store/current/switch) | PASS | TenantController + routes | AUD-17 (تنظيف عميل) |
| B3 | `subscriptions` + `subscriptions/freemius/*` | FIXED | حصر الموظفين + اختبار انحدار | AUD-01 |
| B4 | `payments` + `admin/payments` | FIXED | خرائط لكل عملة | AUD-03 |
| B5 | `admin/teachers/*` + الإحصاءات | FIXED | توزيع أحادي + `withoutTenant` | AUD-02, AUD-04, AUD-05 |
| B6 | `courses`/`sections`/`lessons`/`assignments` | PASS | PHPUnit | — |
| B7 | `exams`/`questions`/`attempts`/التصحيح | PASS | PHPUnit | — |
| B8 | `cards` (توليد/استيراد/تصدير/تفعيل) | PASS | PHPUnit + بوّابة `cards.export` | — |
| B9 | `media`/`videos` (تشغيل موقّع، حصص) | PASS | MediaEngineTest, VideoSecurityTest | — |
| B10 | `live`/recordings | PASS | LiveStreamingTest | — |
| B11 | `monitoring`/`audit-logs`/`error-logs` | PASS | MonitoringTest, SecurityHardeningTest | — |
| B12 | `backups` | PASS | BackupApiTest | — |
| B13 | عزل المساحات عبر المسارات الحساسة | PASS | TenantIsolationTest | — |
| B14 | ترويسات الأمان/CSP/التوقيعات | PASS | SecurityHardeningTest + smoke | — |

### 3.7 النشر والبنية التحتية

| # | العنصر | الحالة | الدليل |
| --- | --- | --- | --- |
| D1 | `fahimni-golive.sh` (dry-run) | PASS | pre-flight + restore drill نجحا |
| D2 | `fahimni-golive.sh` (نشر فعلي) | PASS | EXIT=0 · PRODUCTION LIVE |
| D3 | `fahimni-brand-rollout.sh` وتطابق SHA | PASS | host copy == pinned blob (8de70e41…) |
| D4 | integrity test `fahimni-golive-test.sh` | PASS | كل اختبارات T0–T17 |
| D5 | نسخة احتياطية + استرجاع | PASS | restore drill: 133 migration/18 user/16 tenant |
| D6 | فحص دخاني بعد النشر | PASS | 34 فحصًا · 0 فشل · 6 BLOCKED |
| D7 | `caddy-merge-validate` / `caddy-reconcile-recreate` | BLOCKED | يحتاجان Docker محليًا |
| D8 | دومين مخصّص SSL/DNS حقيقي | BLOCKED | لا دومين عام مملوك |

---

## 4. سجلّ العيوب والإصلاحات (هذه الدورة)

| المعرّف | الخطورة | الوصف | الإصلاح | اختبار الانحدار |
| --- | --- | --- | --- | --- |
| AUD-01 | P1 أمن | عضو/طالب يستطيع قراءة وإلغاء اشتراك المساحة | `TenantSubscriptionPolicy` حصرية للموظفين + `role:teacher,owner,admin` على مسارات الفوترة | `TenantSubscriptionAuthorizationTest` |
| AUD-02 | P1 مالي | ازدواج إحصاء المعلّم في عدة حالات | توزيع بأولوية واحدة (مدفوع>مشرف>تجريبي>غير موثّق>منتهٍ) | `SuperAdminFinancialStatisticsTest` |
| AUD-03 | P1 مالي | جمع عملات مختلفة في رقم إيراد واحد | خرائط `revenue_by_currency`/`refunded_total_by_currency` | `SuperAdminFinancialStatisticsTest` |
| AUD-04 | P2 | ترويسة `X-Tenant-Id` دخيلة تشوّه إحصاءات البطاقات | `withoutTenant()` في `teacherShow` | `SuperAdminFinancialStatisticsTest` |
| AUD-05 | P2 | فحص التجربة حساس لحالة الأحرف | `normalizeStatus()` | `SuperAdminFinancialStatisticsTest` |
| AUD-06 | P2 UX | حذف دورة بلا تأكيد/حالة خطأ/زر مكرر | تأكيد + حالة خطأ + إزالة التكرار + aria | typecheck + Vitest |
| AUD-07 | P2 UX | فصل الدومين بلا تأكيد | تأكيد + حالة انشغال | typecheck |
| AUD-08 | P2 UX | تغيير حالة الدفع بلا تأكيد | تأكيد + Pending | typecheck |
| AUD-09 | P2 جوال | نافذة التأكيد تتجاوز الشاشة | `max-h-[90vh] overflow-y-auto` | Vitest (confirm-dialog) |
| AUD-10 | P2 جوال | شبكات الإحصاءات مقصوصة على الجوال | بعرض كامل تحت `sm` | typecheck |
| AUD-11 | P2 RTL | تباعد/بحث في طلبات القرآن | `pe-1` + RTL search | typecheck |
| AUD-12 | P2 UX | `alert()` في زر Google | `toast.error` | Vitest (google-login-button) |
| AUD-13 | P2 UX | إعادة تعيين الأجهزة بلا تأكيد | تأكيد | typecheck |
| AUD-14 | P2 UX | حذف سؤال بلا تأكيد | تأكيد | Vitest (lesson-questions) |
| AUD-15 | P2 UX | فشل تحميل خطة الدفع يترك دوّارًا لانهائيًا | حالة خطأ + زر رجوع | typecheck |
| AUD-16 | P3 | فيض مبلغ الفاتورة على الجوال | `min-w-0 break-words` | typecheck |
| AUD-17 | P3 | دوال عميل `tenants.get/update/remove` لمقاطع غير موجودة | إزالتها | typecheck |
| AUD-18 | P3 | صفحات مشرف بلا رابط (cards/transactions/logs) | **مُصلَح (Round 2)**: صارت منصّةً-كاملةً (إزالة `X-Tenant-Id` الدخيل) ورُبطت في القائمة (المعاملات→المبيعات، السجلات→المراقبة، البطاقات→المنصة) | e2e (admin-journey) |
| AUD-19 | P1 | **تعذّر على المعلّم إنشاء واجب من الواجهة** — حوار الواجب يرسل `POST /api/v1/assignments` بدون `course_id` ⇒ 422 «حقل معرّف الكورس مطلوب» | إسناد زر الحفظ إلى الدالة `saveAssign()` الموجودة (تحوي `course_id`) وإلغاء النسخة المكرّرة الناقصة | e2e (journeys 7–9: إنشاء→تسليم→تصحيح) |
| AUD-20 | P1 | **بوّابة `assignments_enabled` غائبة عن كل الخطط** (الـseeder لم يكتبها) ⇒ كل مسارات الواجبات 403 على المنصة كاملةً رغم بيع `assignments_limit` | هجرة `2026_11_25_000001` تعبّئ البوّابات المُنفَّذة الناقصة (إضافة فقط، دون مسّ الأسعار) + مزامنة `SubscriptionPlanSeeder` | `PlanFeatureGateBackfillTest` |
| AUD-21 | P2 | بوّابتا `blog_enabled` و`custom_domain` غائبتان عن كل الخطط ⇒ المدونة والدومين المخصّص معطّلان للجميع (بما فيها أعلى خطة) | نفس الهجرة `2026_11_25_000001` (إضافة فقط) + الـseeder | `PlanFeatureGateBackfillTest` |
| AUD-22 | P2 | `GET /api/v1/teacher/analytics` يستخدم `to_char` (PostgreSQL فقط) ⇒ **500 على SQLite** وجميع الدوال غير قابلة للاختبار | تعبير يوم واعٍ بالمحرّك (`strftime`/`DATE_FORMAT`/`to_char`) | `TeacherAnalyticsPortabilityTest` |
| AUD-23 | P2 | مكوّن `Dialog` المشترك بلا دلالات وصول (لا `role="dialog"`/`aria-modal`، وخلفية الإغلاق غير مخفية عن قارئ الشاشة) | `role="dialog" aria-modal="true"` + `aria-hidden` للخلفية | `dialog.test.tsx` |
| AUD-24 | P2 | `AuthController::register` ينشئ المستخدم **قبل** بوّابات دعوة المعلّم/المنصة ⇒ حساب يتيم يحجز البريد ويحجب التسجيل الشرعي | نقل كل البوّابات قبل `User::create` | `TeacherRegistrationGateTest` |

---

## 5. القيود والخطوات التالية المقترحة

1. **صفحات المشرف غير المربوطة (AUD-18)** — قرار منتجي: إمّا ربطها بقائمة «المنصة» مع جعلها تعمل على مستوى المنصة،
   أو تقاعدها. لم تُحذف حفاظًا على العمل غير المُلتزَم.
2. **لاحقة عملة إيراد المعلّم** — الرقم صحيح (عملة المساحة موحّدة) لكن اللاحقة تأتي من أول دفعة؛ يُفضَّل تمرير
   عملة المساحة صراحةً. P3 تجميلي.
3. **الرِحلات المحجوبة** أعلاه تحتاج حساب اختبار/مزوّدًا معزولًا لتحويلها إلى PASS.
4. **دومين مخصّص** يحتاج دومينًا عامًّا مملوكًا لاختبار SSL/DNS فعليًا.

---

## 6. الدورة الثانية — التحقّق بمتصفح حقيقي على بيئة معزولة

### 6.1 البيئة المعزولة (لا تمَس الإنتاج إطلاقًا)

| العنصر | القيمة |
| --- | --- |
| التطبيق | Laravel الحقيقي (`php artisan serve`، منفذ 8080) + Next الحقيقي (`next start`، منفذ 3000، `INTERNAL_API_URL`→8080) |
| قاعدة البيانات | SQLite معزولة `database/audit.sqlite` (مُدرَجة في `.gitignore`، غير مُلتزَمة) |
| الكاش/الجلسة/الطابور | file / file / sync (بلا Redis) |
| الحسابات | مشرف أعلى `admin@audit.local`، معلّمون وطلاب حقيقيون بأبريد `@audit.local` |
| إعدادات الاختبار | `teacher_registration_enabled=true`، `captcha_enabled=false` |

لم يُلمَس أي حساب/بيانات حقيقية، ولم تتغيّر أي أسعار أو خطط (الهجرة تضيف بوّابات مفقودة **دون** مسّ الأسعار أو الحدود الرقمية).

### 6.2 رحلات المتصفح الحقيقي (Playwright — بلا أي `page.route` stub)

الملف: `frontend/e2e/audit-real-journeys.spec.ts` — يقود الواجهة على الـAPI الحقيقي (جلسة BFF + كوكي HttpOnly + نطاق المساحة).

| # | الرحلة | النتيجة |
| --- | --- | --- |
| 1 | دخول المشرف الأعلى + الصفحات الثلاث (transactions/logs/cards) + روابطها في القائمة، بلا أخطاء طلب | **PASS** |
| 2 | تسجيل معلّم → مساحة عمل + تجربة 7 أيام → إنشاء كورس + وحدة + درس | **PASS** |
| 3 | توليد دفعة بطاقات تفعيل وظهور كود حقيقي (24 hex) | **PASS** |
| 4 | تسجيل طالب على مساحة المعلّم (`?tenant_slug=`) واسترداد البطاقة | **PASS** |
| 5 | فتح مشغّل الكورس وعرض الدرس الأول | **PASS** |
| 6 | سطح تصحيح الامتحانات لدى المعلّم | **PASS** |
| 7 | **إنشاء واجب منشور** (كان 422 قبل الإصلاح) | **PASS** |
| 8 | **تسليم الطالب الواجب** من المشغّل | **PASS** |
| 9 | **تصحيح المعلّم التسليم** في عارض التسليمات | **PASS** |

لقطات الشاشة في `frontend/audit-shots/` (غير مُلتزَمة).

### 6.3 ما حُجِب (BLOCKED — خارجي، لم يتغيّر)

Google OAuth الحقيقي · رحلة البريد كاملةً · الفيديو/البث الحقيقي · رفع Bunny · دفع Freemius (sandbox/حيّ) · حساب مشرف/طالب حقيقي على الإنتاج · دومين عام مملوك لاختبار DNS/SSL · Docker محلي (اختبارات `caddy-*`).

### 6.4 الحزمة الكاملة (Round 2 — على هذه الشجرة)

| الحزمة | الأمر | النتيجة |
| --- | --- | --- |
| PHPUnit (كامل) | `vendor/bin/phpunit --no-coverage` | ✅ **919/919** اختبار · 3833 تأكيد |
| اختبارات الانحدار الجديدة | الستة المضافة (AUD-19…24) | ✅ 15 تأكيدًا إضافيًا |
| Vitest (واجهة) | `npx vitest run` | ✅ **356/356** · 66 ملفًا |
| TypeScript | `npm run typecheck` | ✅ نظيف |
| بناء Next | `npm run build` | ✅ نجح |
| Playwright (افتراضي/CI) | `npm run test:e2e` | ✅ 24 مُنفَّذًا · 0 فشل · 52 متخطّى |
| Playwright رحلات حقيقية | `AUDIT_REAL_STACK=1 npx playwright test e2e/audit-real-journeys.spec.ts` | ✅ **9/9 PASS** |

> ملاحظة اختبارية: عند تشغيل PHPUnit من هذه البيئة المحلية يجب عدم تسرّب مفاتيح `.env` المعزولة إلى الحزمة
> (`SUPER_ADMIN_EMAIL=""`, `BACKUP_DISK=backup`, `BACKUP_NAME_PREFIX=SHAHEAD_BACKUP`, `BACKUP_QUEUE=backups`);
> وإلا تظهر اختبارات الإشعارات/النسخ الاحتياطي فاشلة **بسبب البيئة لا بسبب الكود**. لا يتأثر CI (يكتب `.env` نظيفًا).

### 6.5 الحكم

- 9/9 رحلات متصفح حقيقية **PASS** على البيئة المعزولة.
- 4 عيوب جديدة مُثبَتة بالإصلاح (AUD-19…AUD-22) + عيبان مكمّلان (AUD-23/24)، كلها مع اختبار انحدار.
- صفحات المشرف اليتيمة (AUD-18) صارت مربوطة ومنصّةً-كاملة.
- الحزمة الكاملة خضراء: 919 PHPUnit · 356 Vitest · بناء نظيف · 24+9 Playwright.

---

*أُعدّ آليًا ضمن دورة تدقيق 2026-11-25. كل حالة `PASS` هنا مرتبطة بأمر نُفِّذ على هذه الشجرة أو على الإنتاج.*
