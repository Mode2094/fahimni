<?php

namespace App\Services;

use App\Exceptions\DuplicatePaymentReferenceException;
use App\Models\Invoice;
use App\Models\ManualSubscriptionOperation;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\Transaction;
use App\Models\User;
use App\Plans\PlanEngine;
use App\Plans\SubscriptionState;
use App\Plans\UsageService;
use App\Support\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Super-Admin MANUAL subscription management (CliQ / bank / cash paid
 * off-platform, plus comped grants).
 *
 * This service is the ONE write path for administrative activations. It is a
 * thin orchestration over the EXISTING machinery — it never invents a parallel
 * subscription system:
 *
 *  - the plan / features stay in `subscription_plans` + `plan_features`;
 *  - access stays governed by `TenantSubscription` (legacy) and
 *    `admin_ends_at` (the additive admin grant) resolved by SubscriptionState;
 *  - quota/feature enforcement stays in PlanEngine / QuotaService (nothing to
 *    re-enable: bumping the plan simply changes what the engine resolves);
 *  - money stays in `payments` / `transactions` / `invoices`;
 *  - history is appended to `manual_subscription_operations` (never rewrites
 *    the financial archive).
 *
 * Guarantees enforced here:
 *  - one transaction + row locks → concurrent double-clicks serialise;
 *  - `request_id` idempotency → a retried submit never double-activates;
 *  - remaining paid days are preserved by anchoring the new period on the
 *    current effective end (never on "now" when a paid period is running);
 *  - a free grant records NO payment/ledger/invoice row → no fake revenue;
 *  - no teacher data, course, enrollment or student is ever deleted; a
 *    downgrade only changes the plan (limit checks then reject NEW over-limit
 *    creations and warn the admin).
 */
class ManualSubscriptionService
{
    public const OPERATION_TYPES = [
        'activate', 'renew', 'upgrade', 'downgrade', 'plan_change', 'extend', 'free_grant',
    ];

    public const PAYMENT_METHODS = ['cliq', 'bank_transfer', 'cash', 'other'];

    /** Operations that swap the plan; the paid window is preserved. */
    private const PLAN_SWAP_TYPES = ['upgrade', 'downgrade', 'plan_change'];

    public function __construct(
        private readonly UsageService $usage,
    ) {}

    /**
     * Compute the dates/plan a manual operation WOULD apply, without writing.
     * Used by the "preview before confirming" UI step and to fail fast.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(Tenant $tenant, array $input): array
    {
        $plan = SubscriptionPlan::with('features')->findOrFail($input['plan_id']);
        $computed = $this->computeWindow($tenant->id, $input, $plan);

        $warnings = [];

        if (in_array($computed['operation_type'], self::PLAN_SWAP_TYPES, true)) {
            $warnings = $this->downgradeWarnings($tenant->id, $plan);
        }

        return [
            'operation_type' => $computed['operation_type'],
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'monthly_price' => (float) $plan->monthly_price,
                'yearly_price' => (float) $plan->yearly_price,
                'currency' => $plan->currency,
                'features' => $plan->features->map(fn ($f) => [
                    'feature_key' => $f->feature_key,
                    'feature_value' => $f->feature_value,
                ])->values()->all(),
            ],
            'billing_cycle' => $computed['billing_cycle'],
            'duration_days' => $computed['duration_days'],
            'starts_at' => $computed['starts_at']->toIso8601String(),
            'ends_at' => $computed['ends_at']->toIso8601String(),
            'previous_ends_at' => $computed['previous_ends_at']?->toIso8601String(),
            'remaining_days_preserved' => $computed['remaining_days_preserved'],
            'amount' => $this->amountOf($input, $plan, $computed['billing_cycle']),
            'currency' => $input['currency'] ?? $plan->currency,
            'is_free_grant' => $this->isFreeGrant($input),
            'current' => [
                'source' => $computed['state']['source'],
                'status' => $computed['state']['status'],
                'plan_id' => $computed['state']['plan_id'],
                'ends_at' => $computed['state']['ends_at'] instanceof \DateTimeInterface
                    ? $computed['state']['ends_at']->format(\DateTimeInterface::ATOM)
                    : null,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Apply a manual operation. Idempotent on `request_id`.
     *
     * @param  array<string, mixed>  $input  validated payload (see $this->rules())
     * @return array{operation: ManualSubscriptionOperation, subscription: TenantSubscription|null, already_applied: bool}
     */
    public function apply(User $admin, Tenant $tenant, array $input): array
    {
        $requestId = $input['request_id'] ?? null;
        $proofPath = $input['proof_path'] ?? null;
        $request = $input['_request'] ?? null;
        unset($input['proof_path'], $input['_request']);

        return DB::transaction(function () use ($admin, $tenant, $input, $requestId, $proofPath, $request) {
            // Deterministic per-tenant serialization. Row locks alone are not
            // enough when the tenant has zero subscription rows yet; this
            // advisory lock (Postgres) is a no-op elsewhere.
            $this->acquireTenantLock($tenant->id);

            // Idempotency: a retried submit returns the first outcome.
            if ($requestId !== null) {
                $existing = ManualSubscriptionOperation::withoutTenant()
                    ->where('request_id', $requestId)
                    ->first();

                if ($existing) {
                    return [
                        'operation' => $existing,
                        'subscription' => $existing->subscription,
                        'already_applied' => true,
                    ];
                }
            }

            // Lock every row of this tenant so two admins cannot race the
            // "single active subscription" partial unique index.
            $rows = TenantSubscription::withoutTenant()
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->get();

            $plan = SubscriptionPlan::with('features')->findOrFail($input['plan_id']);
            $computed = $this->computeWindow($tenant->id, $input, $plan, locked: true);

            $previousPlanId = $computed['state']['plan_id'];
            $previousEndsAt = $computed['previous_ends_at'];

            // Resolve money/type BEFORE touching any row. A paid operation must
            // carry a positive amount + verified receipt, and a reference that
            // was already recorded must never be replayed onto a second
            // activation (even under a different `request_id`).
            $isFree = $this->isFreeGrant($input);
            $amount = $this->amountOf($input, $plan, $computed['billing_cycle']);

            if (! $isFree) {
                $this->assertPaidOperation($input, $amount);
                $this->guardAgainstDuplicateReference($input);
            }

            $target = $this->targetRow($rows);

            // Supersede the other active/trialing rows first: the partial
            // unique index allows exactly one, and we never delete history.
            foreach ($rows as $row) {
                if ($target && $row->id === $target->id) {
                    continue;
                }
                if (in_array($row->status, ['active', 'trialing'], true)) {
                    $row->update(['status' => 'cancelled', 'cancelled_at' => now(), 'auto_renew' => false]);
                }
            }

            $providerOwned = $target && ($target->billing_source === 'freemius' || $target->fs_license_id !== null);

            $attributes = [
                'plan_id' => $plan->id,
                'billing_cycle' => $computed['billing_cycle'],
                'starts_at' => $computed['starts_at'],
                'ends_at' => $computed['ends_at'],
                // `admin_ends_at` is what makes access survive any provider
                // webhook / stale mirror — the admin grant is additive.
                'admin_ends_at' => $computed['ends_at'],
                'metadata' => array_merge($target?->metadata ?? [], [
                    'manual_operation_at' => now()->toIso8601String(),
                    'manual_operation_by' => $admin->name,
                ]),
            ];

            if (! $providerOwned) {
                // A locally-managed row: move it to an honest paid active state.
                // `auto_renew` is FALSE: a manual / CliQ activation has no
                // gateway mandate to charge again, so it must never be shown as
                // auto-renewing — and it expires (not "past due") when it ends.
                $attributes += [
                    'status' => 'active',
                    'trial_ends_at' => null,
                    'cancelled_at' => null,
                    'auto_renew' => false,
                ];
            }

            if ($target) {
                $target->update($attributes);
                $subscription = $target->fresh();
            } else {
                $subscription = TenantSubscription::withoutTenant()->create(array_merge([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $tenant->id,
                    'status' => 'active',
                    'auto_renew' => false,
                ], $attributes));
            }

            // ── Accounting ────────────────────────────────────────────────
            // Paid manual operations create a real, linked payment + ledger +
            // paid invoice. Free grants create NOTHING → zero fake revenue.
            $payment = null;

            if (! $isFree && $amount > 0) {
                $payment = $this->recordPayment(
                    $admin,
                    $tenant,
                    $subscription,
                    $input,
                    $amount,
                    $proofPath,
                );
            }

            $operation = ManualSubscriptionOperation::withoutTenant()->create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'tenant_subscription_id' => $subscription->id,
                'payment_id' => $payment?->id,
                'operation_type' => $computed['operation_type'],
                'status' => 'applied',
                'previous_plan_id' => $previousPlanId,
                'new_plan_id' => $plan->id,
                'billing_cycle' => $computed['billing_cycle'],
                'duration_days' => $computed['duration_days'],
                'previous_ends_at' => $previousEndsAt,
                'starts_at' => $computed['starts_at'],
                'ends_at' => $computed['ends_at'],
                'amount' => $isFree ? 0 : $amount,
                'currency' => $isFree ? null : ($input['currency'] ?? $plan->currency),
                'payment_method' => $isFree ? null : ($input['payment_method'] ?? null),
                'payment_reference' => $isFree ? null : ($input['payment_reference'] ?? null),
                'payment_date' => $isFree ? null : ($input['payment_date'] ?? now()),
                'proof_path' => $proofPath,
                'reason' => $input['notes'] ?? null,
                'performed_by' => $admin->id,
                'performed_by_name' => $admin->name,
                'request_id' => $requestId,
                'metadata' => [
                    'remaining_days_preserved' => $computed['remaining_days_preserved'],
                    'provider_owned_row' => $providerOwned,
                ],
            ]);

            // Immediate effect on the live plan cache for connected teachers.
            PlanEngine::bumpVersion();

            AuditLogger::log(
                $request instanceof Request ? $request : request(),
                'admin.subscription_manual_operation',
                'manual_subscription_operation',
                $operation->id,
                [
                    'tenant_id' => $tenant->id,
                    'operation_type' => $computed['operation_type'],
                    'previous_plan_id' => $previousPlanId,
                    'new_plan_id' => $plan->id,
                    'amount' => $isFree ? 0 : $amount,
                    'currency' => $isFree ? null : ($input['currency'] ?? $plan->currency),
                    'payment_reference' => $input['payment_reference'] ?? null,
                    'payment_method' => $isFree ? null : ($input['payment_method'] ?? null),
                    'previous_ends_at' => $previousEndsAt?->toIso8601String(),
                    'new_ends_at' => $computed['ends_at']->toIso8601String(),
                    'reason' => $input['notes'] ?? null,
                    'free_grant' => $isFree,
                ],
                $tenant->id,
            );

            return [
                'operation' => $operation->fresh(),
                'subscription' => $subscription,
                'already_applied' => false,
            ];
        });
    }

    /**
     * Validation rules — shared by preview and apply so the preview never
     * shows a window the apply would reject.
     *
     * @return array<string, mixed>
     */
    public function rules(bool $requireConfirmation = true): array
    {
        return [
            'plan_id' => 'required|exists:subscription_plans,id',
            'operation_type' => 'required|in:'.implode(',', self::OPERATION_TYPES),
            'billing_cycle' => 'nullable|in:monthly,yearly',
            'duration_value' => 'nullable|integer|min:1|max:3650',
            'duration_unit' => 'nullable|in:day,month,year',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'payment_method' => 'nullable|in:'.implode(',', self::PAYMENT_METHODS),
            'amount' => 'nullable|numeric|min:0|max:10000000',
            'currency' => 'nullable|string|max:5',
            'payment_date' => 'nullable|date',
            'payment_reference' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:2000',
            'request_id' => 'nullable|string|max:80',
            'proof' => 'nullable|file|max:10240',
            // Enforced conditionally for paid operations in the controller.
            'confirm_payment_received' => 'nullable|boolean',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function computeWindow(string $tenantId, array $input, SubscriptionPlan $plan, bool $locked = false): array
    {
        $state = SubscriptionState::make()->resolve($tenantId);

        $now = now();
        $operationType = (string) $input['operation_type'];
        $billingCycle = $input['billing_cycle']
            ?? ($state['billing_cycle'] ?: 'monthly');

        $durationDays = null;
        if (! empty($input['duration_value'])) {
            $unit = $input['duration_unit'] ?? 'day';
            $durationDays = match ($unit) {
                'month' => (int) $input['duration_value'] * 30,
                'year' => (int) $input['duration_value'] * 365,
                default => (int) $input['duration_value'],
            };
        }

        $previousEndsAt = $state['ends_at'] instanceof \DateTimeInterface
            ? Carbon::instance($state['ends_at'])
            : null;

        $isPaidActive = in_array($state['source'], [SubscriptionState::SOURCE_FREEMIUS, SubscriptionState::SOURCE_LEGACY], true)
            && $previousEndsAt !== null
            && $previousEndsAt->isFuture();

        // A genuinely-running PAID window (a trial is not paid time). This
        // drives activation's "start now" rule and the start-date continuity.
        $paidRunning = ($state['source'] === SubscriptionState::SOURCE_FREEMIUS
                || ($state['source'] === SubscriptionState::SOURCE_LEGACY && $state['status'] === 'active'))
            && $previousEndsAt !== null
            && $previousEndsAt->isFuture();

        // Anchor: never start a new paid window "today" when a paid window is
        // already running — that would silently forfeit the remaining days.
        $anchor = ($previousEndsAt !== null && $previousEndsAt->isFuture())
            ? $previousEndsAt->copy()
            : $now->copy();

        if ($operationType === 'activate' && ! $paidRunning) {
            // Activation converts a trial/expired workspace into a fresh paid
            // period — a trial is not paid time, so it starts now.
            $anchor = $now->copy();
        }

        if ($operationType === 'free_grant') {
            $anchor = $now->copy();
        }

        $explicitEnd = ! empty($input['ends_at']) ? Carbon::parse($input['ends_at'])->endOfDay() : null;

        if ($explicitEnd !== null) {
            $endsAt = $explicitEnd;
        } elseif ($durationDays !== null) {
            $endsAt = $anchor->copy()->addDays($durationDays);
        } else {
            // Pure plan swap: keep the existing window untouched (a lapsed
            // subscription stays lapsed until the admin adds time).
            $endsAt = ($previousEndsAt ?? $now)->copy();
        }

        $startsAt = ! empty($input['starts_at'])
            ? Carbon::parse($input['starts_at'])->startOfDay()
            : ($paidRunning && $state['subscription_id']
                ? (TenantSubscription::withoutTenant()->find($state['subscription_id'])?->starts_at ?? $now)
                : $now);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new \InvalidArgumentException('The end date must be after the start date.');
        }

        $remainingPreserved = $isPaidActive
            && $previousEndsAt !== null
            && $endsAt->greaterThanOrEqualTo($previousEndsAt);

        return [
            'operation_type' => $operationType,
            'billing_cycle' => $billingCycle,
            'duration_days' => $durationDays,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'previous_ends_at' => $previousEndsAt,
            'remaining_days_preserved' => $remainingPreserved,
            'state' => $state,
        ];
    }

    /**
     * Serialize concurrent manual operations for the same tenant. Postgres
     * advisory locks are transaction-scoped (`_xact_`), so they are released
     * automatically on commit/rollback; other drivers are a no-op.
     */
    private function acquireTenantLock(string $tenantId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['manual-sub:'.$tenantId]);
    }

    private function targetRow(\Illuminate\Support\Collection $rows): ?TenantSubscription
    {
        // Prefer a locally-managed row (not provider-owned) so admin edits
        // never fight a Freemius mirror; fall back to the newest row.
        $sortNewest = fn ($rows) => $rows->sortByDesc(
            fn (TenantSubscription $s) => $s->starts_at?->getTimestamp() ?? $s->created_at?->getTimestamp() ?? 0
        );

        return $sortNewest($rows->filter(
            fn (TenantSubscription $s) => $s->billing_source !== 'freemius' && $s->fs_license_id === null
        ))->first() ?? $sortNewest($rows)->first();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function recordPayment(
        User $admin,
        Tenant $tenant,
        TenantSubscription $subscription,
        array $input,
        float $amount,
        ?string $proofPath,
    ): Payment {
        $currency = $input['currency'] ?? 'USD';
        $reference = $input['payment_reference'] ?? null;
        $paidAt = ! empty($input['payment_date']) ? Carbon::parse($input['payment_date']) : now();

        try {
            $payment = Payment::withoutTenant()->create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'tenant_subscription_id' => $subscription->id,
                'transaction_id' => $reference ?: 'manual-'.UuidV7::generate(),
                'gateway' => 'manual',
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'completed',
                'payment_method' => $input['payment_method'] ?? 'other',
                'gateway_data' => [
                    'manual' => true,
                    'operation_type' => $input['operation_type'] ?? null,
                    'payer_name' => $tenant->name,
                    'reference' => $reference,
                    'notes' => $input['notes'] ?? null,
                    // Kept explicit for the CliQ-specific UX/tests.
                    'cliq_reference' => $reference,
                    'admin_notes' => $input['notes'] ?? null,
                    'proof_path' => $proofPath,
                    'performed_by' => $admin->id,
                    'performed_by_name' => $admin->name,
                    'verified_manually' => true,
                ],
                'paid_at' => $paidAt,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // The unique (gateway, transaction_id) index caught a race between
            // two requests using the same reference past the pre-check.
            throw new DuplicatePaymentReferenceException((string) ($reference ?? ''), previous: $e);
        }

        // Ledger entry (accounting-correct: money in).
        Transaction::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'type' => 'payment',
            'amount' => $amount,
            'direction' => 'in',
            'balance_before' => 0,
            'balance_after' => $amount,
            'currency' => $currency,
            'status' => 'completed',
            'reference_type' => 'payment',
            'reference_id' => $payment->id,
            'description' => 'Manual subscription payment ('.($input['payment_method'] ?? 'other').')'.($reference ? " · {$reference}" : ''),
        ]);

        // Paid invoice linked to the subscription — the tenant's receipts stay
        // complete even though no gateway was involved.
        Invoice::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'tenant_subscription_id' => $subscription->id,
            'payment_id' => $payment->id,
            'invoice_number' => 'INV-'.strtoupper(bin2hex(random_bytes(6))),
            'amount' => $amount,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'currency' => $currency,
            'status' => 'paid',
            'due_date' => $paidAt,
            'paid_at' => $paidAt,
            'metadata' => ['manual' => true, 'operation_type' => $input['operation_type'] ?? null],
        ]);

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function isFreeGrant(array $input): bool
    {
        // ONLY an explicit `free_grant` is comped. A missing or zero amount on a
        // paid operation is NOT a free grant — it is an invalid paid operation
        // and must be rejected (otherwise a plan could be handed out with no
        // money collected and nothing recorded).
        return ($input['operation_type'] ?? null) === 'free_grant';
    }

    /**
     * A paid (off-platform) operation always requires an honest amount, the
     * payment method, and an explicit confirmation that the money was actually
     * received — never just a reference number.
     *
     * @param  array<string, mixed>  $input
     */
    private function assertPaidOperation(array $input, float $amount): void
    {
        if (trim((string) ($input['payment_method'] ?? '')) === '') {
            throw new \InvalidArgumentException('A payment method is required for a paid operation.');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('A positive amount is required for a paid operation.');
        }

        if (empty($input['confirm_payment_received'])) {
            throw new \InvalidArgumentException('You must confirm that you verified receipt of the payment.');
        }
    }

    /**
     * One payment reference (CliQ number / bank reference) identifies exactly one
     * real transfer, so it can never be recorded twice — even from a different
     * `request_id` or a different admin. Replaying it would double-count revenue
     * and double-activate the subscription.
     *
     * @param  array<string, mixed>  $input
     */
    private function guardAgainstDuplicateReference(array $input): void
    {
        $reference = trim((string) ($input['payment_reference'] ?? ''));

        if ($reference === '') {
            return;
        }

        $exists = Payment::withoutTenant()
            ->where('gateway', 'manual')
            ->where('transaction_id', $reference)
            ->exists();

        if ($exists) {
            throw new DuplicatePaymentReferenceException($reference);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function amountOf(array $input, SubscriptionPlan $plan, string $billingCycle): float
    {
        if (($input['operation_type'] ?? null) === 'free_grant') {
            return 0.0;
        }

        if (isset($input['amount']) && $input['amount'] !== null && $input['amount'] !== '') {
            return round((float) $input['amount'], 2);
        }

        return (float) ($billingCycle === 'yearly'
            ? ($plan->yearly_price ?? $plan->monthly_price * 12)
            : $plan->monthly_price);
    }

    /**
     * Transparent "safe limit policy": list resources already above the new
     * plan's limits. Nothing is deleted — the admin is told what will be
     * blocked from growing until usage drops.
     *
     * @return list<array{resource: string, label: string, used: int, limit: int}>
     */
    private function downgradeWarnings(string $tenantId, SubscriptionPlan $plan): array
    {
        $features = $plan->features->pluck('feature_value', 'feature_key')->all();
        $warnings = [];

        foreach ($this->usage->registry() as $resource => $meta) {
            if (! $meta['limit'] || ! isset($features[$meta['limit']])) {
                continue;
            }

            $limitRaw = $features[$meta['limit']];
            if ($limitRaw === 'unlimited' || ! is_numeric($limitRaw) || (int) $limitRaw <= 0) {
                continue;
            }

            if ($resource === 'storage') {
                continue; // reported in MB elsewhere; keep this list to counts
            }

            $used = (int) $this->usage->usageOf($tenantId, $resource);
            $limit = (int) $limitRaw;

            if ($used > $limit) {
                $warnings[] = [
                    'resource' => $resource,
                    'label' => $meta['label_ar'],
                    'used' => $used,
                    'limit' => $limit,
                ];
            }
        }

        return $warnings;
    }
}
