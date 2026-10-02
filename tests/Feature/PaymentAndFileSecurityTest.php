<?php

namespace Tests\Feature;

use App\Models\ActivationCard;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\Payment;
use App\Models\Section;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class PaymentAndFileSecurityTest extends TenantTestCase
{
    private function makeCourse(string $tenantId): Course
    {
        return Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'title' => 'Test Course',
            'slug' => 'tc-'.Str::random(6),
            'is_published' => true,
            'sort_order' => 0,
        ]);
    }

    private function makeLesson(string $tenantId, string $courseId): Lesson
    {
        $section = Section::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'course_id' => $courseId,
            'title' => 'S1',
            'sort_order' => 0,
        ]);

        return Lesson::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'section_id' => $section->id,
            'title' => 'L1',
            'sort_order' => 0,
        ]);
    }

    // ─── Fake payment gateway must never be reachable by tenants ─────

    public function test_tenant_teacher_cannot_create_payment_through_legacy_endpoint(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $response = $this->postJson('/api/v1/payments', [
            'amount' => 1,
            'currency' => 'SAR',
        ], $this->authHeaders($teacher, $tenant));

        // The legacy create route is gone (404/405). Even if it were
        // reachable, the controller + policy reject non-super-admins (403).
        $this->assertContains($response->status(), [403, 404, 405]);
    }

    public function test_tenant_teacher_cannot_change_payment_status_to_completed(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $payment = Payment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'transaction_id' => 'tx-test-1',
            'gateway' => 'paytabs',
            'amount' => 49.99,
            'currency' => 'SAR',
            'status' => 'pending',
            'payment_method' => 'paytabs',
            'gateway_data' => [],
        ]);

        $response = $this->putJson("/api/v1/payments/{$payment->id}/status", [
            'status' => 'completed',
        ], $this->authHeaders($teacher, $tenant));

        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    public function test_legacy_payment_create_route_is_not_registered(): void
    {
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('payments.store') ?? false
        );
    }

    // ─── Lesson files must live on the private disk ──────────────────

    public function test_lesson_files_are_stored_on_private_disk(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $course = $this->makeCourse($tenant->id);
        $lesson = $this->makeLesson($tenant->id, $course->id);

        Storage::fake('local');
        Storage::fake('public');

        $response = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->post('/api/v1/upload', [
                'lesson_id' => $lesson->id,
                'file' => UploadedFile::fake()->createWithContent('handout.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF"),
            ]);

        $response->assertStatus(201);

        $content = LessonContent::where('tenant_id', $tenant->id)->where('lesson_id', $lesson->id)->first();
        $this->assertNotNull($content);

        $file = $content->file;
        $this->assertNotNull($file);
        $this->assertStringStartsWith('lesson-files/', $file->storage_path);
        $this->assertTrue(Storage::disk('local')->exists($file->storage_path));
        $this->assertFalse(Storage::disk('public')->exists($file->storage_path));
    }

    public function test_generic_uploads_stay_public(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        Storage::fake('public');

        $response = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->post('/api/v1/upload', [
                'file' => UploadedFile::fake()->image('cover.png'),
            ]);

        $response->assertStatus(201);
        $this->assertStringStartsWith('/storage/', $response->json('url'));
    }

    // ─── Card redemption is atomic ───────────────────────────────────

    public function test_card_cannot_be_redeemed_twice(): void
    {
        $student = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($student, $tenant, 'student');

        $course = $this->makeCourse($tenant->id);

        $card = ActivationCard::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'code' => strtoupper(bin2hex(random_bytes(6))),
            'status' => 'generated',
            'metadata' => [],
        ]);

        $first = $this->postJson('/api/v1/cards/redeem', ['code' => $card->code], $this->authHeaders($student, $tenant));
        $first->assertOk();

        $second = $this->postJson('/api/v1/cards/redeem', ['code' => $card->code], $this->authHeaders($student, $tenant));
        $second->assertStatus(404);
        $this->assertDatabaseHas('activation_cards', ['id' => $card->id, 'status' => 'redeemed']);
    }

    public function test_card_cannot_be_redeemed_by_a_student_of_another_tenant(): void
    {
        $student = $this->makeUser();
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->attachToTenant($student, $tenantA, 'student');

        $courseB = $this->makeCourse($tenantB->id);
        $card = ActivationCard::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantB->id,
            'course_id' => $courseB->id,
            'code' => strtoupper(bin2hex(random_bytes(6))),
            'status' => 'generated',
            'metadata' => [],
        ]);

        $response = $this->postJson('/api/v1/cards/redeem', ['code' => $card->code], $this->authHeaders($student, $tenantA));

        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseHas('activation_cards', ['id' => $card->id, 'status' => 'generated']);
    }

    // ─── Subscription is only activated through gateway callbacks ────

    public function test_tenant_staff_cannot_activate_a_subscription_directly(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $subscription = TenantSubscription::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'plan_id' => $this->makePlan()->id,
            'status' => 'pending',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'auto_renew' => true,
        ]);

        $response = $this->putJson("/api/v1/subscriptions/{$subscription->id}/status", [
            'status' => 'active',
        ], $this->authHeaders($teacher, $tenant));

        $this->assertContains($response->status(), [403, 404, 405]);
        $this->assertDatabaseHas('tenant_subscriptions', ['id' => $subscription->id, 'status' => 'pending']);
    }

    private function makePlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'id' => UuidV7::generate(),
            'name' => 'Basic',
            'slug' => 'basic-'.Str::random(6),
            'monthly_price' => 19.99,
            'yearly_price' => 199.99,
            'currency' => 'SAR',
            'trial_days' => 14,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_admin_can_view_all_card_batches_across_tenants(): void
    {
        $admin = $this->makeUser(['is_super_admin' => true]);

        $tenant = $this->makeTenant();
        $course = Course::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'title' => 'C', 'slug' => 'c-'.Str::random(6), 'is_published' => true, 'sort_order' => 0]);

        $batch = (string) UuidV7::generate();
        ActivationCard::insert([
            ['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'batch_id' => $batch, 'code' => strtoupper(bin2hex(random_bytes(6))), 'status' => 'generated', 'created_at' => now(), 'updated_at' => now(), 'metadata' => '[]', 'used_at' => null, 'student_id' => null],
            ['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'batch_id' => $batch, 'code' => strtoupper(bin2hex(random_bytes(6))), 'status' => 'redeemed', 'student_id' => $this->makeUser()->id, 'used_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'metadata' => '[]'],
        ]);

        $response = $this->getJson('/api/v1/cards/batches', $this->authHeaders($admin, $tenant));

        $response->assertOk();
        $response->assertJsonCount(1, 'batches');
        $response->assertJsonPath('batches.0.total', 2);
        $response->assertJsonPath('batches.0.generated', 1);
        $response->assertJsonPath('batches.0.redeemed', 1);
        $response->assertJsonPath('batches.0.course.title', 'C');
    }
}
