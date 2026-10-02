<?php

namespace App\Jobs;

use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily reminder while a subscription is past_due/expired inside the
 * 10-day grace window: counts down the days until educational content is
 * deleted. One reminder per day per subscription
 * (dedup via metadata.last_expiry_reminder_date).
 */
class ExpiryFollowUpJob implements ShouldQueue
{
    use Dispatchable, Queueable;

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

        foreach ($subs as $sub) {
            $meta = $sub->metadata ?? [];

            if (($meta['last_expiry_reminder_date'] ?? null) === $today) {
                continue; // already reminded today.
            }

            $daysLeft = max(0, self::GRACE_DAYS - now()->diffInDays($sub->ends_at));
            $planName = $sub->plan?->name ?? '';

            $title = 'تنبيه: '.($daysLeft <= 0
                ? 'سيتم حذف المحتوى التعليمي قريباً'
                : 'متبقي '.$daysLeft.' '.($daysLeft === 1 ? 'يوم' : 'أيام').' قبل حذف المحتوى التعليمي');

            $body = 'انتهى اشتراك خطة "'.$planName.'" بتاريخ '.$sub->ends_at?->toDateString()
                .'. إذا لم يتم التجديد خلال '.self::GRACE_DAYS.' أيام من تاريخ الانتهاء سيتم حذف الدورات والامتحانات والواجبات والإعلانات والجلسات المباشرة نهائياً.'
                .' جدّد الآن للحفاظ على كل محتواك وبيانات طلابك.';

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
                        'days_left_before_deletion' => $daysLeft,
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
