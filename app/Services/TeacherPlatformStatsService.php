<?php

namespace App\Services;

use App\Models\TenantSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Live platform counts for the "new teacher" concierge alert.
 *
 * Every number is computed from the real database — never a cached or
 * hardcoded figure — and counts **teacher accounts only**. Students, parents
 * and platform admins are deliberately excluded, so the headline number can
 * never overstate how many teachers joined.
 *
 * The reporting clock is Jordan local time (Asia/Amman), which is the platform
 * owner's business timezone: "today" and "this month" mean the Jordanian
 * calendar day/month, matching the timestamp shown in the email.
 */
class TeacherPlatformStatsService
{
    /** The platform owner's business timezone (Asia/Amman). */
    public const TIMEZONE = 'Asia/Amman';

    /**
     * @return array{total:int,today:int,this_month:int,paid_subscribers:int}
     */
    public function snapshot(): array
    {
        $now = CarbonImmutable::now(self::TIMEZONE);
        $startOfToday = $now->startOfDay()->utc();
        $startOfMonth = $now->startOfMonth()->utc();

        $teacherIds = $this->teacherUserIds();

        return [
            'total' => $teacherIds->count(),
            'today' => $this->countCreatedSince($teacherIds, $startOfToday),
            'this_month' => $this->countCreatedSince($teacherIds, $startOfMonth),
            'paid_subscribers' => $this->paidSubscriberCount(),
        ];
    }

    /**
     * Distinct user ids that hold an active teaching role on the platform.
     * Reuses the platform's canonical teacher-role set so "teacher" means the
     * same thing everywhere.
     *
     * @return Collection<int,string>
     */
    public function teacherUserIds(): Collection
    {
        return DB::table('tenant_user')
            ->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->where('tenant_user.status', 'active')
            ->whereIn('tenant_user.role', AdminNotificationService::TEACHER_ROLES)
            ->whereNull('users.deleted_at')
            ->where('users.is_super_admin', false)
            ->where('users.is_active', true)
            ->distinct()
            ->pluck('tenant_user.user_id');
    }

    private function countCreatedSince(Collection $teacherIds, \DateTimeInterface $since): int
    {
        if ($teacherIds->isEmpty()) {
            return 0;
        }

        return User::query()
            ->whereIn('id', $teacherIds->all())
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * Currently paid (actively-billed) subscriptions.
     *
     * Uses the exact same definition as the admin dashboard
     * (`AdminController::subscriptions`) and `SaasCostService`: rows with
     * status `active`. Free trials (`trialing`) are excluded, so the owner sees
     * the same number here as everywhere else in the product.
     */
    public function paidSubscriberCount(): int
    {
        return TenantSubscription::withoutTenant()
            ->where('status', 'active')
            ->count();
    }
}
