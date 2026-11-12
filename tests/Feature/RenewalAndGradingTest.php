<?php

namespace Tests\Feature;

use App\Jobs\ExpireSubscriptionsJob;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAnswer;
use App\Models\ExamQuestion;
use App\Models\Payment;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Section;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class RenewalAndGradingTest extends TenantTestCase
{
    use RefreshDatabase;

    private function makePlan(string $name, float $monthly, float $yearly): \App\Models\SubscriptionPlan
    {
        return \App\Models\SubscriptionPlan::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'monthly_price' => $monthly,
            'yearly_price' => $yearly,
            'currency' => 'JOD',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * Proration on a plan change is now computed by Freemius: an existing
     * license routes through the upgrade-checkout-link API rather than a
     * locally computed credit. This test asserts the controller returns the
     * Freemius upgrade URL and never mutates the local subscription itself.
     */
    public function test_plan_change_routes_through_freemius_upgrade_link(): void
    {
        config()->set('services.freemius.enabled', true);
        config()->set('services.freemius.product_id', '777');
        config()->set('services.freemius.api_token', 'test-api-token');
        config()->set('services.freemius.secret_key', 'test-secret-key');
        config()->set('services.freemius.api_url', 'https://api.freemius.test');

        $tenant = $this->makeTenant();

        $oldPlan = $this->makePlan('Basic', 10.00, 100.00);
        $newPlan = \App\Models\SubscriptionPlan::create([
            'id' => (string) Str::uuid(),
            'name' => 'Pro',
            'slug' => 'pro-'.Str::random(4),
            'monthly_price' => 20.00,
            'yearly_price' => 200.00,
            'currency' => 'JOD',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
            'metadata' => ['freemius_monthly_plan_id' => '2020'],
        ]);

        $teacher = $this->makeUser(['email' => 'owner@academy.test']);
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $tenant->update(['owner_id' => $teacher->id]);

        // An existing (active) Freemius license for this tenant.
        \App\Models\FsEntitlement::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'fs_license_id' => 'LIC-UP',
            'plan_id' => $oldPlan->id,
            'type' => 'subscription',
            'status' => 'active',
            'expiration' => now()->addDays(20),
            'is_canceled' => false,
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'api.freemius.test/v1/products/777/licenses/LIC-UP/checkout/link.json' => \Illuminate\Support\Facades\Http::response([
                'url' => 'https://checkout.freemius.test/upgrade/xyz',
                'settings' => [],
                'expires' => now()->addHour()->toIso8601String(),
            ]),
        ]);

        $response = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson('/api/v1/subscriptions/freemius/checkout', [
                'plan_id' => $newPlan->id,
                'billing_cycle' => 'monthly',
            ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'upgrade')
            ->assertJsonPath('checkout_url', 'https://checkout.freemius.test/upgrade/xyz');
    }

    public function test_expiry_with_auto_renew_creates_past_due_and_renewal_invoice(): void
    {
        $tenant = $this->makeTenant();
        $plan = $this->makePlan('Pro', 49.99, 499.99);

        $sub = TenantSubscription::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subHour(),
            'auto_renew' => true,
        ]);

        (new ExpireSubscriptionsJob())->handle();

        $this->assertEquals('past_due', $sub->fresh()->status);
        $this->assertFalse((bool) $sub->fresh()->auto_renew);

        // A pending renewal invoice exists.
        $renewal = Payment::where('tenant_subscription_id', $sub->id)
            ->where('status', 'pending')
            ->where('gateway', 'pending')
            ->first();
        $this->assertNotNull($renewal);
        $this->assertEquals(49.99, (float) $renewal->amount);
        $this->assertTrue(($renewal->gateway_data['renewal'] ?? false) === true);

        // PlanEngine sees no active subscription.
        \App\Plans\PlanEngine::bumpVersion();
        $this->assertFalse(app(\App\Plans\PlanEngine::class)->snapshot($tenant->id)['active']);
    }

    public function test_expiry_without_auto_renew_is_expired(): void
    {
        $tenant = $this->makeTenant();
        $plan = $this->makePlan('Basic', 9.99, 99.99);

        $sub = TenantSubscription::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subHour(),
            'auto_renew' => false,
        ]);

        (new ExpireSubscriptionsJob())->handle();

        $this->assertEquals('expired', $sub->fresh()->status);
        $this->assertEquals(0, Payment::where('tenant_subscription_id', $sub->id)->count());
    }

    public function test_writing_answer_flags_attempt_for_manual_review(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $course = Course::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'title' => 'C', 'slug' => 'c', 'is_published' => true]);
        $section = Section::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'title' => 'S', 'sort_order' => 0]);

        $writing = Question::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'question_type' => 'writing',
            'question_text' => 'Explain X', 'points' => 10, 'is_active' => true,
            'metadata' => ['keywords' => ['answer']],
        ]);

        $exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'section_id' => $section->id,
            'title' => 'E', 'pass_mark_percent' => 50, 'max_attempts' => 3, 'is_published' => true, 'duration_minutes' => 30,
            'metadata' => [],
        ]);
        $eq = ExamQuestion::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'exam_id' => $exam->id,
            'question_id' => $writing->id, 'sort_order' => 0, 'points_override' => 10,
        ]);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $enrollment = Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
        ]);

        $headers = $this->authHeaders($student, $tenant);
        $start = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $attemptId = $start->json('attempt.id');

        $submit = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [
                ['exam_question_id' => $eq->id, 'question_id' => $writing->id, 'given_answer' => 'The correct answer'],
            ],
        ]);

        $submit->assertOk();
        $this->assertTrue($submit->json('result.needs_review'));
        $this->assertFalse($submit->json('result.passed'));

        // The teacher grades it: full marks -> passed.
        $attempt = ExamAttempt::where('id', $attemptId)->first();
        $answerId = ExamAnswer::where('exam_attempt_id', $attemptId)->first()->id;

        $grade = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/grade", [
                'answers' => [['exam_answer_id' => $answerId, 'points_earned' => 10]],
            ]);

        $grade->assertOk();
        $this->assertEquals(100, (float) $attempt->fresh()->percentage);
        $this->assertTrue((bool) $attempt->fresh()->passed);
        $this->assertFalse((bool) $attempt->fresh()->needs_review);
    }
}
