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
 * Remind tenants 3, 2 and 1 day(s) before their subscription expires.
 *
 * Every reminder is sent in-app AND by email. Each offset fires exactly
 * once per subscription (dedup via metadata.pre_expiry_reminders_sent).
 * Tenants with a saved card are told the card will be charged
 * automatically; everyone else gets a manual renewal link.
 */
class RenewalReminderJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** @var int[] */
    private const OFFSETS = [3, 2, 1];

    public function handle(): void
    {
        $frontend = rtrim(config('app.frontend_url') ?: config('app.url'), '/');

        foreach (self::OFFSETS as $days) {
            $target = now()->addDays($days)->toDateString();

            $subs = TenantSubscription::with('plan')
                ->where('status', 'active')
                ->whereNotNull('ends_at')
                ->whereDate('ends_at', $target)
                // Freemius owns renewal, dunning and its own reminder emails
                // for Freemius-managed subscriptions — never double-remind.
                ->where(function ($q) {
                    $q->whereNull('billing_source')->orWhere('billing_source', '!=', 'freemius');
                })
                ->whereNull('fs_license_id')
                ->get();

            foreach ($subs as $sub) {
                $meta = $sub->metadata ?? [];
                $sent = $meta['pre_expiry_reminders_sent'] ?? [];

                if (in_array($days, $sent, true)) {
                    continue; // already reminded for this offset.
                }

                $tenant = $sub->tenant;
                $plan = $sub->plan;
                $hasSavedCard = $tenant !== null
                    && (string) ($tenant->payment_token ?? '') !== ''
                    && $sub->auto_renew;

                if ($hasSavedCard) {
                    $title = 'سيتم تجديد اشتراكك تلقائياً بعد '.$days.' '.($days === 1 ? 'يوم' : 'أيام');
                    $body = 'اشتراك خطة "'.($plan?->name ?? '').'" ينتهي بتاريخ '.$sub->ends_at?->toDateString()
                        .'. سيتم خصم قيمة التجديد تلقائياً من بطاقتك المحفوظة'
                        .($tenant->card_last_four ? ' المنتهية بـ '.$tenant->card_last_four : '')
                        .'. لا حاجة لأي إجراء منك.';
                } else {
                    $title = 'اشتراكك ينتهي بعد '.$days.' '.($days === 1 ? 'يوم' : 'أيام');
                    $body = 'اشتراك خطة "'.($plan?->name ?? '').'" ينتهي بتاريخ '.$sub->ends_at?->toDateString()
                        .'. جدّد الآن لتجنب انقطاع الخدمة، وعلّم خيار التجديد التلقائي أثناء الدفع لخصم البطاقة تلقائياً في المرات القادمة.';
                }

                try {
                    app(NotificationService::class)->dispatch(
                        $this->owners($sub->tenant_id),
                        'subscription.renewal_reminder',
                        $title,
                        $body,
                        null,
                        $sub->tenant_id,
                        [
                            'subscription_id' => $sub->id,
                            'days_remaining' => $days,
                            'renew_url' => $frontend.'/checkout?plan='.($sub->plan_id ?? ''),
                        ],
                        ['database', 'mail'],
                    );
                } catch (\Throwable) {
                    // best effort
                }

                $sent[] = $days;
                $meta['pre_expiry_reminders_sent'] = array_values(array_unique($sent));
                $sub->update(['metadata' => $meta]);
            }
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
