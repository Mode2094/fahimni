<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\User;
use App\Plans\PlanEngine;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

class ExpireSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $now = now();
        $changed = 0;

        // ─── Expired paid subscriptions ────────────────────────────────
        // auto_renew tenants enter past_due (features locked, dashboard
        // prompts to pay) and get a renewal invoice; everyone else is
        // simply expired.
        $expiredActive = TenantSubscription::where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $now)
            ->get();

        foreach ($expiredActive as $sub) {
            // ── Freemius-owned subscriptions ───────────────────────────
            // Freemius is the Merchant of Record for platform billing:
            // renewal, dunning (failed payments), expiration and access are
            // managed by Freemius and reflected via license webhooks. The
            // local scheduler must NEVER flip or auto-charge a Freemius
            // subscription while Freemius still owns the billing cycle —
            // that would desync the two and could cut access mid-term.
            if ($sub->billing_source === 'freemius' || ! empty(($sub->metadata ?? [])['freemius'])) {
                continue;
            }

            // Any other (legacy, pre-cutover) provider subscription is also
            // left untouched here: the cutover is complete and legacy rows
            // are archived. Only flip status when there is genuinely no
            // external billing owner.
            if (! empty(($sub->metadata ?? [])['lemon_subscription_id'])
                || ! empty(($sub->metadata ?? [])['paddle_subscription_id'])) {
                continue;
            }

            DB::transaction(function () use ($sub, $now) {
                $sub->update([
                    'status' => $sub->auto_renew ? 'past_due' : 'expired',
                    'auto_renew' => false,
                ]);

                if ($sub->wasChanged('status') && $sub->status === 'past_due') {
                    $this->createRenewalInvoice($sub, $now);
                }
            });
            $changed++;
        }

        // ─── Expired trials ─────────────────────────────────────────────
        $changed += TenantSubscription::where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', $now)
            ->update(['status' => 'expired', 'auto_renew' => false]);

        // Bulk query updates bypass model observers — bump the plan cache
        // version so expired tenants lose their features immediately.
        if ($changed > 0) {
            PlanEngine::bumpVersion();
        }
    }

    /**
     * A pending renewal payment makes the renewal visible in the tenant's
     * invoices/payments and in the admin payments list.
     */
    private function createRenewalInvoice(TenantSubscription $sub, Carbon $now): void
    {
        $plan = $sub->plan;

        if (! $plan) {
            return;
        }

        $amount = $sub->billing_cycle === 'yearly'
            ? (float) $plan->yearly_price
            : (float) $plan->monthly_price;

        Payment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $sub->tenant_id,
            'tenant_subscription_id' => $sub->id,
            'transaction_id' => 'renewal-'.str()->uuid(),
            'gateway' => 'pending',
            'amount' => $amount,
            'currency' => $plan->currency ?? 'JOD',
            'status' => 'pending',
            'payment_method' => 'renewal',
            'gateway_data' => ['renewal' => true, 'billing_cycle' => $sub->billing_cycle],
        ]);

        $this->notifyStaff($sub);
    }

    private function notifyStaff(TenantSubscription $sub): void
    {
        try {
            $frontend = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
            $plan = $sub->plan;

            $userIds = TenantUser::where('tenant_id', $sub->tenant_id)
                ->whereIn('role', ['owner', 'admin'])
                ->where('status', 'active')
                ->pluck('user_id');

            $users = User::whereIn('id', $userIds)->where('is_active', true)->get();

            foreach ($users as $user) {
                app(NotificationService::class)->notify(
                    notifiable: $user,
                    type: 'subscription.past_due',
                    title: 'Your subscription has ended',
                    body: 'Renew now to keep your platform running: '.($plan?->name ?? 'your plan').'.',
                    tenantId: $sub->tenant_id,
                    data: [
                        'subscription_id' => $sub->id,
                        'renew_url' => $frontend.'/checkout?plan='.($plan?->id ?? ''),
                    ],
                );
            }
        } catch (\Throwable) {
            // Notification failures must never break the expiry job.
        }
    }
}
