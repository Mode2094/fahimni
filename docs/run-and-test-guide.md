# خارطة الطريق الكاملة: تشغيل واختبار المنصة

> دليل عملي خطوة بخطوة لتشغيل منصة LMS التعليمية (Laravel 13 + Next.js 16 + Docker) واختبارها من الصفر.

---

## 1. المتطلبات المسبقة

| الأداة | الإصدار الموصى به | الغرض |
|---|---|---|
| Docker Desktop | أحدث | تشغيل الخلفية (app/nginx/postgres/redis/worker/horizon) |
| PHP (محلي، اختياري) | 8.3+ | فحص سريع للملفات |
| Node.js (محلي) | 20+ | تشغيل الواجهة الأمامية |
| Git | — | إدارة النسخ |

> الخلفية تعمل بالكامل داخل Docker — لا تحتاج PHP/Composer محلياً إلا للفحص السريع.

---

## 2. الإعداد الأول (مرة واحدة)

### 2.1 إعداد البيئة
```bash
# من جذر المشروع
cp .env.example .env        # إن لم يوجد
# تحقق من القيم الحرجة في .env:
#   APP_ENV=local | DB_CONNECTION=pgsql | DB_HOST=postgres
#   DB_DATABASE=lms | DB_USERNAME=lms | DB_PASSWORD=lms_secret
#   REDIS_HOST=redis | REDIS_PASSWORD=redis_secret
```

### 2.2 تشغيل الحاويات
```bash
docker compose up -d --build
# تنتظر الحاويات: lms-app, lms-nginx, lms-postgres, lms-redis, lms-worker, lms-horizon
docker compose ps          # كلها running + postgres/redis healthy
```

### 2.3 تثبيت الاعتماديات + الـ Autoloader
```bash
docker compose exec app composer install --no-interaction
docker compose exec app composer dump-autoload -o
```

### 2.4 بناء قاعدة البيانات
```bash
docker compose exec app php artisan key:generate --force   # إن لزم
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force        # الأدوار + الخطط + المشرف
docker compose exec app php artisan db:seed --class=AchievementSeeder --force   # الإنجازات (اختياري)
docker compose exec app php artisan optimize:clear         # تنظيف الكاش بعد أي تغيير
```

### 2.5 تشغيل الواجهة الأمامية
```bash
cd frontend
npm install
npm run dev                 # http://localhost:3000
# API في .env.local: NEXT_PUBLIC_API_URL=http://localhost:8080/api/v1
```

---

## 3. الحسابات الافتراضية

| الدور | البريد | كلمة المرور | بوابة الدخول |
|---|---|---|---|
| **Super Admin** | `admin@lms.com` | `password` | `/admin/login` |
| طالب (جديد) | سجّل بنفسك | — | `/student/login` |
| معلم (جديد) | سجّل بنفسك | — | `/teacher/login` |

---

## 4. تسجيل المعلم المستقل

| النوع | الصفحة | الـ API |
|---|---|---|
| معلم مستقل | `/register/teacher` | `POST /auth/register` + `role: "Teacher"` |

> كل تسجيل ينشئ تلقائياً: المستخدم + الدور + المستأجر (`type: tutor`) + الإعدادات + اشتراك تجريبي.

---

## 5. اختبار كل بوابة (مسارات الـ UI)

### 5.1 بوابة المعلم المستقل — `/teacher/*`
1. سجّل معلم من `/register/teacher` → يُحوَّل تلقائياً للوحة التحكم
2. أنشئ كورساً من `/courses` (أقسام ← دروس ← محتوى نص/فيديو/ملف)
3. أضف أسئلة من `/teacher/questions` (8 أنواع) ← أنشئ امتحاناً ← أضف الأسئلة ← انشر
4. ولّد بطاقات تسجيل من `/teacher/cards` (دفعة QR)
5. جرب `/teacher/live` (بث)، `/teacher/schedule` (دروس مجدولة)، `/teacher/appointments` (مواعيد)
6. `/teacher/integrations` → وصّل مزوداً (Zoom/Drive/YouTube...) واختبر الاتصال
7. `/teacher/settings` → تصميم الموقع (`site-design`) ثم شاهد موقعك العام على `/t/{slug}`

### 5.2 بوابة الطالب (LMS) — المستوى الجذري
1. سجّل طالباً من `/register/student`
2. استرد بطاقة أو اشترك في كورس ← درس من `/course/{id}/learn` (مشغل الفيديو المحمي)
3. حلّ امتحاناً من `/exam/{id}` ← النتيجة في `/results`
4. شاهِد الشهادة في `/certificates/{id}` ورمز QR → `/verify/{number}`
5. جرّب `/leaderboard` و `/profile` (التحفيز) و `/messages` و `/notifications` و `/announcements`

### 5.3 الإدارة المركزية — `/admin/*`
1. دخول `admin@lms.com` → `/admin/dashboard` (إحصائيات وإيرادات)
2. الخطط `/admin/plans` (أنواع ميزات)، الاشتراكات `/admin/subscriptions` (تغيير خطة/تمديد)
3. الكوبونات، المدفوعات، الفواتير، التذاكر `/admin/tickets`
4. السجلات `/admin/logs`، النسخ الاحتياطي `/admin/backups`
5. الإعدادات `/admin/settings` (اسم المنصة، عملة، كابتشا، 2FA، بوابات)

---

## 6. اختبار الـ API المباشر (curl)

### المصادقة
```bash
BASE=http://localhost:8080/api/v1
# دخول
TOKEN=$(curl -s -X POST $BASE/auth/login -H "Content-Type: application/json" -d \
  '{"email":"admin@lms.com","password":"password"}' | python3 -c "import sys,json;print(json.load(sys.stdin)['token'])")
# معرف المستأجر (Header إلزامي لمعظم النقاط)
TID=$(curl -s $BASE/auth/me -H "Authorization: Bearer $TOKEN" | \
  python3 -c "import sys,json;print(json.load(sys.stdin)['user']['tenants'][0]['id'])")
H="Authorization: Bearer $TOKEN"; T="X-Tenant-Id: $TID"; C="Content-Type: application/json"
```

### فحوصات سريعة جاهزة
```bash
curl -s $BASE/health                       # صحة الخادم
curl -s $BASE/platform-settings            # إعدادات المنصة
curl -s $BASE/public/plans                 # الخطط العامة
curl -s "$BASE/tenants" -H "$H"            # مستأجري المستخدم
curl -s "$BASE/dashboard" -H "$H" -H "$T"  # لوحة حسب الدور
curl -s "$BASE/notifications/unread-count" -H "$H" -H "$T"
```

---

## 7. سكربتات التشغيل المتاحة

| السكربت | الغرض |
|---|---|
| `composer run dev` (في الخادم) | تشغيل serve + queue + pail + vite معاً |
| `composer run test` | تشغيل اختبارات PHPUnit |
| `scripts/postgres-backup.sh [db] [user] [host] [dir] [retention]` | نسخ احتياطي pg_dump + ضغط + تنظيف |
| `scripts/verify-production.sh` | فحص الإنتاج: حاويات، كاش Laravel، migrations، seeds، health |
| `php artisan schedule:run` (داخل الحاوية) | الإحصائيات اليومية + نسخ 02:00 (يحتفظ 7) |

---

## 8. الأوامر الشائعة داخل الحاوية

```bash
alias app="docker compose exec app php artisan"
app migrate --force
app db:seed --force
app route:list --path=teacher       # تصفح المسارات
app optimize:clear                  # بعد أي تعديل كود
app queue:work                      # معالجة الطوابير يدوياً
app horizon                         # لوحة الطوابير (مؤشرها: dashboard → localhost:8080/horizon)
app tinker                          # قشرة تفاعلية
```

---

## 9. استكشاف الأخطاء الشائعة

| المشكلة | الحل |
|---|---|
| `could not translate host name "postgres"` | شغّل داخل الحاوية: `docker compose exec app php artisan migrate` (وليس من المضيف) |
| `Class ... not found` بعد إضافة موديل | `docker compose exec app composer dump-autoload -o` |
| `Invalid captcha answer` عند الدخول | `docker compose exec postgres psql -U lms -d lms -c "UPDATE platform_settings SET value='false' WHERE key='captcha_enabled';"` + مسح الكاش |
| 401 بعد فترة | انتهت صلاحية الـ token — سجّل دخولاً جديداً |
| `Server Error` بدون تفاصيل | `docker compose exec app tail -100 storage/logs/laravel.log` |
| تغييرات PHP لا تظهر | `docker compose exec app php artisan optimize:clear` |
| build فشل بأخطاء `.next` قديمة | `rm -rf .next` ثم `npm run build` |
| `useSearchParams` يسبب خطأ prerender | لُفّ الصفحة بـ `<Suspense>` (مثال: صفحة واجبات المعلم) |
| نسيان معرف المستأجر | من `GET /auth/me` → `user.tenants[0].id` ثم Header `X-Tenant-Id` |
| الكابتشا تعود للتفعيل تلقائياً | أُطفئها من لوحة الإدارة `/admin/settings` (وليس بقاعدة البيانات) |
| النسخ الاحتياطي اليومي لا يعمل | تأكد أن `schedule:run` يعمل (worker/horizon يعملان) |

---

## 10. قائمة التحقق النهائية (قبل الإطلاق)

- [ ] `scripts/verify-production.sh` يمر كاملاً (✅)
- [ ] جميع الـ migrations "Ran" و seeders idempotent
- [ ] دورة بطاقات التسجيل: توليد دفعة ← استرداد ← تسجيل طالب
- [ ] الفيديو المحمي يلعب فقط للمسجلين + التوقيع ينتهي
- [ ] الإشعارات تصل على القنوات المفعّلة (تطبيق/بريد/push/SMS/WhatsApp)
- [ ] تحويل عربي/إنجليزي (RTL/LTR) سليم في كل اللوحات
- [ ] بوابات الدفع: PayTabs/PayPal (وضع اختبار) + بوابة تجريبية
- [ ] الحساب التجريبي: معلم ينتهي تجربته وتنتقل لخطة مدفوعة
- [ ] `composer run test` أخضر
