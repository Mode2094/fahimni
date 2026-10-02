<?php

namespace Database\Seeders;

use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;
use Symfony\Component\Uid\UuidV7;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plansData = [
            ['slug' => 'starter', 'name' => 'Starter', 'desc' => 'مثالية للمعلمين الجدد', 'monthly' => 49.99, 'yearly' => 499.99, 'trial' => 7, 'sort' => 1, 'features' => [
                ['students_limit', '50', 'numeric'],
                ['courses_limit', '5', 'numeric'],
                ['storage_limit_mb', '5120', 'numeric'],
                ['exams_limit', '10', 'numeric'],
                ['questions_limit', '100', 'numeric'],
                ['live_sessions_limit', '5', 'numeric'],
                ['assignments_limit', '10', 'numeric'],
                ['live_stream_enabled', 'false', 'boolean'],
                ['scheduled_lessons_enabled', 'true', 'boolean'],
                ['question_bank_enabled', 'true', 'boolean'],
                ['certificates_enabled', 'true', 'boolean'],
                ['custom_branding_enabled', 'false', 'boolean'],
                ['export_reports', 'false', 'boolean'],
                ['max_students_per_course', '30', 'numeric'],
                ['support_level', 'email', 'string'],
                ['community_enabled', 'true', 'boolean'],
                ['community_groups_limit', '5', 'numeric'],
                ['appointments_enabled', 'false', 'boolean'],
                ['paid_services_enabled', 'false', 'boolean'],
                ['multi_teacher_enabled', 'false', 'boolean'],
                ['teachers_limit', '1', 'numeric'],
                ['videos_limit', '50', 'numeric'],
                ['files_limit', '200', 'numeric'],
                ['pdfs_limit', '50', 'numeric'],
                ['blog_posts_limit', '10', 'numeric'],
                ['cards_limit', '500', 'numeric'],
                ['appointments_limit', '30', 'numeric'],
                ['services_limit', '5', 'numeric'],
                ['cards_enabled', 'true', 'boolean'],
                ['announcements_enabled', 'true', 'boolean'],
                ['video_upload_enabled', 'true', 'boolean'],
                ['file_upload_enabled', 'true', 'boolean'],
            ]],
            ['slug' => 'professional', 'name' => 'Professional', 'desc' => 'للمعلمين المحترفين', 'monthly' => 99.99, 'yearly' => 999.99, 'trial' => 7, 'sort' => 2, 'features' => [
                ['students_limit', '200', 'numeric'],
                ['courses_limit', '20', 'numeric'],
                ['storage_limit_mb', '20480', 'numeric'],
                ['exams_limit', '50', 'numeric'],
                ['questions_limit', '500', 'numeric'],
                ['live_sessions_limit', '50', 'numeric'],
                ['assignments_limit', '100', 'numeric'],
                ['live_stream_enabled', 'true', 'boolean'],
                ['scheduled_lessons_enabled', 'true', 'boolean'],
                ['question_bank_enabled', 'true', 'boolean'],
                ['certificates_enabled', 'true', 'boolean'],
                ['custom_branding_enabled', 'true', 'boolean'],
                ['export_reports', 'true', 'boolean'],
                ['max_students_per_course', '100', 'numeric'],
                ['support_level', 'priority', 'string'],
                ['community_enabled', 'true', 'boolean'],
                ['community_groups_limit', '20', 'numeric'],
                ['appointments_enabled', 'true', 'boolean'],
                ['paid_services_enabled', 'true', 'boolean'],
                ['multi_teacher_enabled', 'true', 'boolean'],
                ['teachers_limit', '3', 'numeric'],
                ['videos_limit', '200', 'numeric'],
                ['files_limit', '1000', 'numeric'],
                ['pdfs_limit', '300', 'numeric'],
                ['blog_posts_limit', '50', 'numeric'],
                ['cards_limit', '2000', 'numeric'],
                ['appointments_limit', '200', 'numeric'],
                ['services_limit', '50', 'numeric'],
                ['cards_enabled', 'true', 'boolean'],
                ['announcements_enabled', 'true', 'boolean'],
                ['video_upload_enabled', 'true', 'boolean'],
                ['file_upload_enabled', 'true', 'boolean'],
            ]],
            ['slug' => 'enterprise', 'name' => 'Enterprise', 'desc' => 'للأكاديميات والمؤسسات', 'monthly' => 199.99, 'yearly' => 1999.99, 'trial' => 7, 'sort' => 3, 'features' => [
                ['students_limit', 'unlimited', 'string'],
                ['courses_limit', 'unlimited', 'string'],
                ['storage_limit_mb', '102400', 'numeric'],
                ['exams_limit', 'unlimited', 'string'],
                ['questions_limit', 'unlimited', 'string'],
                ['live_sessions_limit', 'unlimited', 'string'],
                ['assignments_limit', 'unlimited', 'string'],
                ['live_stream_enabled', 'true', 'boolean'],
                ['scheduled_lessons_enabled', 'true', 'boolean'],
                ['question_bank_enabled', 'true', 'boolean'],
                ['certificates_enabled', 'true', 'boolean'],
                ['custom_branding_enabled', 'true', 'boolean'],
                ['export_reports', 'true', 'boolean'],
                ['max_students_per_course', 'unlimited', 'string'],
                ['support_level', 'dedicated', 'string'],
                ['api_access', 'true', 'boolean'],
                ['white_label', 'true', 'boolean'],
                ['community_enabled', 'true', 'boolean'],
                ['community_groups_limit', 'unlimited', 'string'],
                ['appointments_enabled', 'true', 'boolean'],
                ['paid_services_enabled', 'true', 'boolean'],
                ['multi_teacher_enabled', 'true', 'boolean'],
                ['teachers_limit', 'unlimited', 'string'],
                ['videos_limit', 'unlimited', 'string'],
                ['files_limit', 'unlimited', 'string'],
                ['pdfs_limit', 'unlimited', 'string'],
                ['blog_posts_limit', 'unlimited', 'string'],
                ['cards_limit', 'unlimited', 'string'],
                ['appointments_limit', 'unlimited', 'string'],
                ['services_limit', 'unlimited', 'string'],
                ['cards_enabled', 'true', 'boolean'],
                ['announcements_enabled', 'true', 'boolean'],
                ['video_upload_enabled', 'true', 'boolean'],
                ['file_upload_enabled', 'true', 'boolean'],
            ]],
        ];

        foreach ($plansData as $data) {
            $plan = SubscriptionPlan::firstOrCreate(
                ['slug' => $data['slug']],
                [
                    'id' => UuidV7::generate(),
                    'name' => $data['name'],
                    'description' => $data['desc'],
                    'monthly_price' => $data['monthly'],
                    'yearly_price' => $data['yearly'],
                    'currency' => 'JOD',
                    'trial_days' => $data['trial'],
                    'is_active' => true,
                    'is_public' => true,
                    'sort_order' => $data['sort'],
                ],
            );
            $plan->features()->delete();
            $this->createFeatures($plan, $data['features']);
        }
    }

    private function createFeatures(SubscriptionPlan $plan, array $features): void
    {
        foreach ($features as $i => [$key, $value, $type]) {
            PlanFeature::create([
                'id' => UuidV7::generate(),
                'plan_id' => $plan->id,
                'feature_key' => $key,
                'feature_value' => $value,
                'feature_type' => $type,
                'sort_order' => $i + 1,
            ]);
        }
    }
}
