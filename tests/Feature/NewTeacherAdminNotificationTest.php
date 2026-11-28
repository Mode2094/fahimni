<?php

namespace Tests\Feature;

use App\Jobs\NotifyAdminNewTeacherJob;
use App\Mail\NewTeacherAdminMail;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NewTeacherNotifier;
use App\Services\TeacherPlatformStatsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Spatie\Permission\Models\Role;
use Symfony\Component\Uid\UuidV7;

/**
 * The Super-Admin "a new teacher joined" email.
 *
 * Pins the whole contract:
 *  - exactly one email per successful teacher registration (email/password and
 *    Google), never for students, never a second time on a retry;
 *  - the numbers come from the real database and count TEACHER accounts only;
 *  - the existing `notify_new_teacher` platform switch is honoured;
 *  - the email is branded (RTL + فهمني identity) and carries the real data.
 */
class NewTeacherAdminNotificationTest extends TenantTestCase
{
    private const ORIGIN = 'https://fahimni.me';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function freshEmail(string $prefix = 'teacher'): string
    {
        return $prefix.'-'.strtolower(Str::random(8)).'@test.local';
    }

    /**
     * Pin `sanctum.stateful` to the production list; the test default includes
     * `localhost`, which would mask session-auth behaviour on the real domain.
     */
    private function useProductionStatefulDomains(): void
    {
        config(['sanctum.stateful' => ['fahimni.me', 'www.fahimni.me']]);
    }

    private function enableTeacherRegistration(): void
    {
        Role::findOrCreate('Teacher', 'web');
        PlatformSetting::set('teacher_registration_enabled', true);
    }

    private function makePlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'id' => UuidV7::generate(),
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
    }

    private function makeSuperAdmin(?string $email = null): User
    {
        $admin = $this->makeUser(['email' => $email ?? $this->freshEmail('admin')]);
        $admin->forceFill(['is_super_admin' => true, 'is_active' => true])->save();

        return $admin;
    }

    private function registerTeacher(string $email, string $name = 'أستاذ جديد')
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => $name,
            'email' => $email,
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'Teacher',
            'phone' => '0791234567',
        ]);
    }

    /** An existing teacher account (tenant membership + role), for counting. */
    private function seedTeacher(?string $createdAt = null): User
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'teacher');

        if ($createdAt) {
            DB::table('users')->where('id', $user->id)->update(['created_at' => $createdAt]);
        }

        return $user;
    }

    private function seedStudent(): User
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'student');

        return $user;
    }

    // ───────────────────────── email/password signup ─────────────────────────

    public function test_registering_a_teacher_emails_the_super_admin_once_with_the_live_count(): void
    {
        Mail::fake();
        $this->enableTeacherRegistration();
        $this->makePlan();
        PlatformSetting::set('platform_name', 'منصة فهمني');
        $admin = $this->makeSuperAdmin('admin@fahimni.test');

        $email = $this->freshEmail();
        $this->registerTeacher($email, 'أستاذ جديد')->assertStatus(201);

        Mail::assertSent(NewTeacherAdminMail::class, 1);
        Mail::assertSent(NewTeacherAdminMail::class, function (NewTeacherAdminMail $mail) use ($admin, $email) {
            return $mail->hasTo($admin->email)
                && $mail->teacherEmail === $email
                && $mail->teacherName === 'أستاذ جديد'
                && $mail->stats['total'] === 1
                && $mail->stats['today'] === 1;
        });

        $mail = Mail::sent(NewTeacherAdminMail::class)->first();

        // Subject follows the approved wording with the real headline number.
        $this->assertStringContainsString('معلم جديد انضم إلى فهمني', $mail->envelope()->subject);
        $this->assertStringContainsString('1 معلمًا', $mail->envelope()->subject);

        // Branded, RTL, Arabic, with the real teacher + platform data.
        $mail->assertSeeInHtml('dir="rtl"', false);
        $mail->assertSeeInHtml('#0d9488', false);
        $mail->assertSeeInHtml('أستاذ جديد');
        $mail->assertSeeInHtml($email);
        $mail->assertSeeInHtml('اسم المنصة التعليمية');
        $mail->assertSeeInHtml('تجربة مجانية');
        $mail->assertSeeInHtml('إجمالي المعلمين المسجلين');
    }

    public function test_the_count_excludes_students_and_counts_only_teachers(): void
    {
        Mail::fake();
        $this->enableTeacherRegistration();
        $this->makePlan();
        $this->makeSuperAdmin();

        $this->seedTeacher();
        $this->seedTeacher();
        $this->seedStudent();
        $this->seedStudent();
        $this->seedStudent();

        $this->registerTeacher($this->freshEmail())->assertStatus(201);

        $mail = Mail::sent(NewTeacherAdminMail::class)->first();
        $this->assertSame(3, $mail->stats['total'], 'only the 2 existing teachers + the new one must be counted');
    }

    public function test_a_retry_or_duplicate_dispatch_never_sends_a_second_email(): void
    {
        Mail::fake();
        $this->enableTeacherRegistration();
        $this->makePlan();
        $this->makeSuperAdmin();

        $email = $this->freshEmail();
        $this->registerTeacher($email)->assertStatus(201);

        $teacher = User::where('email', $email)->firstOrFail();
        $tenantId = $teacher->tenantPivots()->value('tenant_id');

        // Same registration replayed: directly and via the queue job.
        app(NewTeacherNotifier::class)->announce($teacher, $tenantId, true);
        NotifyAdminNewTeacherJob::dispatch($teacher->id, $tenantId, true);

        Mail::assertSent(NewTeacherAdminMail::class, 1);
    }

    public function test_student_registration_never_notifies_the_admin(): void
    {
        Mail::fake();
        PlatformSetting::set('registration_enabled', true);
        $this->makeSuperAdmin();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'طالب جديد',
            'email' => $this->freshEmail('student'),
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'Student',
        ])->assertStatus(201);

        Mail::assertNotSent(NewTeacherAdminMail::class);
    }

    public function test_the_existing_notify_new_teacher_switch_disables_the_email(): void
    {
        Mail::fake();
        $this->enableTeacherRegistration();
        $this->makePlan();
        $this->makeSuperAdmin();
        PlatformSetting::set('notify_new_teacher', false);

        $this->registerTeacher($this->freshEmail())->assertStatus(201);

        Mail::assertNotSent(NewTeacherAdminMail::class);
    }

    // ───────────────────────────── stats numbers ────────────────────────────

    public function test_today_and_month_counts_use_jordan_days_and_ignore_students(): void
    {
        $stats = app(TeacherPlatformStatsService::class);

        $this->seedTeacher(); // created now → today
        $this->seedTeacher(now()->startOfMonth()->subDays(10)); // previous month
        $this->seedStudent();

        $snapshot = $stats->snapshot();

        $this->assertSame(2, $snapshot['total']);
        $this->assertSame(1, $snapshot['today']);
        $this->assertSame(1, $snapshot['this_month']);
    }

    public function test_paid_subscribers_count_active_subscriptions_only(): void
    {
        $paidTenant = $this->makeTenant();
        $paid = $this->attachSubscription($paidTenant, 'active');
        $paid->forceFill(['ends_at' => now()->addMonth()])->save();

        $trialTenant = $this->makeTenant();
        $this->attachSubscription($trialTenant, 'trialing');

        $snapshot = app(TeacherPlatformStatsService::class)->snapshot();

        $this->assertSame(1, $snapshot['paid_subscribers'], 'a trialing subscription is not a paid subscriber');
    }

    // ───────────────────────────── Google signup ────────────────────────────

    private function fakeGoogle(string $email, string $name = 'معلّم جوجل'): void
    {
        $googleUser = Mockery::mock(SocialiteUser::class);
        $googleUser->shouldReceive('getEmail')->andReturn($email);
        $googleUser->shouldReceive('getName')->andReturn($name);
        $googleUser->shouldReceive('getNickname')->andReturn('nick');
        $googleUser->shouldReceive('getId')->andReturn('1234567890');

        $provider = Mockery::mock(SocialiteProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function signedState(array $overrides = []): string
    {
        $payload = array_merge([
            'role' => 'teacher',
            'name' => 'معلّم جوجل',
            'phone' => '+962790000000',
            'exp' => time() + 600,
            'n' => Str::random(16),
        ], $overrides);

        $encoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        return $encoded.'.'.hash_hmac('sha256', $encoded, (string) config('app.key'));
    }

    private function callbackWith(string $state)
    {
        return $this->get('/api/v1/auth/google/callback?state='.urlencode($state).'&code=fake-code');
    }

    private function sessionCookie($response): string
    {
        $cookie = $response->getCookie((string) config('session.cookie'));
        $this->assertNotNull($cookie, 'Expected the session cookie to be set.');

        return (string) $this->app['session']->getId();
    }

    private function asCookie(string $cookie)
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->withCredentials()
            ->withCookie((string) config('session.cookie'), $cookie)
            ->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json']);
    }

    public function test_a_new_google_teacher_notifies_the_admin_exactly_once(): void
    {
        Mail::fake();
        $this->useProductionStatefulDomains();
        $this->enableTeacherRegistration();
        $this->makePlan();
        $this->makeSuperAdmin();

        $email = $this->freshEmail('gteacher');
        $this->fakeGoogle($email);

        $this->callbackWith($this->signedState(['role' => 'teacher']))->assertRedirect();

        Mail::assertSent(NewTeacherAdminMail::class, 1);

        // A repeat Google login is the same account — no second email.
        $this->callbackWith($this->signedState(['role' => 'teacher']))->assertRedirect();
        Mail::assertSent(NewTeacherAdminMail::class, 1);
    }

    public function test_completing_a_google_teachers_profile_notifies_once(): void
    {
        Mail::fake();
        $this->useProductionStatefulDomains();
        $this->enableTeacherRegistration();
        $this->makePlan();
        $this->makeSuperAdmin();

        $email = $this->freshEmail('gteacher');
        $this->fakeGoogle($email);

        // Sign up without a phone: no workspace yet, so no email yet.
        $response = $this->callbackWith($this->signedState(['role' => 'teacher', 'phone' => null]));
        $cookie = $this->sessionCookie($response);
        Mail::assertNotSent(NewTeacherAdminMail::class);

        $this->asCookie($cookie)->postJson('/api/v1/auth/complete-profile', [
            'name' => 'معلّم جوجل',
            'phone' => '0791234567',
        ])->assertOk();

        Mail::assertSent(NewTeacherAdminMail::class, 1);

        // Re-finishing the profile is idempotent — still a single email.
        $this->asCookie($cookie)->postJson('/api/v1/auth/complete-profile', [
            'name' => 'معلّم جوجل',
            'phone' => '+962791234567',
        ])->assertOk();

        Mail::assertSent(NewTeacherAdminMail::class, 1);
    }
}
