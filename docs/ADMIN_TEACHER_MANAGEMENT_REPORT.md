# إدارة المعلم من السوبر أدمن: وضع الدعم الإداري + الدومين المخصص — تقرير مختصر

- الفرع: `feat/admin-manual-subscription` (انطلاقًا من `release/launch-2026-11-rc` @ `65fe516`)
- الإنتاج: **لم يُنشر شيء.** لا دمج، لا نشر تلقائي، لم تُلمس أي بيانات حقيقية ولم يُغيّر أي DNS إنتاجي.
- المبدأ: **إعادة استخدام** منظومة الجلسات/الصلاحيات ومحرّك الدومين المخصص/DNS/TLS القائم. لم يُنشأ نظام موازٍ.
- التاريخ: 26 Nov 2026

---

## (1) مطابقة المتطلبات

### أ) وضع الدعم الإداري (Admin Impersonation)

| المتطلب | الحالة | الدليل |
|---|---|---|
| زر «الدخول إلى لوحة المعلم» في تفاصيل المعلم | **Done** | `frontend/src/components/admin/teachers-panel.tsx` (`data-testid="enter-support"`) |
| يفتح لوحة المعلم في تبويب جديد | **Done** | `window.open(d.enter_path, '_blank')` عبر مسار `/admin/support/enter` |
| إدارة الكورسات/الإعدادات/الدومين دون كلمة مرور المعلم | **Done** | تبديل جلسة من جهة الخادم (`WebSessionAuth::login`) دون أي بيانات دخول |
| شريط علوي واضح بالمعلم + «إنهاء الجلسة والعودة للإدارة» | **Done** | `frontend/src/components/layout/admin-support-banner.tsx` (مُثبّت في `panel-shell.tsx`) |
| الحفاظ على جلسة الأدمن الأصلية | **Done** | هوية الأدمن محفوظة داخل الماركر (`actor_id`) وتُستعاد عند الإنهاء/الانتهاء |
| منع الدخول المتداخل معلم-إلى-معلم | **Done** | `begin`/`activate` يرفضان عند وجود جلسة قائمة؛ `ViewAsController` يرفض فوقها |
| جلسة قصيرة + رمز أحادي الاستخدام | **Done** | TTL = 30 دقيقة؛ رمز دخول `Cache::pull` بصلاحية 120 ثانية ومربوط بالأدمن المُصدِر |
| حصرها بالسوبر أدمن مع تحقق طرف الخادم | **Done** | `is_super_admin` يُفحص من جديد في كل مسار، وليس من علم عميل |
| تسجيل كل بدء/إيقاف (أدمن، معلم، سبب) | **Done** | `admin.support.ticket` / `.start` / `.stop` / `.expired` عبر `AuditLogger` |
| تسجيل التعديلات بهوية السوبر أدمن الحقيقية | **Done** | `AuditLogger::log(..., $actorId)` + `BlockSensitiveSupportWrites` |
| حظر العمليات الحساسة داخل وضع الدعم | **Done** | `app/Http/Middleware/BlockSensitiveSupportWrites.php`: الدفع/الاشتراك/الفاتورة، كلمة المرور/2FA/المفاتيح/التوكنات، الأدوار/الملكية/الأعضاء/الدعوات/الـwebhooks، وكل `DELETE` |
| عدم التأثير على معلمين/مستأجرين آخرين | **Done** | الاختبار `test_a_support_session_covers_only_the_target_tenant` |
| عدم إيهام بأن المعلم نفّذ إجراءً إداريًا | **Done** | الشريط يصرّح أن الفاعل هو السوبر أدمن؛ كل книابة `admin.support.write` باسم الأدمن |

### ب) الدومين المخصص (Super-Admin)

| المتطلب | الحالة | الدليل |
|---|---|---|
| قسم «الدومين المخصص» في تفاصيل المعلم | **Done** | `frontend/src/components/admin/teacher-domain-modal.tsx` (`data-testid="open-domain"`) |
| ربط دومين بمعلم دون دخوله | **Done** | `app/Http/Controllers/Api/Admin/TeacherDomainController.php` (`connect`) |
| إعادة استخدام آلية الدومين القائمة | **Done** | نفس `TenantSetting.custom_domain` + `metadata.domain_verified` ومُساعدات `TeacherDomainController` |
| تعليمات DNS دقيقة من إعدادات الإنتاج (CNAME/A/TXT) بلا اختراع IP | **Done** | `TeacherDomainController::instructionsFor` تقرأ `platformIp($platform)` الفعلي |
| دعم دومين مُدار على Hostinger دون تخزين كلمة مرور | **Done** | `hostinger_steps` + `provider_note` (لا نطلب كلمة مرور إطلاقًا) |
| التحقق من الملكية عبر DNS قبل التفعيل | **Done** | `verify` يتحقق عبر `dnsRecordsFor`/`recordsPointAtPlatform` |
| منع الاستيلاء على دومين معلم آخر أو غير مُتحقق | **Done** | فحص `TenantSetting::withoutTenant()` أحادي الملكية + `test_admin_cannot_connect_a_domain_owned_by_another_teacher` |
| SSL تلقائي إن سمح الاستضافة | **Done** | on-demand TLS عبر `public/tls/ask`؛ يُسمح فقط للمُتحقق عبر `TenantDomainResolver::isAllowedEdgeHost` |
| عمل موقع المعلم على الدومين الصحيح | **Done** | `/api/v1/public/domain/{domain}` يرجّع المستأجر الصحيح (اختبار) |
| لا تسريب بيانات عبر Host Header/redirect/cache | **Done** | الحل عبر المستأجر المقصود من الدومين؛ لا يخدم غير المُتحقق |
| إبقاء رابط fahimni.me وسياسة روابط/SEO واضحة | **Done** | الرابط المجاني يبقى ظاهرًا وفعّالًا («العنوان الأساسي للمشاركة» للمخصص) |
| حالة الدومين (بانتظار DNS/تحقق/SSL/نشط/فشل بسبب) | **Done** | `statusPayload`: `none` / `pending_dns` / `failed` / `active` + `status_reason` |
| «فحص الربط» و«إلغاء الربط» مع حماية | **Done** | `verify` + `disconnect`؛ الإلغاء يبقي الرابط المجاني |
| أمان الجلسات عبر الدومينات دون تسريب صلاحيات | **Done** | نفس الجلسة المُبدّلة من الخادم؛ لا منطق صلاحيات على العميل |
| عدم شراء دومينات أو تغيير DNS إنتاجي | **Done** | لم يُلمس أي DNS/دومين إنتاجي؛ العمليات اختبارية فقط |

---

## (2) التصميم — إعادة استخدام لا استبدال

- **الجلسة:** لا تغيير في بنية المصادقة. يُبدَّل المستخدم داخل الجلسة القائمة من جهة الخادم (`WebSessionAuth::login`)، ويُحفظ ماركر `admin_support` منفصل عن `view_as` (المعاينة للقراءة فقط) حتى لا يُلمس عقده/اختباراته.
- **الرجوع المضمون:** هوية الأدمن (`actor_id`) محفوظة في الماركر. عند `stop` أو انتهاء `expires_at` يُعاد الأدمن؛ وإن فُقد/سُحبت صلاحيته تُبطَل الجلسة ولا تُترك جلسة المعلم مُصادَقة.
- **الرمز الأحادي:** `begin` يصدُر رمزًا عشوائيًا (64 حرفًا) في `Cache` لمدة 120 ثانية مربوطًا بأدمن + معلم + مستأجر + سبب؛ `activate` يستهلكه عبر `Cache::pull` (نفس السلوك عند الفشل).
- **الكتابة في وضع الدعم:** تُسمح كتابات المحتوى/الإعدادات/الدومين، وتُحظر قائمة سوداء من جهة الخادم + كل `DELETE`. كل كتابة مسموحة تُقيَّد بهوية الأدمن الحقيقية عبر `AuditLogger::log(..., $actorId)`.
- **الدومين المخصص:** نفس مخزن `TenantSetting` ونفس مُساعدات التحقق وon-demand TLS؛ لا نظام موازٍ. ملكية واحدة للدومين (`UniqueConstraintViolationException` + فحص `withoutTenant`).

### مسارات جديدة
- `POST /api/v1/admin/support/{teacher}/begin` — إصدار رمز الدخول.
- `POST /api/v1/admin/support/activate` — استهلاك الرمز وتبديل الجلسة.
- `GET /api/v1/admin/support` و `DELETE /api/v1/admin/support` — الحالة والإنهاء.
- `GET/PUT /api/v1/admin/teachers/{teacher}/domain` + `POST .../domain/verify` + `DELETE .../domain`.

---

## (3) الاختبارات

### الخلفية (`tests/Feature/AdminSupportSessionTest.php`) — 14 اختبارًا / 97 تأكيدًا (تشغيل مستهدف)
- حصر البدء/التفعيل بالسوبر أدمن (403) وعدم إمكانية انتحال حساب أدمن (404).
- أحادية استخدام الرمز (`TOKEN_INVALID`) وربط الرمز بالأدمن المُصدِر (403).
- الدخول يبدّل الجلسة إلى المعلم، والإنهاء يعيد الأدمن، و`/auth/me` يؤكد الحالتين.
- منع التداخل (جلسة دعم ثانية + view-as فوقها).
- كتابة المحتوى مسموحة + تُقيَّد بهوية الأدمن؛ والعمليات الحساسة/كل DELETE تُرفض (403 `SUPPORT_SENSITIVE_BLOCKED`).
- انتهاء الجلسة يعيد الأدمن ويُقيَّد `admin.support.expired` (401 `SUPPORT_EXPIRED`).
- عزل المستأجر المستهدف.
- تدقيق البدء/الإيقاف بالهوية الحقيقية.
- الدومين: ربط/تحقق/فصل، رفض دومين مملوك لمعلم آخر، رفض غير المُتحقق في الـedge، وحصر الإدارة بالسوبر أدمن.

### الواجهة
- `admin-support-banner.test.tsx` — لا يظهر بلا جلسة، ويعرض الاسم والتحذير وينهي عند الضغط.
- `teacher-domain-modal.test.tsx` — الحالة + سجلات DNS الدقيقة، الربط (تنظيف scheme)، «فحص الربط»، والفصل.
- `app/admin/support/enter/page.test.tsx` — خطأ عند غياب الرمز، وتمرير رفض الخادم.
- الحزمة الكاملة: **368 اختبارًا ناجحًا / 70 ملفًا**. `tsc --noEmit`: نظيف.

### الخلفية الكاملة
- `php artisan test`: **948 اختبارًا ناجحًا / 4032 تأكيدًا**.

> ملاحظة: لا تُشغَّل حزمتا اختبار PHP متزامنتين على نفس ملف SQLite (يسبب «database is locked»).

---

## (3.b) إصلاحات مراجعة PR #66

| # | المشكلة | الإصلاح | الاختبار |
|---|---|---|---|
| 1 | خطأ TypeScript `TS2783` في `teacher-domain-modal.test.tsx` (تكرار `verified` قبل الـspread) | إزالة الخاصية المكرّرة والاعتماد على `status({ verified: true, ... })` | `tsc --noEmit` نظيف |
| 2 | اعتبار غياب/صفر المبلغ «منحة مجانية»، ما قد يمنح خطة بلا تحصيل | `isFreeGrant` صار `free_grant` الصريح فقط؛ والعملية المدفوعة تتطلب طريقة دفع + مبلغًا موجبًا + تأكيد الاستلام (تحقق في المتحكّم + حارس في الخدمة) | `test_paid_operation_with_zero_or_missing_amount_is_rejected` |
| 3 | إمكانية تسجيل نفس مرجع CliQ مرتين تحت `request_id` مختلف | حارس مرجع مكرر داخل المعاملة (`payments.gateway='manual'` + `transaction_id`) + التقاط `UniqueConstraintViolationException` كخط دفاع ثانٍ → 422 `payment_reference` | `test_duplicate_cliq_reference_is_rejected_even_with_a_new_request_id` |
| 4 | عرض الاشتراك اليدوي كـ«تجديد تلقائي» رغم عدم وجود بوابة مرتبطة | `auto_renew = false` للصفوف المُدارة يدويًا (إنشاءً وتحديثًا) | `test_manual_subscription_is_not_marked_auto_renewing` |
| 5 | مراجعة صلاحيات وضع الدعم والجلسة المشتركة بين التبويبات | إثبات أن الجلسة المُبدَّلة حساب معلم عادي: كل مسارات الأدمن مرفوضة طرف الخادم، وبدء جلسة/معاينة جديدة مرفوض | `test_admin_power_is_unreachable_from_the_impersonated_session` |

---

## (4) ما لم يُفعل عن قصد

- **لا نشر إلى الإنتاج** ولا دمج تلقائي.
- **لا شراء دومينات** ولا تغيير أي DNS إنتاجي.
- **لم تُغيَّر الأسعار/الحصص** ولم يُفعَّل أي Gateway.
- **لم تُخفَّض** سياسات الأمان؛ أُضيفت طبقة حظر إضافية فوقها.

## (5) خطوات لاحقة مقترحة
1. مراجعة الـPR ودمجه في `release/launch-2026-11-rc`.
2. اختبار حي مُقيَّد على حساب تجريبي: دخول وضع الدعم، تعديل محتوى، ثم إنهاء والعودة للأدمن.
3. تجربة دومين تجريبي واحد عبر Hostinger وإكمال DNS → «فحص الربط» → التأكد من إصدار SSL.
