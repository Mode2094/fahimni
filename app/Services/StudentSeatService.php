<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for «how many students does this teacher have?».
 *
 * A student occupies exactly ONE seat per teacher regardless of how many
 * courses they are enrolled in. Seats are held only while the student has at
 * least one *effective* enrollment — one that grants access right now:
 *
 *   status ∈ (active, completed)  AND  (no expiry OR expiry in the future)
 *
 * Archived enrollments (a finished academic term) keep their row for history
 * but release the seat. Every consumer — plan limits, dashboards, terms —
 * must go through this service so the number can never disagree with itself.
 */
class StudentSeatService
{
    /**
     * Base query for the enrollments that hold a seat for a tenant.
     */
    public function effectiveQuery(string $tenantId): Builder
    {
        return Enrollment::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->effective();
    }

    /**
     * Distinct student ids currently holding a seat.
     *
     * @return Collection<int, string>
     */
    public function activeStudentIds(string $tenantId): Collection
    {
        return $this->effectiveQuery($tenantId)->distinct()->pluck('user_id');
    }

    public function count(string $tenantId): int
    {
        return (int) $this->effectiveQuery($tenantId)->distinct()->count('user_id');
    }

    /**
     * Does this student already occupy a seat for the tenant? Used to decide
     * whether a new enrollment needs a *new* seat or just re-uses the one the
     * student already holds (so a full plan never blocks a second course).
     */
    public function studentHoldsSeat(string $tenantId, string $userId): bool
    {
        return $this->effectiveQuery($tenantId)->where('user_id', $userId)->exists();
    }

    /**
     * Serialize every seat-affecting decision for a tenant. Call this FIRST,
     * inside a DB transaction, in every enrollment path: the tenant row lock
     * is the shared mutex that stops two concurrent enrollments in *different*
     * courses from both passing the seat check.
     */
    public function lockTenant(string $tenantId): void
    {
        Tenant::whereKey($tenantId)->lockForUpdate()->first();
    }

    /**
     * Drop the cached dashboard usage so limits reflect a mutation instantly.
     */
    public function flushUsageCache(string $tenantId): void
    {
        Cache::forget('plan:usage:'.$tenantId);
    }
}
