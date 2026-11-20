<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Plans\PlanEngine;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Academic-term lifecycle: linking existing students, previewing and
 * executing «إنهاء الفصل وتجديد مقاعد الطلاب», and promoting continuing
 * students into a next term.
 *
 * Core invariants:
 *  - Nothing is ever deleted. Ending a term archives enrollment rows so all
 *    grades / attempts / certificates that reference them stay intact.
 *  - Linking an existing enrollment to a term only sets `academic_term_id`
 *    on the SAME row — progress and results are never recreated.
 *  - A seat is released only when a student has NO other effective enrollment
 *    with the teacher. A student still active in another course/term keeps it.
 *  - Ending runs entirely under the tenant seat lock, locks the term row,
 *    re-checks state after the lock, and is idempotent.
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
     * Distinct students with an effective enrollment that is NOT yet bound to
     * any term — the pool a teacher can link into a term.
     *
     * @return Collection<int, User>
     */
    public function linkableStudents(string $tenantId, ?string $courseId = null): Collection
    {
        $userIds = $this->seats->effectiveQuery($tenantId)
            ->whereNull('academic_term_id')
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->distinct()
            ->pluck('user_id');

        return User::whereIn('id', $userIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /**
     * Attach EXISTING enrollments (with academic_term_id = NULL) to a term by
     * updating the same rows. No enrollment is deleted or recreated, so lesson
     * progress, certificates and results keep pointing at the original rows.
     *
     * @param  array<int, string>  $userIds
     * @return array{linked:int, users:array<int,string>, skipped:array<int,array{user_id:string,reason:string}>}
     */
    public function linkEnrollments(AcademicTerm $term, array $userIds, ?string $courseId = null): array
    {
        $tenantId = (string) $term->tenant_id;
        $this->enrollments->assertTermAcceptsEnrollment($tenantId, (string) $term->id);

        if ($courseId !== null) {
            // Course must belong to the same tenant.
            Course::withoutTenant()->where('tenant_id', $tenantId)->findOrFail($courseId);
        }

        $skipped = [];
        $valid = [];

        foreach (array_unique($userIds) as $userId) {
            $user = User::find($userId);

            if (! $user) {
                $skipped[] = ['user_id' => $userId, 'reason' => 'not_found'];

                continue;
            }

            try {
                $this->enrollments->assertTenantStudent($tenantId, $user);
                $valid[] = (string) $userId;
            } catch (HttpResponseException $e) {
                $skipped[] = ['user_id' => (string) $userId, 'reason' => 'not_a_tenant_student'];
            }
        }

        if ($valid === []) {
            return ['linked' => 0, 'users' => [], 'skipped' => $skipped];
        }

        $linked = DB::transaction(function () use ($term, $tenantId, $valid, $courseId) {
            $this->seats->lockTenant($tenantId);

            return Enrollment::withoutTenant()
                ->where('tenant_id', $tenantId)
                ->whereNull('academic_term_id')
                ->whereIn('user_id', $valid)
                ->whereIn('status', Enrollment::EFFECTIVE_STATUSES)
                ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
                ->update([
                    'academic_term_id' => $term->id,
                    'updated_at' => now(),
                ]);
        });

        $this->seats->flushUsageCache($tenantId);

        return ['linked' => (int) $linked, 'users' => $valid, 'skipped' => $skipped];
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
        $summary = [
            'term'                 => $term->only(['id', 'name', 'status', 'starts_at', 'ends_at']),
            'students_count'       => $termUserIds->count(),
            'students'             => $this->studentRows($rows),
            'seats'                => $this->seatSummary($tenantId),
            'seats_before'         => $before,
            'seats_after'          => $before - $freedIds->count(),
            'seats_freed'          => $freedIds->count(),
            'free_ids'             => $freedIds,
            'continuing_ids'       => $continuingIds,
            'active_elsewhere_ids' => $keptIds,
        ];

        $summary['signature'] = $this->previewSignature($term, $summary);

        return $summary;
    }

    /**
     * Server-truth signature for a preview. The UI must echo it back when it
     * confirms, so a stale selection can never execute a different outcome.
     *
     * @param  array<string, mixed>  $preview
     */
    public function previewSignature(AcademicTerm $term, array $preview): string
    {
        $continue = collect($preview['continuing_ids'] ?? [])->map(fn ($i) => (string) $i)->sort()->values()->all();

        return hash('sha256', implode('|', [
            (string) $term->id,
            (string) optional($term->updated_at)->getTimestamp(),
            (string) ($preview['seats_before'] ?? ''),
            (string) ($preview['seats_freed'] ?? ''),
            implode(',', $continue),
        ]));
    }

    /**
     * End a term: archive its enrollments, optionally carrying continuing
     * students into the next term. The whole thing — including creating or
     * resolving the next term — runs in one transaction under the tenant
     * lock, and is idempotent.
     *
     * @param  array<int, string>  $continueIds
     * @param  array{next_term_id?:?string, create?:array{name?:string, starts_at?:?string, ends_at?:?string}}  $nextTermSpec
     */
    public function end(
        AcademicTerm $term,
        array $continueIds = [],
        array|AcademicTerm|null $nextTermSpec = [],
        ?string $previewToken = null,
    ): array {
        $tenantId = (string) $term->tenant_id;

        // Back-compat: callers may pass a resolved AcademicTerm (or null)
        // instead of the spec array.
        if ($nextTermSpec instanceof AcademicTerm) {
            $nextTermSpec = ['next_term_id' => (string) $nextTermSpec->id];
        } elseif ($nextTermSpec === null) {
            $nextTermSpec = [];
        }

        return DB::transaction(function () use ($term, $continueIds, $nextTermSpec, $previewToken, $tenantId) {
            // Canonical lock order: tenant (seat mutex) first, then the term row.
            $this->seats->lockTenant($tenantId);

            $locked = AcademicTerm::withoutTenant()->whereKey($term->id)->lockForUpdate()->firstOrFail();

            // Re-check AFTER the lock: a concurrent request may have ended it.
            if ($locked->status === AcademicTerm::STATUS_ARCHIVED) {
                return $locked->metadata['end_summary'] ?? ['already_ended' => true, 'term_id' => $locked->id];
            }

            $continueIds = collect($continueIds)->map(fn ($i) => (string) $i);

            $nextTerm = $this->resolveNextTerm($locked, $nextTermSpec, $tenantId);

            if ($continueIds->isNotEmpty() && ! $nextTerm) {
                throw new HttpResponseException(
                    response()->json(['message' => 'Next term required for continuing students.', 'code' => 'next_term_required'], 422)
                );
            }

            $preview = $this->endPreview($locked, $continueIds->all());

            if ($previewToken !== null && $previewToken !== $preview['signature']) {
                throw new HttpResponseException(
                    response()->json(['message' => 'Preview is out of date; reload and try again.', 'code' => 'stale_preview'], 409)
                );
            }

            // Archive EVERY live row of the term — including expired-but-active
            // ones — so no straggler stays `active` inside an archived term.
            $rows = Enrollment::withoutTenant()
                ->where('tenant_id', $tenantId)
                ->where('academic_term_id', $locked->id)
                ->whereIn('status', Enrollment::EFFECTIVE_STATUSES)
                ->get();

            foreach ($rows as $enrollment) {
                $userId = (string) $enrollment->user_id;
                $continues = $continueIds->contains($userId);

                $enrollment->update([
                    'status' => 'archived',
                    'archived_at' => now(),
                    'expires_at' => $enrollment->expires_at ?? now(),
                ]);

                if ($continues && $nextTerm) {
                    $this->enrollments->closeGuardedRows($tenantId, (string) $enrollment->course_id, (string) $enrollment->user_id);

                    Enrollment::create([
                        'id' => UuidV7::generate(),
                        'tenant_id' => $tenantId,
                        'course_id' => $enrollment->course_id,
                        'user_id' => $enrollment->user_id,
                        'academic_term_id' => $nextTerm->id,
                        'status' => 'active',
                        'enrolled_at' => now(),
                        'source' => 'term_renewal',
                        'metadata' => [
                            'continued_from_term_id' => $locked->id,
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
                'status' => AcademicTerm::STATUS_ARCHIVED,
                'archived_at' => now(),
                'metadata' => array_merge($locked->metadata ?? [], ['end_summary' => $summary]),
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

        if ((string) $from->id === (string) $to->id) {
            throw new HttpResponseException(
                response()->json(['message' => 'The next term must differ from the current one.', 'code' => 'same_term'], 422)
            );
        }

        if ($to->tenant_id !== $from->tenant_id) {
            throw new HttpResponseException(
                response()->json(['message' => 'Next term does not belong to this tenant.', 'code' => 'term_tenant_mismatch'], 422)
            );
        }

        if (! in_array($to->status, EnrollmentService::ENROLLABLE_TERM_STATUSES, true)) {
            throw new HttpResponseException(
                response()->json(['message' => 'Next term is closed for enrollment.', 'code' => 'term_closed'], 422)
            );
        }

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
                    'status' => 'archived',
                    'archived_at' => now(),
                    'expires_at' => $enrollment->expires_at ?? now(),
                ]);

                $this->enrollments->closeGuardedRows($tenantId, (string) $enrollment->course_id, (string) $enrollment->user_id);

                Enrollment::create([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $tenantId,
                    'course_id' => $enrollment->course_id,
                    'user_id' => $enrollment->user_id,
                    'academic_term_id' => $to->id,
                    'status' => 'active',
                    'enrolled_at' => now(),
                    'source' => 'term_promotion',
                    'metadata' => ['promoted_from_term_id' => $from->id, 'from_enrollment_id' => $enrollment->id],
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
     * Membership/role/term-state are enforced on every user.
     *
     * @param  array<int, string>  $userIds
     */
    public function assignStudents(AcademicTerm $term, string $courseId, array $userIds): array
    {
        $tenantId = (string) $term->tenant_id;

        // Term must still accept enrollments, and the course must belong to
        // the same tenant (both raise a clean HTTP error otherwise).
        $this->enrollments->assertTermAcceptsEnrollment($tenantId, (string) $term->id);
        $course = Course::withoutTenant()->where('tenant_id', $tenantId)->findOrFail($courseId);

        $created = [];
        $failed = [];

        foreach (array_unique($userIds) as $userId) {
            $user = User::find($userId);

            if (! $user) {
                $failed[] = ['user_id' => (string) $userId, 'reason' => 'not_found'];

                continue;
            }

            try {
                $this->enrollments->assertTenantStudent($tenantId, $user);

                $enrollment = $this->enrollments->enroll($course, $user, [
                    'academic_term_id' => $term->id,
                    'source' => 'term_assignment',
                ]);
                $created[] = $enrollment->user_id;
            } catch (HttpResponseException $e) {
                $code = json_decode($e->getResponse()->getContent(), true)['code'] ?? 'rejected';
                $failed[] = ['user_id' => (string) $userId, 'reason' => $code];
            } catch (\Throwable $e) {
                $failed[] = ['user_id' => (string) $userId, 'reason' => $e->getMessage() ?: 'error'];
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * Resolve or create the next term INSIDE the end transaction. Validates
     * it belongs to the tenant, is not the current term and is still open.
     *
     * @param  array{next_term_id?:?string, create?:array{name?:string, starts_at?:?string, ends_at?:?string}}  $spec
     */
    private function resolveNextTerm(AcademicTerm $current, array $spec, string $tenantId): ?AcademicTerm
    {
        $nextId = $spec['next_term_id'] ?? null;
        $create = $spec['create'] ?? [];

        if ($nextId) {
            $next = AcademicTerm::withoutTenant()
                ->where('tenant_id', $tenantId)
                ->whereKey($nextId)
                ->firstOrFail();

            if ((string) $next->id === (string) $current->id) {
                throw new HttpResponseException(
                    response()->json(['message' => 'The next term must differ from the current one.', 'code' => 'same_term'], 422)
                );
            }

            if (! in_array($next->status, EnrollmentService::ENROLLABLE_TERM_STATUSES, true)) {
                throw new HttpResponseException(
                    response()->json(['message' => 'Next term is closed for enrollment.', 'code' => 'term_closed'], 422)
                );
            }

            return $next;
        }

        if (! empty($create['name'])) {
            return AcademicTerm::create([
                'tenant_id' => $tenantId,
                'name' => $create['name'],
                'starts_at' => $create['starts_at'] ?? null,
                'ends_at' => $create['ends_at'] ?? null,
                'status' => AcademicTerm::STATUS_ACTIVE,
                'created_by' => auth()->id(),
            ]);
        }

        return null;
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
                    'user_id' => $first->user_id,
                    'name' => $first->student?->name,
                    'email' => $first->student?->email,
                    'courses' => $group->map(fn ($e) => ['id' => $e->course_id, 'title' => $e->course?->title])->values(),
                    'enrollment_ids' => $group->pluck('id')->values(),
                ];
            })
            ->values()
            ->all();
    }
}
