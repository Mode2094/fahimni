<?php

namespace App\Observers;

use App\Models\TenantSubscription;
use App\Models\User;
use App\Plans\PlanEngine;
use App\Services\NotificationService;

class TenantSubscriptionObserver
{
    public function __construct(
        private readonly NotificationService $notifier,
    ) {}

    public function created(TenantSubscription $subscription): void
    {
        PlanEngine::bumpVersion();
    }

    public function updated(TenantSubscription $subscription): void
    {
        // Subscription changes (upgrade/downgrade/expiry/renewal) invalidate
        // the plan cache immediately.
        PlanEngine::bumpVersion();

        if ($subscription->wasChanged('status') && in_array($subscription->status, ['active', 'trialing'])) {
            $plan = $subscription->plan;
            $tenant = $subscription->tenant;

            if (! $plan || ! $tenant) {
                return;
            }

            $users = User::whereIn('id', function ($q) use ($tenant) {
                $q->select('user_id')
                    ->from('tenant_user')
                    ->where('tenant_id', $tenant->id)
                    ->whereIn('role', ['Teacher', 'Super Admin']);
            })->get();

            foreach ($users as $user) {
                $isAr = ($user->locale ?? 'ar') === 'ar';

                $this->notifier->notify(
                    notifiable: $user,
                    type: 'subscription.activated',
                    title: $isAr ? "تم تفعيل اشتراكك: {$plan->name}" : "Subscription activated: {$plan->name}",
                    body: $isAr ? "خطة {$plan->name} أصبحت نشطة الآن." : "Your {$plan->name} plan subscription is now active.",
                    tenantId: $subscription->tenant_id,
                    data: [
                        'subscription_id' => $subscription->id,
                        'plan_id' => $plan->id,
                        'plan_name' => $plan->name,
                    ],
                );
            }
        }
    }
}
