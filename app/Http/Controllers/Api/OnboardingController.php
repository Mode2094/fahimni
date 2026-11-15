<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivationCard;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LiveSession;
use App\Models\ScheduledLesson;
use App\Models\VideoContent;
use App\Plans\PlanEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher getting-started checklist.
 *
 * Every step is derived from the tenant's OWN data — no hardcoded "done",
 * no fake progress. Steps whose feature the plan does not include are dropped
 * (a Starter tenant is never nudged to record a live session it cannot hold),
 * so `total` reflects only the steps that tenant can actually complete.
 */
class OnboardingController extends Controller
{
    public function index(Request $request, PlanEngine $engine): JsonResponse
    {
        $tenantId = $request->current_tenant->id;

        // [key, done, feature-gate (null = always relevant), target page]
        $definitions = [
            ['course', Course::withoutTenant()->where('tenant_id', $tenantId)->exists(), null, '/courses'],
            ['lesson', Lesson::withoutTenant()->where('tenant_id', $tenantId)->exists(), null, '/courses'],
            ['video', VideoContent::withoutTenant()->where('tenant_id', $tenantId)->exists(), 'video_upload_enabled', '/courses'],
            ['card', ActivationCard::withoutTenant()->where('tenant_id', $tenantId)->exists(), 'cards_enabled', '/teacher/cards'],
            ['assignment', Assignment::withoutTenant()->where('tenant_id', $tenantId)->exists(), 'assignments_enabled', '/courses'],
            ['live', LiveSession::withoutTenant()->where('tenant_id', $tenantId)->exists(), 'live_stream_enabled', '/teacher/live'],
            ['schedule', ScheduledLesson::withoutTenant()->where('tenant_id', $tenantId)->exists(), 'scheduled_lessons_enabled', '/teacher/schedule'],
        ];

        $steps = [];
        foreach ($definitions as [$key, $done, $gate, $href]) {
            // A gated step is hidden unless the tenant's plan allows it.
            if ($gate !== null && ! $engine->hasFeature($tenantId, $gate)) {
                continue;
            }

            $steps[] = ['key' => $key, 'done' => (bool) $done, 'href' => $href];
        }

        $completed = count(array_filter($steps, fn ($s) => $s['done']));

        return response()->json([
            'steps' => $steps,
            'completed' => $completed,
            'total' => count($steps),
            'all_done' => $completed === count($steps),
        ]);
    }
}
