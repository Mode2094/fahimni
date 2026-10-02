<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ExamAttempt;
use App\Models\Lesson;
use App\Models\LessonProgress;

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
     * Fraction of a video lesson's duration the student must have watched
     * (min 60s, max 20min) before the lesson may be marked complete.
     */
    private const MIN_WATCH_FRACTION = 0.6;

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
     */
    public function requiredWatchSeconds(Lesson $lesson): int
    {
        $duration = (int) $lesson->duration_minutes;

        if ($duration <= 0) {
            return 0;
        }

        return min(max((int) round($duration * 60 * self::MIN_WATCH_FRACTION), 60), 1200);
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
