<?php

namespace App\Support;

use App\Plans\UsageService;
use Illuminate\Support\Facades\App;

/**
 * PlatformIndex — فهرس المعرفة الديناميكي للمنصة.
 *
 * بدلاً من قاعدة معرفة يدوية محدودة، يُنشأ الفهرس هنا من مصادر الكود الفعلية:
 *   - صفحات لوحة المعلم الحقيقية (من الملاحة والـ routes).
 *   - كل ميزات الخطط (FeatureCatalog — مصدر الحقيقة للميزات).
 *   - كل الموارد القابلة للعد (UsageService::registry — مصدر الحقيقة للحدود).
 *   - سير العمل الفعلية + قواعد التشخيص.
 *
 * أي ميزة/مورد يضاف للكود يظهر تلقائياً كموضوع قابل للإجابة عنه — تغطية كاملة
 * بالبناء (completeness by construction).
 */
class PlatformIndex
{
    private static ?array $cache = null;

    /**
     * تطبيع النص العربي للغة العامية/الكتابة الحرة:
     * إزالة التشكيل، توحيد الهمزات، التاء المربوطة، الياء/الألف المقصورة.
     */
    public static function normalize(string $text): string
    {
        $t = mb_strtolower($text);

        $t = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $t) ?? $t; // تشكيل + تطويل
        $t = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $t);
        $t = str_replace('ة', 'ه', $t);
        $t = str_replace(['ى', 'ئ', 'ؤ'], ['ي', 'ي', 'و'], $t);
        $t = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t) ?? $t; // إزالة الرموز
        $t = preg_replace('/\s+/u', ' ', trim($t)) ?? $t;

        return $t;
    }

    /**
     * جميع مواضيع المنصة (صفحات + ميزات + موارد + سير عمل + تشخيص).
     *
     * @return array<int, array{key: string, kind: string, label_ar: string, synonyms: list<string>, gate: ?string, data: array<string, mixed>}>
     */
    public static function allTopics(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $topics = [];

        // ── الصفحات ──
        foreach (self::pages() as $p) {
            $topics[] = [
                'key' => $p['key'],
                'kind' => 'page',
                'label_ar' => $p['label_ar'],
                'synonyms' => $p['synonyms'],
                'gate' => $p['gate'] ?? null,
                'data' => $p,
            ];
        }

        // ── ميزات الخطط (FeatureCatalog — ديناميكي) ──
        foreach (FeatureCatalog::all() as $f) {
            $key = $f['key'];
            $topics[] = [
                'key' => 'feature:'.$key,
                'kind' => 'feature',
                'label_ar' => $f['ar'] ?? $key,
                'synonyms' => [$f['ar'] ?? $key, $f['en'] ?? $key, str_replace('_', ' ', $key)],
                'gate' => null,
                'data' => $f,
            ];
        }

        // ── موارد الاستخدام (UsageService::registry — ديناميكي) ──
        $registry = App::make(UsageService::class)->registry();
        foreach ($registry as $resource => $meta) {
            $topics[] = [
                'key' => 'resource:'.$resource,
                'kind' => 'resource',
                'label_ar' => $meta['label_ar'],
                'synonyms' => array_merge(
                    [$meta['label_ar'], $meta['label_en']],
                    self::resourceExtraSynonyms($resource),
                ),
                'gate' => $meta['gate'],
                'data' => ['resource' => $resource] + $meta,
            ];
        }

        // ── سير العمل ──
        foreach (AssistantKnowledge::workflows() as $wf) {
            $topics[] = [
                'key' => 'workflow:'.$wf['key'],
                'kind' => 'workflow',
                'label_ar' => $wf['title_ar'],
                'synonyms' => AssistantKnowledge::workflowSynonyms()[$wf['key']] ?? [$wf['title_ar']],
                'gate' => null,
                'data' => $wf + ['path' => self::workflowPath($wf['key'])],
            ];
        }

        // ── التشخيص ──
        foreach (AssistantKnowledge::troubleshooting() as $d) {
            $topics[] = [
                'key' => 'diag:'.$d['key'],
                'kind' => 'diag',
                'label_ar' => $d['title_ar'],
                'synonyms' => $d['keywords_ar'],
                'gate' => null,
                'data' => $d,
            ];
        }

        return self::$cache = $topics;
    }

    /**
     * مطابقة سؤال بأفضل موضوع — يعتمد على المطابقة الموسّعة لكل المرادفات
     * بعد التطبيع (لا مجرد "contains" سطحية).
     *
     * @return array{topic: ?array, score: int}
     */
    public static function match(string $question): array
    {
        $q = self::normalize($question);

        // إزالة كلمات الحشو الشائعة (لتجنب التطابق الخاطئ وحساب النقاط).
        $q = self::stripFiller($q);

        if ($q === '') {
            return ['topic' => null, 'score' => 0];
        }

        // نسخة ثانية بحذف "ال" التعريف من بداية كل كلمة —
        // تحل مشكلة المطابقة متعددة الكلمات (مثل "العلامة المائية").
        $variants = [$q];
        $noAl = preg_replace('/(^|\s)ال(?=[^\s])/u', '$1', $q) ?? $q;

        if ($noAl !== $q) {
            $variants[] = $noAl;
        }

        $best = null;
        $bestScore = 0;

        // مؤشرات نية: سؤال "مشكلة/خلل" → يفضّل التشخيص؛ سؤال "كم باقي/استخدام" → يفضّل الموارد.
        $isProblem = self::containsAny($q, ['مشكله', 'ما بقدر', 'فشل', 'لا يعمل', 'مش ظاهر', 'ما اشتغل', 'معلق', 'خطا', 'وقف', 'مش شغال', 'ما شغال', 'مش قادر', 'ما يفتح', 'لا يشتغل', 'ما يشتغل', 'بطيء', 'مش راضي', 'ما راضي']);
        $isUsage = self::containsAny($q, ['كم باقي', 'باقي', 'متبقي', 'كم عندي', 'كم طالب', 'كم كورس', 'كم فيديو', 'كم معلم', 'كم بطاقه', 'كم اختبار', 'كم سؤال', 'كم مساحه', 'مساحه التخزين', 'كم بث', 'كم موعد', 'كم خدمه', 'كم مقال', 'كم ملف']);

        foreach (self::allTopics() as $topic) {
            $score = 0;
            $kind = $topic['kind'] ?? '';

            // بونس التسمية للصفحات والتشخيص فقط —
            // سير العمل (مثل "رفع فيديو") والموارد والميزات لها أسماء عامة
            // تسبب تطابقاً خاطئاً على أسئلة المشاكل.
            if (in_array($kind, ['page', 'diag'], true)) {
                $norm = self::normalize($topic['label_ar']);

                if ($norm !== '' && self::inAny($norm, $variants)) {
                    $score += 5;
                }
            }

            foreach ($topic['synonyms'] as $syn) {
                $ns = self::normalize($syn);
                if ($ns === '' || mb_strlen($ns) < 2) {
                    continue;
                }

                if (self::inAny($ns, $variants)) {
                    $score += mb_strlen($ns) >= 6 ? 3 : 2;
                }
            }

            // توجيه حسب نية السؤال.
            if ($isProblem && $kind === 'diag') {
                $score += 3;
            }
            if ($isUsage && $kind === 'resource') {
                $score += 2;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $topic;
            }
        }

        return ['topic' => $best, 'score' => $bestScore];
    }

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($haystack, self::normalize($n))) {
                return true;
            }
        }

        return false;
    }

    private static function inAny(string $needle, array $haystacks): bool
    {
        foreach ($haystacks as $haystack) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function stripFiller(string $q): string
    {
        // نحذف فقط كلمات الاستفهام والأدوات والملكية —
        // الأفعال (اعمل/اضيف/ارفع...) محفوظة لأنها جزء من معنى السؤال.
        $fillers = [
            'كيف', 'ليش', 'وين', 'شو', 'شنو', 'ماذا', 'هل', 'ممكن', 'ممكنش', 'اقدر', 'اقدرش', 'اعرف',
            'عندي', 'عند', 'احنا', 'انا', 'بشنو', 'بشو', 'متى', 'الان', 'حاليا',
            'هذا', 'هذي', 'هذ', 'ال', 'في', 'من', 'علي', 'على', 'به', 'بها',
            'والله', 'ارجو', 'من فضلك', 'لو', 'الا', 'الرجا', 'بتاع', 'بتاعت', 'بتع',
            'موجود', 'موجوده', 'موجودة', 'الخاص', 'الخاصه', 'الخاصة', 'الخاصةب', 'ي',
            // ملاحظة: كلمات النفي (مش/مو/ما/لا) محفوظة عمداً — جزء من معنى التشخيص.
        ];

        $q = ' '.$q.' ';

        foreach ($fillers as $f) {
            $nf = self::normalize($f);
            if ($nf !== '') {
                $q = str_replace(' '.$nf.' ', ' ', $q);
            }
        }

        return trim($q);
    }

    /**
     * صفحات لوحة المعلم الفعلية (مصدر: nav-config.ts + routes/api.php).
     *
     * @return array<int, array{key: string, label_ar: string, synonyms: list<string>, gate: ?string, path: string, desc_ar: string, actions_ar: list<string>}>
     */
    public static function pages(): array
    {
        return [
            ['key' => 'page:dashboard', 'label_ar' => 'لوحة التحكم', 'synonyms' => ['لوحه التحكم', 'لوحه', 'الرئيسيه', 'الرئيسية', 'الصفحة الرئيسية', 'dashboard', 'home'], 'gate' => null, 'path' => '/teacher/dashboard', 'desc_ar' => 'نظرة يومية على منصتك: الإحصائيات، استخدام الخطة، الدروس والمواعيد القادمة، والأداء.', 'actions_ar' => ['إنشاء كورس', 'إدارة الكورسات', 'التحليلات']],
            ['key' => 'page:courses', 'label_ar' => 'الكورسات', 'synonyms' => ['كورس', 'كورسات', 'الدورات', 'دوره', 'دورة', 'courses', 'course', 'مواد', 'الماده', 'المادة', 'فيديو', 'درس', 'دروس', 'اضيف درس', 'وحدة', 'وحدات', 'اضيف وحدة', 'اضيف فيديو', 'محتوي', 'محتوى'], 'gate' => null, 'path' => '/courses', 'desc_ar' => 'إنشاء وإدارة الكورسات: وحدات، دروس، اختبارات، واجبات، محتوى، ونشر.', 'actions_ar' => ['كورس جديد', 'إدارة', 'نشر/إلغاء نشر', 'معاينة', 'بحث']],
            ['key' => 'page:students', 'label_ar' => 'الطلاب', 'synonyms' => ['طالب', 'طلاب', 'تلميذ', 'تلاميذ', 'الدارسين', 'students', 'student'], 'gate' => null, 'path' => '/students', 'desc_ar' => 'إدارة الطلاب: ملف كامل، نشاط، نتائج، أجهزة، حدود.', 'actions_ar' => ['الملف', 'تحديد حد الأجهزة', 'إعادة تعيين الأجهزة']],
            ['key' => 'page:cards', 'label_ar' => 'البطاقات', 'synonyms' => ['بطاقه', 'بطاقات', 'كارت', 'كروت', 'كود', 'اكواد', 'التسجيل', 'cards', 'activation', 'كوبون', 'رموز', 'يسترد', 'استرداد', 'استرد', 'يفعّل الكود', 'يريد الكود'], 'gate' => 'cards_enabled', 'path' => '/teacher/cards', 'desc_ar' => 'توليد بطاقات تسجيل برمز QR، استيراد Excel، طباعة الدفعات، تتبع الاستخدام.', 'actions_ar' => ['إنشاء بطاقات', 'استيراد', 'طباعة الدفعة', 'إلغاء', 'نسخ الكود']],
            ['key' => 'page:appointments', 'label_ar' => 'المواعيد', 'synonyms' => ['موعد', 'مواعيد', 'حجز', 'حجوزات', 'توفر', 'اوقات', 'اوقات', 'appointment', 'booking', 'جدوله', 'جدولة'], 'gate' => 'appointments_enabled', 'path' => '/teacher/appointments', 'desc_ar' => 'حجوزات الطلاب (تأكيد/رفض/إنهاء) وتحديد أوقات التوفر الأسبوعية.', 'actions_ar' => ['تأكيد', 'رفض', 'إنهاء', 'رابط الاجتماع', 'أوقات التوفر']],
            ['key' => 'page:services', 'label_ar' => 'الخدمات المدفوعة', 'synonyms' => ['خدمه', 'خدمات', 'استشاره', 'استشارة', 'جلسه', 'جلسات', 'مدفوعه', 'services', 'paid'], 'gate' => 'paid_services_enabled', 'path' => '/teacher/services', 'desc_ar' => 'استشارات وجلسات مدفوعة يحجزها الطالب ويدفع أونلاين.', 'actions_ar' => ['خدمة جديدة', 'تعديل', 'تفعيل/تعطيل']],
            ['key' => 'page:live', 'label_ar' => 'البث المباشر', 'synonyms' => ['بث', 'بثه', 'مباشر', 'بث مباشر', 'ستريم', 'live', 'streaming', 'بثوث', 'جلسه بث', 'meet'], 'gate' => 'live_stream_enabled', 'path' => '/teacher/live', 'desc_ar' => 'إنشاء وإدارة جلسات البث (Google Meet — المعلم يلصق رابط الحصة الخاص به).', 'actions_ar' => ['جلسة جديدة', 'بدء', 'إنهاء', 'إلغاء', 'نسخ الرابط']],
            ['key' => 'page:live_studio', 'label_ar' => 'ستوديو البث', 'synonyms' => ['استوديو', 'ستوديو', 'غرفه البث', 'غرفة البث', 'studuo', 'studio', 'كاميرا'], 'gate' => 'live_stream_enabled', 'path' => '/teacher/live', 'desc_ar' => 'إدارة جلسات البث: إنشاء حصة Google Meet وتعديل رابطها ومشاركة الانضمام.', 'actions_ar' => ['جلسة جديدة', 'نسخ الرابط']],
            ['key' => 'page:schedule', 'label_ar' => 'الدروس المجدولة', 'synonyms' => ['درس مجدول', 'دروس مجدوله', 'جدول', 'جدوله', 'جدولة', 'متكرر', 'scheduled', 'جدول الدروس', 'درس اسبوعي', 'دروس متكرره', 'درس متكرر', 'اجدد درس'], 'gate' => 'scheduled_lessons_enabled', 'path' => '/teacher/schedule', 'desc_ar' => 'جدولة دروس (مرة أو متكررة أسبوعياً) برابط اجتماع تظهر للطلاب.', 'actions_ar' => ['درس جديد', 'إنهاء', 'إلغاء']],
            ['key' => 'page:team', 'label_ar' => 'فريق العمل', 'synonyms' => ['فريق', 'فريق العمل', 'معلمين', 'اعضاء', 'أعضاء', 'معلم تاني', 'staff', 'team', 'دعوه', 'دعوة'], 'gate' => 'multi_teacher_enabled', 'path' => '/teacher/staff', 'desc_ar' => 'دعوة معلمين مشاركين بصلاحيات دقيقة (12 صلاحية).', 'actions_ar' => ['إرسال دعوة', 'إلغاء دعوة', 'تعديل صلاحيات']],
            ['key' => 'page:settings', 'label_ar' => 'الإعدادات', 'synonyms' => ['اعدادات', 'إعدادات', 'تصميم', 'منشئ الصفحة', 'دومين', 'نطاق', 'الوان', 'ألوان', 'شعار', 'logo', 'settings', 'design', 'watermark', 'علامه مائيه', 'العلامه', 'مائيه', 'سياسه', 'الأجهزة', 'الاجهزه', 'ملفات الدروس'], 'gate' => null, 'path' => '/teacher/settings', 'desc_ar' => '7 تبويبات: تصميم الصفحة، منشئ الصفحة، الدومين، سياسة الأجهزة، سياسة الملفات، حماية الفيديو (علامة مائية)، مشاركة الصفحة.', 'actions_ar' => ['تصميم', 'منشئ الصفحة', 'دومين', 'سياسة الأجهزة', 'سياسة الملفات', 'حماية الفيديو', 'مشاركة']],
            ['key' => 'page:support', 'label_ar' => 'الدعم الفني', 'synonyms' => ['دعم', 'دعم فني', 'تذكره', 'تذكرة', 'تذاكر', 'شكوى', 'شكوي', 'support', 'المساعده', 'المساعدة'], 'gate' => null, 'path' => '/teacher/support', 'desc_ar' => 'تذاكر دعم (دردشة حية أو بريد حسب مستوى الدعم في الخطة).', 'actions_ar' => ['تذكرة جديدة', 'رد']],
            ['key' => 'page:blog', 'label_ar' => 'المدونة', 'synonyms' => ['مدونه', 'مدونة', 'مقال', 'مقالات', 'بوست', 'blog', 'post'], 'gate' => 'blog_enabled', 'path' => '/blog', 'desc_ar' => 'مقالات تظهر على موقعك العام (نشر/مسودة).', 'actions_ar' => ['مقال جديد', 'نشر/إلغاء']],
            ['key' => 'page:community', 'label_ar' => 'المجتمع', 'synonyms' => ['مجتمع', 'منتدى', 'منتدي', 'موضوع', 'مواضيع', 'مجموعه', 'مجموعة', 'نقاش', 'تعليق', 'community', 'forum', 'سؤال وجواب'], 'gate' => 'community_enabled', 'path' => '/community', 'desc_ar' => 'منتدى خاص: موضوعات، مجموعات، تعليقات مع إجابة مقبولة.', 'actions_ar' => ['موضوع جديد', 'تعليق', 'مجموعات']],
            ['key' => 'page:announcements', 'label_ar' => 'الإعلانات', 'synonyms' => ['اعلان', 'إعلان', 'اعلانات', 'إعلانات', 'اخبار', 'أخبار', 'اشعار جماعي', 'announcement', 'تنبيه'], 'gate' => 'announcements_enabled', 'path' => '/announcements', 'desc_ar' => 'إرسال إعلانات لجمهور محدد عبر قنوات متعددة.', 'actions_ar' => ['إعلان جديد', 'إرسال']],
            ['key' => 'page:analytics', 'label_ar' => 'التحليلات والتقارير', 'synonyms' => ['تحليل', 'تحليلات', 'تقارير', 'تقرير', 'ارباح', 'أرباح', 'ايراد', 'إيراد', 'ايرادات', 'مبيعات', 'دخل', 'ربح', 'احصاءات', 'إحصاءات', 'analytics', 'reports', 'profit', 'revenue', 'نسبة الاكمال', 'الاكمال', 'نسبه الاكمال', 'الانجاز', 'الاجتياز', 'نسبة النجاح'], 'gate' => 'export_reports', 'path' => '/analytics', 'desc_ar' => 'KPIs ورسوم وأداء الكورسات/الامتحانات وتصدير PDF/Excel.', 'actions_ar' => ['تصدير PDF', 'تصدير Excel']],
            ['key' => 'page:exams', 'label_ar' => 'الامتحانات', 'synonyms' => ['امتحان', 'امتحانات', 'اختبار', 'اختبارات', 'نموذج', 'نماذج', 'exams', 'exam', 'النهائي'], 'gate' => null, 'path' => '/teacher/exams', 'desc_ar' => 'مراجعة محاولات الطلاب وتصحيح الأسئلة المقالية.', 'actions_ar' => ['تصحيح', 'حفظ التصحيح']],
            ['key' => 'page:questions', 'label_ar' => 'بنك الأسئلة', 'synonyms' => ['سؤال', 'اسئله', 'أسئلة', 'بنك', 'بنك الاسئله', 'سوال', 'questions', 'question bank', 'خيارات'], 'gate' => 'question_bank_enabled', 'path' => '/teacher/questions', 'desc_ar' => 'بنك أسئلة قابل لإعادة الاستخدام + إدارة أسئلة الاختبارات (8 أنواع مع LaTeX).', 'actions_ar' => ['سؤال جديد', 'استيراد من البنك']],
            ['key' => 'page:assignments', 'label_ar' => 'الواجبات', 'synonyms' => ['واجب', 'واجبات', 'تسليم', 'تسليمات', 'homework', 'assignment', 'مهمه', 'مهمة', 'اعمل واجب', 'اضيف واجب'], 'gate' => 'assignments_enabled', 'path' => '/courses/{id}', 'desc_ar' => 'واجبات مع تسليم الطلاب وتصحيح وتعليقات.', 'actions_ar' => ['واجب جديد', 'تصحيح', 'تعليق']],
            ['key' => 'page:subscription', 'label_ar' => 'الاشتراك والخطة', 'synonyms' => ['اشتراك', 'اشتراكات', 'خطه', 'خطة', 'خطط', 'تجربه', 'تجربة', 'تحديث الخطه', 'subscription', 'plan', 'ترخيص', 'الاشتراك الشهري'], 'gate' => null, 'path' => '/pricing', 'desc_ar' => 'خطتك الحالية، الميزات المفعلة/المعطلة، حدود الاستخدام، والترقية.', 'actions_ar' => ['عرض الخطط', 'ترقية', 'دفع']],
            ['key' => 'page:storage', 'label_ar' => 'التخزين', 'synonyms' => ['تخزين', 'مساحه', 'مساحة', 'storage', 'مساحه التخزين', 'جيجا', 'ميجا', 'مساحه تخزين'], 'gate' => null, 'path' => '/teacher/dashboard', 'desc_ar' => 'مساحة تخزين ملفاتك وفيديوهاتك، محدودة حسب خطتك.', 'actions_ar' => []],
        ];
    }

    private static function workflowPath(string $key): ?string
    {
        return match ($key) {
            'create_course', 'upload_video', 'create_exam' => '/courses',
            'live_stream' => '/teacher/live',
            'registration_card' => '/teacher/cards',
            'booking_service' => '/teacher/appointments',
            'team' => '/teacher/staff',
            'announcement' => '/announcements',
            default => null,
        };
    }

    /**
     * مرادفات إضافية لكل مورد (توسيع التغطية للعامية).
     *
     * @return list<string>
     */
    private static function resourceExtraSynonyms(string $resource): array
    {
        return match ($resource) {
            'students' => ['كم طالب', 'عدد الطلاب', 'طالب اقدر', 'كم طالب باقي'],
            'courses' => ['كم كورس', 'عدد الكورسات', 'كورس اقدر'],
            'videos' => ['كم فيديو', 'عدد الفيديوهات', 'فيديو اقدر'],
            'files' => ['كم ملف', 'عدد الملفات'],
            'pdfs' => ['كم بي دي اف', 'عدد ملفات البي دي اف', 'pdf'],
            'questions' => ['كم سؤال', 'عدد الاسئله'],
            'exams' => ['كم اختبار', 'عدد الاختبارات'],
            'assignments' => ['كم واجب', 'عدد الواجبات'],
            'teachers' => ['كم معلم', 'عدد المعلمين', 'فريق'],
            'storage' => ['مساحه', 'مساحة', 'كم باقيلي مساحه', 'storage', 'جيجا', 'ميجا'],
            'cards' => ['كم بطاقه', 'عدد البطاقات', 'بطاقه اقدر', 'كم كارت'],
            'live_sessions' => ['كم بث', 'عدد جلسات البث'],
            'appointments' => ['كم موعد', 'عدد المواعيد'],
            'services' => ['كم خدمه', 'عدد الخدمات'],
            'blog_posts' => ['كم مقال', 'عدد المقالات'],
            default => [],
        };
    }
}
