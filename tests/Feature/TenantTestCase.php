<?php

namespace Tests\Feature;

use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    protected function makeTenant(): Tenant
    {
        return Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => 'Academy '.Str::random(6),
            'slug' => 'academy-'.Str::random(6),
            'email' => Str::random(8).'@test.local',
            'status' => 'active',
            'locale' => 'ar',
            'timezone' => 'Asia/Riyadh',
            'max_students' => 100,
            'storage_limit_mb' => 5000,
            'metadata' => [],
        ]);
    }

    protected function makeUser(array $attrs = []): User
    {
        return User::factory()->create($attrs);
    }

    protected function attachToTenant(User $user, Tenant $tenant, string $role = 'student', string $status = 'active'): void
    {
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => $status,
            'joined_at' => now(),
            'metadata' => [],
        ]);
    }

    protected function authHeaders(User $user, Tenant $tenant): array
    {
        // The Sanctum guard caches the authenticated user + request between
        // HTTP calls within the same test, so always reset it before issuing
        // a request for a different user.
        $this->app['auth']->forgetGuards();

        return [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'X-Tenant-Id' => $tenant->id,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Give a tenant an active trial so staff routes pass the subscription gate.
     * The test plan includes the standard features so feature-gated routes
     * behave like production plans.
     */
    protected function attachSubscription(Tenant $tenant, string $status = 'trialing'): TenantSubscription
    {
        $plan = SubscriptionPlan::create([
            'id' => (string) Str::uuid(),
            'name' => 'Basic',
            'slug' => 'basic-'.Str::random(6),
            'monthly_price' => 19.99,
            'yearly_price' => 199.99,
            'currency' => 'JOD',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);

        $features = [
            'students_limit' => '1000', 'courses_limit' => '100', 'storage_limit_mb' => '5120',
            'videos_limit' => '100', 'files_limit' => '1000', 'pdfs_limit' => '100',
            'questions_limit' => '1000', 'exams_limit' => '100', 'assignments_limit' => '100',
            'teachers_limit' => '10', 'community_groups_limit' => '20', 'live_sessions_limit' => '50',
            'blog_posts_limit' => '100', 'cards_limit' => '1000', 'appointments_limit' => '100',
            'services_limit' => '20',
            'live_stream_enabled' => 'true', 'scheduled_lessons_enabled' => 'true',
            'appointments_enabled' => 'true', 'paid_services_enabled' => 'true',
            'multi_teacher_enabled' => 'true', 'question_bank_enabled' => 'true',
            'certificates_enabled' => 'true', 'assignments_enabled' => 'true',
            'community_enabled' => 'true', 'custom_branding_enabled' => 'true',
            'custom_domain' => 'true', 'blog_enabled' => 'true', 'export_reports' => 'true',
            'white_label' => 'true', 'api_access' => 'true', 'cards_enabled' => 'true',
            'announcements_enabled' => 'true', 'video_upload_enabled' => 'true',
            'file_upload_enabled' => 'true',
        ];

        foreach ($features as $key => $value) {
            PlanFeature::create([
                'id' => (string) Str::uuid(),
                'plan_id' => $plan->id,
                'feature_key' => $key,
                'feature_value' => $value,
                'feature_type' => 'string',
                'sort_order' => 0,
            ]);
        }

        return TenantSubscription::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'trial_ends_at' => now()->addDays(7),
            'auto_renew' => true,
        ]);
    }
}
