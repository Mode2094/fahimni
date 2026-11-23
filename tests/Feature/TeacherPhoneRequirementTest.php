<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * The mandatory teacher profile (full name + phone) is enforced at every
 * account-creation path — not only in the UI:
 *  - e-mail/password registration (role=Teacher);
 *  - admin-created teachers;
 *  - the Google completion endpoint;
 * and it is visible to authorized admins in the Teachers section.
 *
 * Students are deliberately unaffected: their phone stays optional.
 */
class TeacherPhoneRequirementTest extends TenantTestCase
{
    private const ORIGIN = 'https://fahimni.me';

    private function freshEmail(string $prefix = 'teacher'): string
    {
        return $prefix.'-'.strtolower(Str::random(8)).'@test.local';
    }

    private function enableTeacherRegistration(): void
    {
        Role::findOrCreate('Teacher', 'web');
        PlatformSetting::set('teacher_registration_enabled', true);
    }

    private function asAdmin(User $admin, ?string $tenantId = null)
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $headers = ['Origin' => self::ORIGIN, 'Accept' => 'application/json'];
        if ($tenantId) {
            $headers['X-Tenant-Id'] = $tenantId;
        }

        return $this->withHeader('Authorization', 'Bearer '.$admin->createToken('test')->plainTextToken)
            ->withHeaders($headers);
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create([
            'email' => $this->freshEmail('admin'),
            'password' => Hash::make('admin-123'),
            'is_active' => true,
        ]);
        $admin->forceFill(['is_super_admin' => true, 'is_active' => true])->save();

        return $admin;
    }

    // ───────────────────── e-mail/password registration ─────────────────────

    public function test_teacher_registration_without_a_phone_is_rejected(): void
    {
        $this->enableTeacherRegistration();
        $email = $this->freshEmail();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'No Phone',
            'email' => $email,
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'Teacher',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->assertSame(0, User::where('email', $email)->count(), 'a rejected signup creates no account');
    }

    public function test_teacher_registration_with_an_invalid_phone_is_rejected(): void
    {
        $this->enableTeacherRegistration();
        $email = $this->freshEmail();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Bad Phone',
            'email' => $email,
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'Teacher',
            'phone' => '12',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->assertSame(0, User::where('email', $email)->count());
    }

    public function test_teacher_registration_stores_a_national_number_as_e164(): void
    {
        $this->enableTeacherRegistration();
        $email = $this->freshEmail();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'National Phone',
            'email' => $email,
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'Teacher',
            'phone' => '0791234567',
        ]);

        $response->assertStatus(201);

        // One canonical stored format; the Jordan dial code is the default.
        $this->assertSame('+962791234567', User::where('email', $email)->first()->phone);
    }

    public function test_student_registration_still_allows_no_phone(): void
    {
        $email = $this->freshEmail('student');

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Student No Phone',
            'email' => $email,
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ]);

        $response->assertStatus(201);
        $this->assertNull(User::where('email', $email)->first()->phone);
    }

    // ───────────────────────── admin-created teachers ───────────────────────

    public function test_admin_cannot_create_a_teacher_without_a_phone(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->makeAdmin();

        $this->asAdmin($admin, $tenant->id)->postJson('/api/v1/admin/teachers', [
            'name' => 'Admin Made',
            'email' => $this->freshEmail(),
        ])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_admin_created_teacher_stores_a_canonical_phone(): void
    {
        Role::findOrCreate('Teacher', 'web');
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->makeAdmin();
        $email = $this->freshEmail();

        $this->asAdmin($admin, $tenant->id)->postJson('/api/v1/admin/teachers', [
            'name' => 'Admin Made',
            'email' => $email,
            'phone' => '0790000000',
        ])->assertStatus(201);

        $this->assertSame('+962790000000', User::where('email', $email)->first()->phone);
    }

    // ─────────────────────── admin Teachers visibility ──────────────────────

    private function makeTeacher(array $attrs = []): User
    {
        Role::findOrCreate('Teacher', 'web');
        $teacher = User::factory()->create(array_merge([
            'email' => $this->freshEmail(),
            'is_active' => true,
        ], $attrs));
        $teacher->assignRole('Teacher');

        return $teacher;
    }

    public function test_admin_teachers_list_exposes_name_phone_email_and_last_activity(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->makeAdmin();

        $teacher = $this->makeTeacher([
            'name' => 'Visible Teacher',
            'phone' => '+962791112223',
            'last_login_at' => now(),
        ]);

        $res = $this->asAdmin($admin, $tenant->id)->getJson('/api/v1/admin/teachers')->assertOk();

        $row = collect($res->json('teachers'))->firstWhere('id', $teacher->id);
        $this->assertNotNull($row, 'the teacher must be listed');
        $this->assertSame('Visible Teacher', $row['name']);
        $this->assertSame($teacher->email, $row['email']);
        $this->assertSame('+962791112223', $row['phone']);
        $this->assertNotNull($row['last_login_at']);
        $this->assertTrue($row['is_active']);
    }

    public function test_admin_can_search_teachers_by_phone_even_though_it_is_encrypted(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->makeAdmin();

        $teacher = $this->makeTeacher([
            'name' => 'Searchable',
            'phone' => '+962799988776',
        ]);

        $res = $this->asAdmin($admin, $tenant->id)
            ->getJson('/api/v1/admin/teachers?search=9998877')
            ->assertOk();

        $found = collect($res->json('teachers'))->firstWhere('id', $teacher->id);
        $this->assertNotNull($found, 'a phone substring must find the teacher');
    }

    public function test_admin_can_search_teachers_by_name_and_email(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->makeAdmin();

        $teacher = $this->makeTeacher([
            'name' => 'Uniquename Zaid',
            'phone' => '+962790000001',
        ]);

        $byName = $this->asAdmin($admin, $tenant->id)
            ->getJson('/api/v1/admin/teachers?search=Uniquename')
            ->assertOk();
        $this->assertNotNull(collect($byName->json('teachers'))->firstWhere('id', $teacher->id));

        $byEmail = $this->asAdmin($admin, $tenant->id)
            ->getJson('/api/v1/admin/teachers?search='.urlencode($teacher->email))
            ->assertOk();
        $this->assertNotNull(collect($byEmail->json('teachers'))->firstWhere('id', $teacher->id));
    }

    public function test_non_admin_cannot_read_teachers(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);

        $outsider = $this->makeUser(['is_active' => true]);
        $this->attachToTenant($outsider, $tenant, 'student');

        $this->withHeader('Authorization', 'Bearer '.$outsider->createToken('test')->plainTextToken)
            ->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json', 'X-Tenant-Id' => $tenant->id])
            ->getJson('/api/v1/admin/teachers')
            ->assertStatus(403);
    }

    // ─────────────────── completion endpoint (legacy accounts) ──────────────

    public function test_completing_a_legacy_teacher_profile_never_restarts_the_trial(): void
    {
        $tenant = $this->makeTenant();
        $subscription = $this->attachSubscription($tenant);

        $teacher = $this->makeTeacher(['name' => 'Legacy', 'phone' => null]);
        $this->attachToTenant($teacher, $tenant, 'owner');

        $trialEndsAt = $subscription->trial_ends_at?->timestamp;

        $this->withHeader('Authorization', 'Bearer '.$teacher->createToken('test')->plainTextToken)
            ->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json'])
            ->postJson('/api/v1/auth/complete-profile', [
                'name' => 'Legacy Completed',
                'phone' => '0791112222',
            ])
            ->assertOk()
            ->assertJsonPath('user.phone', '+962791112222')
            ->assertJsonPath('trial_started', false);

        // Same workspace, same trial — nothing duplicated, nothing restarted.
        $this->assertSame(1, Tenant::whereKey($tenant->id)->count());
        $this->assertSame(1, TenantSubscription::withoutTenant()->where('tenant_id', $tenant->id)->count());
        $this->assertSame($trialEndsAt, TenantSubscription::withoutTenant()->find($subscription->id)->trial_ends_at?->timestamp);

        $teacher->refresh();
        $this->assertSame('Legacy Completed', $teacher->name);
        $this->assertSame('+962791112222', $teacher->phone);
    }

    public function test_completing_a_profile_requires_a_valid_phone(): void
    {
        $teacher = $this->makeTeacher(['phone' => null]);

        $this->withHeader('Authorization', 'Bearer '.$teacher->createToken('test')->plainTextToken)
            ->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json'])
            ->postJson('/api/v1/auth/complete-profile', ['name' => 'X', 'phone' => 'nope'])
            ->assertStatus(422);

        $this->assertNull($teacher->fresh()->phone);
    }

    public function test_teacher_without_phone_cannot_edit_the_profile_around_the_gate(): void
    {
        $teacher = $this->makeTeacher(['phone' => null]);

        $this->withHeader('Authorization', 'Bearer '.$teacher->createToken('test')->plainTextToken)
            ->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json'])
            ->putJson('/api/v1/user/profile', ['name' => 'Sneaky', 'phone' => '0791234567'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'profile_incomplete');

        $this->assertNull($teacher->fresh()->phone, 'the bypass must not persist anything');
    }

    // ───────────────────── Quran teacher request (another path) ─────────────

    public function test_quran_teacher_request_requires_a_phone(): void
    {
        $this->postJson('/api/v1/quran-teacher-requests', [
            'name' => 'Quran Teacher',
            'email' => $this->freshEmail('quran'),
            'country' => 'JO',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_quran_teacher_request_stores_a_canonical_phone(): void
    {
        $email = $this->freshEmail('quran');

        $this->postJson('/api/v1/quran-teacher-requests', [
            'name' => 'Quran Teacher',
            'email' => $email,
            'country' => 'JO',
            'phone' => '0791234567',
        ])->assertStatus(201);

        $this->assertDatabaseHas('quran_teacher_requests', [
            'email' => $email,
            'phone' => '+962791234567',
        ]);
    }
}
