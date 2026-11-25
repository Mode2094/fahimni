<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FsEntitlement;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Plans\SubscriptionState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One platform-wide source for Super Admin dashboard, teacher and subscription
 * metrics. Every tenant-owned query explicitly removes the current tenant
 * scope; request headers and browser storage can never narrow this snapshot.
 */
class SuperAdminStatisticsService
{
    private const PLATFORM_CURRENCY = 'USD';

    public function __construct(
        private readonly FreemiusPaymentSummaryService $freemiusPayments,
    ) {}

    /**
     * @return array{
     *     overview: array{total_tenants: int, total_users: int, total_students: int, total_courses: int},
     *     subscription_metrics: array<string, mixed>,
     *     teacher_ids_by_state: array<string, list<string>>
     * }
     */
    public function snapshot(): array
    {
        $now = now();
        $teachers = User::role('Teacher')->get(['users.id', 'users.is_active']);
        $teacherIds = $teachers->map(fn (User $teacher) => (string) $teacher->id)->all();
        $teacherInfo = $teachers->mapWithKeys(fn (User $teacher) => [
            (string) $teacher->id => ['is_active' => (bool) $teacher->is_active],
        ])->all();

        $tenants = Tenant::query()
            ->where('status', 'active')
            ->get(['id', 'owner_id']);
        $tenantIds = $tenants->map(fn (Tenant $tenant) => (string) $tenant->id)->all();
        $tenantTeacherIds = $this->tenantTeacherIds($tenants, $teacherIds);

        $effectiveStates = SubscriptionState::make()->resolveMany($tenantIds);
        $subscriptionRows = TenantSubscription::withoutTenant()
            ->whereIn('tenant_id', $tenantIds)
            ->get([
                'id', 'tenant_id', 'plan_id', 'status', 'billing_cycle',
                'starts_at', 'ends_at', 'trial_ends_at', 'admin_ends_at',
                'billing_source', 'fs_license_id', 'metadata',
            ])
            ->groupBy(fn (TenantSubscription $subscription) => (string) $subscription->tenant_id);
        $entitlements = FsEntitlement::query()
            ->whereIn('tenant_id', $tenantIds)
            ->get()
            ->groupBy(fn (FsEntitlement $entitlement) => (string) $entitlement->tenant_id);

        [$subscriptionPayments, $confirmedLegacyPaymentIds] = $this->legacySubscriptionPayments();
        $plans = $this->plansFor(
            $subscriptionRows->flatten()->pluck('plan_id')
                ->merge($entitlements->flatten()->pluck('plan_id'))
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->all()
        );

        $paidTenantIds = [];
        $trialTenantIds = [];
        $adminTenantIds = [];
        $unverifiedTenantIds = [];
        $expiredTenantIds = [];
        $mrrByCurrency = [self::PLATFORM_CURRENCY => 0.0];
        $mrrUnpriced = 0;
        $mrrSubscriptions = [];
        $effectiveTeacherIds = [];

        foreach ($tenantIds as $tenantId) {
            $state = $effectiveStates[$tenantId] ?? null;
            $rows = $subscriptionRows->get($tenantId, collect());
            $tenantEntitlements = $entitlements->get($tenantId, collect());

            $paidFreemius = $tenantEntitlements->filter(fn (FsEntitlement $entitlement) => $entitlement->isActive() && ! $this->isFreemiusTrial($entitlement)
            );

            foreach ($paidFreemius as $entitlement) {
                $paidTenantIds[$tenantId] = true;

                // An entitlement is one billable license, regardless of its
                // local archival subscription mirror.
                $licenseKey = 'freemius:'.(string) $entitlement->fs_license_id;
                if (isset($mrrSubscriptions[$licenseKey])) {
                    continue;
                }
                $mrrSubscriptions[$licenseKey] = true;

                if ($entitlement->type === 'lifetime') {
                    continue; // Paid lifetime access has no recurring revenue.
                }

                $cycle = strtolower((string) ($entitlement->metadata['billing_cycle'] ?? ''));
                $this->addPlanMrr(
                    $mrrByCurrency,
                    $mrrUnpriced,
                    $plans->get((string) $entitlement->plan_id),
                    $cycle,
                );
            }

            $activeLegacyPayments = [];

            foreach ($rows as $subscription) {
                $status = $this->normalizeStatus((string) $subscription->status);

                if ($status === 'active'
                    && $this->isInCurrentPeriod($subscription, $now)
                    && $this->isLegacySubscription($subscription)
                    && isset($confirmedLegacyPaymentIds[(string) $subscription->id])) {
                    $activeLegacyPayments[] = $subscription;
                }

                if ($status === 'trialing'
                    && $this->isValidTrial($subscription, $now)
                    && ($state['source'] ?? null) === SubscriptionState::SOURCE_LEGACY
                    && ($state['subscription_id'] ?? null) === $subscription->id) {
                    $trialTenantIds[$tenantId] = true;
                }
            }

            // Freemius is canonical for its tenant. Never add a local legacy
            // row or mirror a second time when a live provider license exists.
            if ($paidFreemius->isEmpty()) {
                foreach ($activeLegacyPayments as $subscription) {
                    $paidTenantIds[$tenantId] = true;
                    $subscriptionKey = 'legacy:'.(string) $subscription->id;
                    if (isset($mrrSubscriptions[$subscriptionKey])) {
                        continue;
                    }
                    $mrrSubscriptions[$subscriptionKey] = true;

                    $this->addPlanMrr(
                        $mrrByCurrency,
                        $mrrUnpriced,
                        $plans->get((string) $subscription->plan_id),
                        strtolower((string) $subscription->billing_cycle),
                    );
                }
            }

            foreach ($tenantEntitlements as $entitlement) {
                if (! isset($paidTenantIds[$tenantId])
                    && $entitlement->isActive()
                    && $this->isFreemiusTrial($entitlement)) {
                    $trialTenantIds[$tenantId] = true;
                }
            }

            if (($state['source'] ?? SubscriptionState::SOURCE_NONE) === SubscriptionState::SOURCE_ADMIN) {
                $adminTenantIds[$tenantId] = true;
            } elseif (($state['source'] ?? null) === SubscriptionState::SOURCE_LEGACY
                && ($state['status'] ?? null) === 'active'
                && ! isset($paidTenantIds[$tenantId])) {
                // Access is effective, but there is no verified transaction
                // proving that this legacy active row is paid.
                $unverifiedTenantIds[$tenantId] = true;
            }

            if (($state['source'] ?? SubscriptionState::SOURCE_NONE) === SubscriptionState::SOURCE_NONE
                && $this->hasExpiredHistory($rows, $tenantEntitlements, $now)) {
                $expiredTenantIds[$tenantId] = true;
            }

            if (($state['source'] ?? SubscriptionState::SOURCE_NONE) !== SubscriptionState::SOURCE_NONE
                && isset($plans[(string) ($state['plan_id'] ?? '')])
                && ! $plans[(string) $state['plan_id']]->trashed()) {
                // Only enabled teacher accounts can currently use the tenant.
                foreach ($tenantTeacherIds[$tenantId] ?? [] as $teacherId) {
                    if ($teacherInfo[$teacherId]['is_active'] ?? false) {
                        $effectiveTeacherIds[$teacherId] = true;
                    }
                }
            }
        }

        $teacherIdsByState = $this->teacherIdsByState(
            $tenantTeacherIds,
            $teacherInfo,
            $paidTenantIds,
            $trialTenantIds,
            $adminTenantIds,
            $expiredTenantIds,
            $unverifiedTenantIds,
            $effectiveTeacherIds ?? [],
        );

        // An expired historical row does not make a teacher "expired" when
        // another current tenant still grants access.
        $effectiveTeacherSet = array_fill_keys($teacherIdsByState['effective'], true);
        $teacherIdsByState['expired'] = array_values(array_filter(
            $teacherIdsByState['expired'],
            fn (string $teacherId) => ! isset($effectiveTeacherSet[$teacherId]),
        ));
        $teacherSubscriptionById = $this->teacherSubscriptionSummaries(
            $teacherIds,
            $tenantTeacherIds,
            $effectiveStates,
            $subscriptionRows,
            $plans,
            $paidTenantIds,
            $trialTenantIds,
            $adminTenantIds,
            $expiredTenantIds,
            $unverifiedTenantIds,
        );

        $statusCounts = $this->subscriptionStatusCounts();
        $pendingCheckoutIntents = TenantSubscription::withoutTenant()
            ->whereRaw("LOWER(COALESCE(status, '')) = ?", ['pending'])
            ->where('billing_source', SubscriptionState::SOURCE_FREEMIUS)
            ->get(['metadata'])
            ->filter(fn (TenantSubscription $subscription) => filled($subscription->metadata['freemius_checkout_reference'] ?? null)
            )
            ->count();
        $financials = $this->financialSummary(
            $subscriptionPayments,
            $mrrByCurrency,
            $mrrUnpriced,
            $this->freemiusPayments->summary(),
            $pendingCheckoutIntents,
        );

        $metrics = [
            'total_teachers' => $teachers->count(),
            'active_accounts' => $teachers->where('is_active', true)->count(),
            'valid_trials' => count($teacherIdsByState['valid_trial']),
            'paid_subscribers' => count($teacherIdsByState['paid']),
            'admin_access' => count($teacherIdsByState['admin']),
            'expired_access' => count($teacherIdsByState['expired']),
            'effective_access' => count($teacherIdsByState['effective']),
            'unverified_access' => count($teacherIdsByState['unverified']),
            'pending_subscriptions' => (int) ($statusCounts['pending'] ?? 0),
            'historical_records' => TenantSubscription::withoutTenant()->count(),
            'record_status_counts' => $statusCounts,
            'financials' => $financials,
        ];

        return [
            'overview' => [
                'total_tenants' => Tenant::query()->count(),
                'total_users' => User::query()->count(),
                'total_students' => Enrollment::withoutTenant()->distinct('user_id')->count('user_id'),
                'total_courses' => Course::withoutTenant()->count(),
            ],
            'subscription_metrics' => $metrics,
            'teacher_ids_by_state' => $teacherIdsByState,
            'teacher_subscription_by_id' => $teacherSubscriptionById,
        ];
    }

    /**
     * @param  iterable<Tenant>  $tenants
     * @param  list<string>  $teacherIds
     * @return array<string, list<string>>
     */
    private function tenantTeacherIds(iterable $tenants, array $teacherIds): array
    {
        $teacherSet = array_fill_keys($teacherIds, true);
        $tenantTeacherIds = [];
        $tenantIds = [];

        foreach ($tenants as $tenant) {
            $tenantId = (string) $tenant->id;
            $tenantIds[] = $tenantId;
            $tenantTeacherIds[$tenantId] = [];

            if ($tenant->owner_id && isset($teacherSet[(string) $tenant->owner_id])) {
                $tenantTeacherIds[$tenantId][] = (string) $tenant->owner_id;
            }
        }

        if ($teacherIds !== [] && $tenantIds !== []) {
            $memberships = DB::table('tenant_user')
                ->join('tenants', 'tenants.id', '=', 'tenant_user.tenant_id')
                ->whereIn('tenant_user.tenant_id', $tenantIds)
                ->whereIn('tenant_user.user_id', $teacherIds)
                ->where('tenant_user.status', 'active')
                ->whereIn('tenant_user.role', ['owner', 'teacher', 'admin'])
                ->where('tenants.status', 'active')
                ->whereNull('tenants.deleted_at')
                ->get(['tenant_user.tenant_id', 'tenant_user.user_id']);

            foreach ($memberships as $membership) {
                $tenantTeacherIds[(string) $membership->tenant_id][] = (string) $membership->user_id;
            }
        }

        foreach ($tenantTeacherIds as $tenantId => $ids) {
            $tenantTeacherIds[$tenantId] = array_values(array_unique($ids));
        }

        return $tenantTeacherIds;
    }

    /**
     * @param  list<string>  $planIds
     * @return Collection<string, SubscriptionPlan>
     */
    private function plansFor(array $planIds)
    {
        return SubscriptionPlan::withTrashed()
            ->whereIn('id', $planIds)
            ->get()
            ->keyBy(fn (SubscriptionPlan $plan) => (string) $plan->id);
    }

    /**
     * Read local payments only when they are tied to a legacy platform
     * subscription. Booking/course settlements and Freemius mirrors are not
     * platform revenue.
     *
     * @return array{0: Collection<int, object>, 1: array<string, bool>}
     */
    private function legacySubscriptionPayments(): array
    {
        $payments = DB::table('payments as p')
            ->join('tenant_subscriptions as s', 's.id', '=', 'p.tenant_subscription_id')
            ->whereNotNull('p.tenant_subscription_id')
            ->whereNull('p.booking_id')
            ->whereNull('p.fs_license_id')
            ->whereNull('s.fs_license_id')
            ->where(function ($query) {
                $query->whereNull('s.billing_source')->orWhereRaw('LOWER(s.billing_source) <> ?', ['freemius']);
            })
            ->whereNotIn(DB::raw("LOWER(COALESCE(p.gateway, ''))"), ['freemius', 'fake', 'card'])
            ->whereRaw("LOWER(COALESCE(p.status, '')) IN ('completed', 'refunded', 'pending')")
            ->get([
                'p.id',
                'p.tenant_subscription_id',
                'p.tenant_id',
                'p.transaction_id',
                'p.amount',
                'p.currency',
                'p.status',
                'p.paid_at',
            ]);

        $confirmedSubscriptionIds = [];

        foreach ($payments as $payment) {
            if (strtolower((string) $payment->status) === 'completed'
                && $payment->paid_at !== null
                && trim((string) $payment->transaction_id) !== '') {
                $confirmedSubscriptionIds[(string) $payment->tenant_subscription_id] = true;
            }
        }

        return [$payments, $confirmedSubscriptionIds];
    }

    private function isLegacySubscription(TenantSubscription $subscription): bool
    {
        return $subscription->billing_source !== SubscriptionState::SOURCE_FREEMIUS
            && $subscription->fs_license_id === null;
    }

    private function isValidTrial(TenantSubscription $subscription, Carbon $now): bool
    {
        return $this->normalizeStatus((string) $subscription->status) === 'trialing'
            && $subscription->trial_ends_at !== null
            && $subscription->trial_ends_at->gt($now)
            && ($subscription->starts_at === null || $subscription->starts_at->lte($now))
            && $this->isLegacySubscription($subscription);
    }

    private function isInCurrentPeriod(TenantSubscription $subscription, Carbon $now): bool
    {
        return ($subscription->starts_at === null || $subscription->starts_at->lte($now))
            && ($subscription->ends_at === null || $subscription->ends_at->gt($now));
    }

    private function isFreemiusTrial(FsEntitlement $entitlement): bool
    {
        $subscriptionStatus = strtolower((string) ($entitlement->metadata['subscription_status'] ?? ''));

        return in_array($subscriptionStatus, ['trial', 'trialing', 'free_trial'], true)
            || in_array(strtolower((string) $entitlement->status), ['trial', 'trialing', 'free_trial'], true);
    }

    private function hasExpiredHistory($rows, $entitlements, Carbon $now): bool
    {
        foreach ($rows as $subscription) {
            $status = $this->normalizeStatus((string) $subscription->status);

            if (in_array($status, ['expired', 'past_due', 'canceled'], true)) {
                return true;
            }
            if ($status === 'active' && $subscription->ends_at && $subscription->ends_at->lte($now)) {
                return true;
            }
            if ($status === 'trialing'
                && ($subscription->trial_ends_at === null || $subscription->trial_ends_at->lte($now))) {
                return true;
            }
            if ($subscription->admin_ends_at && $subscription->admin_ends_at->lte($now)) {
                return true;
            }
        }

        foreach ($entitlements as $entitlement) {
            if (! $entitlement->isActive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve each teacher to exactly ONE subscription state so the counters
     * form a partition: a teacher who owns/teaches in several workspaces (e.g. a
     * paid tenant and a trial tenant) is never counted in more than one bucket.
     * The priority mirrors {@see teacherSubscriptionSummaries()} so the counters
     * and the per-teacher badges always agree.
     *
     * @param  array<string, bool>  $paidTenantIds
     * @param  array<string, bool>  $trialTenantIds
     * @param  array<string, bool>  $adminTenantIds
     * @param  array<string, bool>  $expiredTenantIds
     * @param  array<string, bool>  $unverifiedTenantIds
     * @param  array<string, bool>  $effectiveTeacherIds
     * @return array<string, list<string>>
     */
    private function teacherIdsByState(
        array $tenantTeacherIds,
        array $teacherInfo,
        array $paidTenantIds,
        array $trialTenantIds,
        array $adminTenantIds,
        array $expiredTenantIds,
        array $unverifiedTenantIds,
        array $effectiveTeacherIds,
    ): array {
        // Higher rank wins; only one state is retained per teacher.
        $priority = [
            'paid' => 6,
            'admin' => 5,
            'valid_trial' => 4,
            'unverified' => 3,
            'expired' => 2,
        ];

        $best = [];            // teacherId => ['state' => string, 'rank' => int]
        $effective = [];       // teacherId => true
        $activeAccount = [];   // teacherId => true

        foreach ($tenantTeacherIds as $tenantId => $teacherIds) {
            $tenantState = null;
            $rank = 0;

            if (isset($paidTenantIds[$tenantId])) {
                $tenantState = 'paid';
                $rank = $priority['paid'];
            } elseif (isset($adminTenantIds[$tenantId])) {
                $tenantState = 'admin';
                $rank = $priority['admin'];
            } elseif (isset($trialTenantIds[$tenantId])) {
                $tenantState = 'valid_trial';
                $rank = $priority['valid_trial'];
            } elseif (isset($unverifiedTenantIds[$tenantId])) {
                $tenantState = 'unverified';
                $rank = $priority['unverified'];
            } elseif (isset($expiredTenantIds[$tenantId])) {
                $tenantState = 'expired';
                $rank = $priority['expired'];
            }

            foreach ($teacherIds as $teacherId) {
                if (! isset($teacherInfo[$teacherId])) {
                    continue;
                }
                if ($teacherInfo[$teacherId]['is_active']) {
                    $activeAccount[$teacherId] = true;
                }
                if (isset($effectiveTeacherIds[$teacherId])) {
                    $effective[$teacherId] = true;
                }
                if ($tenantState !== null && (! isset($best[$teacherId]) || $rank > $best[$teacherId]['rank'])) {
                    $best[$teacherId] = ['state' => $tenantState, 'rank' => $rank];
                }
            }
        }

        $states = [
            'paid' => [],
            'valid_trial' => [],
            'admin' => [],
            'expired' => [],
            'unverified' => [],
            'effective' => [],
            'active_account' => [],
        ];

        foreach ($best as $teacherId => $info) {
            $states[$info['state']][] = (string) $teacherId;
        }

        $states['effective'] = array_keys($effective);
        $states['active_account'] = array_keys($activeAccount);

        return $states;
    }

    /**
     * A teacher may own or teach in several workspaces. Pick one display state
     * with a stable priority while keeping the counters themselves distinct.
     *
     * @param  list<string>  $teacherIds
     * @param  array<string, list<string>>  $tenantTeacherIds
     * @param  array<string, array<string, mixed>>  $effectiveStates
     * @param  Collection<string, Collection<int, TenantSubscription>>  $subscriptionRows
     * @param  Collection<string, SubscriptionPlan>  $plans
     * @param  array<string, bool>  $paidTenantIds
     * @param  array<string, bool>  $trialTenantIds
     * @param  array<string, bool>  $adminTenantIds
     * @param  array<string, bool>  $expiredTenantIds
     * @param  array<string, bool>  $unverifiedTenantIds
     * @return array<string, array<string, mixed>>
     */
    private function teacherSubscriptionSummaries(
        array $teacherIds,
        array $tenantTeacherIds,
        array $effectiveStates,
        $subscriptionRows,
        $plans,
        array $paidTenantIds,
        array $trialTenantIds,
        array $adminTenantIds,
        array $expiredTenantIds,
        array $unverifiedTenantIds,
    ): array {
        $priority = [
            'paid' => 6,
            'admin' => 5,
            'valid_trial' => 4,
            'unverified' => 3,
            'expired' => 2,
            'pending' => 1,
            'none' => 0,
        ];
        $summaries = [];

        foreach ($teacherIds as $teacherId) {
            $summaries[$teacherId] = [
                'state' => 'none',
                'source' => SubscriptionState::SOURCE_NONE,
                'status' => null,
                'plan_name' => null,
                'billing_cycle' => null,
                'trial_ends_at' => null,
                'ends_at' => null,
            ];
        }

        foreach ($tenantTeacherIds as $tenantId => $teacherIdsForTenant) {
            $tenantState = $effectiveStates[$tenantId] ?? [];
            $rows = $subscriptionRows->get($tenantId, collect());
            $hasPendingRow = $rows->contains(
                fn (TenantSubscription $subscription) => $this->normalizeStatus((string) $subscription->status) === 'pending'
            );

            foreach ($teacherIdsForTenant as $teacherId) {
                $displayState = match (true) {
                    isset($paidTenantIds[$tenantId]) => 'paid',
                    isset($adminTenantIds[$tenantId]) => 'admin',
                    isset($trialTenantIds[$tenantId]) => 'valid_trial',
                    isset($unverifiedTenantIds[$tenantId]) => 'unverified',
                    isset($expiredTenantIds[$tenantId]) => 'expired',
                    default => 'none',
                };

                if ($displayState === 'none' && $hasPendingRow) {
                    $displayState = 'pending';
                }

                $current = $summaries[$teacherId];
                if (($priority[$displayState] ?? 0) < ($priority[$current['state']] ?? 0)) {
                    continue;
                }

                $planId = (string) ($tenantState['plan_id'] ?? '');
                $trialEnd = $tenantState['trial_ends_at'] ?? null;
                if ($displayState === 'valid_trial' && $trialEnd === null) {
                    // Freemius uses license expiration as the trial end.
                    $trialEnd = $tenantState['ends_at'] ?? null;
                }

                $summaries[$teacherId] = [
                    'state' => $displayState,
                    'source' => $tenantState['source'] ?? SubscriptionState::SOURCE_NONE,
                    'status' => $tenantState['status'] ?? null,
                    'plan_name' => $plans->get($planId)?->name,
                    'billing_cycle' => $tenantState['billing_cycle'] ?? null,
                    'trial_ends_at' => $trialEnd?->toIso8601String(),
                    'ends_at' => ($tenantState['ends_at'] ?? null)?->toIso8601String(),
                ];
            }
        }

        return $summaries;
    }

    private function addPlanMrr(
        array &$mrrByCurrency,
        int &$unpricedCount,
        ?SubscriptionPlan $plan,
        string $cycle,
    ): void {
        if (! $plan || $plan->trashed()) {
            $unpricedCount++;

            return;
        }

        $currency = strtoupper((string) ($plan->currency ?: self::PLATFORM_CURRENCY));
        $normalizedCycle = strtolower($cycle);

        if (in_array($normalizedCycle, ['yearly', 'annual', 'annually', '12'], true)) {
            if ($plan->yearly_price === null) {
                $unpricedCount++;

                return;
            }
            $amount = (float) $plan->yearly_price / 12;
        } elseif (in_array($normalizedCycle, ['monthly', 'month', '1', ''], true)) {
            if ($plan->monthly_price === null) {
                $unpricedCount++;

                return;
            }
            $amount = (float) $plan->monthly_price;
        } else {
            $unpricedCount++;

            return;
        }

        $this->addCurrencyAmount($mrrByCurrency, $currency, $amount);
    }

    private function financialSummary(
        $localPayments,
        array $mrrByCurrency,
        int $mrrUnpriced,
        array $provider,
        int $pendingCheckoutIntents,
    ): array {
        $localGross = [];
        $localRefunds = [];
        $pendingAmounts = [self::PLATFORM_CURRENCY => 0.0];
        $pendingPaymentCount = 0;

        foreach ($localPayments as $payment) {
            $currency = strtoupper((string) ($payment->currency ?: self::PLATFORM_CURRENCY));
            $status = $this->normalizeStatus((string) $payment->status);
            $amount = round((float) $payment->amount, 2);

            if ($status === 'pending') {
                $pendingPaymentCount++;
                $this->addCurrencyAmount($pendingAmounts, $currency, $amount);

                continue;
            }

            if ($payment->paid_at === null || trim((string) $payment->transaction_id) === '') {
                continue;
            }

            // A refunded row still represents money that was collected first;
            // refunds are reported separately instead of erasing gross sales.
            $this->addCurrencyAmount($localGross, $currency, $amount);
            if ($status === 'refunded') {
                $this->addCurrencyAmount($localRefunds, $currency, $amount);
            }
        }

        $providerAvailable = ($provider['status'] ?? null) === 'available'
            && ($provider['complete'] ?? false) === true;

        $grossCollected = null;
        $refunds = null;
        $chargebacks = null;
        $disputed = null;
        $gatewayFees = null;
        $taxes = null;
        $netCollected = null;

        if ($providerAvailable) {
            $grossCollected = $this->mergeCurrencyAmounts(
                $provider['gross_collected_by_currency'] ?? [],
                $localGross,
            );
            $refunds = $this->mergeCurrencyAmounts(
                $provider['refunds_by_currency'] ?? [],
                $localRefunds,
            );
            $chargebacks = $provider['chargebacks_by_currency'] ?? [];
            $disputed = $provider['disputed_by_currency'] ?? [];
            $gatewayFees = $provider['gateway_fees_by_currency'] ?? [];
            $taxes = $provider['taxes_by_currency'] ?? [];

            $netCollected = $grossCollected;
            foreach ($refunds as $currency => $amount) {
                $netCollected[$currency] = ($netCollected[$currency] ?? 0.0) - $amount;
            }
            foreach ($chargebacks as $currency => $amount) {
                $netCollected[$currency] = ($netCollected[$currency] ?? 0.0) - $amount;
            }
            $grossCollected[self::PLATFORM_CURRENCY] ??= 0.0;
            $refunds[self::PLATFORM_CURRENCY] ??= 0.0;
            $netCollected[self::PLATFORM_CURRENCY] ??= 0.0;
            ksort($grossCollected);
            ksort($refunds);
            ksort($netCollected);
        }

        ksort($mrrByCurrency);
        ksort($pendingAmounts);

        return [
            'provider_status' => $providerAvailable ? 'available' : 'unavailable',
            'provider_payment_rows' => $providerAvailable ? (int) ($provider['payment_rows'] ?? 0) : null,
            'collected_complete' => $providerAvailable,
            'gross_collected_by_currency' => $grossCollected,
            'refunds_by_currency' => $refunds,
            'chargebacks_by_currency' => $chargebacks,
            'disputed_by_currency' => $disputed,
            'net_collected_by_currency' => $netCollected,
            'gateway_fees_by_currency' => $gatewayFees,
            'taxes_by_currency' => $taxes,
            'mrr_by_currency' => $mrrByCurrency,
            'mrr_unpriced_subscriptions' => $mrrUnpriced,
            'pending_payment_transactions' => $pendingPaymentCount,
            'pending_payment_amount_by_currency' => $pendingAmounts,
            'pending_checkout_intents' => $pendingCheckoutIntents,
        ];
    }

    /**
     * @param  array<string, float>  $target
     * @param  array<string, float>  $source
     * @return array<string, float>
     */
    private function mergeCurrencyAmounts(array $target, array $source): array
    {
        foreach ($source as $currency => $amount) {
            $this->addCurrencyAmount($target, strtoupper((string) $currency), (float) $amount);
        }

        return $target;
    }

    /**
     * @param  array<string, float>  $amounts
     */
    private function addCurrencyAmount(array &$amounts, string $currency, float $amount): void
    {
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            return;
        }

        $amounts[$currency] = round(($amounts[$currency] ?? 0.0) + $amount, 2);
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return in_array($status, ['canceled', 'cancelled'], true) ? 'canceled' : $status;
    }

    /**
     * @return array<string, int>
     */
    private function subscriptionStatusCounts(): array
    {
        $counts = [];

        foreach (DB::table('tenant_subscriptions')
            ->selectRaw("LOWER(COALESCE(status, '')) as status, COUNT(*) as rows")
            ->groupBy('status')
            ->get() as $row) {
            $status = $this->normalizeStatus((string) $row->status);
            $counts[$status] = ($counts[$status] ?? 0) + (int) $row->rows;
        }

        ksort($counts);

        return $counts;
    }
}
