<?php

namespace Tests\Feature;

use App\Models\PlatformCounter;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TrialGrant;
use App\Models\User;
use App\Plans\TeacherTrialProgram;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Uid\UuidV7;

/**
 * The teacher free-trial program is the platform's only trial gate.
 *
 * These tests pin the two hard rules the business depends on:
 *  1. the admin master switch is absolute — OFF means no path grants a trial
 *     and no marketing surface may claim one;
 *  2. the limited "first N teachers" offer never over-grants: a real DB seat is
 *     consumed at most once per eligible teacher and the count can never exceed
 *     the configured total, even for the very last seat.
 */
class TeacherTrialProgramTest extends TenantTestCase
{
    private function program(): TeacherTrialProgram
    {
        return app(TeacherTrialProgram::class);
    }

    /** An active plan so `trialPlan()` resolves and trials can be granted. */
    private function makePlan(int $trialDays = 3, float $price = 19.99): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'id' => UuidV7::generate(),
            'name' => 'Basic',
            'slug' => 'basic-'.Str::random(6),
            'monthly_price' => $price,
            'yearly_price' => $price * 10,
            'currency' => 'USD',
            'trial_days' => $trialDays,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);
    }

    private function enableOffer(int $seats): void
    {
        PlatformSetting::set(TeacherTrialProgram::KEY_OFFER_ENABLED, true);
        PlatformSetting::set(TeacherTrialProgram::KEY_OFFER_SEATS, $seats);
    }

    private function disableOffer(): void
    {
        PlatformSetting::set(TeacherTrialProgram::KEY_OFFER_ENABLED, false);
    }

    private function seedTeacherRole(): void
    {
        Role::findOrCreate('Teacher', 'web');
    }

    // ───────────────────────── service-level rules ─────────────────────────

    public function test_master_on_with_the_offer_disabled_grants_a_trial_without_consuming_a_seat(): void
    {
        $this->makePlan();
        $this->disableOffer();
        $tenant = $this->makeTenant();
        $user = $this->makeUser();

        $sub = $this->program()->grantTrial($user, $tenant->id, 'register', true);

        $this->assertInstanceOf(TenantSubscription::class, $sub, 'a trial must be granted while the master switch is ON');
        $this->assertSame('trialing', $sub->status);
        $this->assertNotNull($sub->trial_ends_at);
        // The trial length is platform-owned (default 7), NOT the plan's
        // trial_days (which is 3 here, mirroring production).
        $this->assertEqualsWithDelta(7, $sub->trial_ends_at->diffInDays(now(), true), 0.5);

        // The seat counter only moves while the limited offer is on.
        $this->assertSame(0, $this->program()->seatsUsed(), 'the offer counter must stay untouched when the offer is disabled');
        $this->assertFalse((bool) TrialGrant::where('user_id', $user->id)->first()->counted);
    }

    public function test_trial_length_is_platform_owned_and_configurable(): void
    {
        // The plan's own trial_days must never dictate the teacher trial.
        $this->makePlan(trialDays: 3);
        $this->disableOffer();
        $this->assertSame(7, $this->program()->trialDays(), 'the default trial length is 7 days');

        PlatformSetting::set(TeacherTrialProgram::KEY_TRIAL_DAYS, 14);
        $this->assertSame(14, $this->program()->trialDays());

        $tenant = $this->makeTenant();
        $sub = $this->program()->grantTrial($this->makeUser(), $tenant->id);

        $this->assertEqualsWithDelta(14, $sub->trial_ends_at->diffInDays(now(), true), 0.5);
    }

    public function test_master_on_with_the_offer_enabled_consumes_exactly_one_real_seat(): void
    {
        $this->makePlan();
        $this->enableOffer(2);
        $tenant = $this->makeTenant();
        $user = $this->makeUser();

        $sub = $this->program()->grantTrial($user, $tenant->id, 'register', true);

        $this->assertNotNull($sub);
        $this->assertSame(1, $this->program()->seatsUsed());
        $this->assertSame(1, $this->program()->seatsRemaining());
        $this->assertTrue((bool) TrialGrant::where('user_id', $user->id)->first()->counted);
    }

    public function test_seats_exhausted_grants_nothing(): void
    {
        $this->makePlan();
        $this->enableOffer(1);

        $first = $this->program()->grantTrial($this->makeUser(), $this->makeTenant()->id);
        $this->assertNotNull($first);

        $secondUser = $this->makeUser();
        $second = $this->program()->grantTrial($secondUser, $this->makeTenant()->id);

        $this->assertNull($second, 'the second teacher must not get a trial once the offer is exhausted');
        $this->assertSame(1, $this->program()->seatsUsed());
        $this->assertSame(0, $this->program()->seatsRemaining());
        $this->assertFalse($this->program()->trialAvailable());
        $this->assertFalse(TrialGrant::where('user_id', $secondUser->id)->exists());
    }

    public function test_master_off_grants_nothing_even_with_seats_remaining(): void
    {
        $this->makePlan();
        $this->enableOffer(50);
        PlatformSetting::set(TeacherTrialProgram::KEY_ENABLED, false);

        $tenant = $this->makeTenant();
        $user = $this->makeUser();

        $sub = $this->program()->grantTrial($user, $tenant->id, 'register', true);

        $this->assertNull($sub, 'the master switch must veto every trial grant');
        $this->assertFalse($this->program()->trialAvailable());
        $this->assertSame(0, TenantSubscription::withoutTenant()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, $this->program()->seatsUsed());
        $this->assertFalse(TrialGrant::where('user_id', $user->id)->exists());
    }

    public function test_the_master_switch_overrides_an_enabled_offer_with_remaining_seats(): void
    {
        $this->makePlan();
        $this->enableOffer(50); // plenty of seats…
        PlatformSetting::set(TeacherTrialProgram::KEY_ENABLED, false); // …but the master switch is OFF

        $this->assertFalse($this->program()->trialAvailable());
        $this->assertGreaterThan(0, $this->program()->seatsRemaining());
    }

    public function test_one_account_never_receives_a_second_trial_or_a_second_seat(): void
    {
        $this->makePlan();
        $this->enableOffer(5);
        $tenant = $this->makeTenant();
        $user = $this->makeUser();

        $first = $this->program()->grantTrial($user, $tenant->id, 'register', true);
        $second = $this->program()->grantTrial($user, $tenant->id, 'register', true);

        $this->assertNotNull($first);
        $this->assertNull($second, 'a second trial for the same account must be refused');
        $this->assertSame(1, TrialGrant::where('user_id', $user->id)->count());
        $this->assertSame(1, $this->program()->seatsUsed());
    }

    public function test_last_seat_is_enforced_by_the_atomic_reservation(): void
    {
        $this->enableOffer(1);

        // The reservation primitive is what makes concurrent signups safe: once
        // the single seat is taken, every further reservation returns false.
        $this->assertTrue(PlatformCounter::tryReserve(TeacherTrialProgram::COUNTER_KEY, 1));
        $this->assertFalse(PlatformCounter::tryReserve(TeacherTrialProgram::COUNTER_KEY, 1));
        $this->assertSame(1, $this->program()->seatsUsed());
    }

    public function test_summary_tracks_the_live_counter_and_availability(): void
    {
        $this->makePlan();
        $this->enableOffer(3);

        $this->program()->grantTrial($this->makeUser(), $this->makeTenant()->id);
        $this->program()->grantTrial($this->makeUser(), $this->makeTenant()->id);

        $summary = $this->program()->summary();
        $this->assertSame(3, $summary['offer_seats_total']);
        $this->assertSame(2, $summary['offer_seats_used']);
        $this->assertSame(1, $summary['offer_seats_remaining']);
        $this->assertTrue($summary['trial_available']);
        $this->assertStringContainsString('1', $summary['offer_text_rendered']);

        // Filling the last seat flips availability off with no admin action.
        $this->program()->grantTrial($this->makeUser(), $this->makeTenant()->id);
        $filled = $this->program()->summary();
        $this->assertSame(0, $filled['offer_seats_remaining']);
        $this->assertFalse($filled['trial_available']);
    }

    // ─────────────────────── HTTP register path ────────────────────────────

    private function registerTeacher(string $email): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => 'Teacher '.Str::random(4),
            'email' => $email,
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'Teacher',
            // Every teacher account now requires a valid phone number.
            'phone' => '+9627'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        ]);
    }

    public function test_register_starts_a_trial_and_consumes_a_seat_when_available(): void
    {
        $this->seedTeacherRole();
        PlatformSetting::set('teacher_registration_enabled', true);
        $this->makePlan();
        $this->enableOffer(50);

        $response = $this->registerTeacher('trial-'.Str::lower(Str::random(8)).'@test.local');

        $response->assertStatus(201);
        $response->assertJsonPath('trial_started', true);

        $tenantId = $response->json('tenant_id');
        $this->assertNotNull($tenantId);
        $this->assertSame(1, TenantSubscription::withoutTenant()->where('tenant_id', $tenantId)->where('status', 'trialing')->count());
        $this->assertSame(1, $this->program()->seatsUsed());
    }

    public function test_register_creates_a_pending_payment_account_when_the_master_switch_is_off(): void
    {
        $this->seedTeacherRole();
        PlatformSetting::set('teacher_registration_enabled', true);
        $this->makePlan();
        $this->enableOffer(50);
        PlatformSetting::set(TeacherTrialProgram::KEY_ENABLED, false);

        $response = $this->registerTeacher('pending-'.Str::lower(Str::random(8)).'@test.local');

        $response->assertStatus(201);
        $response->assertJsonPath('trial_started', false);

        $tenantId = $response->json('tenant_id');
        $this->assertNotNull($tenantId);
        // No trial subscription is created → the account is "pending payment".
        $this->assertSame(0, TenantSubscription::withoutTenant()->where('tenant_id', $tenantId)->count());
        $this->assertSame(0, $this->program()->seatsUsed());
    }

    public function test_register_refuses_a_trial_once_the_last_seat_is_taken(): void
    {
        $this->seedTeacherRole();
        PlatformSetting::set('teacher_registration_enabled', true);
        $this->makePlan();
        $this->enableOffer(1);

        $first = $this->registerTeacher('first-'.Str::lower(Str::random(8)).'@test.local');
        $first->assertStatus(201);
        $first->assertJsonPath('trial_started', true);

        $second = $this->registerTeacher('second-'.Str::lower(Str::random(8)).'@test.local');
        $second->assertStatus(201);
        $second->assertJsonPath('trial_started', false);

        $this->assertSame(1, $this->program()->seatsUsed(), 'the offer must never over-grant across registrations');
    }

    // ───────────────────── admin settings API ──────────────────────────────

    public function test_admin_master_switch_toggles_and_reports_live_stats(): void
    {
        $this->makePlan();
        $admin = $this->makeUser(['is_super_admin' => true]);
        $headers = [
            'Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];

        // Turn the master switch OFF while keeping the offer ON with seats left.
        $this->withHeaders($headers)
            ->putJson('/api/v1/platform-settings', [
                'teacher_trial_enabled' => false,
                'teacher_trial_offer_enabled' => true,
                'teacher_trial_offer_seats' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('settings.teacher_trial_enabled', false)
            ->assertJsonPath('settings.teacher_trial_offer_enabled', true)
            ->assertJsonPath('settings.teacher_trial_offer_seats', 10)
            // The offer has seats, yet the master switch wins.
            ->assertJsonPath('settings.trial_available', false)
            ->assertJsonPath('settings.teacher_trial_offer_seats_remaining', 10)
            ->assertJsonPath('settings.teacher_trial_days', 7);

        // The trial length is platform-owned and admin-editable.
        $this->withHeaders($headers)
            ->putJson('/api/v1/platform-settings', ['teacher_trial_days' => 10])
            ->assertOk()
            ->assertJsonPath('settings.trial_days', 10);

        // The public payload agrees — no surface may advertise a trial.
        $this->getJson('/api/v1/platform-settings')
            ->assertOk()
            ->assertJsonPath('settings.trial_available', false);

        // Re-enabling makes trials available again.
        $this->withHeaders($headers)
            ->putJson('/api/v1/platform-settings', ['teacher_trial_enabled' => true])
            ->assertOk()
            ->assertJsonPath('settings.teacher_trial_enabled', true)
            ->assertJsonPath('settings.trial_available', true);
    }

    public function test_non_admin_cannot_change_the_master_switch(): void
    {
        $user = $this->makeUser(['is_super_admin' => false]);
        $headers = [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];

        $this->withHeaders($headers)
            ->putJson('/api/v1/platform-settings', ['teacher_trial_enabled' => false])
            ->assertStatus(403);
    }
}
