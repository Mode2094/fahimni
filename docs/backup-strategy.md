# Database Backup Strategy

## Implementation (محل التنفيذ الفعلي)
- **Automated**: نسخة يومية عبر Scheduler container (`php artisan schedule:work`) في 02:00
  - أمر `pg_dump -Fc` (تنسيق مخصص، متسق، قابل للاستعادة) ثم ضغط gzip
  - الاحتفاظ بآخر 7 نسخ (`DatabaseBackup::deleteOld(7)`)
  - الملفات في volume منفصل `backups_data` (`storage/app/backups/`)
- **Manual** (من لوحة التحكم — super admin): `/api/v1/security/backups`

## Restore
```bash
# من داخل docker-compose (أو أي host فيه pg_restore)
gunzip -k storage/app/backups/backup-2026-08-05_02-00-00.dump.gz
pg_restore -U lms -h localhost -d lms --clean --if-exists backup-2026-08-05_02-00-00.dump
```

## Off-site (إلزامي قبل الإطلاق — لم يُفعّل بعد)
النسخ المحلية لا تحمي من فشل المضيف. سكربت جاهز في المستودع:
- `deploy/scripts/backup-offsite.sh` — `pg_dump -Fc` + أرشيف الوسائط، تشفير
  إلزامي (age أو OpenSSL)، رفع rclone، تحقق من البصمات، واحتفاظ افتراضي 14 يومًا.
- `deploy/scripts/restore-drill.sh` — استعادة إلى حاوية Postgres معزولة
  (`--network none`) وفحص عدد الجداول/المستخدمين، دون لمس الإنتاج.
- **الحالة:** السكربت جاهز لكن النسخ الخارجي **غير مفعّل** حتى يُضبط rclone
  ويُخزَّن مفتاح/عبارة فك التشفير خارج المضيف.
- **خيار بديل**: snapshot يومي لـ `backups_data` على مستوى مزود السحابة
  (EBS snapshot / PITR من managed Postgres).

## Retention
| Type | Retention |
|------|-----------|
| Daily | 7 نسخ (تُحذف تلقائياً) |

## Verification (إلزامي)
- استعادة إلى بيئة اختبار مرة أسبوعياً
- فحص حجم وسلامة الملف (`pg_restore --list`)

## ملاحظة أمان
- لا تُخزَّن كلمات مرور DB داخل ملفات النسخ (pg_dump لا يتضمنها)
- حماية volume النسخ (permissions) لأنها تحتوي كامل بيانات العملاء
