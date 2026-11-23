<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivationCard;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\LiveSession;
use App\Models\ScheduledLesson;
use App\Models\VideoContent;
use App\Plans\PlanEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher getting-started guide.
 *
 * Every step is derived from the tenant's OWN data — no hardcoded "done",
 * no fake progress. Steps whose feature the plan does not include are dropped
 * (a Starter tenant is never nudged to record a live session it cannot hold),
 * and optional steps (the exam and the advanced extras) never block the guide
 * from completing.
 *
 * Hiding the guide is a per-teacher preference stored on the user record, so it
 * survives every session and device (and never touches the tenant's data).
 */
class OnboardingController extends Controller
{
    /** User-metadata key holding the "guide hidden" timestamp. */
    private const DISMISS_KEY = 'onboarding_guide_dismissed_at';

    public function index(Request $request, PlanEngine $engine): JsonResponse
    {
        $tenantId = $request->current_tenant->id;
        $user = $request->user();

        // "Upload a teaching video or content" is satisfied by ANY lesson
        // content (video, text or file) — not just a video.
        $hasContent = LessonContent::withoutTenant()->where('tenant_id', $tenantId)->exists()
            || VideoContent::withoutTenant()->where('tenant_id', $tenantId)->exists();

        $contentIncluded = $engine->hasFeature($tenantId, 'video_upload_enabled')
            || $engine->hasFeature($tenantId, 'file_upload_enabled');

        // Exams have a numeric limit, not a boolean gate: hide the step only
        // when the tenant can create none at all.
        $examsIncluded = $engine->isUnlimited($tenantId, 'exams_limit')
            || $engine->intValue($tenantId, 'exams_limit') > 0;

        $cardsEnabled = $engine->hasFeature($tenantId, 'cards_enabled');

        // A registration method is "ready" once a card is generated OR a first
        // student is enrolled — either path proves the teacher opened a door.
        $enrollmentReady = ActivationCard::withoutTenant()->where('tenant_id', $tenantId)->exists()
            || Enrollment::withoutTenant()->where('tenant_id', $tenantId)->exists();

        // [key, done, included, target page, optional]
        $definitions = [
            ['profile', filled($user->phone), true, '/teacher/complete-profile', false],
            ['course', Course::withoutTenant()->where('tenant_id', $tenantId)->exists(), true, '/courses', false],
            ['lesson', Lesson::withoutTenant()->where('tenant_id', $tenantId)->exists(), true, '/courses', false],
            ['content', $hasContent, $contentIncluded, '/courses', false],
            ['exam', Exam::withoutTenant()->where('tenant_id', $tenantId)->exists(), $examsIncluded, '/teacher/exams', true],
            ['enrollment', $enrollmentReady, true, $cardsEnabled ? '/teacher/cards' : '/students', false],
            ['assignment', Assignment::withoutTenant()->where('tenant_id', $tenantId)->exists(), $engine->hasFeature($tenantId, 'assignments_enabled'), '/courses', true],
            ['live', LiveSession::withoutTenant()->where('tenant_id', $tenantId)->exists(), $engine->hasFeature($tenantId, 'live_stream_enabled'), '/teacher/live', true],
            ['schedule', ScheduledLesson::withoutTenant()->where('tenant_id', $tenantId)->exists(), $engine->hasFeature($tenantId, 'scheduled_lessons_enabled'), '/teacher/schedule', true],
        ];

        $steps = [];
        foreach ($definitions as [$key, $done, $included, $href, $optional]) {
            // A step the plan cannot complete is hidden entirely.
            if (! $included) {
                continue;
            }

            $steps[] = ['key' => $key, 'done' => (bool) $done, 'href' => $href, 'optional' => $optional];
        }

        $completed = count(array_filter($steps, fn ($s) => $s['done']));
        // Optional steps never block completion — the guide can finish without
        // an exam, while still surfacing the suggestion.
        $required = array_filter($steps, fn ($s) => ! $s['optional']);
        $requiredDone = count(array_filter($required, fn ($s) => $s['done']));

        return response()->json([
            'steps' => $steps,
            'completed' => $completed,
            'total' => count($steps),
            'required_completed' => $requiredDone,
            'required_total' => count($required),
            'all_done' => $requiredDone === count($required),
            'dismissed' => $this->dismissedAt($user) !== null,
            'dismissed_at' => $this->dismissedAt($user),
        ]);
    }

    /** Hide the guide for this teacher (persisted across sessions/devices). */
    public function dismiss(Request $request): JsonResponse
    {
        $user = $request->user();
        $metadata = $user->metadata ?? [];
        $metadata[self::DISMISS_KEY] = now()->toIso8601String();
        $user->forceFill(['metadata' => $metadata])->save();

        return response()->json(['dismissed' => true, 'dismissed_at' => $metadata[self::DISMISS_KEY]]);
    }

    /** Bring the guide back for this teacher. */
    public function restore(Request $request): JsonResponse
    {
        $user = $request->user();
        $metadata = $user->metadata ?? [];
        unset($metadata[self::DISMISS_KEY]);
        $user->forceFill(['metadata' => $metadata])->save();

        return response()->json(['dismissed' => false, 'dismissed_at' => null]);
    }

    private function dismissedAt($user): ?string
    {
        $value = ($user->metadata ?? [])[self::DISMISS_KEY] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
