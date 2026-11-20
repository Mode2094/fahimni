<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\User;
use App\Plans\PlanEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Academic-term lifecycle: previewing and executing «إنهاء الفصل وتجديد
 * مقاعد الطلاب», plus promoting continuing students into a next term.
 *
 * Core invariants:
 *  - Nothing is ever deleted. Ending a term archives enrollment rows so all
 *    grades / attempts / certificates that reference them stay intact.
 *  - A seat is released only when a student has NO other effective enrollment
 *    with the teacher. A student still active in another course/term keeps it.
 *  - The whole operation runs under the tenant seat lock and is idempotent:
 *    ending an already-ended term returns the recorded summary.
 */
class AcademicTermService
{
    public function __construct(
        private readonly StudentSeatService $seats,
        private readonly EnrollmentService $enrollments,
        private readonly PlanEngine $engine,
    ) {}

    /**
     * @return array{used:int, limit:?int, remaining:?int, unlimited:bool}
     */
    public function seatSummary(string $tenantId): array
    {
        $used = $this->seats->count($tenantId);
        $limit = $this->engine->value($tenantId, 'students_limit');
        $unlimited = $limit === null || $limit === 'unlimited' || (int) $limit === 0;

        return [
            'used' => $used,
            'limit' => $unlimited ? null : (int) $limit,
            'remaining' => $unlimited ? null : max(0, (int) $limit - $used),
            'unlimited' => $unlimited,
        ];
    }

    /**
     * Effective enrollments belonging to a term, with their student + course.
     *
     * @return Collection<int, Enrollment>
     */
    public function termEnrollments(AcademicTerm $term): Collection
    {
        return $this->seats->effectiveQuery((string) $term->tenant_id)
            ->where('academic_term_id', $term->id)
            ->with(['student:id,name,email', 'course:id,title'])
            ->orderBy('user_id')
            ->get();
    }

    /**
     * Distinguish, for the students in a term, who will be freed vs. stay.
     *
     * @param  array<int, string>  $continueIds
     */
    public function endPreview(AcademicTerm $term, array $continueIds = []): array
    {
        $tenantId = (string) $term->tenant_id;
        $rows = $this->termEnrollments($term);

        $termUserIds = $rows->pluck('user_id')->unique()->values();
        $continueIds = collect($continueIds)->map(fn ($i) => (string) $i);

        // Students who still hold an effective enrollment outside this term
        // must NOT count as freed — their seat stays occupied.
        $activeElsewhere = $this->seats->effectiveQuery($tenantId)
            ->where(fn ($q) => $q->where('academic_term_id', '!=', $term->id)->orWhereNull('academic_term_id'))
            ->distinct()
            ->pluck('user_id');

        $freedIds = $termUserIds
            ->reject(fn ($id) => $activeElsewhere->contains($id) || $continueIds->contains($id))
            ->values();

        $continuingIds = $termUserIds->intersect($continueIds)->values();
        $keptIds = $termUserIds->filter(fn ($id) => $activeElsewhere->contains($id))->values();

        $before = $this->seats->count($tenantId);

        return [
            'term'              => $term->only(['id', 'name', 'status', 'starts_at', 'ends_at']),
            'students_count'    => $termUserIds->count(),
            'students'          => $this->studentRows($rows),
            'seats'             => $this->seatSummary($tenantId),
            'seats_before'      => $before,
            'seats_after'       => $before - $freedIds->count(),
            'seats_freed'       => $freedIds->count(),
            'free_ids'          => $freedIds,
            'continuing_ids'    => $continuingIds,
            'active_elsewhere_ids' => $keptIds,
        ];
    }

    /**
     * End a term: archive its enrollments, optionally carrying continuing
     * students into the next term. Idempotent.
     *
     * @param  array<int, string>  $continueIds
     */
    public function end(AcademicTerm $term, array $continueIds = [], ?AcademicTerm $nextTerm = null): array
    {
        if ($term->isArchived()) {
            return $term->metadata['end_summary'] ?? ['already_ended' => true, 'term_id' => $term->id];
        }

        $tenantId = (string) $term->tenant_id;

        return DB::transaction(function () use ($term, $continueIds, $nextTerm, $tenantId) {
            $locked = AcademicTerm::withoutTenant()->whereKey($term->id)->lockForUpdate()->firstOrFail();
            $this->seats->lockTenant($tenantId);

            $preview = $this->endPreview($locked, $continueIds);
            $continueIds = collect($continueIds)->map(fn ($i) => (string) $i);

            $rows = $this->seats->effectiveQuery($tenantId)
                ->where('academic_term_id', $locked->id)
                ->get();

            foreach ($rows as $enrollment) {
                $userId = (string) $enrollment->user_id;
                $continues = $continueIds->contains($userId);

                $enrollment->update([
                    'status'     => 'archived',
                    'archived_at' => now(),
                    'expires_at' => $enrollment->expires_at ?? now(),
                ]);

                if ($continues && $nextTerm) {
                    $this->enrollments->closeGuardedRows($tenantId, (string) $enrollment->course_id, (string) $enrollment->user_id);

                    Enrollment::create([
                        'id'               => UuidV7::generate(),
                        'tenant_id'        => $tenantId,
                        'course_id'        => $enrollment->course_id,
                        'user_id'          => $enrollment->user_id,
                        'academic_term_id' => $nextTerm->id,
                        'status'           => 'active',
                        'enrolled_at'      => now(),
                        'source'           => 'term_renewal',
                        'metadata'         => [
                            'continued_from_term_id'       => $locked->id,
                            'continued_from_enrollment_id' => $enrollment->id,
                        ],
                    ]);
                }
            }

            $summary = [
                'term_id'              => $locked->id,
                'ended_at'             => now()->toIso8601String(),
                'seats_before'         => $preview['seats_before'],
                'seats_after'          => $preview['seats_after'],
                'seats_freed'          => $preview['seats_freed'],
                'students_total'       => $preview['students_count'],
                'continuing_ids'       => $preview['continuing_ids']->values()->all(),
                'archived_ids'         => $preview['free_ids']->values()->all(),
                'active_elsewhere_ids' => $preview['active_elsewhere_ids']->values()->all(),
                'next_term_id'         => $nextTerm?->id,
            ];

            $locked->update([
                'status'      => AcademicTerm::STATUS_ARCHIVED,
                'archived_at' => now(),
                'metadata'    => array_merge($locked->metadata ?? [], ['end_summary' => $summary]),
            ]);

            $this->seats->flushUsageCache($tenantId);

            return $summary;
        });
    }

    /**
     * Move selected students into the next term without ending the current
     * one (their current-term enrollments are archived; new ones open).
     *
     * @param  array<int, string>  $userIds
     */
    public function promote(AcademicTerm $from, AcademicTerm $to, array $userIds): array
    {
        $tenantId = (string) $from->tenant_id;
        $userIds = collect($userIds)->map(fn ($i) => (string) $i);

        return DB::transaction(function () use ($from, $to, $userIds, $tenantId) {
            $this->seats->lockTenant($tenantId);

            $rows = $this->seats->effectiveQuery($tenantId)
                ->where('academic_term_id', $from->id)
                ->whereIn('user_id', $userIds)
                ->get();

            $moved = 0;

            foreach ($rows as $enrollment) {
                $enrollment->update([
                    'status'      => 'archived',
                    'archived_at' => now(),
                    'expires_at'  => $enrollment->expires_at ?? now(),
                ]);

                $this->enrollments->closeGuardedRows($tenantId, (string) $enrollment->course_id, (string) $enrollment->user_id);

                Enrollment::create([
                    'id'               => UuidV7::generate(),
                    'tenant_id'        => $tenantId,
                    'course_id'        => $enrollment->course_id,
                    'user_id'          => $enrollment->user_id,
                    'academic_term_id' => $to->id,
                    'status'           => 'active',
                    'enrolled_at'      => now(),
                    'source'           => 'term_promotion',
                    'metadata'         => ['promoted_from_term_id' => $from->id, 'from_enrollment_id' => $enrollment->id],
                ]);

                $moved++;
            }

            $this->seats->flushUsageCache($tenantId);

            return ['moved' => $moved, 'next_term_id' => $to->id];
        });
    }

    /**
     * Enroll a set of students into one course within a term, respecting the
     * tenant seat limit (already-counted students do not consume a new seat).
     *
     * @param  array<int, string>  $userIds
     */
    public function assignStudents(AcademicTerm $term, string $courseId, array $userIds): array
    {
        $tenantId = (string) $term->tenant_id;
        $course = \App\Models\Course::withoutTenant()->where('tenant_id', $tenantId)->findOrFail($courseId);

        $created = [];
        $failed = [];

        foreach (array_unique($userIds) as $userId) {
            $user = User::find($userId);

            if (! $user) {
                $failed[] = ['user_id' => $userId, 'reason' => 'not_found'];

                continue;
            }

            try {
                $enrollment = $this->enrollments->enroll($course, $user, [
                    'academic_term_id' => $term->id,
                    'source'           => 'term_assignment',
                ]);
                $created[] = $enrollment->user_id;
            } catch (\Throwable $e) {
                $failed[] = ['user_id' => $userId, 'reason' => $e->getMessage()];
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * @param  Collection<int, Enrollment>  $rows
     */
    private function studentRows(Collection $rows): array
    {
        return $rows
            ->groupBy('user_id')
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'user_id'    => $first->user_id,
                    'name'       => $first->student?->name,
                    'email'      => $first->student?->email,
                    'courses'    => $group->map(fn ($e) => ['id' => $e->course_id, 'title' => $e->course?->title])->values(),
                    'enrollment_ids' => $group->pluck('id')->values(),
                ];
            })
            ->values()
            ->all();
    }
}
