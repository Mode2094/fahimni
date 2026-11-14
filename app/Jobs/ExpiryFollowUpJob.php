<?php

namespace App\Jobs;

use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\User;
use App\Plans\SubscriptionState;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily renewal reminder for a tenant whose paid period has ended and has not
 * been renewed.
 *
 * IMPORTANT: this job NEVER threatens content deletion. Teacher/student
 * content is never deleted on expiry (see PurgeExpiredTenantContentJob, which
 * is disabled by policy). The message invites the tenant to renew to keep
 * receiving service — nothing more.
 *
 * The tenant's effective state is derived from SubscriptionState (the single
 * source of truth), so a tenant that has meanwhile become active via a
 * Freemius entitlement (or any current subscription) is never reminded and
 * stale legacy rows cannot trigger a false reminder.
 *
 * One reminder per day per tenant (dedup via metadata.last_expiry_reminder_date
 * on the referenced subscription row).
 */
class ExpiryFollowUpJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** Grace window (days after expiry) during which we keep reminding. */
    public const GRACE_DAYS = 10;

    public function handle(): void
    {
        $frontend = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
        $today = now()->toDateString();

        $subs = TenantSubscription::with('plan')
            ->whereIn('status', ['past_due', 'expired'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '>=', now()->subDays(self::GRACE_DAYS))
            ->get();

        $state = SubscriptionState::make();

        foreach ($subs as $sub) {
            // Re-derive the EFFECTIVE state: if the tenant is active again
            // (e.g. renewed via Freemius), never remind.
            if ($state->isActive((string) $sub->tenant_id)) {
                continue;
            }

            $meta = $sub->metadata ?? [];

            if (($meta['last_expiry_reminder_date'] ?? null) === $today) {
                continue; // already reminded today.
            }

            $daysSinceExpiry = $sub->ends_at ? now()->diffInDays($sub->ends_at) : 0;
            $planName = $sub->plan?->name ?? '';

            $title = 'اشتراكك منتهٍ — جدّد لاستمرار الخدمة';
            $body = 'انتهى اشتراك خطة "'.$planName.'" بتاريخ '.$sub->ends_at?->toDateString().'.'
                .' محتواك وبيانات طلابك محفوظة بالكامل ولن تُحذف.'
                .' جدّد الآن لاستعادة الوصول الكامل إلى لوحة التحكم وميزات خطتك.';

            try {
                app(NotificationService::class)->dispatch(
                    $this->owners($sub->tenant_id),
                    'subscription.expiry_followup',
                    $title,
                    $body,
                    null,
                    $sub->tenant_id,
                    [
                        'subscription_id' => $sub->id,
                        'days_since_expiry' => $daysSinceExpiry,
                        'renew_url' => $frontend.'/checkout?plan='.($sub->plan_id ?? ''),
                    ],
                    ['database', 'mail'],
                );
            } catch (\Throwable) {
                // best effort
            }

            $meta['last_expiry_reminder_date'] = $today;
            $sub->update(['metadata' => $meta]);
        }
    }

    private function owners(string $tenantId): \Illuminate\Support\Collection
    {
        $userIds = TenantUser::where('tenant_id', $tenantId)
            ->whereIn('role', ['owner', 'admin'])
            ->where('status', 'active')
            ->pluck('user_id');

        return User::whereIn('id', $userIds)->where('is_active', true)->get();
    }
}
