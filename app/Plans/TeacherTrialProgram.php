<?php

namespace App\Plans;

use App\Models\PlatformCounter;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Models\TrialGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Central controller for the teacher free trial and the limited "first N
 * teachers" offer.
 *
 * The admin master switch `teacher_trial_enabled` is the single source of
 * truth: when it is OFF no code path may grant a trial (email/password,
 * Google OAuth, API, or old links), and every marketing surface must hide
 * trial copy. No other setting can override it.
 *
 * The limited offer adds a *second* gate on top: a real, DB-backed seat
 * counter. A seat is consumed only when an actual eligible teacher receives
 * a trial. Concurrent registrations race for the last seat through a single
 * locked counter row, so the limit can never be exceeded.
 *
 * All reads go through PlatformSetting (Redis-shared, 60s TTL, invalidated on
 * write) and PlatformCounter, so every container/device sees the same state.
 */
class TeacherTrialProgram
{
    /** Admin master switch — the highest-priority decision. */
    public const KEY_ENABLED = 'teacher_trial_enabled';

    /** Limited-offer banner enable/disable. */
    public const KEY_OFFER_ENABLED = 'teacher_trial_offer_enabled';

    /** Total seats for the limited offer (default 50). */
    public const KEY_OFFER_SEATS = 'teacher_trial_offer_seats';

    /** Admin-editable offer text (supports {seats} / {remaining} / {days}). */
    public const KEY_OFFER_TEXT = 'teacher_trial_offer_text';

    /** Shared counter key for consumed limited-offer seats. */
    public const COUNTER_KEY = 'teacher_trial_offer_used';

    private const DEFAULT_OFFER_SEATS = 50;

    private const DEFAULT_TRIAL_DAYS = 7;

    public const DEFAULT_OFFER_TEXT = '🎁 تجربة مجانية لمدة {days} أيام لأول {seats} معلمًا — الأماكن المتبقية: {remaining}';

    /**
     * The admin master switch. Defaults to ON so behaviour is unchanged until
     * an admin explicitly turns trials off.
     */
    public function masterEnabled(): bool
    {
        return PlatformSetting::bool(self::KEY_ENABLED, true);
    }

    public function offerEnabled(): bool
    {
        return PlatformSetting::bool(self::KEY_OFFER_ENABLED, false);
    }

    public function offerSeatsTotal(): int
    {
        return max(1, (int) PlatformSetting::get(self::KEY_OFFER_SEATS, self::DEFAULT_OFFER_SEATS));
    }

    public function offerText(): string
    {
        $text = PlatformSetting::get(self::KEY_OFFER_TEXT);

        return is_string($text) && trim($text) !== '' ? $text : self::DEFAULT_OFFER_TEXT;
    }

    /** Offer text with {seats}/{remaining}/{days} substituted. */
    public function renderOfferText(): string
    {
        return str_replace(
            ['{seats}', '{remaining}', '{days}'],
            [(string) $this->offerSeatsTotal(), (string) $this->seatsRemaining(), (string) $this->trialDays()],
            $this->offerText(),
        );
    }

    public function seatsUsed(): int
    {
        return PlatformCounter::valueOf(self::COUNTER_KEY);
    }

    public function seatsRemaining(): int
    {
        return max(0, $this->offerSeatsTotal() - $this->seatsUsed());
    }

    /**
     * Whether a *new* teacher may start a free trial right now.
     *
     * Master OFF ⇒ never. Offer enabled ⇒ only while seats remain. Master ON
     * with the offer disabled ⇒ trials are open (no seat limit).
     */
    public function trialAvailable(): bool
    {
        if (! $this->masterEnabled()) {
            return false;
        }

        return ! $this->offerEnabled() || $this->seatsRemaining() > 0;
    }

    /** Cheapest active plan — the one new teachers trial on. */
    public function trialPlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('is_active', true)
            ->orderBy('monthly_price')
            ->first();
    }

    /** Effective trial length (days) — from the cheapest active plan. */
    public function trialDays(): int
    {
        $days = (int) ($this->trialPlan()?->trial_days ?? 0);

        return max(1, $days ?: self::DEFAULT_TRIAL_DAYS);
    }

    /**
     * Public payload consumed by the frontend (home, register, pricing).
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'master_enabled' => $this->masterEnabled(),
            'offer_enabled' => $this->offerEnabled(),
            'offer_seats_total' => $this->offerSeatsTotal(),
            'offer_seats_used' => $this->seatsUsed(),
            'offer_seats_remaining' => $this->seatsRemaining(),
            'offer_text' => $this->offerText(),
            'offer_text_rendered' => $this->renderOfferText(),
            'trial_available' => $this->trialAvailable(),
            'trial_days' => $this->trialDays(),
        ];
    }

    /**
     * Grant a free trial to a teacher, atomically and at most once per user.
     *
     * Returns the created subscription, or null when no trial may be granted
     * (master switch OFF, seats exhausted, no active plan, or the user already
     * consumed a trial). Callers must NOT create a subscription themselves —
     * a null result is the correct "pending_payment" / no-subscription state.
     *
     * @param  bool  $consumeSeat  real teacher signup paths pass true; admin /
     *                             test provisioning passes false so it never
     *                             consumes a limited-offer seat.
     */
    public function grantTrial(
        User $user,
        ?string $tenantId,
        string $source = 'register',
        bool $consumeSeat = true,
    ): ?TenantSubscription {
        if (! $this->masterEnabled()) {
            return null;
        }

        $plan = $this->trialPlan();

        if (! $plan) {
            return null;
        }

        // One trial per account, ever — enforced here and by the UNIQUE
        // constraint on trial_grants.user_id as the DB-level backstop.
        if (TrialGrant::where('user_id', $user->id)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($user, $tenantId, $source, $consumeSeat, $plan) {
            $reserved = false;

            if ($consumeSeat && $this->offerEnabled()) {
                $reserved = PlatformCounter::tryReserve(self::COUNTER_KEY, $this->offerSeatsTotal());

                if (! $reserved) {
                    return null; // seats exhausted
                }
            }

            try {
                $subscription = TenantSubscription::create([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $tenantId,
                    'plan_id' => $plan->id,
                    'status' => 'trialing',
                    'billing_cycle' => 'monthly',
                    'starts_at' => now(),
                    'trial_ends_at' => now()->addDays($this->trialDays()),
                    'auto_renew' => true,
                ]);

                TrialGrant::create([
                    'id' => UuidV7::generate(),
                    'user_id' => $user->id,
                    'tenant_id' => $tenantId,
                    'source' => $source,
                    'counted' => $reserved,
                    'granted_at' => now(),
                ]);
            } catch (\Throwable $e) {
                if ($reserved) {
                    PlatformCounter::release(self::COUNTER_KEY);
                }

                throw $e;
            }

            return $subscription;
        });
    }
}
