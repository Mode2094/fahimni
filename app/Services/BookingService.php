<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\TeacherAvailability;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;

class BookingService
{
    public const DEFAULT_SLOT_MINUTES = 30;

    public function tenantTimezone(Tenant $tenant): string
    {
        return $tenant->timezone ?: 'Asia/Riyadh';
    }

    /**
     * Generate free time slots for a teacher on a given date (in tenant timezone).
     */
    public function freeSlots(Tenant $tenant, User $teacher, string $date, int $durationMinutes = self::DEFAULT_SLOT_MINUTES): array
    {
        $tz = $this->tenantTimezone($tenant);
        $day = Carbon::parse($date, $tz);
        $weekday = (int) $day->format('N'); // 1 (Mon) - 7 (Sun)

        $availabilities = TeacherAvailability::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $teacher->id)
            ->where('is_active', true)
            ->get();

        $slots = [];
        foreach ($availabilities as $availability) {
            if ((int) $availability->day_of_week !== $weekday) {
                continue;
            }

            $start = Carbon::parse($date.' '.$availability->start_time, $tz);
            $end = Carbon::parse($date.' '.$availability->end_time, $tz);
            $step = max(15, (int) $availability->slot_minutes ?: self::DEFAULT_SLOT_MINUTES);
            $duration = max(15, $durationMinutes);

            while ($start->copy()->addMinutes($duration)->lte($end)) {
                $slotStart = $start->copy();
                $slotEnd = $start->copy()->addMinutes($duration);

                // Eloquent stores datetimes as UTC wall time; compare in UTC for consistency.
                if ($slotStart->isFuture() && ! $this->hasConflict($tenant, $teacher, $slotStart->copy()->setTimezone('UTC'), $slotEnd->copy()->setTimezone('UTC'))) {
                    $slots[] = [
                        'starts_at' => $slotStart->toIso8601String(),
                        'ends_at' => $slotEnd->toIso8601String(),
                        'start_time' => $slotStart->format('H:i'),
                        'end_time' => $slotEnd->format('H:i'),
                    ];
                }

                $start->addMinutes($step);
            }
        }

        usort($slots, fn ($a, $b) => $a['starts_at'] <=> $b['starts_at']);

        return $slots;
    }

    /**
     * A booking conflicts if the teacher already has a pending/confirmed booking overlapping the window.
     */
    public function hasConflict(Tenant $tenant, User $teacher, Carbon $startsAt, Carbon $endsAt): bool
    {
        return Booking::query()
            ->where('tenant_id', $tenant->id)
            ->where('teacher_id', $teacher->id)
            ->whereIn('status', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }
}
