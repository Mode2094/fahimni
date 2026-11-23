# صيانة القرص ودوكر على سيرفر الإنتاج

يحدّد هذا المستند سياسة **آمنة ومحدودة** لمنع تراكم صور Docker وكاش البناء على
مضيف الإنتاج (`fahimni.me`). طُبّقت بعد حادثة امتلاء القرص (76GB → 41GB →
44GB) التي كان سببها صور Docker القديمة وكاش BuildKit، لا فيديوهات المعلمين.

## الملخّص

| العنصر | الحدّ | آلية التنفيذ |
|--------|-------|--------------|
| صور إصدارات `fahimni-*` | الإصدار الحالي + نسخة Rollback المسجّلة + أحدث نسخة سابقة (≤ 3 وسوم) | `fahimni-docker-gc` (يوميًا) |
| كاش البناء (BuildKit) | ≤ 8 GiB | `docker builder prune -a --keep-storage` (يوميًا) |
| مجلدات `releases/` | الحالي + Rollback + 3 أحدث (≤ 5) | `fahimni-docker-gc` (يوميًا) |
| تنبيه استخدام القرص | عند بلوغ 75% | `fahimni-disk-alert` (كل ساعة) |

**لا يُلمس إطلاقًا:** الـVolumes، النسخ الاحتياطية، قواعد البيانات، مساحة
تخزين التطبيق، ملفات الفيديو المحلية، وأصول Bunny.

## السكربتات

- `deploy/scripts/docker-gc.sh` → يُثبَّت كـ`/usr/local/bin/fahimni-docker-gc`.
- `deploy/scripts/disk-alert.sh` → يُثبَّت كـ`/usr/local/bin/fahimni-disk-alert`.

كلاهما مكتفٍ ذاتيًا، و`docker-gc.sh` يتجاهل التنفيذ أثناء وجود قفل نشر
(`.deploy.lock`) أو عملية بناء/نشر جارية.

## التركيب على المضيف (آمن، idempotent)

```bash
install -m 755 deploy/scripts/docker-gc.sh   /usr/local/bin/fahimni-docker-gc
install -m 755 deploy/scripts/disk-alert.sh  /usr/local/bin/fahimni-disk-alert
install -m 644 /dev/stdin /etc/cron.d/fahimni-maintenance <<'CRON'
# Fahimni production maintenance
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
30 4 * * * root /usr/local/bin/fahimni-docker-gc >> /var/log/fahimni-docker-gc.log 2>&1
15 * * * * root /usr/local/bin/fahimni-disk-alert >> /var/log/fahimni-disk-alert.log 2>&1
CRON
```

قابل للتشغيل يدويًا في أي وقت (تشغيل تجريبي آمن):

```bash
/usr/local/bin/fahimni-docker-gc      # حدود واضحة، لا حذف شامل
FAHIMNI_DISK_VERBOSE=1 /usr/local/bin/fahimni-disk-alert
```

## علاقة السياسة بالنشر

- يبقى `fresh-install.sh` كما هو: لا يحذف كاشًا ولا صورًا (لمنع أي مفاجأة أثناء
  النشر).
- التنظيف يحدث **بعد** نجاح النشر والتحقق من صحة الموقع، وليس قبله — عبر المهمة
  اليومية، مع ضمانها عدم العمل أثناء النشر. يُنصح بتشغيل
  `/usr/local/bin/fahimni-docker-gc` يدويًا بعد أي نشر تمّ التحقق منه يدويًا.
- **متابعة مقترحة (لم تُطبّق بعد):** استدعاء `fahimni-docker-gc` من
  `fresh-install.sh` في نهاية خطوة التحقق فقط، بعد التأكد من ثبات السياسة الحالية
  عبر عدة نشرات.
