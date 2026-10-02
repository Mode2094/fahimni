<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProgressResource;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\StudentCertificate;
use App\Services\CourseAccessService;
use App\Services\FeatureService;
use App\Services\StudentGamificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class LessonProgressController extends Controller
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly FeatureService $features,
        private readonly StudentGamificationService $gamification,
    ) {}

    public function index(string $enrollmentId): JsonResponse
    {
        $enrollment = Enrollment::findOrFail($enrollmentId);

        $this->authorize('view', $enrollment);

        $progress = LessonProgress::where('enrollment_id', $enrollmentId)
            ->with('lesson')
            ->get();

        return response()->json([
            'progress' => ProgressResource::collection($progress),
        ]);
    }

    public function update(Request $request, string $enrollmentId, string $lessonId): JsonResponse
    {
        $enrollment = Enrollment::findOrFail($enrollmentId);

        $this->authorize('view', $enrollment);

        $lesson = $this->assertLessonInCourse($lessonId, $enrollment);

        $validated = $request->validate([
            'status' => 'nullable|string|in:not_started,in_progress,completed',
            'watch_time_seconds' => 'nullable|integer|min:0|max:86400',
            'metadata' => 'nullable|array',
        ]);

        $status = $validated['status'] ?? 'in_progress';

        // Completion is a server-enforced transition: the enrollment must be
        // valid, the lesson published and unlocked (drip), and for video
        // lessons a minimum watch time must have been reached.
        if ($status === 'completed') {
            $this->guardCanComplete($enrollment, $lesson, (int) ($validated['watch_time_seconds'] ?? 0));
        }

        $wasCompleted = LessonProgress::where('enrollment_id', $enrollmentId)
            ->where('lesson_id', $lessonId)
            ->where('status', 'completed')
            ->exists();

        $progress = LessonProgress::updateOrCreate(
            [
                'enrollment_id' => $enrollmentId,
                'lesson_id' => $lessonId,
            ],
            [
                'id' => UuidV7::generate(),
                'tenant_id' => $enrollment->tenant_id,
                'user_id' => $enrollment->user_id,
                'status' => $status,
                'watch_time_seconds' => $validated['watch_time_seconds'] ?? 0,
                'completed_at' => $status === 'completed' && ! $wasCompleted ? now() : null,
                'metadata' => $validated['metadata'] ?? [],
            ]
        );

        if ($progress->status === 'completed' && ! $progress->completed_at) {
            $progress->update(['completed_at' => now()]);
        }

        $this->recalculateEnrollmentProgress($enrollment);

        if ($status === 'completed' && ! $wasCompleted) {
            $this->gamification->award(
                $enrollment->student,
                $enrollment->tenant_id,
                StudentGamificationService::POINTS_LESSON,
                'lesson_completed',
                'lesson',
                $lessonId,
                'أكمل درس: '.($lesson->title ?? '')
            );
        }

        return response()->json([
            'progress' => new ProgressResource($progress->fresh('lesson')),
        ]);
    }

    public function markCompleted(string $enrollmentId, string $lessonId): JsonResponse
    {
        $enrollment = Enrollment::findOrFail($enrollmentId);

        $this->authorize('view', $enrollment);

        $lesson = $this->assertLessonInCourse($lessonId, $enrollment);

        $this->guardCanComplete($enrollment, $lesson);

        $wasCompleted = LessonProgress::where('enrollment_id', $enrollmentId)
            ->where('lesson_id', $lessonId)
            ->where('status', 'completed')
            ->exists();

        $progress = LessonProgress::updateOrCreate(
            [
                'enrollment_id' => $enrollmentId,
                'lesson_id' => $lessonId,
            ],
            [
                'id' => UuidV7::generate(),
                'tenant_id' => $enrollment->tenant_id,
                'user_id' => $enrollment->user_id,
                'status' => 'completed',
                'completed_at' => $wasCompleted ? null : now(),
            ]
        );

        if (! $progress->wasRecentlyCreated && $progress->status !== 'completed') {
            $progress->update(['status' => 'completed', 'completed_at' => now()]);
        }

        if ($progress->status === 'completed' && ! $progress->completed_at) {
            $progress->update(['completed_at' => now()]);
        }

        $this->recalculateEnrollmentProgress($enrollment);

        if ($enrollment->status === 'completed' && ! $wasCompleted) {
            $this->gamification->award(
                $enrollment->student,
                $enrollment->tenant_id,
                StudentGamificationService::POINTS_COURSE_COMPLETE,
                'course_completed',
                'course',
                $enrollment->course_id,
                'أكمل الكورس: '.($enrollment->course?->title ?? '')
            );
        }

        return response()->json([
            'progress' => new ProgressResource($progress->fresh('lesson')),
            'message' => 'Lesson marked as completed.',
        ]);
    }

    private function guardCanComplete(Enrollment $enrollment, Lesson $lesson, int $watchTime = 0): void
    {
        if (! $this->access->enrollmentIsValid($enrollment)) {
            abort(422, 'Enrollment is not active or has expired.');
        }

        if (! $lesson->is_published) {
            abort(422, 'This lesson is not published yet.');
        }

        if (! $this->access->lessonUnlocked($enrollment, $lesson)) {
            abort(422, 'This lesson is locked (drip content). Complete the previous lessons first.');
        }

        $required = $this->access->requiredWatchSeconds($lesson);

        if ($required > 0 && $watchTime < $required) {
            abort(422, 'You must watch most of the lesson before marking it as completed.');
        }
    }

    private function recalculateEnrollmentProgress(Enrollment $enrollment): void
    {
        $course = $enrollment->course;

        $totalLessons = $this->access->liveLessonIds($course);

        if (count($totalLessons) === 0) {
            return;
        }

        // Count completed lessons restricted to the live lesson set so
        // soft-deleted sections can never push the percent above 100.
        $completedLessons = LessonProgress::where('enrollment_id', $enrollment->id)
            ->whereIn('lesson_id', $totalLessons)
            ->where('status', 'completed')
            ->count();

        $percent = round(($completedLessons / count($totalLessons)) * 100, 2);

        $updateData = ['progress_percent' => min($percent, 100)];

        // Certificates and the completed status only fire when the full
        // completion criteria (lessons + exams) are actually met.
        if ($percent >= 100 && $this->access->completionRequirementsMet($enrollment)) {
            $wasCompleted = $enrollment->status === 'completed';

            $updateData['status'] = 'completed';
            $updateData['completed_at'] = $wasCompleted ? $enrollment->completed_at : now();

            $this->issueCertificate($enrollment);
        } elseif ($enrollment->status === 'completed') {
            $updateData['status'] = 'active';
        }

        $enrollment->update($updateData);
    }

    private function assertLessonInCourse(string $lessonId, Enrollment $enrollment): Lesson
    {
        $lesson = Lesson::where('tenant_id', $enrollment->tenant_id)
            ->where('id', $lessonId)
            ->whereHas('section', fn ($q) => $q->where('course_id', $enrollment->course_id))
            ->firstOr(fn () => abort(422, 'This lesson does not belong to the enrolled course.'));

        return $lesson;
    }

    private function issueCertificate(Enrollment $enrollment): void
    {
        $course = $enrollment->course;

        if (! $course->certificate_enabled) {
            return;
        }

        if (! $this->features->hasFeature('certificates_enabled')) {
            return;
        }

        $exists = StudentCertificate::where('user_id', $enrollment->user_id)
            ->where('course_id', $course->id)
            ->where('status', 'issued')
            ->exists();

        if ($exists) {
            return;
        }

        StudentCertificate::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $enrollment->tenant_id,
            'user_id' => $enrollment->user_id,
            'course_id' => $course->id,
            'enrollment_id' => $enrollment->id,
            'certificate_number' => 'CERT-'.strtoupper(bin2hex(random_bytes(6))),
            'issued_at' => now(),
            'status' => 'issued',
            'template' => $course->certificate_template ?? 'classic',
            'accent_color' => $course->certificate_accent_color ?? '#2563eb',
            'signature_name' => $course->certificate_signature_name,
            'signature_title' => $course->certificate_signature_title,
            'metadata' => [
                'student_name' => $enrollment->user?->name,
                'course_title' => $course->title,
            ],
        ]);
    }
}
