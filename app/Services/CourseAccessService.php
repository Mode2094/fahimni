<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;

/**
 * Single source of truth for course-content access rules:
 *  - enrollment validity (active, not expired)
 *  - published-only lesson visibility
 *  - drip-content unlocking (position-based)
 *  - prerequisite courses
 *  - course completion requirements (certificate gating)
 *
 * Used by the progress controller, the video/file protection services and
 * the lesson-content policy so every path enforces identical rules.
 */
class CourseAccessService
{
    /**
     * Minimum genuine watch coverage (fraction of the lesson's duration) before
     * a video lesson counts as complete. This is the platform default policy:
     * ~90% of the video actually watched — no seeking to the end. Capped below
     * by a floor of 60s and a ceiling of 20min so a very short or very long
     * lesson stays reasonable.
     */
    private const MIN_WATCH_FRACTION = 0.9;

    /**
     * Course-group visibility — the single implementation every content path
     * must call so the rule can never drift between web, mobile, media, files,
     * search and Q&A.
     *
     * A lesson shared to specific course groups is visible only to members of
     * at least one of those groups; a lesson with no group rows is visible to
     * every enrolled student. Staff and super admins bypass the check (they
     * own the content), so callers that have already granted staff access can
     * skip this.
     */
    public function lessonVisibleToUser(Lesson $lesson, ?User $user): bool
    {
        $query = Lesson::query()->whereKey($lesson->id);

        // A guest can still reach an UNRESTRICTED lesson (free preview). Only a
        // lesson shared to groups is hidden from a caller we cannot identify.
        return $user
            ? $query->visibleToStudent((string) $user->id)->exists()
            : $query->whereDoesntHave('groups')->exists();
    }

    public function examVisibleToUser(Exam $exam, ?User $user): bool
    {
        $query = Exam::query()->whereKey($exam->id);

        return $user
            ? $query->visibleToStudent((string) $user->id)->exists()
            : $query->whereDoesntHave('groups')->exists();
    }

    public function enrollmentIsValid(?Enrollment $enrollment): bool
    {
        if (! $enrollment) {
            return false;
        }

        if (! in_array($enrollment->status, ['active', 'completed'], true)) {
            return false;
        }

        if ($enrollment->expires_at && $enrollment->expires_at->lt(now())) {
            return false;
        }

        return true;
    }

    /**
     * Live lessons = lessons that exist, are published, and sit in a
     * non-deleted, published section of the course. Soft-deleted sections
     * are excluded by the default scope; published-section filtering only
     * applies when the course uses sections that can be unpublished.
     */
    public function liveLessonIds(Course $course): array
    {
        return Lesson::query()
            ->whereIn('section_id', $course->sections()->pluck('sections.id'))
            ->where('is_published', true)
            ->pluck('id')
            ->all();
    }

    /**
     * Is the lesson unlocked for this student right now?
     * Enforces enrollment validity, publish state and drip intervals.
     */
    public function lessonUnlocked(Enrollment $enrollment, Lesson $lesson): bool
    {
        if (! $this->enrollmentIsValid($enrollment)) {
            return false;
        }

        if (! $lesson->is_published) {
            return false;
        }

        $course = $enrollment->course;
        if (! $course || $lesson->section?->course_id !== $course->id) {
            return false;
        }

        if (! $course->drip_enabled || ! $course->drip_interval_days || $course->drip_interval_days < 1) {
            return true;
        }

        $liveIds = $this->liveLessonIds($course);
        $position = array_search($lesson->id, $liveIds, true);

        if ($position === false) {
            return false;
        }

        // Free previews are always unlocked.
        if ($lesson->is_free_preview) {
            return true;
        }

        $enrolledAt = $enrollment->enrolled_at ?? $enrollment->created_at ?? now();
        $elapsedDays = $enrolledAt->diffInDays(now(), false);

        if ($elapsedDays >= $course->drip_interval_days) {
            return true;
        }

        if ($position === 0) {
            return true;
        }

        $previousLessonId = $liveIds[$position - 1] ?? null;

        if ($previousLessonId) {
            return LessonProgress::where('enrollment_id', $enrollment->id)
                ->where('lesson_id', $previousLessonId)
                ->where('status', 'completed')
                ->exists();
        }

        return false;
    }

    /**
     * All prerequisites must have a completed enrollment for this student.
     */
    public function prerequisitesMet(Enrollment $enrollment): bool
    {
        $prerequisites = $enrollment->course?->prerequisites ?? [];

        if (empty($prerequisites)) {
            return true;
        }

        $courseIds = collect($prerequisites)
            ->map(fn ($p) => is_array($p) ? ($p['course_id'] ?? null) : $p)
            ->filter()
            ->unique();

        if ($courseIds->isEmpty()) {
            return true;
        }

        $completed = Enrollment::where('user_id', $enrollment->user_id)
            ->whereIn('course_id', $courseIds)
            ->where('status', 'completed')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('course_id');

        return $courseIds->diff($completed)->isEmpty();
    }

    /**
     * Minimum watch time (seconds) before a video lesson counts as complete.
     * Non-video lessons (text, file, live) have no watch-time requirement — the
     * gate only ever applied to video, and requiring it elsewhere would make a
     * text lesson impossible to finish.
     *
     * The requirement is a fraction of the lesson's own duration (the platform
     * default is ~90% genuine coverage), floored at 60s so a very short clip
     * still asks for a real watch, and capped at the video's own length so it
     * can never exceed 100%.
     */
    public function requiredWatchSeconds(Lesson $lesson): int
    {
        if ($lesson->lesson_type !== 'video') {
            return 0;
        }

        $duration = (int) $lesson->duration_minutes;

        if ($duration <= 0) {
            return 0;
        }

        $durationSeconds = $duration * 60;
        $required = (int) round($durationSeconds * self::MIN_WATCH_FRACTION);

        return min(max($required, 60), $durationSeconds);
    }

    /**
     * Does the student meet every completion requirement of the course?
     *  - all_lessons: every live lesson completed
     *  - all_lessons_and_exams: live lessons + a passed attempt per published
     *    exam of the course
     */
    public function completionRequirementsMet(Enrollment $enrollment): bool
    {
        if (! $this->enrollmentIsValid($enrollment)) {
            return false;
        }

        $course = $enrollment->course;
        if (! $course) {
            return false;
        }

        $liveIds = $this->liveLessonIds($course);

        if (! empty($liveIds)) {
            $completedIds = LessonProgress::where('enrollment_id', $enrollment->id)
                ->whereIn('lesson_id', $liveIds)
                ->where('status', 'completed')
                ->pluck('lesson_id')
                ->all();

            if (count(array_diff($liveIds, $completedIds)) > 0) {
                return false;
            }
        }

        $criteria = $course->completion_criteria ?? 'all_lessons';

        if ($criteria === 'all_lessons_and_exams') {
            $exams = $course->exams()->where('is_published', true)->pluck('id');

            foreach ($exams as $examId) {
                $passed = ExamAttempt::where('exam_id', $examId)
                    ->where('user_id', $enrollment->user_id)
                    ->where('status', 'submitted')
                    ->where('passed', true)
                    ->exists();

                if (! $passed) {
                    return false;
                }
            }
        }

        return true;
    }
}
