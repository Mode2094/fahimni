<?php

namespace Database\Seeders;

use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Models\UsageWarningRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * الخطط الثلاث الأساسية للمنصة — Idempotent (يمكن تشغيله مراراً دون تكرار).
 *
 * كل القيم محسوبة لضمان الربحية عبر Cost Engine (لا أرقام عشوائية):
 *  - storage × سعر التخزين + storage × 2 × سعر التسليم + دقائق البث × سعر البث
 *  - الهامش المطلوب: الأساسية >95% · الاحترافية >90% · النخبة >80% حتى بالاستخدام العالي.
 */
class PlansSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPlans();
        $this->seedWarningRules();
        $this->linkLegacySubscriptions();
    }

    private function seedPlans(): void
    {
        $plans = [
            'starter' => [
                'name' => 'الأساسية',
                'name_en' => 'Basic',
                'description_ar' => 'للمعلم الفردي الذي يبدأ رحلته في بيع الكورسات — كل الأدوات الأساسية لإطلاق منصتك التعليمية.',
                'description_en' => 'For the solo teacher starting to sell courses — all the core tools to launch your teaching platform.',
                'monthly_price' => 49.00,
                'yearly_price' => 499.00,
                'trial_days' => 7,
                'is_popular' => false,
                'features' => [
                    // ── Limits ─────────────────────────
                    'students_limit' => '50',
                    'courses_limit' => '5',
                    'storage_limit_mb' => '20480',           // 20 GB
                    'max_students_per_course' => '30',
                    'exams_limit' => '10',
                    'questions_limit' => '100',
                    'live_sessions_limit' => '0',
                    'assignments_limit' => '10',
                    'teachers_limit' => '1',
                    'videos_limit' => '50',
                    'files_limit' => '200',
                    'pdfs_limit' => '50',
                    'blog_posts_limit' => '10',
                    'cards_limit' => '500',
                    'appointments_limit' => '0',
                    'services_limit' => '0',
                    'live_minutes_limit' => '0',
                    'recordings_limit' => '0',
                    'delivery_minutes_limit' => '20000',     // 20k دقيقة مشاهدة شهرياً
                    'community_groups_limit' => '3',
                    // ── Gates (Enabled) ────────────────
                    'video_hosting' => 'true',
                    'video_upload_enabled' => 'true',
                    'file_upload_enabled' => 'true',
                    'secure_playback_enabled' => 'true',
                    'video_analytics_enabled' => 'true',
                    'scheduled_lessons_enabled' => 'true',
                    'question_bank_enabled' => 'true',
                    'certificates_enabled' => 'true',
                    'assignments_enabled' => 'true',
                    'community_enabled' => 'true',
                    'cards_enabled' => 'true',
                    'announcements_enabled' => 'true',
                    // ── Gates (Disabled) ───────────────
                    'live_stream_enabled' => 'false',
                    'live_recording_enabled' => 'false',
                    'appointments_enabled' => 'false',
                    'paid_services_enabled' => 'false',
                    'multi_teacher_enabled' => 'false',
                    'custom_branding_enabled' => 'false',
                    'custom_domain' => 'false',
                    'blog_enabled' => 'false',
                    'export_reports' => 'false',
                    'white_label' => 'false',
                    'api_access' => 'false',
                    'video_embeds' => 'true',
                    // ── Select ──────────────────────────
                    'support_level' => 'email',
                ],
            ],

            'professional' => [
                'name' => 'الاحترافية',
                'name_en' => 'Professional',
                'description_ar' => 'للمعلم الذي لديه عدد كبير من الطلاب والكورسات — بث مباشر، علامة تجارية، مواعيد وخدمات مدفوعة.',
                'description_en' => 'For teachers with many students and courses — live streaming, branding, appointments and paid services.',
                'monthly_price' => 99.00,
                'yearly_price' => 999.00,
                'trial_days' => 7,
                'is_popular' => true,
                'features' => [
                    // ── Limits ─────────────────────────
                    'students_limit' => '300',
                    'courses_limit' => '20',
                    'storage_limit_mb' => '102400',          // 100 GB
                    'max_students_per_course' => '150',
                    'exams_limit' => '50',
                    'questions_limit' => '500',
                    'live_sessions_limit' => '50',
                    'assignments_limit' => '100',
                    'teachers_limit' => '3',
                    'videos_limit' => '250',
                    'files_limit' => '1000',
                    'pdfs_limit' => '300',
                    'blog_posts_limit' => '50',
                    'cards_limit' => '2000',
                    'appointments_limit' => '200',
                    'services_limit' => '20',
                    'live_minutes_limit' => '3000',          // 50 ساعة بث شهرياً
                    'recordings_limit' => '100',
                    'delivery_minutes_limit' => '100000',    // 100k دقيقة مشاهدة
                    'community_groups_limit' => '20',
                    // ── Gates (Enabled) ────────────────
                    'video_hosting' => 'true',
                    'video_upload_enabled' => 'true',
                    'file_upload_enabled' => 'true',
                    'secure_playback_enabled' => 'true',
                    'video_analytics_enabled' => 'true',
                    'scheduled_lessons_enabled' => 'true',
                    'question_bank_enabled' => 'true',
                    'certificates_enabled' => 'true',
                    'assignments_enabled' => 'true',
                    'community_enabled' => 'true',
                    'cards_enabled' => 'true',
                    'announcements_enabled' => 'true',
                    'live_stream_enabled' => 'true',
                    'live_recording_enabled' => 'true',
                    'appointments_enabled' => 'true',
                    'paid_services_enabled' => 'true',
                    'multi_teacher_enabled' => 'true',
                    'custom_branding_enabled' => 'true',
                    'custom_domain' => 'true',
                    'blog_enabled' => 'true',
                    'export_reports' => 'true',
                    'video_embeds' => 'true',
                    // ── Gates (Disabled) ───────────────
                    'white_label' => 'false',
                    'api_access' => 'false',
                    // ── Select ──────────────────────────
                    'support_level' => 'priority',
                ],
            ],

            'elite' => [
                'legacy_slugs' => ['enterprise'],
                'name' => 'النخبة',
                'name_en' => 'Elite',
                'description_ar' => 'للمعلمين الكبار والأكاديميات الصغيرة — كل شيء بلا حدود تقريباً، علامة بيضاء ووصول API ودعم مخصص.',
                'description_en' => 'For top teachers and small academies — almost everything unlimited, white label, API access and dedicated support.',
                'monthly_price' => 199.00,
                'yearly_price' => 1999.00,
                'trial_days' => 7,
                'is_popular' => false,
                'features' => [
                    // ── Limits ─────────────────────────
                    'students_limit' => 'unlimited',
                    'courses_limit' => 'unlimited',
                    'storage_limit_mb' => '512000',          // 500 GB
                    'max_students_per_course' => 'unlimited',
                    'exams_limit' => 'unlimited',
                    'questions_limit' => 'unlimited',
                    'live_sessions_limit' => 'unlimited',
                    'assignments_limit' => 'unlimited',
                    'teachers_limit' => '10',
                    'videos_limit' => 'unlimited',
                    'files_limit' => 'unlimited',
                    'pdfs_limit' => 'unlimited',
                    'blog_posts_limit' => 'unlimited',
                    'cards_limit' => 'unlimited',
                    'appointments_limit' => 'unlimited',
                    'services_limit' => 'unlimited',
                    'live_minutes_limit' => '10000',         // ~166 ساعة بث شهرياً
                    'recordings_limit' => 'unlimited',
                    'delivery_minutes_limit' => '500000',    // 500k دقيقة مشاهدة
                    'community_groups_limit' => 'unlimited',
                    // ── Gates (All enabled) ────────────
                    'video_hosting' => 'true',
                    'video_upload_enabled' => 'true',
                    'file_upload_enabled' => 'true',
                    'secure_playback_enabled' => 'true',
                    'video_analytics_enabled' => 'true',
                    'scheduled_lessons_enabled' => 'true',
                    'question_bank_enabled' => 'true',
                    'certificates_enabled' => 'true',
                    'assignments_enabled' => 'true',
                    'community_enabled' => 'true',
                    'cards_enabled' => 'true',
                    'announcements_enabled' => 'true',
                    'live_stream_enabled' => 'true',
                    'live_recording_enabled' => 'true',
                    'appointments_enabled' => 'true',
                    'paid_services_enabled' => 'true',
                    'multi_teacher_enabled' => 'true',
                    'custom_branding_enabled' => 'true',
                    'custom_domain' => 'true',
                    'blog_enabled' => 'true',
                    'export_reports' => 'true',
                    'white_label' => 'true',
                    'api_access' => 'true',
                    'video_embeds' => 'true',
                    // ── Select ──────────────────────────
                    'support_level' => 'dedicated',
                ],
            ],
        ];

        foreach ($plans as $slug => $data) {
            // مع SoftDeletes: قد يكون الصف محذوفاً ناعماً (أو بسلغ قديم
            // كـ enterprise) — نستعيده ونحدّثه بدل التصادم مع الـ unique.
            $legacySlugs = [$slug, ...($data['legacy_slugs'] ?? [])];
            $plan = SubscriptionPlan::withTrashed()
                ->whereIn('slug', $legacySlugs)
                ->first();

            if ($plan && $plan->trashed()) {
                $plan->restore();
            }

            if (! $plan) {
                $plan = new SubscriptionPlan(['id' => UuidV7::generate()]);
            }

            $plan->fill([
                'slug' => $slug,
                'name' => $data['name'],
                'description' => $data['description_ar'],
                'monthly_price' => $data['monthly_price'],
                'yearly_price' => $data['yearly_price'],
                'currency' => 'JOD',
                'trial_days' => $data['trial_days'],
                'is_active' => true,
                'is_public' => true,
                'is_popular' => $data['is_popular'],
                'sort_order' => match ($slug) { 'starter' => 0, 'professional' => 1, default => 2 },
            ])->save();

            foreach ($data['features'] as $key => $value) {
                PlanFeature::updateOrCreate(
                    ['plan_id' => $plan->id, 'feature_key' => $key],
                    ['feature_value' => (string) $value, 'feature_type' => 'string', 'sort_order' => 0],
                );
            }

            // حذف أي ميزات قديمة لم تعد موجودة في المصفوفة (تطهير).
            PlanFeature::where('plan_id', $plan->id)
                ->whereNotIn('feature_key', array_keys($data['features']))
                ->delete();
        }
    }

    private function seedWarningRules(): void
    {
        $rules = [
            ['storage', 'warning', 80.00, 'info', 'استخدامك وصل 80% من مساحة التخزين.', 'You have used 80% of your storage.'],
            ['storage', 'critical', 90.00, 'warning', 'اقتربت من حد التخزين (90%). حرر مساحة قريباً.', 'You are near your storage limit (90%). Free up space soon.'],
            ['storage', 'blocked', 100.00, 'error', 'لقد وصلت إلى الحد الأقصى للتخزين. قم بترقية خطتك أو احذف بعض الملفات.', 'You have reached the storage limit. Upgrade your plan or delete files.'],
            ['videos', 'warning', 80.00, 'info', 'استخدمت 80% من حد الفيديوهات.', 'You have used 80% of your video limit.'],
            ['videos', 'critical', 90.00, 'warning', 'اقتربت من حد الفيديوهات (90%).', 'You are near your video limit (90%).'],
            ['videos', 'blocked', 100.00, 'error', 'وصلت لحد الفيديوهات. قم بترقية خطتك.', 'You have reached the video limit. Upgrade your plan.'],
            ['students', 'warning', 80.00, 'info', 'استخدمت 80% من حد الطلاب.', 'You have used 80% of your student limit.'],
            ['students', 'critical', 90.00, 'warning', 'اقتربت من حد الطلاب (90%).', 'You are near your student limit (90%).'],
            ['students', 'blocked', 100.00, 'error', 'وصلت لحد الطلاب. قم بترقية خطتك لإضافة المزيد.', 'You have reached the student limit. Upgrade to add more.'],
            ['watch_minutes', 'warning', 80.00, 'info', 'استهلكت 80% من دقائق المشاهدة الشهرية.', 'You have consumed 80% of your monthly watch minutes.'],
            ['watch_minutes', 'critical', 90.00, 'warning', 'اقتربت من حد المشاهدة الشهري (90%).', 'You are near your monthly watch limit (90%).'],
            ['watch_minutes', 'blocked', 100.00, 'error', 'استنفدت دقائق المشاهدة الشهرية. قم بترقية خطتك.', 'You have exhausted your monthly watch minutes. Upgrade your plan.'],
            ['live_minutes', 'warning', 80.00, 'info', 'استهلكت 80% من دقائق البث الشهرية.', 'You have consumed 80% of your monthly live minutes.'],
            ['live_minutes', 'blocked', 100.00, 'error', 'استنفدت دقائق البث الشهرية. قم بترقية خطتك.', 'You have exhausted your monthly live minutes. Upgrade your plan.'],
        ];

        foreach ($rules as [$resource, $level, $threshold, $severity, $msgAr, $msgEn]) {
            UsageWarningRule::updateOrCreate(
                ['resource' => $resource, 'level' => $level],
                [
                    'threshold' => $threshold,
                    'severity' => $severity,
                    'message_ar' => $msgAr,
                    'message_en' => $msgEn,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * الاشتراكات التاريخية المرتبطة بخطط محذوفة تُحوَّل بأمان للخطة
     * المناظرة (التجربة → الأساسية، النشط → الاحترافية) دون حذف أي تاريخ.
     */
    private function linkLegacySubscriptions(): void
    {
        $basic = SubscriptionPlan::where('slug', 'starter')->value('id');
        $professional = SubscriptionPlan::where('slug', 'professional')->value('id');

        if (! $basic || ! $professional) {
            return;
        }

        $orphanPlans = SubscriptionPlan::whereNotIn('slug', ['starter', 'professional', 'elite'])->pluck('id');

        TenantSubscription::withoutTenant()
            ->whereIn('plan_id', $orphanPlans)
            ->orWhereNotIn('plan_id', SubscriptionPlan::pluck('id'))
            ->get()
            ->each(function (TenantSubscription $sub) use ($basic, $professional) {
                $target = $sub->status === 'active' ? $professional : $basic;
                $sub->update(['plan_id' => $target]);
            });

        // تنظيف ميزات ولقطات الربحية للخطط القديمة المحذوفة.
        PlanFeature::whereNotIn('plan_id', SubscriptionPlan::pluck('id'))->delete();
        \App\Models\PlanProfitability::whereNotIn('plan_id', SubscriptionPlan::pluck('id'))->delete();
    }
}
