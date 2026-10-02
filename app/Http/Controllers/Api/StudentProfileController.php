<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\StudentGamificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentProfileController extends Controller
{
    public function __construct(
        private readonly StudentGamificationService $gamification,
    ) {}

    private function tenantId(Request $request): ?string
    {
        $tenantId = $request->header('X-Tenant-Id');

        if ($tenantId && $request->user()->tenants()
            ->where('tenant_id', $tenantId)
            ->wherePivot('status', 'active')
            ->exists()) {
            return $tenantId;
        }

        $first = $request->user()->tenants()
            ->wherePivot('status', 'active')
            ->first();

        return $first?->id;
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->tenantId($request);

        if (! $tenantId) {
            return response()->json([
                'message' => 'No active tenant found for this account.',
            ], 400);
        }

        $stats = $this->gamification->statsFor($user, $tenantId);

        $enrollments = $user->enrollments()
            ->with(['course'])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('enrolled_at')
            ->get();

        $favoriteIds = $this->gamification->favoriteIds($user, $tenantId);

        $favorites = $enrollments
            ->filter(fn ($e) => in_array($e->course_id, $favoriteIds, true))
            ->values()
            ->map(fn ($e) => [
                'course_id' => $e->course_id,
                'title' => $e->course?->title,
                'progress_percent' => (float) $e->progress_percent,
            ]);

        return response()->json([
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'joined_at' => $user->created_at?->toIso8601String(),
                'stats' => $stats,
                'enrollments' => $enrollments->map(fn ($e) => [
                    'course_id' => $e->course_id,
                    'course_title' => $e->course?->title,
                    'status' => $e->status,
                    'progress_percent' => (float) $e->progress_percent,
                    'enrolled_at' => $e->enrolled_at?->toIso8601String(),
                ]),
                'favorites' => $favorites,
            ],
        ]);
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'No active tenant found.'], 400);
        }

        $entries = $this->gamification->leaderboard($tenantId, $this->clampLimit($request));

        return response()->json([
            'leaderboard' => $entries,
            'my_rank' => $this->gamification->rankOf($request->user(), $tenantId),
        ]);
    }

    public function achievements(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'No active tenant found.'], 400);
        }

        $achievements = $this->gamification->achievementsList($request->user(), $tenantId);

        return response()->json([
            'achievements' => $achievements,
            'unlocked_count' => collect($achievements)->where('unlocked', true)->count(),
            'total_count' => count($achievements),
        ]);
    }

    public function points(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'No active tenant found.'], 400);
        }

        return response()->json([
            'total_points' => $this->gamification->totalPoints($request->user(), $tenantId),
            'history' => $this->gamification->pointsHistory($request->user(), $tenantId),
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'No active tenant found.'], 400);
        }

        return response()->json([
            'activity' => $this->gamification->activityFeed($request->user(), $tenantId, $this->clampLimit($request)),
        ]);
    }

    public function favorites(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'No active tenant found.'], 400);
        }

        $favoriteIds = $this->gamification->favoriteIds($request->user(), $tenantId);

        $courses = Course::whereIn('id', $favoriteIds)->get();

        return response()->json([
            'favorites' => $courses->map(fn (Course $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'slug' => $c->slug,
                'description' => $c->short_description,
                'price' => $c->price,
                'currency' => $c->currency,
                'lessons_count' => $c->lessons_count,
            ]),
        ]);
    }

    public function toggleFavorite(Request $request, string $courseId): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'No active tenant found.'], 400);
        }

        $course = Course::findOrFail($courseId);

        if ($course->tenant_id !== $tenantId) {
            return response()->json(['message' => 'Course not found in this tenant.'], 404);
        }

        return response()->json($this->gamification->toggleFavorite($request->user(), $tenantId, $courseId));
    }

    public function stats(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'No active tenant found.'], 400);
        }

        return response()->json([
            'stats' => $this->gamification->statsFor($request->user(), $tenantId),
        ]);
    }
}
