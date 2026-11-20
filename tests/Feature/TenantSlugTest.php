<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\TenantSlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * رابط المعلم (slug) — اسم صافٍ بدل الأكواد، مع لاحقة رقمية عند التشابه،
 * وفحص توفّر الاسم قبل الحجز (تبويب الدومين).
 */
class TenantSlugTest extends TenantTestCase
{
    use RefreshDatabase;

    private function slugs(): TenantSlugService
    {
        return app(TenantSlugService::class);
    }

    private function teacherTenant(): array
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant, 'trialing');
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher', 'active');

        return [$tenant, $teacher];
    }

    /* ── التوليد ─────────────────────────────────────────── */

    public function test_generates_clean_slug_from_name(): void
    {
        $this->assertSame('mohmmad', $this->slugs()->generate('MOHMMAD'));
        $this->assertSame('fatima-al-zahra', $this->slugs()->generate('Fatima Al-Zahra'));
    }

    public function test_generates_ascii_slug_for_arabic_name(): void
    {
        $slug = $this->slugs()->generate('محمد');

        $this->assertNotSame('', $slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
        $this->assertTrue($this->slugs()->isValidFormat($slug));
    }

    public function test_falls_back_when_name_has_no_usable_characters(): void
    {
        $this->assertSame('mysite', $this->slugs()->generate(''));
        $this->assertSame('mysite', $this->slugs()->generate('!!!'));
    }

    public function test_appends_sequential_suffix_on_collision(): void
    {
        Tenant::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Mohmmad', 'slug' => 'mohmmad', 'email' => 'a@test.local', 'status' => 'active',
        ]);
        Tenant::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Mohmmad 2', 'slug' => 'mohmmad-2', 'email' => 'b@test.local', 'status' => 'active',
        ]);

        $this->assertSame('mohmmad-3', $this->slugs()->generate('MOHMMAD'));
    }

    public function test_reserved_names_are_never_generated(): void
    {
        $this->assertTrue($this->slugs()->isReserved('admin'));
        $this->assertFalse($this->slugs()->available('admin'));

        $slug = $this->slugs()->generate('admin');
        $this->assertSame('admin-2', $slug);
        $this->assertTrue($this->slugs()->available($slug));
    }

    public function test_create_tenant_uses_clean_slug_then_suffixes(): void
    {
        $first = $this->slugs()->createTenant([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Zaid Platform', 'email' => 'z1@test.local',
        ], 'Zaid');

        $second = $this->slugs()->createTenant([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Zaid Platform', 'email' => 'z2@test.local',
        ], 'Zaid');

        $this->assertSame('zaid', $first->slug);
        $this->assertSame('zaid-2', $second->slug);
    }

    /* ── فحص التوفّر عبر الواجهة ─────────────────────────── */

    public function test_teacher_can_check_slug_availability(): void
    {
        [$tenant, $teacher] = $this->teacherTenant();

        $res = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->getJson('/api/v1/teacher/domain/slug-available?slug=my-new-name');

        $res->assertOk();
        $this->assertTrue($res->json('available'));
        $this->assertNull($res->json('reason'));
        $this->assertSame('my-new-name', $res->json('slug'));
    }

    public function test_availability_reports_taken_reserved_and_invalid(): void
    {
        [$tenant, $teacher] = $this->teacherTenant();
        $headers = $this->authHeaders($teacher, $tenant);

        Tenant::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Other', 'slug' => 'taken-name', 'email' => 'c@test.local', 'status' => 'active',
        ]);

        $taken = $this->withHeaders($headers)->getJson('/api/v1/teacher/domain/slug-available?slug=taken-name');
        $taken->assertOk();
        $this->assertFalse($taken->json('available'));
        $this->assertSame('taken', $taken->json('reason'));

        $reserved = $this->withHeaders($headers)->getJson('/api/v1/teacher/domain/slug-available?slug=admin');
        $this->assertFalse($reserved->json('available'));
        $this->assertSame('reserved', $reserved->json('reason'));

        $invalid = $this->withHeaders($headers)->getJson('/api/v1/teacher/domain/slug-available?slug=ab%21');
        $this->assertFalse($invalid->json('available'));
        $this->assertSame('invalid', $invalid->json('reason'));

        // الرابط الحالي للمعلّم يُعتبر متاحاً له (ليس مأخوذاً ضده)
        $own = $this->withHeaders($headers)->getJson('/api/v1/teacher/domain/slug-available?slug='.$tenant->slug);
        $this->assertTrue($own->json('is_current'));
        $this->assertTrue($own->json('available'));
    }

    /* ── الحفظ ───────────────────────────────────────────── */

    public function test_teacher_can_update_slug_to_an_available_name(): void
    {
        [$tenant, $teacher] = $this->teacherTenant();

        $res = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->putJson('/api/v1/teacher/domain/slug', ['slug' => '  My-Clean-Name  ']);

        $res->assertOk();
        $this->assertSame('my-clean-name', $res->json('slug'));
        $this->assertSame('my-clean-name', $tenant->fresh()->slug);
    }

    public function test_update_rejects_taken_and_reserved_and_invalid(): void
    {
        [$tenant, $teacher] = $this->teacherTenant();
        $headers = $this->authHeaders($teacher, $tenant);

        Tenant::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Other', 'slug' => 'already-taken', 'email' => 'd@test.local', 'status' => 'active',
        ]);

        $original = $tenant->slug;

        $this->withHeaders($headers)->putJson('/api/v1/teacher/domain/slug', ['slug' => 'already-taken'])->assertStatus(422);
        $this->withHeaders($headers)->putJson('/api/v1/teacher/domain/slug', ['slug' => 'admin'])->assertStatus(422);
        $this->withHeaders($headers)->putJson('/api/v1/teacher/domain/slug', ['slug' => 'a'])->assertStatus(422);

        $this->assertSame($original, $tenant->fresh()->slug, 'الرابط لا يجب أن يتغير عند رفض الطلب');
    }

    public function test_update_with_same_slug_is_a_noop(): void
    {
        [$tenant, $teacher] = $this->teacherTenant();

        $res = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->putJson('/api/v1/teacher/domain/slug', ['slug' => $tenant->slug]);

        $res->assertOk();
        $this->assertSame($tenant->slug, $res->json('slug'));
    }

    public function test_show_exposes_slug_and_platform_urls(): void
    {
        [$tenant, $teacher] = $this->teacherTenant();

        $res = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->getJson('/api/v1/teacher/domain');

        $res->assertOk();
        $this->assertSame($tenant->slug, $res->json('slug'));
        $this->assertStringContainsString($tenant->slug, (string) $res->json('subdomain'));
        $this->assertStringContainsString($tenant->slug, (string) $res->json('page_url'));
        $this->assertNotEmpty($res->json('platform_domain'));
    }

    public function test_student_cannot_change_slug(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant, 'trialing');
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student', 'active');

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->putJson('/api/v1/teacher/domain/slug', ['slug' => 'student-hack'])
            ->assertStatus(403);
    }
}
