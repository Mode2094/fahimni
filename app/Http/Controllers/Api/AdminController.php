<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Mail\PlatformNotification;
use App\Models\ActivationCard;
use App\Models\Assignment;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ErrorLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\FsEntitlement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StudentPoint;
use App\Models\SubscriptionPlan;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\TicketMessage;
use App\Models\Transaction;
use App\Models\TrialGrant;
use App\Models\User;
use App\Models\UserDevice;
use App\Plans\PlanEngine;
use App\Plans\SubscriptionState;
use App\Plans\TeacherTrialProgram;
use App\Rules\ValidPhoneNumber;
use App\Services\EnrollmentService;
use App\Services\NotificationService;
use App\Services\PaymentCompletionService;
use App\Services\SuperAdminStatisticsService;
use App\Services\TenantSlugService;
use App\Support\AuditLogger;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class AdminController extends Controller
{
    public function __construct(
        private readonly SuperAdminStatisticsService $superAdminStatistics,
    ) {}

    // ─── Subscriptions ────────────────────────────────

    public function subscriptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:active,trialing,pending,canceled,cancelled,expired,past_due',
            'search' => 'nullable|string|max:255',
        ]);
        $snapshot = $this->superAdminStatistics->snapshot();
        $metrics = $snapshot['subscription_metrics'];

        // Platform-wide view: ignore the tenant scope entirely (super admin only).
        $query = TenantSubscription::withoutTenant()->with(['tenant', 'plan']);

        if (! empty($validated['status'])) {
            $status = strtolower($validated['status']);

            if (in_array($status, ['canceled', 'cancelled'], true)) {
                $query->whereIn('status', ['canceled', 'cancelled']);
            } else {
                $query->whereRaw('LOWER(status) = ?', [$status]);
            }
        }
        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->whereHas('tenant', fn ($q) => $q->where('name', 'like', '%'.$search.'%'));
        }

        $subscriptions = $query->orderByDesc('created_at')->get();
        $recordStatuses = $metrics['record_status_counts'];

        $stats = [
            'total' => $metrics['historical_records'],
            'active' => $recordStatuses['active'] ?? 0,
            'trialing' => $recordStatuses['trialing'] ?? 0,
            'pending' => $metrics['pending_subscriptions'],
            'cancelled' => $recordStatuses['canceled'] ?? 0,
            'expired' => $recordStatuses['expired'] ?? 0,
            'monthly_revenue' => $metrics['financials']['mrr_by_currency']['USD'] ?? 0,
        ];

        return response()->json([
            'subscriptions' => $subscriptions,
            'stats' => $stats,
            'subscription_metrics' => $metrics,
        ]);
    }

    public function subscriptionStatus(Request $request, string $id): JsonResponse
    {
        $sub = TenantSubscription::withoutTenant()->findOrFail($id);
        $request->validate(['status' => 'required|in:active,trialing,pending,cancelled,expired,past_due']);

        $sub->update([
            'status' => $request->status,
            'cancelled_at' => $request->status === 'cancelled' ? now() : $sub->cancelled_at,
            'trial_ends_at' => $request->status === 'active' ? null : $sub->trial_ends_at,
            'ends_at' => $request->status === 'active' && ! $sub->ends_at
                ? now()->addDays($sub->billing_cycle === 'yearly' ? 365 : 30)
                : $sub->ends_at,
        ]);

        return response()->json(['message' => 'Subscription status updated.', 'subscription' => $sub->load(['tenant', 'plan'])]);
    }

    /**
     * Super Admin subscription extension.
     *
     * This is the ONE place that grants access administratively. It writes the
     * dedicated `admin_ends_at` field, which the access resolver honors
     * independently of any payment/billing state — so the teacher can use the
     * platform immediately, whatever Freemius (or an old webhook) reports. It
     * never fakes a paid invoice or a Freemius renewal.
     *
     * Idempotent: a `request_id` (stable per dialog) makes a retry/double
     * submit a no-op; a short cooldown covers retries without one.
     */
    public function subscriptionExtend(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:3650',
            'request_id' => 'nullable|string|max:80',
            'reason' => 'nullable|string|max:500',
        ]);

        $days = (int) $validated['days'];
        $requestId = $validated['request_id'] ?? null;
        $reason = $validated['reason'] ?? null;
        $admin = $request->user();

        $result = DB::transaction(function () use ($id, $days, $requestId, $reason, $admin, $request) {
            // Row lock: concurrent double-clicks serialise instead of racing.
            $sub = TenantSubscription::withoutTenant()->lockForUpdate()->findOrFail($id);
            $tenantId = $sub->tenant_id;

            $metadata = $sub->metadata ?? [];
            $grants = array_values((array) ($metadata['admin_grants'] ?? []));

            // Idempotency #1: an identical request_id was already applied.
            if ($requestId !== null) {
                foreach ($grants as $grant) {
                    if (($grant['request_id'] ?? null) === $requestId) {
                        return ['sub' => $sub, 'already_applied' => true, 'ends_at' => $sub->admin_ends_at];
                    }
                }
            }

            // Idempotency #2 (no request_id): an identical extension by the
            // same admin within a few seconds is a retry, not a new grant.
            $lastGrant = $grants !== [] ? $grants[array_key_last($grants)] : null;
            if ($requestId === null && is_array($lastGrant)
                && (int) ($lastGrant['days'] ?? 0) === $days
                && ($lastGrant['by'] ?? null) === $admin?->id
                && isset($lastGrant['at'])) {
                $seconds = now()->timestamp - Carbon::parse($lastGrant['at'])->timestamp;
                if ($seconds >= 0 && $seconds < 10) {
                    return ['sub' => $sub, 'already_applied' => true, 'ends_at' => $sub->admin_ends_at];
                }
            }

            // Base = the tenant's CURRENT effective access end, so an in-term
            // extension keeps every remaining day and an expired workspace
            // starts from today. An admin grant already recorded also counts.
            $base = $this->currentAccessEnd($tenantId) ?? now();
            $base = Carbon::instance($base);
            if ($base->lt(now())) {
                $base = now();
            }
            $newEnd = $base->copy()->addDays($days);

            $previousAdminEnd = $sub->admin_ends_at?->toIso8601String();

            $grants[] = [
                'request_id' => $requestId,
                'days' => $days,
                'by' => $admin?->id,
                'by_name' => $admin?->name,
                'at' => now()->toIso8601String(),
                'previous_ends_at' => $previousAdminEnd,
                'new_ends_at' => $newEnd->toIso8601String(),
                'reason' => $reason,
            ];

            $metadata['admin_ends_at'] = $newEnd->toIso8601String();
            $metadata['admin_grants'] = $grants;

            // Access is driven by `admin_ends_at`; the payment status is left
            // truthful (never flipped to a fake "active"). `ends_at` is
            // mirrored too so the admin lists show the real access end — it is
            // display-only and a Freemius re-sync may overwrite it.
            $sub->update([
                'admin_ends_at' => $newEnd,
                'ends_at' => $sub->ends_at && $sub->ends_at->gt($newEnd) ? $sub->ends_at : $newEnd,
                'metadata' => $metadata,
            ]);

            // Immediate effect for an already-connected teacher.
            PlanEngine::bumpVersion();

            AuditLogger::log($request, 'admin.subscription_extended', 'tenant_subscription', $sub->id, [
                'tenant_id' => $tenantId,
                'days' => $days,
                'previous_admin_ends_at' => $previousAdminEnd,
                'new_admin_ends_at' => $newEnd->toIso8601String(),
                'request_id' => $requestId,
                'reason' => $reason,
            ]);

            return ['sub' => $sub->fresh(), 'already_applied' => false, 'ends_at' => $newEnd];
        });

        /** @var TenantSubscription $sub */
        $sub = $result['sub'];

        return response()->json([
            'message' => $result['already_applied']
                ? 'This extension was already applied.'
                : 'Subscription extended — the teacher can use the platform immediately.',
            'already_applied' => $result['already_applied'],
            'access_ends_at' => $result['ends_at']?->toIso8601String(),
            'subscription' => $sub->loadMissing('tenant', 'plan'),
            'access' => $this->accessInfo($sub->tenant_id),
        ]);
    }

    /**
     * Super Admin FREE-TRIAL extension, for one teacher only.
     *
     * Deliberately separate from `subscriptionExtend` (the universal
     * administrative access grant): here we move the trial's own
     * `trial_ends_at` forward and, when the trial had lapsed, put the row
     * back into `trialing` so access resumes. It never converts the teacher
     * to a paid plan, never creates an invoice/charge, and never touches a
     * paid subscription (a provider-owned mirror or an active paid period).
     */
    public function subscriptionExtendTrial(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:3650',
            'request_id' => 'nullable|string|max:80',
            'reason' => 'nullable|string|max:500',
        ]);

        $days = (int) $validated['days'];
        $requestId = $validated['request_id'] ?? null;
        $reason = $validated['reason'] ?? null;
        $admin = $request->user();

        $outcome = DB::transaction(function () use ($id, $days, $requestId, $reason, $admin, $request) {
            // Row lock: concurrent double-clicks serialise instead of racing.
            $sub = TenantSubscription::withoutTenant()->lockForUpdate()->findOrFail($id);
            $tenantId = $sub->tenant_id;

            // Never touch a paid subscription: a provider-owned mirror, a
            // genuinely active paid period, and a row with no trial window
            // at all (e.g. a canceled paid legacy row) are all off-limits.
            $providerOwned = $sub->billing_source === 'freemius' || $sub->fs_license_id !== null;
            $hasTrial = $sub->trial_ends_at !== null || $sub->status === 'trialing';
            if ($providerOwned || $sub->status === 'active' || ! $hasTrial) {
                return ['refused' => true];
            }

            $metadata = $sub->metadata ?? [];
            $grants = array_values((array) ($metadata['trial_grants'] ?? []));

            // Idempotency #1: an identical request_id was already applied.
            if ($requestId !== null) {
                foreach ($grants as $grant) {
                    if (($grant['request_id'] ?? null) === $requestId) {
                        return ['sub' => $sub, 'already_applied' => true, 'reactivated' => false];
                    }
                }
            }

            // Idempotency #2 (no request_id): an identical extension by the
            // same admin within a few seconds is a retry, not a new grant.
            $lastGrant = $grants !== [] ? $grants[array_key_last($grants)] : null;
            if ($requestId === null && is_array($lastGrant)
                && (int) ($lastGrant['days'] ?? 0) === $days
                && ($lastGrant['by'] ?? null) === $admin?->id
                && isset($lastGrant['at'])) {
                $seconds = now()->timestamp - Carbon::parse($lastGrant['at'])->timestamp;
                if ($seconds >= 0 && $seconds < 10) {
                    return ['sub' => $sub, 'already_applied' => true, 'reactivated' => false];
                }
            }

            // An active trial keeps every remaining day (base = its current
            // end); a lapsed trial starts a fresh window from now.
            $previousTrialEnd = $sub->trial_ends_at?->toIso8601String();
            $trialLapsed = $sub->trial_ends_at === null || $sub->trial_ends_at->isPast();
            $base = $trialLapsed ? now() : $sub->trial_ends_at->copy();
            $newTrialEnd = $base->addDays($days);

            $reactivated = $trialLapsed || $sub->status !== 'trialing';

            $grants[] = [
                'request_id' => $requestId,
                'days' => $days,
                'by' => $admin?->id,
                'by_name' => $admin?->name,
                'at' => now()->toIso8601String(),
                'previous_trial_ends_at' => $previousTrialEnd,
                'new_trial_ends_at' => $newTrialEnd->toIso8601String(),
                'reactivated' => $reactivated,
                'reason' => $reason,
            ];

            $metadata['trial_grants'] = $grants;

            // Only the trial fields move: the plan, its limits, any real
            // `ends_at` and the billing source are left untouched. A lapsed
            // trial is simply re-opened as `trialing`.
            $sub->update([
                'trial_ends_at' => $newTrialEnd,
                'status' => 'trialing',
                'cancelled_at' => null,
                'metadata' => $metadata,
            ]);

            // Immediate effect for an already-connected teacher.
            PlanEngine::bumpVersion();

            AuditLogger::log($request, 'admin.subscription_trial_extended', 'tenant_subscription', $sub->id, [
                'tenant_id' => $tenantId,
                'days' => $days,
                'previous_trial_ends_at' => $previousTrialEnd,
                'new_trial_ends_at' => $newTrialEnd->toIso8601String(),
                'reactivated' => $reactivated,
                'request_id' => $requestId,
                'reason' => $reason,
            ]);

            return ['sub' => $sub->fresh(), 'already_applied' => false, 'reactivated' => $reactivated];
        });

        if (($outcome['refused'] ?? false) === true) {
            return response()->json([
                'message' => 'This teacher has a paid or active subscription — trial extension does not apply. Use “Extend access” instead.',
                'code' => 'not_a_trial',
            ], 422);
        }

        /** @var TenantSubscription $sub */
        $sub = $outcome['sub'];

        return response()->json([
            'message' => $outcome['already_applied']
                ? 'This trial extension was already applied.'
                : 'Free trial extended — no charge and no plan change.',
            'already_applied' => $outcome['already_applied'],
            'reactivated' => (bool) ($outcome['reactivated'] ?? false),
            'trial_ends_at' => $sub->trial_ends_at?->toIso8601String(),
            'subscription' => $sub->loadMissing('tenant', 'plan'),
            'access' => $this->accessInfo($sub->tenant_id),
        ]);
    }

    /**
     * The tenant's current effective access end (max of every source that can
     * grant access): an admin grant, an active Freemius entitlement's
     * expiration, and any still-running legacy subscription. Null when nothing
     * is granted.
     */
    private function currentAccessEnd(string $tenantId): ?Carbon
    {
        /** @var list<\DateTimeInterface> $ends */
        $ends = [];

        foreach (TenantSubscription::withoutTenant()->where('tenant_id', $tenantId)
            ->whereNotNull('admin_ends_at')->get() as $row) {
            if ($row->admin_ends_at) {
                $ends[] = $row->admin_ends_at;
            }
        }

        $entitlement = FsEntitlement::where('tenant_id', $tenantId)
            ->whereNotNull('plan_id')
            ->latest('synced_at')
            ->get()
            ->first(fn (FsEntitlement $e) => $e->isActive());

        if ($entitlement && $entitlement->expiration) {
            $ends[] = $entitlement->expiration;
        }

        $legacy = TenantSubscription::withoutTenant()->where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($q) {
                $q->whereNull('billing_source')->orWhere('billing_source', '!=', 'freemius');
            })
            ->whereNull('fs_license_id')
            ->get();

        foreach ($legacy as $row) {
            $end = $row->status === 'trialing' ? $row->trial_ends_at : $row->ends_at;
            if ($end) {
                $ends[] = $end;
            }
        }

        if ($ends === []) {
            return null;
        }

        $max = null;
        foreach ($ends as $end) {
            $candidate = Carbon::instance($end);
            if ($max === null || $candidate->gt($max)) {
                $max = $candidate;
            }
        }

        return $max;
    }

    /**
     * Compact, honest access summary for the admin UI: separates the real
     * payment state from the effective access state and surfaces any
     * administrative grant (who/when/how many days).
     *
     * @return array<string, mixed>
     */
    private function accessInfo(string $tenantId): array
    {
        $state = SubscriptionState::make()->resolve($tenantId);

        $adminRow = TenantSubscription::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('admin_ends_at')
            ->orderByDesc('admin_ends_at')
            ->first();

        $endsAt = $state['ends_at'];
        $daysLeft = null;
        if ($endsAt instanceof \DateTimeInterface) {
            $daysLeft = (int) max(0, (int) ceil(($endsAt->getTimestamp() - now()->getTimestamp()) / 86400));
        }

        $grants = array_values((array) (($adminRow->metadata ?? [])['admin_grants'] ?? []));
        $lastGrant = $grants !== [] ? $grants[array_key_last($grants)] : null;

        $adminActive = (bool) ($adminRow && $adminRow->admin_ends_at && $adminRow->admin_ends_at->isFuture());

        // The FREE-TRIAL window (if any). It lives on the local row that
        // carries `trial_ends_at` and is NOT owned by the payment provider —
        // a paid Freemius row is never a trial and must never be touched by
        // trial extension.
        $trialRow = TenantSubscription::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('trial_ends_at')
            ->where(function ($q) {
                $q->whereNull('billing_source')->orWhere('billing_source', '!=', 'freemius');
            })
            ->whereNull('fs_license_id')
            ->orderByDesc('trial_ends_at')
            ->first();

        $trialEndsAt = $trialRow?->trial_ends_at;
        $trialDaysLeft = null;
        if ($trialEndsAt instanceof \DateTimeInterface) {
            $trialDaysLeft = (int) max(0, (int) ceil(($trialEndsAt->getTimestamp() - now()->getTimestamp()) / 86400));
        }

        // Trial extension only applies while access is not governed by a paid
        // provider subscription and the row is not already a paid `active`
        // period (an active row has no trial window to extend).
        $canExtendTrial = $trialRow !== null
            && $state['source'] !== SubscriptionState::SOURCE_FREEMIUS
            && $trialRow->status !== 'active';

        return [
            'access_active' => $state['source'] !== SubscriptionState::SOURCE_NONE,
            'access_source' => $state['source'],
            'access_ends_at' => $endsAt instanceof \DateTimeInterface ? $endsAt->format(\DateTimeInterface::ATOM) : null,
            'days_left' => $daysLeft,
            'trial_ends_at' => $trialEndsAt instanceof \DateTimeInterface ? $trialEndsAt->format(\DateTimeInterface::ATOM) : null,
            'trial_days_left' => $trialDaysLeft,
            'trial_active' => (bool) ($trialEndsAt instanceof \DateTimeInterface && $trialEndsAt->isFuture()),
            'can_extend_trial' => $canExtendTrial,
            'subscription_id' => $state['subscription_id'],
            'admin_extension' => $adminActive,
            'admin_ends_at' => $adminRow?->admin_ends_at?->toIso8601String(),
            'admin_granted_at' => is_array($lastGrant) ? ($lastGrant['at'] ?? null) : null,
            'admin_granted_by' => is_array($lastGrant) ? ($lastGrant['by_name'] ?? null) : null,
            'admin_grant_days' => is_array($lastGrant) ? ($lastGrant['days'] ?? null) : null,
        ];
    }

    public function subscriptionPlan(Request $request, string $id): JsonResponse
    {
        $sub = TenantSubscription::withoutTenant()->findOrFail($id);
        $request->validate(['plan_id' => 'required|exists:subscription_plans,id']);

        $oldPlanId = $sub->plan_id;
        $sub->update(['plan_id' => $request->plan_id]);

        // Mid-cycle plan swaps never restart the billing period silently:
        // the ends_at stays anchored so the tenant keeps their remaining
        // days (admin can extend separately when needed).
        AuditLogger::log($request, 'admin.subscription_plan_changed', 'tenant_subscription', $sub->id, [
            'from_plan' => $oldPlanId,
            'to_plan' => $request->plan_id,
            'ends_at' => $sub->ends_at?->toIso8601String(),
        ]);

        return response()->json(['message' => 'Subscription plan changed.', 'subscription' => $sub->load(['tenant', 'plan'])]);
    }

    // ─── Teachers ──────────────────────────────────

    public function teachers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscription_state' => 'nullable|in:valid_trial,paid,admin,expired,unverified,effective',
        ]);
        $snapshot = $this->superAdminStatistics->snapshot();
        $metrics = $snapshot['subscription_metrics'];

        $base = User::role('Teacher')
            ->with([
                'tenantPivots' => fn ($query) => $query->withoutTenant(),
                'tenantPivots.tenant.settings' => fn ($query) => $query->withoutTenant(),
                // All platform rows (newest first): the effective state is
                // supplied separately by the shared statistics resolver.
                'tenantPivots.tenant.subscriptions' => fn ($query) => $query->withoutTenant()->latest('starts_at'),
            ]);

        if ($request->filled('is_active')) {
            $base->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('plan_id')) {
            $base->whereHas('tenantPivots', fn ($pivot) => $pivot
                ->withoutTenant()
                ->where('status', 'active')
                ->whereHas('tenant.subscriptions', fn ($subscription) => $subscription
                    ->withoutTenant()
                    ->where('plan_id', $request->plan_id)
                    ->whereIn('status', ['active', 'trialing'])));
        }
        if (! empty($validated['subscription_state'])) {
            $teacherIds = $snapshot['teacher_ids_by_state'][$validated['subscription_state']] ?? [];
            $base->whereIn('users.id', $teacherIds);
        }

        $search = $request->filled('search') ? trim((string) $request->input('search')) : null;

        $rows = (clone $base);

        if ($search !== null) {
            // Postgres supports case-insensitive ILIKE; SQLite's LIKE is already
            // case-insensitive for ASCII, so pick the portable operator.
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $rows->where(fn ($q) => $q
                ->where('name', $like, '%'.$search.'%')
                ->orWhere('email', $like, '%'.$search.'%')
                ->orWhereHas('tenantPivots', fn ($pivot) => $pivot
                    ->withoutTenant()
                    ->whereHas('tenant', fn ($tenant) => $tenant->where('name', $like, '%'.$search.'%'))));
        }

        $rows = $rows->orderByDesc('created_at')->limit(200)->get();

        // Phone lives encrypted at rest, so it cannot be matched with SQL LIKE.
        // When the term carries digits, scan the (small) teacher set and match
        // on the decrypted value, then merge — name/email hits stay primary.
        if ($search !== null && preg_match('/\d/', $search) === 1) {
            $digits = preg_replace('/\D/', '', $search) ?? '';

            $phoneMatches = (clone $base)
                ->orderByDesc('created_at')
                ->limit(500)
                ->get()
                ->filter(fn (User $u) => filled($u->phone)
                    && str_contains(preg_replace('/\D/', '', (string) $u->phone) ?? '', $digits));

            $rows = $rows->concat($phoneMatches)->unique('id')->sortByDesc('created_at')->values();
        }

        $teachers = $rows->map(function (User $user) use ($snapshot) {
            $payload = $this->teacherPayload($user);
            $payload['subscription_summary'] = $snapshot['teacher_subscription_by_id'][(string) $user->id] ?? null;

            return $payload;
        });

        $stats = [
            'total' => $metrics['total_teachers'],
            'active' => $metrics['active_accounts'],
            'inactive' => $metrics['total_teachers'] - $metrics['active_accounts'],
            // Deprecated compatibility alias: this is effective access,
            // not a count of paid subscriptions or subscription rows.
            'with_subscription' => $metrics['effective_access'],
        ];

        return response()->json([
            'teachers' => $teachers,
            'stats' => $stats,
            'subscription_metrics' => $metrics,
        ]);
    }

    public function teacherStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,NULL,id,deleted_at,NULL',
            // Every teacher account — including admin-created ones — needs a phone.
            'phone' => ['required', 'string', 'max:25', new ValidPhoneNumber],
            'password' => 'nullable|string|min:8',
            'plan_id' => 'nullable|exists:subscription_plans,id',
        ]);

        if (User::onlyTrashed()->where('email', strtolower($validated['email']))->exists()) {
            return response()->json([
                'message' => 'هذا البريد مرتبط بحساب محذوف — استخدم بريداً آخر، أو أطلب من صاحبه استعادة الحساب بتسجيل الدخول.',
                'code' => 'email_previously_deleted',
            ], 422);
        }

        $password = $validated['password'] ?? Str::random(14);
        $user = User::create([
            'id' => UuidV7::generate(),
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'phone' => PhoneNumber::toE164($validated['phone']),
            'password' => Hash::make($password),
            'is_active' => true,
            'email_verified_at' => now(),
            'locale' => 'ar',
        ]);
        $user->assignRole('Teacher');

        $tenant = app(TenantSlugService::class)->createTenant([
            'id' => UuidV7::generate(),
            'name' => $validated['name'].' Academy',
            'type' => 'tutor',
            'email' => strtolower($validated['email']),
            'owner_id' => $user->id,
        ], $validated['name']);

        TenantSetting::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'academy_name' => $validated['name'],
        ]);

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
            'metadata' => [],
        ]);

        // ابدأ التجربة المجانية فقط عندما يسمح المفتاح الرئيسي بمنح تجارب
        // جديدة. تجارب الأدمن لا تستهلك مقعدًا من العرض المحدود (حسابات
        // الاختبار/التجهيز مستثناة من العداد) لكنها تُسجَّل للتدقيق.
        $program = app(TeacherTrialProgram::class);
        $plan = $request->filled('plan_id')
            ? SubscriptionPlan::findOrFail($request->plan_id)
            : SubscriptionPlan::where('is_active', true)->orderBy('monthly_price')->first();

        if ($plan && $program->masterEnabled()) {
            TenantSubscription::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => 'trialing',
                'billing_cycle' => 'monthly',
                'starts_at' => now(),
                'trial_ends_at' => now()->addDays(max(1, (int) $plan->trial_days)),
                'auto_renew' => true,
            ]);

            TrialGrant::create([
                'id' => UuidV7::generate(),
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'source' => 'admin',
                'counted' => false,
                'granted_at' => now(),
            ]);
        }

        AuditLogger::log($request, 'admin.teacher_created', 'user', $user->id, ['email' => $user->email]);

        return response()->json([
            'message' => 'Teacher created.',
            'teacher' => $this->teacherPayload($user->load('tenantPivots.tenant.settings', 'tenantPivots.tenant.subscriptions')),
            'generated_password' => $request->filled('password') ? null : $password,
        ], 201);
    }

    public function teacherShow(string $id): JsonResponse
    {
        $user = User::role('Teacher')
            ->with([
                'tenantPivots' => fn ($pivots) => $pivots->withoutTenant()->with([
                    'tenant.settings' => fn ($settings) => $settings->withoutTenant(),
                    'tenant.subscriptions' => fn ($subscriptions) => $subscriptions
                        ->withoutTenant()
                        ->with('plan')
                        ->latest('starts_at'),
                ]),
            ])
            ->findOrFail($id);

        $tenant = $user->tenantPivots->first()?->tenant;

        if (! $tenant) {
            return response()->json([
                'teacher' => $this->teacherPayload($user),
                'tenant' => null,
                'stats' => $this->emptyTeacherStats(),
                'courses' => [],
                'students' => [],
                'payments' => [],
                'revenue_by_course' => [],
            ]);
        }

        $tenantId = $tenant->id;

        // ── Courses with per-course stats ────────────
        $courses = Course::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->withCount(['sections', 'lessons', 'enrollments'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Course $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'is_published' => (bool) $c->is_published,
                'price' => $c->price,
                'currency' => $c->currency,
                'sections_count' => $c->sections_count,
                'lessons_count' => $c->lessons_count,
                'students_count' => $c->enrollments_count,
                'created_at' => $c->created_at?->toIso8601String(),
            ]);

        // ── Students (distinct users with enrollments) ──
        $students = Enrollment::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->effective()
            ->with('student:id,name,email,created_at')
            ->get()
            ->groupBy('user_id')
            ->map(function ($rows, $userId) {
                $student = $rows->first()->student;

                return [
                    'id' => $userId,
                    'name' => $student?->name ?? '—',
                    'email' => $student?->email ?? '—',
                    'courses_count' => $rows->pluck('course_id')->unique()->count(),
                    'enrollments_count' => $rows->count(),
                    'completed_count' => $rows->where('status', 'completed')->count(),
                    'joined_at' => $rows->min('enrolled_at')?->toIso8601String(),
                ];
            })
            ->sortByDesc('courses_count')
            ->values();

        // ── Payments & revenue ────────────────────────
        $payments = Payment::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->latest('paid_at')
            ->limit(15)
            ->get();

        $revenueBase = fn ($q) => $q
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->where('courses.price', '>', 0);

        $totalRevenue = round((float) $revenueBase(
            Enrollment::withoutTenant()->where('enrollments.tenant_id', $tenantId)
        )->sum('courses.price'), 2);

        $since = now()->subDays(30)->startOfDay();
        $monthRevenue = round((float) $revenueBase(
            Enrollment::withoutTenant()
                ->where('enrollments.tenant_id', $tenantId)
                ->where('enrolled_at', '>=', $since)
        )->sum('courses.price'), 2);

        $revenueByCourse = $revenueBase(
            Enrollment::withoutTenant()->where('enrollments.tenant_id', $tenantId)
        )
            ->selectRaw('courses.id, courses.title, count(*) as sales, sum(courses.price) as revenue')
            ->groupBy('courses.id', 'courses.title')
            ->get()
            ->map(fn ($r) => [
                'course_id' => $r->id,
                'title' => $r->title,
                'sales' => (int) $r->sales,
                'revenue' => round((float) $r->revenue, 2),
            ])
            ->sortByDesc('sales')
            ->values();

        // ── Exams / attempts / engagement ─────────────
        $examStats = Exam::withoutTenant()->where('tenant_id', $tenantId);
        $totalExams = (clone $examStats)->count();

        $submitted = ExamAttempt::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('status', 'submitted');
        $submittedCount = (clone $submitted)->count();
        $passed = (clone $submitted)->where('passed', true)->count();
        $avgScore = round((float) (clone $submitted)->avg('percentage'), 2);
        $passRate = $submittedCount > 0 ? round(($passed / $submittedCount) * 100, 2) : 0;

        $totalEnrollments = Enrollment::withoutTenant()->where('tenant_id', $tenantId)->count();
        $completed = Enrollment::withoutTenant()
            ->where('tenant_id', $tenantId)->where('status', 'completed')->count();
        $completionRate = $totalEnrollments > 0 ? round(($completed / $totalEnrollments) * 100, 2) : 0;

        $assignments = Assignment::withoutTenant()->where('tenant_id', $tenantId)->count();

        // ── Cards ─────────────────────────────────────
        $cardsTotal = ActivationCard::withoutTenant()->where('tenant_id', $tenantId)->count();
        $cardsRedeemed = ActivationCard::withoutTenant()->where('tenant_id', $tenantId)
            ->where('status', 'redeemed')->count();
        $cardsAvailable = ActivationCard::withoutTenant()->where('tenant_id', $tenantId)
            ->where('status', 'generated')->count();

        return response()->json([
            'teacher' => $this->teacherPayload($user),
            'tenant' => $tenant ? new TenantResource($tenant->load([
                'settings' => fn ($settings) => $settings->withoutTenant(),
                'subscriptions' => fn ($subscriptions) => $subscriptions
                    ->withoutTenant()
                    ->with('plan')
                    ->latest('starts_at'),
            ])) : null,
            'access' => $this->accessInfo($tenantId),
            'stats' => [
                'courses' => $courses->count(),
                'students' => $students->count(),
                'enrollments' => $totalEnrollments,
                'completion_rate' => $completionRate,
                'exams' => $totalExams,
                'attempts' => $submittedCount,
                'avg_score' => $avgScore,
                'pass_rate' => $passRate,
                'assignments' => $assignments,
                'cards' => $cardsTotal,
                'cards_redeemed' => $cardsRedeemed,
                'cards_available' => $cardsAvailable,
                'revenue' => $totalRevenue,
                'revenue_30d' => $monthRevenue,
                'payments_count' => $payments->count(),
                'payments_paid' => Payment::withoutTenant()
                    ->where('tenant_id', $tenantId)->where('status', 'paid')->sum('amount'),
                'payments_pending' => Payment::withoutTenant()
                    ->where('tenant_id', $tenantId)->where('status', 'pending')->sum('amount'),
            ],
            'courses' => $courses,
            'students' => $students,
            'payments' => $payments,
            'revenue_by_course' => $revenueByCourse,
        ]);
    }

    private function emptyTeacherStats(): array
    {
        return [
            'courses' => 0, 'students' => 0, 'enrollments' => 0, 'completion_rate' => 0,
            'exams' => 0, 'attempts' => 0, 'avg_score' => 0, 'pass_rate' => 0,
            'assignments' => 0, 'cards' => 0, 'cards_redeemed' => 0, 'cards_available' => 0,
            'revenue' => 0, 'revenue_30d' => 0, 'payments_count' => 0,
            'payments_paid' => 0, 'payments_pending' => 0,
        ];
    }

    public function teacherStatus(Request $request, string $id): JsonResponse
    {
        $user = User::role('Teacher')->findOrFail($id);
        $request->validate(['is_active' => 'required|boolean']);

        $user->update(['is_active' => $request->boolean('is_active')]);

        // Suspend/restore the teacher's tenants in sync.
        $tenantIds = TenantUser::where('user_id', $user->id)->where('status', 'active')->pluck('tenant_id');
        Tenant::whereIn('id', $tenantIds)->update(['status' => $request->boolean('is_active') ? 'active' : 'suspended']);

        // Deactivation must kill every live session immediately — bearer
        // tokens here and all web sessions through the version bump.
        if (! $request->boolean('is_active')) {
            $user->increment('auth_version');
            $user->tokens()->delete();
        }

        AuditLogger::log($request, 'admin.teacher_status', 'user', $user->id, ['is_active' => $request->boolean('is_active')]);

        return response()->json(['message' => 'Teacher status updated.', 'teacher' => $this->teacherPayload($user->fresh('tenantPivots.tenant'))]);
    }

    public function teacherDestroy(Request $request, string $id): JsonResponse
    {
        if ((string) $request->user()->id === $id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $user = User::role('Teacher')->findOrFail($id);

        // Soft-delete the teacher and their tenants (data preserved for audits).
        $tenantIds = TenantUser::where('user_id', $user->id)->pluck('tenant_id');
        Tenant::whereIn('id', $tenantIds)->delete();
        $user->increment('auth_version');
        $user->tokens()->delete();
        $user->delete();

        AuditLogger::log($request, 'admin.teacher_deleted', 'user', $id, ['email' => $user->email]);

        return response()->json(['message' => 'Teacher deleted.']);
    }

    private function teacherPayload(User $user): array
    {
        $pivot = $user->tenantPivots->first();
        $tenant = $pivot?->tenant;
        $subscription = $tenant?->subscriptions->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_active' => (bool) $user->is_active,
            'created_at' => $user->created_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'academy_name' => $tenant->settings?->academy_name,
            ] : null,
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'plan_name' => $subscription->plan?->name,
                'status' => $subscription->status,
                'billing_cycle' => $subscription->billing_cycle,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'ends_at' => $subscription->ends_at?->toIso8601String(),
                'admin_ends_at' => $subscription->admin_ends_at?->toIso8601String(),
            ] : null,
            'has_subscription' => (bool) $subscription,
            // Cheap list-level flag derived from the eager-loaded rows (no
            // extra queries): does an administrative grant currently apply?
            'admin_extension' => (bool) ($tenant?->subscriptions?->contains(
                fn (TenantSubscription $s) => (bool) ($s->admin_ends_at && $s->admin_ends_at->isFuture())
            ) ?? false),
        ];
    }

    // ─── Students ─────────────────────────────────────

    public function students(Request $request): JsonResponse
    {
        $query = User::query()
            ->whereHas('tenantPivots', fn ($q) => $q->withoutGlobalScope('tenant')->where('role', 'student'))
            ->withCount(['enrollments']);

        if ($request->filled('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', '%'.$request->search.'%')
                ->orWhere('email', 'like', '%'.$request->search.'%')
                ->orWhere('phone', 'like', '%'.$request->search.'%'));
        }
        if ($request->filled('tenant_id')) {
            $query->whereHas('tenantPivots', fn ($q) => $q->withoutGlobalScope('tenant')->where('tenant_id', $request->tenant_id));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $students = $query->orderByDesc('created_at')->limit(200)->get();

        $studentStats = User::whereHas('tenantPivots', fn ($q) => $q->withoutGlobalScope('tenant')->where('role', 'student'))
            ->selectRaw('count(*) as total, sum(case when is_active then 1 else 0 end) as active, sum(case when not is_active then 1 else 0 end) as inactive')
            ->first();

        $stats = [
            'total' => (int) ($studentStats->total ?? 0),
            'active' => (int) ($studentStats->active ?? 0),
            'inactive' => (int) ($studentStats->inactive ?? 0),
            'enrolled' => DB::table('enrollments')->count(),
        ];

        return response()->json(['students' => $students, 'stats' => $stats]);
    }

    public function studentShow(string $id): JsonResponse
    {
        $student = User::withCount('enrollments')
            ->with([
                'enrollments.course.sections',
                'tenantPivots' => fn ($q) => $q->withoutGlobalScope('tenant')->with('tenant'),
            ])
            ->findOrFail($id);

        $devices = UserDevice::where('user_id', $student->id)->latest()->limit(10)->get();
        $points = (int) StudentPoint::where('user_id', $student->id)->sum('points');

        return response()->json([
            'student' => $this->studentPayload($student),
            'devices' => $devices,
            'total_points' => $points,
            'enrollments' => $student->enrollments->map(fn ($e) => [
                'id' => $e->id,
                'course_id' => $e->course_id,
                'course_title' => $e->course?->title,
                'tenant_name' => $e->course?->tenant?->name,
                'status' => $e->status,
                'progress_percent' => $e->progress_percent ?? 0,
                'enrolled_at' => $e->enrolled_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function studentStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,NULL,id,deleted_at,NULL',
            'password' => 'nullable|string|min:8',
            'tenant_id' => 'nullable|exists:tenants,id',
            'course_id' => 'nullable|exists:courses,id',
        ]);

        if (User::onlyTrashed()->where('email', strtolower($validated['email']))->exists()) {
            return response()->json([
                'message' => 'هذا البريد مرتبط بحساب محذوف — استخدم بريداً آخر، أو أطلب من صاحبه استعادة الحساب بتسجيل الدخول.',
                'code' => 'email_previously_deleted',
            ], 422);
        }

        $password = $validated['password'] ?? Str::random(12);

        $user = DB::transaction(function () use ($request, $validated, $password) {
            $tenantId = $validated['tenant_id'] ?? Tenant::where('status', 'active')->first()?->id;

            $course = null;
            if ($tenantId && $request->filled('course_id')) {
                $course = Course::where('tenant_id', $tenantId)->findOrFail($request->course_id);
                // Fail before the account is created if the teacher's seat
                // limit is full — never leave an orphan student.
                app(EnrollmentService::class)->assertNewSeatAvailable($tenantId);
            }

            $user = User::create([
                'id' => UuidV7::generate(),
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
                'locale' => 'ar',
            ]);
            $user->assignRole('Student');

            if ($tenantId) {
                TenantUser::firstOrCreate(
                    ['tenant_id' => $tenantId, 'user_id' => $user->id],
                    ['role' => 'student', 'status' => 'active', 'joined_at' => now(), 'metadata' => []]
                );

                if ($course) {
                    // Seat limit, duplicate and per-course cap are enforced
                    // centrally under the tenant lock.
                    app(EnrollmentService::class)->enroll($course, $user, ['source' => 'admin']);
                }
            }

            return $user;
        });

        AuditLogger::log($request, 'admin.student_created', 'user', $user->id, ['email' => $user->email]);

        return response()->json([
            'message' => 'Student created.',
            'student' => $this->studentPayload($user->load('tenantPivots.tenant')),
            'generated_password' => $request->filled('password') ? null : $password,
        ], 201);
    }

    public function studentDestroy(Request $request, string $id): JsonResponse
    {
        if ((string) $request->user()->id === $id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $student = User::whereHas('tenantPivots', fn ($q) => $q->withoutGlobalScope('tenant')->where('role', 'student'))
            ->findOrFail($id);

        $student->increment('auth_version');
        $student->tokens()->delete();
        $student->delete();

        AuditLogger::log($request, 'admin.student_deleted', 'user', $id, ['email' => $student->email]);

        return response()->json(['message' => 'Student deleted.']);
    }

    public function tenantOptions(): JsonResponse
    {
        return response()->json([
            'tenants' => Tenant::where('status', '!=', 'suspended')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'status'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug]),
        ]);
    }

    private function studentPayload(User $student): array
    {
        return [
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'phone' => $student->phone,
            'is_active' => (bool) $student->is_active,
            'created_at' => $student->created_at?->toIso8601String(),
            'enrollments_count' => (int) $student->enrollments_count,
            'tenants' => $student->tenantPivots->map(fn ($p) => [
                'id' => $p->tenant_id,
                'name' => $p->tenant?->name,
                'slug' => $p->tenant?->slug,
                'role' => $p->role,
            ])->values(),
        ];
    }

    public function studentStatus(Request $request, string $id): JsonResponse
    {
        $student = User::findOrFail($id);
        $request->validate(['is_active' => 'required|boolean']);

        $student->update(['is_active' => $request->boolean('is_active')]);

        // Deactivated students lose their sessions immediately (tokens and
        // every web session via the version bump).
        if (! $request->boolean('is_active')) {
            $student->increment('auth_version');
            $student->tokens()->delete();
        }

        return response()->json(['message' => 'Student status updated.', 'student' => $student]);
    }

    // ─── Payments ─────────────────────────────────────

    public function payments(Request $request): JsonResponse
    {
        $query = Payment::withoutTenant()
            ->with(['tenant', 'subscription.plan', 'invoices']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('gateway')) {
            $query->where('gateway', $request->gateway);
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('transaction_id', 'ilike', '%'.$request->search.'%')
                    ->orWhereHas('tenant', fn ($t) => $t->where('name', 'ilike', '%'.$request->search.'%'));
            });
        }

        $rows = $query->orderByDesc('created_at')->limit(300)->get();

        $payments = $rows->map(fn (Payment $p) => $this->paymentPayload($p));

        // Sum completed/refunded amounts per currency. Never collapse different
        // currencies into one scalar — that would report a meaningless total.
        $currencyExpr = DB::raw("UPPER(COALESCE(NULLIF(currency, ''), 'USD'))");
        $completedByCurrency = Payment::withoutTenant()
            ->where('status', 'completed')
            ->selectRaw('UPPER(COALESCE(NULLIF(currency, \'\'), \'USD\')) as code, SUM(amount) as total')
            ->groupBy($currencyExpr)
            ->pluck('total', 'code')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
        $refundedByCurrency = Payment::withoutTenant()
            ->where('status', 'refunded')
            ->selectRaw('UPPER(COALESCE(NULLIF(currency, \'\'), \'USD\')) as code, SUM(amount) as total')
            ->groupBy($currencyExpr)
            ->pluck('total', 'code')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
        ksort($completedByCurrency);
        ksort($refundedByCurrency);

        $stats = [
            'total' => Payment::withoutTenant()->count(),
            'completed' => Payment::withoutTenant()->where('status', 'completed')->count(),
            'pending' => Payment::withoutTenant()->where('status', 'pending')->count(),
            'failed' => Payment::withoutTenant()->where('status', 'failed')->count(),
            'refunded' => Payment::withoutTenant()->where('status', 'refunded')->count(),
            // Platform-currency scalar kept for backwards compatibility; the UI
            // renders the per-currency map so multiple currencies never mix.
            'revenue' => round((float) ($completedByCurrency['USD'] ?? 0), 2),
            'revenue_by_currency' => $completedByCurrency,
            'refunded_total' => round((float) ($refundedByCurrency['USD'] ?? 0), 2),
            'refunded_total_by_currency' => $refundedByCurrency,
        ];

        return response()->json(['payments' => $payments, 'stats' => $stats]);
    }

    public function paymentShow(string $id): JsonResponse
    {
        $payment = Payment::withoutTenant()
            ->with(['tenant', 'subscription.plan', 'invoices', 'booking'])
            ->findOrFail($id);

        $transactions = Transaction::withoutTenant()
            ->where('reference_id', $payment->id)
            ->latest()
            ->limit(10)
            ->get();

        return response()->json([
            'payment' => $this->paymentPayload($payment, full: true),
            'transactions' => $transactions,
        ]);
    }

    public function paymentProof(Request $request, string $id)
    {
        $payment = Payment::withoutTenant()
            ->where('id', $id)
            ->where('gateway', 'manual')
            ->firstOrFail();

        $proofPath = ($payment->gateway_data ?? [])['proof_path'] ?? null;

        if (! $proofPath || ! Storage::disk('local')->exists($proofPath)) {
            abort(404, 'Proof not found.');
        }

        return Storage::disk('local')->response(
            $proofPath,
            'proof-'.$payment->id.'.'.pathinfo($proofPath, PATHINFO_EXTENSION)
        );
    }

    public function paymentStatus(Request $request, string $id): JsonResponse
    {
        $payment = Payment::withoutTenant()->findOrFail($id);
        $request->validate(['status' => 'required|in:completed,pending,failed,refunded']);

        $oldStatus = $payment->status;

        // Completing an admin payment must run the same side effects as a
        // gateway callback: ledger row, coupon release, subscription
        // activation, invoice. Otherwise revenue stats diverge from the ledger.
        if ($request->status === 'completed' && $oldStatus !== 'completed') {
            $affected = Payment::withoutTenant()
                ->where('id', $payment->id)
                ->where('status', '!=', 'completed')
                ->update(['status' => 'completed', 'paid_at' => now()]);

            if ($affected === 1) {
                $this->paymentCompleter()->complete($payment);

                // Manual (Click/wallet) payments: tell the tenant owner their
                // plan is now active.
                if ($payment->gateway === 'manual') {
                    $owner = TenantUser::withoutTenant()
                        ->where('tenant_id', $payment->tenant_id)
                        ->whereIn('role', ['teacher', 'owner', 'admin'])
                        ->first()?->user;

                    if ($owner) {
                        try {
                            app(NotificationService::class)->notify(
                                notifiable: $owner,
                                type: 'payment.approved',
                                title: 'تم قبول دفعتك',
                                body: "تمت الموافقة على دفعتك ({$payment->amount} {$payment->currency}) وتم تفعيل خطتك.",
                                tenantId: $payment->tenant_id,
                                data: ['payment_id' => $payment->id, 'amount' => $payment->amount],
                            );
                        } catch (\Throwable) {
                            // Notification failures must never roll back the payment.
                        }
                    }
                }
            }
        } elseif ($request->status === 'refunded' && $oldStatus !== 'refunded') {
            // The real gateway must confirm the refund before the platform
            // records it — money was collected, so a status flip alone is a
            // lie. Platform-subscription refunds are issued in Freemius;
            // booking refunds are issued in the booking gateway dashboard.
            if (in_array($payment->gateway, ['fake', 'card'], true)) {
                $payment->update(['status' => 'refunded']);

                Transaction::create([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $payment->tenant_id,
                    'type' => 'refund',
                    'amount' => $payment->amount,
                    'direction' => 'out',
                    'balance_before' => 0,
                    'balance_after' => 0,
                    'currency' => $payment->currency,
                    'status' => 'completed',
                    'reference_type' => 'payment',
                    'reference_id' => $payment->id,
                    'description' => "Refund for payment {$payment->transaction_id}",
                ]);

                Invoice::where('payment_id', $payment->id)->update(['status' => 'cancelled']);
                $payment->subscription?->update(['status' => 'cancelled', 'cancelled_at' => now(), 'auto_renew' => false]);
            } else {
                $message = $payment->gateway === 'freemius'
                    ? 'Refunds for Freemius subscriptions must be issued from the Freemius dashboard; the platform will sync the resulting entitlement change via webhook.'
                    : "Refunds for {$payment->gateway} payments must be processed inside the gateway dashboard; the platform will sync via webhook.";

                return response()->json(['message' => $message], 422);
            }
        } else {
            $payment->update(['status' => $request->status]);
        }

        AuditLogger::log($request, 'admin.payment_status', 'payment', $payment->id, ['status' => $request->status]);

        return response()->json(['message' => 'Payment status updated.', 'payment' => $payment->fresh(['tenant', 'subscription'])]);
    }

    private function paymentCompleter(): PaymentCompletionService
    {
        return app(PaymentCompletionService::class);
    }

    private function paymentPayload(Payment $p, bool $full = false): array
    {
        // اسم الدافع الحقيقي: صاحب حساب المنصة (أول مستخدم بصلاحيات مالك).
        $payerName = null;
        if ($p->tenant_id) {
            $owner = TenantUser::where('tenant_id', $p->tenant_id)
                ->whereIn('role', ['owner', 'admin'])
                ->where('status', 'active')
                ->orderBy('created_at')
                ->first();

            $payerName = $owner ? (User::find($owner->user_id)?->name ?? null) : null;
        }

        $payload = [
            'id' => $p->id,
            'payer_name' => $payerName,
            'tenant' => $p->tenant ? ['id' => $p->tenant->id, 'name' => $p->tenant->name, 'slug' => $p->tenant->slug] : null,
            'gateway' => $p->gateway,
            'transaction_id' => $p->transaction_id,
            'amount' => (float) $p->amount,
            'currency' => $p->currency,
            'status' => $p->status,
            'payment_method' => $p->payment_method,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
            'plan' => $p->subscription?->plan ? [
                'id' => $p->subscription->plan->id,
                'name' => $p->subscription->plan->name,
            ] : null,
            'subscription_status' => $p->subscription?->status,
            'invoice' => $p->invoices->first() ? [
                'id' => $p->invoices->first()->id,
                'number' => $p->invoices->first()->invoice_number,
                'status' => $p->invoices->first()->status,
                'total' => (float) $p->invoices->first()->total_amount,
            ] : null,
            'booking_id' => $p->booking_id,
        ];

        if ($full) {
            $payload['gateway_data'] = $p->gateway_data;
            $payload['tenant_full'] = $p->tenant;
            $payload['subscription'] = $p->subscription ? [
                'id' => $p->subscription->id,
                'status' => $p->subscription->status,
                'billing_cycle' => $p->subscription->billing_cycle,
                'trial_ends_at' => $p->subscription->trial_ends_at?->toIso8601String(),
                'ends_at' => $p->subscription->ends_at?->toIso8601String(),
                'plan' => $p->subscription->plan?->name,
            ] : null;
            $payload['invoice_full'] = $p->invoices->first();
            $payload['booking'] = $p->booking ? [
                'id' => $p->booking->id,
                'kind' => $p->booking->kind,
                'status' => $p->booking->status,
                'starts_at' => $p->booking->starts_at?->toIso8601String(),
                'price' => (float) $p->booking->price,
            ] : null;
        }

        return $payload;
    }

    // ─── Coupons ──────────────────────────────────────

    public function coupons(Request $request): JsonResponse
    {
        $query = Coupon::withoutTenant()->with('tenant');

        if ($request->filled('search')) {
            $query->where('code', 'like', '%'.$request->search.'%');
        }
        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        $coupons = $query->orderByDesc('created_at')->get();

        return response()->json([
            'coupons' => $coupons,
            'stats' => [
                'total' => Coupon::withoutTenant()->count(),
                'active' => Coupon::withoutTenant()->where('is_active', true)->count(),
                'total_uses' => Coupon::withoutTenant()->sum('used_count'),
                'expired' => Coupon::withoutTenant()->whereNotNull('expires_at')->where('expires_at', '<', now())->count(),
                'global' => Coupon::withoutTenant()->whereNull('tenant_id')->count(),
            ],
        ]);
    }

    public function couponStore(Request $request): JsonResponse
    {
        $request->validate($this->couponRules());

        $coupon = Coupon::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $request->input('tenant_id') ?: null,
            'code' => strtoupper(trim($request->code)),
            'type' => $request->type,
            'value' => $request->value,
            'currency' => $request->input('currency', 'JOD'),
            'max_uses' => $request->filled('max_uses') ? $request->integer('max_uses') : null,
            'starts_at' => $request->input('starts_at') ?: null,
            'expires_at' => $request->input('expires_at') ?: null,
            'is_active' => $request->boolean('is_active', true),
            'description' => $request->input('description'),
        ]);

        return response()->json(['message' => 'Coupon created.', 'coupon' => $coupon], 201);
    }

    public function couponUpdate(Request $request, string $id): JsonResponse
    {
        $coupon = Coupon::withoutTenant()->findOrFail($id);

        $request->validate($this->couponRules(partial: true));

        $data = [];
        foreach ([
            'type' => $request->input('type'),
            'value' => $request->input('value'),
            'currency' => $request->input('currency'),
            'description' => $request->input('description'),
        ] as $key => $value) {
            if ($request->has($key) && $value !== null) {
                $data[$key] = $value;
            }
        }

        // Explicit nulls are allowed for these: they CLEAR the value
        // (unlimited uses / no start / no expiry).
        foreach (['max_uses', 'starts_at', 'expires_at'] as $key) {
            if ($request->has($key)) {
                $data[$key] = $request->input($key) ?: null;
            }
        }

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $coupon->update($data);

        return response()->json(['message' => 'Coupon updated.', 'coupon' => $coupon->fresh()]);
    }

    private function couponRules(bool $partial = false): array
    {
        $prefix = $partial ? 'nullable' : 'required';

        // The route parameter is a raw id string (no model binding here).
        $ignore = $partial && request()->route('coupon')
            ? ','.request()->route('coupon').',id'
            : '';

        // Percent coupons are capped at 100%; fixed amounts are not.
        $type = $partial
            ? 'nullable|string|in:percent,fixed'
            : 'required|string|in:percent,fixed';
        $valueMax = request()->input('type') === 'fixed' ? '1000000' : '100';

        return [
            'code' => ($partial ? 'sometimes|nullable' : 'required').'|string|max:50|unique:coupons,code'.$ignore,
            'type' => $type,
            'value' => $prefix.'|numeric|min:0.01|max:'.$valueMax,
            'currency' => 'nullable|string|max:10',
            'tenant_id' => 'nullable|exists:tenants,id',
            'max_uses' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string|max:255',
        ];
    }

    public function couponToggle(string $id): JsonResponse
    {
        $coupon = Coupon::withoutTenant()->findOrFail($id);
        $coupon->update(['is_active' => ! $coupon->is_active]);

        return response()->json(['message' => 'Coupon toggled.', 'coupon' => $coupon->fresh()]);
    }

    public function couponDestroy(string $id): JsonResponse
    {
        Coupon::withoutTenant()->findOrFail($id)->delete();

        return response()->json(['message' => 'Coupon deleted.']);
    }

    // ─── Support Tickets ──────────────────────────────

    public function tickets(Request $request): JsonResponse
    {
        $query = SupportTicket::withoutTenant()->with(['user', 'tenant']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('channel')) {
            $query->where('channel', $request->channel);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('search')) {
            $query->where(fn ($q) => $q
                ->where('subject', 'like', '%'.$request->search.'%')
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%'.$request->search.'%')));
        }

        $tickets = $query
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'pending' THEN 1 WHEN 'resolved' THEN 2 WHEN 'closed' THEN 3 ELSE 4 END")
            ->orderByDesc('created_at')
            ->get();

        SupportTicket::attachLatestMessages($tickets);

        $stats = [
            'total' => SupportTicket::withoutTenant()->count(),
            'open' => SupportTicket::withoutTenant()->where('status', 'open')->count(),
            'pending' => SupportTicket::withoutTenant()->where('status', 'pending')->count(),
            'resolved' => SupportTicket::withoutTenant()->where('status', 'resolved')->count(),
            'closed' => SupportTicket::withoutTenant()->where('status', 'closed')->count(),
            'urgent' => SupportTicket::withoutTenant()->where('priority', 'urgent')->whereIn('status', ['open', 'pending'])->count(),
        ];

        return response()->json(['tickets' => $tickets, 'stats' => $stats]);
    }

    public function ticketShow(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $ticket = $user->is_super_admin
            ? SupportTicket::withoutTenant()->with(['user', 'tenant', 'assignee', 'messages.user'])->findOrFail($id)
            : SupportTicket::with(['user', 'tenant', 'assignee', 'messages.user'])->findOrFail($id);

        if ($user->is_super_admin) {
            return response()->json(['ticket' => $ticket]);
        }

        $pivot = $user->tenantPivots()
            ->where('tenant_id', $request->header('X-Tenant-Id'))
            ->where('status', 'active')
            ->first();

        $isStaff = $pivot && in_array($pivot->role, ['teacher', 'owner', 'admin'], true);

        if ($ticket->user_id !== $user->id && ! ($isStaff && $ticket->tenant_id === $request->header('X-Tenant-Id'))) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['ticket' => $ticket]);
    }

    public function ticketStore(Request $request): JsonResponse
    {
        $request->validate([
            'subject' => 'required|string|max:200',
            'category' => 'nullable|string|max:50',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'body' => 'required|string|max:10000',
        ]);

        $tenantId = $request->header('X-Tenant-Id');

        // The support channel comes from the tenant's plan:
        //   support_level = dedicated → live in-site chat
        //   support_level = email / priority (or no plan) → email only
        $supportLevel = app(PlanEngine::class)->value((string) $tenantId, 'support_level', 'email');
        $channel = $supportLevel === 'dedicated' ? 'chat' : 'email';

        $ticket = SupportTicket::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'user_id' => $request->user()->id,
            'subject' => $request->subject,
            'category' => $request->input('category', 'general'),
            'priority' => $request->input('priority', 'medium'),
            'channel' => $channel,
            'status' => 'open',
        ]);

        TicketMessage::create([
            'id' => UuidV7::generate(),
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'body' => $request->body,
            'metadata' => ['via' => 'site'],
        ]);

        return response()->json(['message' => 'Ticket created.', 'ticket' => $ticket->load(['user', 'messages.user'])], 201);
    }

    public function ticketReply(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $ticket = $user->is_super_admin
            ? SupportTicket::withoutTenant()->findOrFail($id)
            : SupportTicket::findOrFail($id);

        // The ticket owner can always reply; tenant staff (teacher/owner/admin)
        // of the ticket's tenant can reply on behalf of the tenant as well.
        if (! $user->is_super_admin && $ticket->user_id !== $user->id) {
            $pivot = $user->tenantPivots()
                ->where('tenant_id', $ticket->tenant_id)
                ->where('status', 'active')
                ->first();

            $isStaff = $pivot && in_array($pivot->role, ['teacher', 'owner', 'admin'], true);

            if (! $isStaff) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $request->validate(['body' => 'required|string|max:10000']);

        $message = TicketMessage::create([
            'id' => UuidV7::generate(),
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => $request->body,
            'metadata' => ['via' => $ticket->channel === 'chat' ? 'chat' : 'email'],
        ]);

        // Email-only plans: every reply is delivered by email to the other
        // side — there is no live chat on the site for them.
        if ($ticket->channel === 'email') {
            $recipient = $user->is_super_admin
                ? $ticket->user
                : User::where('is_super_admin', true)->first();

            if ($recipient) {
                try {
                    Mail::to($recipient->email)->send(
                        new PlatformNotification(
                            title: 'Support: '.$ticket->subject,
                            body: $request->body,
                            data: [
                                'action_url' => url('/tickets'),
                                'action_text' => $user->is_super_admin ? 'Open ticket' : 'افتح التذكرة',
                                'ticket_id' => $ticket->id,
                            ],
                        )
                    );
                } catch (\Throwable $e) {
                    Log::warning('Support email failed', ['error' => $e->getMessage()]);
                }
            }
        }

        if ($user->is_super_admin && in_array($ticket->status, ['open', 'pending'])) {
            $ticket->update(['status' => 'pending']);
        }
        if (! $user->is_super_admin && $ticket->status === 'closed') {
            $ticket->update(['status' => 'open']);
        }

        return response()->json(['message' => 'Reply sent.', 'ticket_message' => $message]);
    }

    public function ticketStatus(Request $request, string $id): JsonResponse
    {
        $ticket = SupportTicket::withoutTenant()->findOrFail($id);
        $request->validate(['status' => 'required|in:open,pending,resolved,closed']);

        $ticket->update([
            'status' => $request->status,
            'resolved_at' => in_array($request->status, ['resolved', 'closed']) ? now() : null,
        ]);

        return response()->json(['message' => 'Ticket status updated.', 'ticket' => $ticket->fresh()]);
    }

    public function ticketAssign(Request $request, string $id): JsonResponse
    {
        $ticket = SupportTicket::withoutTenant()->findOrFail($id);
        $request->validate(['assigned_to' => 'nullable|exists:users,id']);

        $ticket->update(['assigned_to' => $request->input('assigned_to')]);

        return response()->json(['message' => 'Ticket assigned.', 'ticket' => $ticket->fresh()]);
    }

    // ─── Error Logs ───────────────────────────────────

    public function errorLogs(Request $request): JsonResponse
    {
        $query = ErrorLog::query();

        if ($request->filled('level')) {
            $query->where('level', $request->level);
        }
        if ($request->filled('search')) {
            $query->where('message', 'like', '%'.$request->search.'%');
        }

        $logs = $query->orderByDesc('created_at')->paginate(50);

        $stats = [
            'total' => ErrorLog::count(),
            'error' => ErrorLog::where('level', 'error')->count(),
            'warning' => ErrorLog::where('level', 'warning')->count(),
            'critical' => ErrorLog::where('level', 'critical')->count(),
        ];

        return response()->json(['logs' => $logs, 'stats' => $stats]);
    }

    public function errorLogDestroy(string $id): JsonResponse
    {
        ErrorLog::findOrFail($id)->delete();

        return response()->json(['message' => 'Log deleted.']);
    }

    public function errorLogsClear(): JsonResponse
    {
        ErrorLog::query()->delete();

        return response()->json(['message' => 'All logs cleared.']);
    }

    // ─── Teacher-side tickets ─────────────────────────

    public function myTickets(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = SupportTicket::with(['user'])
            ->where('user_id', $user->id);

        if (! $user->is_super_admin) {
            $pivot = $user->tenantPivots()
                ->where('tenant_id', $request->header('X-Tenant-Id'))
                ->where('status', 'active')
                ->first();

            $isStaff = $pivot && in_array($pivot->role, ['teacher', 'owner', 'admin'], true);

            if ($isStaff) {
                $query->orWhere('tenant_id', $request->header('X-Tenant-Id'));
            }
        } else {
            $query->orWhere('tenant_id', $request->header('X-Tenant-Id'));
        }

        $tickets = $query->orderByDesc('created_at')->get();

        SupportTicket::attachLatestMessages($tickets);

        return response()->json(['tickets' => $tickets]);
    }
}
