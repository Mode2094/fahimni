<?php

namespace App\Support;

use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;

/**
 * Single source of truth for plan features.
 * The admin panel renders features dynamically from this catalog,
 * so any feature added here appears in the admin panel without code changes.
 */
class FeatureCatalog
{
    public static function all(): array
    {
        return [
            // ── Limits ──────────────────────────────────────
            ['key' => 'students_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد الطلاب', 'en' => 'Students limit', 'desc_ar' => 'الحد الأقصى للطلاب الذين يمكنك تسجيلهم في منصتك', 'desc_en' => 'Maximum students you can enroll in your platform'],
            ['key' => 'courses_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد الكورسات', 'en' => 'Courses limit', 'desc_ar' => 'عدد الكورسات التي يمكنك إنشاؤها', 'desc_en' => 'Number of courses you can create'],
            ['key' => 'storage_limit_mb', 'type' => 'numeric', 'unit' => 'GB', 'ar' => 'مساحة التخزين', 'en' => 'Storage', 'desc_ar' => 'مساحة تخزين ملفاتك وفيديوهاتك التعليمية', 'desc_en' => 'Storage space for your files and videos'],
            ['key' => 'max_students_per_course', 'type' => 'numeric', 'ar' => 'طلاب لكل كورس', 'en' => 'Students per course', 'desc_ar' => 'أقصى عدد طلاب يمكن تسجيلهم في الكورس الواحد', 'desc_en' => 'Maximum students per single course'],
            ['key' => 'exams_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد الاختبارات', 'en' => 'Exams limit', 'desc_ar' => 'عدد الاختبارات التي يمكنك إنشاؤها', 'desc_en' => 'Number of exams you can create'],
            ['key' => 'questions_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد الأسئلة', 'en' => 'Questions limit', 'desc_ar' => 'عدد الأسئلة في بنك الأسئلة الخاص بك', 'desc_en' => 'Number of questions in your question bank'],
            ['key' => 'live_sessions_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد البث المباشر', 'en' => 'Live sessions limit', 'desc_ar' => 'عدد جلسات البث المباشر المتاحة لك', 'desc_en' => 'Number of live streaming sessions available'],
            ['key' => 'assignments_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد الواجبات', 'en' => 'Assignments limit', 'desc_ar' => 'عدد الواجبات التي يمكنك إنشاؤها لطلابك', 'desc_en' => 'Number of assignments you can create'],
            ['key' => 'teachers_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد المعلمين', 'en' => 'Teachers limit', 'desc_ar' => 'عدد المعلمين الذين يمكنك إضافتهم لفريقك', 'desc_en' => 'Number of teachers you can add to your team'],
            ['key' => 'videos_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد الفيديوهات', 'en' => 'Videos limit', 'desc_ar' => 'عدد الفيديوهات المرفوعة في كل كورساتك', 'desc_en' => 'Number of uploaded videos across your courses'],
            ['key' => 'files_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد الملفات', 'en' => 'Files limit', 'desc_ar' => 'عدد الملفات المرفوعة (PDF، مستندات وغيرها)', 'desc_en' => 'Number of uploaded files (PDF, documents, etc.)'],
            ['key' => 'pdfs_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد ملفات PDF', 'en' => 'PDFs limit', 'desc_ar' => 'عدد ملفات PDF التي يمكنك رفعها', 'desc_en' => 'Number of PDF files you can upload'],
            ['key' => 'blog_posts_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد مقالات المدونة', 'en' => 'Blog posts limit', 'desc_ar' => 'عدد المقالات التي يمكنك نشرها في مدونتك', 'desc_en' => 'Number of posts you can publish on your blog'],
            ['key' => 'cards_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد بطاقات التسجيل', 'en' => 'Registration cards limit', 'desc_ar' => 'عدد بطاقات التسجيل التي يمكنك توليدها', 'desc_en' => 'Number of registration cards you can generate'],
            ['key' => 'appointments_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد المواعيد', 'en' => 'Appointments limit', 'desc_ar' => 'عدد المواعيد النشطة المتاحة', 'desc_en' => 'Number of active appointments available'],
            ['key' => 'services_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد الخدمات المدفوعة', 'en' => 'Paid services limit', 'desc_ar' => 'عدد الخدمات المدفوعة التي يمكنك عرضها', 'desc_en' => 'Number of paid services you can offer'],
            ['key' => 'live_minutes_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد دقائق البث شهرياً', 'en' => 'Monthly live minutes limit', 'desc_ar' => 'عدد دقائق البث المباشر المسموح بها شهرياً', 'desc_en' => 'Live streaming minutes allowed per month'],
            ['key' => 'recordings_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد التسجيلات', 'en' => 'Recordings limit', 'desc_ar' => 'عدد تسجيلات البث المباشر المحفوظة', 'desc_en' => 'Number of stored live recordings'],
            ['key' => 'delivery_minutes_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد دقائق المشاهدة', 'en' => 'Delivery minutes limit', 'desc_ar' => 'دقائق تشغيل الفيديو المسموح بها (التسليم)', 'desc_en' => 'Allowed video playback minutes (delivery)'],

            // ── Gates ───────────────────────────────────────
            ['key' => 'live_stream_enabled', 'type' => 'boolean', 'ar' => 'بث مباشر', 'en' => 'Live streaming', 'desc_ar' => 'عقد جلسات بث مباشر تفاعلية مع طلابك', 'desc_en' => 'Hold interactive live streaming sessions with your students'],
            ['key' => 'scheduled_lessons_enabled', 'type' => 'boolean', 'ar' => 'دروس مجدولة', 'en' => 'Scheduled lessons', 'desc_ar' => 'جدولة دروس بمواعيد ثابتة تتكرر أسبوعياً', 'desc_en' => 'Schedule lessons with fixed weekly recurring times'],
            ['key' => 'appointments_enabled', 'type' => 'boolean', 'ar' => 'نظام حجز المواعيد', 'en' => 'Appointment booking', 'desc_ar' => 'حجز مواعيد خاصة مع الطلاب من موقعك', 'desc_en' => 'Students book private appointments from your site'],
            ['key' => 'paid_services_enabled', 'type' => 'boolean', 'ar' => 'الاستشارات والجلسات المدفوعة', 'en' => 'Paid consultations & sessions', 'desc_ar' => 'بيع استشارات وجلسات تعليمية مدفوعة', 'desc_en' => 'Sell paid consultations and tutoring sessions'],
            ['key' => 'multi_teacher_enabled', 'type' => 'boolean', 'ar' => 'تعدد المعلمين', 'en' => 'Multiple teachers', 'desc_ar' => 'إضافة أكثر من معلم لإدارة المنصة معك', 'desc_en' => 'Add multiple teachers to manage the platform with you'],
            ['key' => 'question_bank_enabled', 'type' => 'boolean', 'ar' => 'بنك الأسئلة', 'en' => 'Question bank', 'desc_ar' => 'مكتبة أسئلة قابلة لإعادة الاستخدام في اختباراتك', 'desc_en' => 'Reusable question library for your exams'],
            ['key' => 'certificates_enabled', 'type' => 'boolean', 'ar' => 'شهادات إتمام', 'en' => 'Certificates', 'desc_ar' => 'منح طلابك شهادة إتمام عند إنهاء الكورس', 'desc_en' => 'Issue completion certificates to your students'],
            ['key' => 'assignments_enabled', 'type' => 'boolean', 'ar' => 'الواجبات', 'en' => 'Assignments', 'desc_ar' => 'نظام واجبات مع تسليم الطلاب وتصحيح', 'desc_en' => 'Assignments with student submissions and grading'],
            ['key' => 'community_enabled', 'type' => 'boolean', 'ar' => 'المجتمع والنقاشات', 'en' => 'Community & forums', 'desc_ar' => 'مساحات نقاش ومجموعات لطلابك', 'desc_en' => 'Discussion spaces and groups for your students'],
            ['key' => 'community_groups_limit', 'type' => 'numeric', 'allow_unlimited' => true, 'ar' => 'حد مجموعات المجتمع', 'en' => 'Community groups limit', 'desc_ar' => 'عدد المجموعات النقاشية التي يمكنك إنشاؤها', 'desc_en' => 'Number of discussion groups you can create'],
            ['key' => 'custom_branding_enabled', 'type' => 'boolean', 'ar' => 'علامة تجارية مخصصة', 'en' => 'Custom branding', 'desc_ar' => 'تخصيص ألوان وشعار موقعك بالكامل', 'desc_en' => 'Fully customize your site colors and logo'],
            ['key' => 'custom_domain', 'type' => 'boolean', 'ar' => 'دومين خاص (الموقع الخاص)', 'en' => 'Custom domain', 'desc_ar' => 'ربط اسم دومين خاص بموقعك التعليمي', 'desc_en' => 'Connect your own domain name to your site'],
            ['key' => 'blog_enabled', 'type' => 'boolean', 'ar' => 'مدونة خاصة', 'en' => 'Blog', 'desc_ar' => 'نشر مقالات ومحتوى لموقعك العام', 'desc_en' => 'Publish articles and content on your public site'],
            ['key' => 'export_reports', 'type' => 'boolean', 'ar' => 'تصدير التقارير', 'en' => 'Export reports', 'desc_ar' => 'تصدير تقارير أداء طلابك (PDF/Excel)', 'desc_en' => 'Export student performance reports (PDF/Excel)'],
            ['key' => 'white_label', 'type' => 'boolean', 'ar' => 'علامة بيضاء كاملة', 'en' => 'White label', 'desc_ar' => 'إزالة علامة المنصة واستخدام علامتك التجارية فقط', 'desc_en' => 'Remove platform branding and use only yours'],
            ['key' => 'api_access', 'type' => 'boolean', 'ar' => 'الوصول للـ API', 'en' => 'API access', 'desc_ar' => 'ربط منصتك بأنظمة خارجية عبر الواجهات البرمجية', 'desc_en' => 'Integrate your platform with external systems via API'],
            ['key' => 'cards_enabled', 'type' => 'boolean', 'ar' => 'بطاقات التسجيل', 'en' => 'Registration cards', 'desc_ar' => 'توليد بطاقات تسجيل برمز QR لطلابك', 'desc_en' => 'Generate QR registration cards for your students'],
            ['key' => 'announcements_enabled', 'type' => 'boolean', 'ar' => 'الإعلانات', 'en' => 'Announcements', 'desc_ar' => 'إرسال إعلانات لطلابك داخل المنصة', 'desc_en' => 'Send announcements to your students'],
            ['key' => 'video_upload_enabled', 'type' => 'boolean', 'ar' => 'رفع الفيديو', 'en' => 'Video upload', 'desc_ar' => 'رفع فيديوهاتك مباشرة إلى المنصة', 'desc_en' => 'Upload your videos directly to the platform'],
            ['key' => 'file_upload_enabled', 'type' => 'boolean', 'ar' => 'رفع الملفات', 'en' => 'File upload', 'desc_ar' => 'رفع ملفات PDF والمستندات إلى دروسك', 'desc_en' => 'Upload PDFs and documents to your lessons'],
            ['key' => 'live_recording_enabled', 'type' => 'boolean', 'ar' => 'تسجيل البث المباشر', 'en' => 'Live recording', 'desc_ar' => 'تسجيل البث تلقائياً وتحويله لفيديو مسجل', 'desc_en' => 'Automatically record live streams and save them as videos'],
            ['key' => 'secure_playback_enabled', 'type' => 'boolean', 'ar' => 'تشغيل آمن', 'en' => 'Secure playback', 'desc_ar' => 'روابط تشغيل موقعة ومنتهية الصلاحية للفيديوهات', 'desc_en' => 'Signed, expiring playback URLs for videos'],
            ['key' => 'video_watermark', 'type' => 'boolean', 'ar' => 'علامة مائية للفيديو', 'en' => 'Video watermark', 'desc_ar' => 'عرض اسم الطالب كعلامة مائية متحركة أثناء مشاهدة الفيديو', 'desc_en' => 'Show the student name as an animated watermark while watching videos'],
            ['key' => 'video_analytics_enabled', 'type' => 'boolean', 'ar' => 'تحليلات الفيديو', 'en' => 'Video analytics', 'desc_ar' => 'إحصائيات مشاهدة لكل فيديو', 'desc_en' => 'Per-video viewing analytics'],
            ['key' => 'student_mobile_app', 'type' => 'boolean', 'ar' => 'تطبيق الطلاب (Mobile App)', 'en' => 'Student Mobile App', 'desc_ar' => 'تفعيل التطبيق الرسمي للطلاب وربطه بكود المعلم', 'desc_en' => 'Enable the official student app bound to your teacher code'],

            // ── Integration features (Feature Engine) ───
            ['key' => 'video_hosting', 'type' => 'boolean', 'ar' => 'استضافة الفيديو (Bunny Stream)', 'en' => 'Video hosting (Bunny Stream)', 'desc_ar' => 'استضافة الفيديوهات وتشغيلها عبر مزود الوسائط (Bunny Stream)', 'desc_en' => 'Host and stream videos via the media provider (Bunny Stream)'],
            ['key' => 'video_embeds', 'type' => 'boolean', 'ar' => 'تضمين فيديوهات خارجية', 'en' => 'External video embeds', 'desc_ar' => 'تضمين فيديوهات يوتيوب و Vimeo في الدروس', 'desc_en' => 'Embed YouTube and Vimeo videos in lessons'],
            ['key' => 'whatsapp_notifications', 'type' => 'boolean', 'ar' => 'إشعارات واتساب', 'en' => 'WhatsApp notifications', 'desc_ar' => 'إرسال إشعارات عبر واتساب للأولياء والطلاب', 'desc_en' => 'Send WhatsApp notifications to parents and students'],
            ['key' => 'telegram_notifications', 'type' => 'boolean', 'ar' => 'إشعارات تيليجرام', 'en' => 'Telegram notifications', 'desc_ar' => 'إرسال إشعارات عبر تيليجرام', 'desc_en' => 'Send notifications via Telegram'],
            ['key' => 'zapier_automations', 'type' => 'boolean', 'ar' => 'أتمتة Zapier', 'en' => 'Zapier automations', 'desc_ar' => 'ربط المنصة بأنظمة خارجية عبر Zapier', 'desc_en' => 'Connect the platform to external systems via Zapier'],
            ['key' => 'file_storage', 'type' => 'boolean', 'ar' => 'التخزين السحابي الخارجي', 'en' => 'External cloud storage', 'desc_ar' => 'ربط Google Drive / Dropbox / OneDrive', 'desc_en' => 'Connect Google Drive / Dropbox / OneDrive'],
            ['key' => 'support_level', 'type' => 'select', 'options' => [
                ['value' => 'email', 'ar' => 'بريد إلكتروني', 'en' => 'Email'],
                ['value' => 'priority', 'ar' => 'أولوية', 'en' => 'Priority'],
                ['value' => 'dedicated', 'ar' => 'مخصص', 'en' => 'Dedicated'],
            ], 'ar' => 'الدعم الفني', 'en' => 'Support level', 'desc_ar' => 'مستوى الدعم الفني المتاح لك', 'desc_en' => 'Level of technical support available to you'],
        ];
    }

    /**
     * Catalog entries + any extra feature keys already stored in plans
     * (so admin editing never drops unknown/custom features).
     */
    public static function withStoredExtra(): array
    {
        $catalog = self::all();
        $known = collect($catalog)->pluck('key')->flip();

        $stored = SubscriptionPlan::query()
            ->with('features')
            ->get()
            ->flatMap(fn (SubscriptionPlan $plan) => $plan->features->pluck('feature_key'))
            ->unique()
            ->filter(fn (string $key) => ! $known->has($key));

        foreach ($stored as $key) {
            $catalog[] = ['key' => $key, 'type' => 'custom', 'ar' => $key, 'en' => $key];
        }

        return $catalog;
    }

    /**
     * Convert stored PlanFeature rows into a public, fully-localized payload:
     * label_ar/label_en from the catalog plus a pre-formatted display value
     * (e.g. "5 GB", "غير محدود", "مفعل") so clients never render raw keys
     * or English-only values.
     *
     * @param  iterable<PlanFeature>  $features
     * @return array<int, array{key: string, label_ar: string, label_en: string, type: string, value: string, display_ar: string, display_en: string}>
     */
    public static function localizeFeatures(iterable $features): array
    {
        $byKey = collect(self::all())->keyBy('key');

        return collect($features)
            ->map(function (PlanFeature $f) use ($byKey) {
                $meta = $byKey[$f->feature_key] ?? ['type' => 'custom', 'ar' => $f->feature_key, 'en' => $f->feature_key];
                $type = $meta['type'] ?? 'custom';
                $raw = (string) $f->feature_value;
                $unit = $meta['unit'] ?? null;

                $display = function (bool $ar) use ($type, $raw, $meta, $unit) {
                    $lang = $ar ? 'ar' : 'en';

                    if ($raw === 'unlimited') {
                        return $ar ? 'غير محدود' : 'Unlimited';
                    }

                    if ($type === 'boolean') {
                        $on = $raw === 'true' || $raw === '1' || $raw === 'on';

                        return $ar ? ($on ? 'مفعل' : 'غير مفعل') : ($on ? 'Enabled' : 'Disabled');
                    }

                    if ($type === 'select' && is_array($meta['options'] ?? null)) {
                        foreach ($meta['options'] as $option) {
                            if ((string) ($option['value'] ?? '') === $raw) {
                                return $ar ? ($option['ar'] ?? $raw) : ($option['en'] ?? $raw);
                            }
                        }

                        return $raw;
                    }

                    if ($type === 'numeric') {
                        if ($unit === 'GB' && is_numeric($raw)) {
                            $mb = (float) $raw;

                            // Whole-number display: 5120 → "5 جيجا", not "5120".
                            if ($mb >= 1024) {
                                $gb = round($mb / 1024);

                                return $gb.' '.($ar ? 'جيجا' : 'GB');
                            }

                            return $raw.' '.($ar ? 'ميجا' : 'MB');
                        }

                        return $raw;
                    }

                    return $raw;
                };

                return [
                    'key' => $f->feature_key,
                    'label_ar' => (string) ($meta['ar'] ?? $f->feature_key),
                    'label_en' => (string) ($meta['en'] ?? $f->feature_key),
                    'desc_ar' => (string) ($meta['desc_ar'] ?? ''),
                    'desc_en' => (string) ($meta['desc_en'] ?? ''),
                    'type' => $type,
                    'value' => $raw,
                    'display_ar' => $display(true),
                    'display_en' => $display(false),
                ];
            })
            ->values()
            ->all();
    }
}
