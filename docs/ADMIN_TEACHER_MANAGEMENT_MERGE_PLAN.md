# خطة الدمج والنشر الآمن — PR #66 (إدارة المعلم: وضع الدعم + الدومين المخصص)

- الفرع: `feat/admin-manual-subscription` → القاعدة: `release/launch-2026-11-rc`
- الإنتاج: `fahimni.me` / `api.fahimni.me` (Docker Compose، مشروع `fahimni`).
- **لا نشر تلقائي:** الدمج في فرع الإصدار لا ينشر شيئًا. النشر فقط بالأمر الواحد في §4 بعد موافقة المالك.
- التاريخ: 26 Nov 2026

> الوضع الحالي: **جاهز للدمج** (CI أخضر + حزم الاختبارات كاملة خضراء). **بانتظار موافقة المالك** للنشر.

---

## 1. بوابات ما قبل الدمج

| البوابة | الحالة |
|---|---|
| CI: Laravel (PHP 8.4) | ✅ |
| CI: Next.js (tsc + unit + build) | ✅ |
| CI: Playwright sandbox | ✅ |
| لا تعارضات على `routes/api.php` مع الفرع الأساس | يُتحقق عند الدمج |
| مراجعة بشرية للتغييرات الحساسة (الحظر المالي/الأمني، الرمز الأحادي، ملكية الدومين) | مطلوبة |

**الدمج**: `release/launch-2026-11-rc` ← `feat/admin-manual-subscription` عبر GitHub (PR #66). لا دمج إلى `main` في هذه المرحلة إلا بموافقة صريحة.

---

## 2. ما يتضمنه الإصدار (نطاق المخاطر)

- لا **migration جديدة** في هذه المرحلة سوى `2026_11_26_000001_create_manual_subscription_operations_table.php` (جدول إضافي فقط).
- ميزة الدعم الإداري والدومين المخصص **لا تضيف أعمدة/جداول** — تعيد استخدام `tenant_settings`, `audit_logs`, ومخزن الجلسة.
- كل المسارات الجديدة خلف `auth:sanctum` + `admin` (سوبر أدمن فقط) + طبقة حظر الكتابات الحساسة.
- لا تغيير على الأسعار/الحصص/اشتراكات العملاء القائمة.

> هذا يجعل الترحيل **إضافيًا بالكامل** → التراجع عن الكود آمن دون تراجع قاعدة بيانات.

---

## 3. النسخة الاحتياطية (قبل أي نشر — إلزامي)

نفِّذ بالترتيب وقبل لمس الكود:

1. **تسجيل نقطة التراجع:**
   ```bash
   cat /opt/fahimni/production/.deployed-tag
   readlink -f /opt/fahimni/production/current      # الـSHA الحالي (d2ff3a4 للتشغيل الحالي)
   ```
2. **نسخة قاعدة بيانات مشفّرة ومحمّلة خارجيًا (المسار الموصى به):**
   ```bash
   cd /opt/fahimni/production/current
   sudo -u fahimni ./deploy/scripts/backup-offsite.sh --env production
   ```
   ينسخ PostgreSQL بصيغة custom + الميديا، يشفّر قبل الرفع، ويتحقق من التجزئة.
3. **نسخة محلية إضافية (تصغير المخاطر):**
   ```bash
   sudo -u fahimni ./scripts/postgres-backup.sh fahimni fahimni localhost /var/backups/fahimni 30
   ```
4. **تأكيد قابلية الاستعادة (اختباريًا، دون لمس الإنتاج):**
   ```bash
   sudo BACKUP_AGE_IDENTITY=/root/fahimni-age.key \
     ./deploy/scripts/restore-drill.sh --env production --from-remote --yes
   ```
   يجب أن يطبع عدد صفوف `migrations/users/tenants` وحجم القاعدة.

**شرط الانتقال:** نسخة قاعدة بيانات حديثة (< 24 ساعة) + نسخة محلية + نجاح تدريب الاستعادة + تسجيل الـSHA الحالي.

---

## 4. النشر (أمر واحد، بعد موافقة المالك)

```bash
# 1) جلب الإصدار الجديد في مجلد إصدار جديد
sudo -u fahimni git clone https://github.com/Mode2094/fahimni.git \
  /opt/fahimni/production/releases/<NEW_SHA>
cd /opt/fahimni/production/releases/<NEW_SHA>
sudo -u fahimni git checkout <NEW_SHA>
sudo -u fahimni ln -sfn /etc/fahimni/production.env .env

# 2) أمر النشر الواحد (يبني الصور، يشغّل migrate، ينتظر الصحة، يبدّل current)
sudo -u fahimni ./deploy/scripts/deploy.sh --env production
```

`deploy.sh` لن يبدّل `current` إلا بعد نجاح فحوص الصحة والدخان، ويحاول التراجع تلقائيًا عند الفشل إن وُجد هدف سابق.

> **ممنوع:** `docker compose down -v`، أي `migrate:rollback` أعمى، أي تغيير على LooksLoop/Caddy، أو DNS.

---

## 5. اختبار حساب تجريبي بعد النشر (Smoke حقيقي)

على **حساب معلم تجريبي مُخصص** (لا حساب عميل حقيقي):

**أ) الاشتراك اليدوي:**
1. تفاصيل المعلم → «اشتراك المعلم والوصول» → تفعيل مدفوع بمرجع CliQ تجريبي.
2. تأكيد: ظهور دفعة `manual/completed` + قيد `in` + فاتورة `paid`، والوصول فوري.
3. إعادة إرسال **نفس المرجع** (request_id مختلف) → يجب الرفض 422 «payment_reference» دون إيراد مزدوج.
4. محاولة تفعيل مدفوع بمبلغ 0 → رفض 422 «amount».
5. التأكد من أن بطاقة الاشتراك تُظهر **تجديد تلقائي: لا**.
6. تراجع: `rollback.sh` إن لزم — القاعدة إضافية.

**ب) وضع الدعم الإداري:**
1. «الدخول إلى لوحة المعلم» → تبويب جديد + شريط «أنت الآن تدير منصة ... بصلاحية السوبر أدمن».
2. تعديل محتوى (مثلاً إعدادات) → ينجح ويُقيَّد بـ`admin.support.write` باسم الأدمن.
3. محاولة عملية حساسة (تغيير كلمة مرور/دفع) → يجب الرفض 403 `SUPPORT_SENSITIVE_BLOCKED`.
4. «إنهاء الجلسة والعودة للإدارة» → رجوع مضمون لحساب السوبر أدمن دون فقدان الجلسة.

**ج) الدومين المخصص (بدون لمس DNS إنتاجي):**
1. ربط دومين تجريبي (`test-<ts>.example.com`) → تظهر تعليمات CNAME/A/TXT الحقيقية.
2. «فحص الربط» قبل نشر DNS → يبقى غير مُتحقق (لا شهادة).
3. «إلغاء الربط» → يعود الرابط المجاني.
4. **لا** تغيير DNS إنتاجي ولا شراء دومين.

**د) العزل:** التأكد أن حساب معلم آخر لم يتأثر (كورسات/طلاب/اشتراك كما هي).

---

## 6. فحوص ما بعد النشر

```bash
./deploy/scripts/healthcheck.sh --env production
./deploy/scripts/smoke-test.sh  --env production
# نقاط أساسية:
# - https://api.fahimni.me/up → 200
# - https://api.fahimni.me/api/health/ready → 200 (DB/Redis)
# - https://fahimni.me/ → 200
# - تسجيل دخول معلم + تحميل my-learning لطالب تجريبي
```
- مراقبة `/api/v1/admin/monitoring` وأعمار النسخ الاحتياطية لمدة 24 ساعة.

---

## 7. التراجع (Rollback)

```bash
cd /opt/fahimni/production/current
sudo -u fahimni ./deploy/scripts/rollback.sh --env production
```
- يستعيد الإصدار/الصورة من `.previous-release` / `.previous-tag`، ثم يعيد فحوص الصحة.
- **تراجع الكود ≠ تراجع قاعدة البيانات.** نظرًا لأن الترحيل إضافي، لا حاجة لتراجع DB. عند فشل نادر يمسّ البيانات: استعد النسخة المشفّرة من §3.

---

## 8. المخاطر المتبقية والحدود المعروفة

- **الجلسة المشتركة بين التبويبات:** مبني على كوكي HttpOnly واحد عبر BFF (لا جلسة لكل تبويب). تشغيل وضع الدعم في تبويب يبدّل هوية الجلسة في كل التبويبات؛ الشريط يظهر في أي تبويب، والإنهاء يعيد الجميع للأدمن، وكل مسارات الأدمن مرفوضة طرف الخادم أثناء الدعم (مغطّى باختبار).
- **الدومين:** HTTPS يُصدر عند أول زيارة بعد التحقق؛ لا نلمس DNS/Caddy الإنتاجي.
- **الترحيل:** إضافي فقط؛ لا حذف/تصفير أي بيانات.
