<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\Enrollment;
use App\Models\ExamAttempt;
use App\Models\LessonProgress;
use App\Models\StudentAchievement;
use App\Models\StudentActivity;
use App\Models\StudentFavorite;
use App\Models\StudentPoint;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

class StudentGamificationService
{
    public const POINTS_LOGIN = 5;

    public const POINTS_LESSON = 10;

    public const POINTS_EXAM_PASS = 50;

    public const POINTS_COURSE_COMPLETE = 100;

    /**
     * Award points (idempotent per reference) + record activity + check achievements.
     */
    public function award(User $user, string $tenantId, int $points, string $reason, ?string $refType = null, ?string $refId = null, ?string $activity = null): void
    {
        if ($this->awardPointsOnly($user, $tenantId, $points, $reason, $refType, $refId, $activity)) {
            $this->checkAchievements($user, $tenantId);
        }
    }

    /**
     * Insert a point entry + activity without triggering achievement checks.
     * Returns false when the award is a duplicate.
     */
    private function awardPointsOnly(User $user, string $tenantId, int $points, string $reason, ?string $refType, ?string $refId, ?string $activity): bool
    {
        if ($points === 0) {
            return false;
        }

        if ($refType && $refId && StudentPoint::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('reference_type', $refType)
            ->where('reference_id', $refId)
            ->exists()) {
            return false;
        }

        StudentPoint::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'points' => $points,
            'reason' => $reason,
            'reference_type' => $refType,
            'reference_id' => $refId,
        ]);

        if ($activity) {
            $this->logActivity($user, $tenantId, $refType ?? 'point', $activity);
        }

        return true;
    }

    public function logActivity(User $user, string $tenantId, string $type, string $description, array $metadata = []): void
    {
        StudentActivity::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'type' => $type,
            'description' => $description,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function toggleFavorite(User $user, string $tenantId, string $courseId): array
    {
        $favorite = StudentFavorite::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->first();

        if ($favorite) {
            $favorite->delete();

            return ['favorited' => false];
        }

        StudentFavorite::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'course_id' => $courseId,
        ]);

        return ['favorited' => true];
    }

    public function favoriteIds(User $user, string $tenantId): array
    {
        return StudentFavorite::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->pluck('course_id')
            ->all();
    }

    public function totalPoints(User $user, string $tenantId): int
    {
        return (int) StudentPoint::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->sum('points');
    }

    public function pointsHistory(User $user, string $tenantId, int $limit = 50): array
    {
        return StudentPoint::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (StudentPoint $p) => [
                'id' => $p->id,
                'points' => (int) $p->points,
                'reason' => $p->reason,
                'created_at' => $p->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Leaderboard for a tenant, ordered by total points.
     * Returns rank, student info, points and course completion stats.
     */
    public function leaderboard(string $tenantId, int $limit = 50): array
    {
        $rows = StudentPoint::where('tenant_id', $tenantId)
            ->select('user_id', DB::raw('SUM(points) as total'))
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $userIds = $rows->pluck('user_id')->all();

        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $completion = Enrollment::where('tenant_id', $tenantId)
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, count(*) as courses, sum(case when status = ? then 1 else 0 end) as completed', ['completed'])
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $rank = 0;

        return $rows->map(function ($row) use (&$rank, $users, $completion) {
            $rank++;
            $user = $users->get($row->user_id);

            return [
                'rank' => $rank,
                'user_id' => $row->user_id,
                'name' => $user?->name ?? '—',
                'avatar_url' => $user?->avatar_url,
                'points' => (int) $row->total,
                'courses_count' => (int) ($completion[$row->user_id]->courses ?? 0),
                'courses_completed' => (int) ($completion[$row->user_id]->completed ?? 0),
            ];
        })->all();
    }

    /**
     * Rank (1-based) of a user within their tenant; null if no points.
     */
    public function rankOf(User $user, string $tenantId): ?int
    {
        $total = $this->totalPoints($user, $tenantId);

        if ($total <= 0) {
            return null;
        }

        $ahead = StudentPoint::where('tenant_id', $tenantId)
            ->select('user_id', DB::raw('SUM(points) as total'))
            ->groupBy('user_id')
            ->havingRaw('SUM(points) > ?', [$total])
            ->count();

        return $ahead + 1;
    }

    public function statsFor(User $user, string $tenantId): array
    {
        $completedLessons = LessonProgress::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->count();

        $passedExams = ExamAttempt::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'submitted')
            ->where('passed', true)
            ->count();

        $enrollments = Enrollment::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->get();

        $avgProgress = $enrollments->isNotEmpty()
            ? round($enrollments->avg('progress_percent'), 1)
            : 0.0;

        return [
            'points' => $this->totalPoints($user, $tenantId),
            'rank' => $this->rankOf($user, $tenantId),
            'courses_count' => $enrollments->count(),
            'courses_completed' => $enrollments->where('status', 'completed')->count(),
            'avg_progress' => $avgProgress,
            'lessons_completed' => $completedLessons,
            'exams_passed' => $passedExams,
            'favorites_count' => StudentFavorite::where('tenant_id', $tenantId)->where('user_id', $user->id)->count(),
        ];
    }

    public function activityFeed(User $user, string $tenantId, int $limit = 30): array
    {
        return StudentActivity::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (StudentActivity $a) => [
                'id' => $a->id,
                'type' => $a->type,
                'description' => $a->description,
                'metadata' => $a->metadata,
                'created_at' => $a->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function achievementsList(User $user, string $tenantId): array
    {
        $achievements = Achievement::withoutTenant()
            ->whereNull('tenant_id')
            ->orWhere('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->get();

        $earned = StudentAchievement::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->pluck('unlocked_at', 'achievement_id');

        return $achievements->map(fn (Achievement $a) => [
            'id' => $a->id,
            'key' => $a->key,
            'title' => $a->title,
            'title_ar' => $a->title_ar,
            'description' => $a->description,
            'description_ar' => $a->description_ar,
            'icon' => $a->icon,
            'points' => (int) $a->points,
            'unlocked' => $earned->has($a->id),
            'unlocked_at' => $earned->get($a->id)?->toIso8601String(),
        ])->all();
    }

    /**
     * Evaluate all achievement criteria for a user and unlock any newly earned.
     */
    public function checkAchievements(User $user, string $tenantId): array
    {
        $unlocked = [];

        $achievements = Achievement::withoutTenant()
            ->whereNull('tenant_id')
            ->orWhere('tenant_id', $tenantId)
            ->get();

        $earnedKeys = StudentAchievement::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->pluck('achievement_id')
            ->flip();

        $stats = $this->countsForCriteria($user, $tenantId);

        foreach ($achievements as $achievement) {
            if ($earnedKeys->has($achievement->id)) {
                continue;
            }

            if ($this->criteriaMet($achievement->criteria ?? [], $stats)) {
                StudentAchievement::create([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'achievement_id' => $achievement->id,
                    'unlocked_at' => now(),
                ]);

                $earnedKeys->put($achievement->id, true);

                if ($achievement->points > 0) {
                    $this->awardPointsOnly(
                        $user,
                        $tenantId,
                        (int) $achievement->points,
                        'achievement:'.$achievement->key,
                        'achievement',
                        $achievement->id,
                        'حصل على إنجاز: '.($achievement->title_ar ?? $achievement->title)
                    );
                }

                $unlocked[] = $achievement->key;
            }
        }

        return $unlocked;
    }

    private function countsForCriteria(User $user, string $tenantId): array
    {
        return [
            'courses_enrolled' => Enrollment::where('tenant_id', $tenantId)->where('user_id', $user->id)->count(),
            'courses_completed' => Enrollment::where('tenant_id', $tenantId)->where('user_id', $user->id)->where('status', 'completed')->count(),
            'lessons_completed' => LessonProgress::where('tenant_id', $tenantId)->where('user_id', $user->id)->where('status', 'completed')->count(),
            'exams_passed' => ExamAttempt::where('tenant_id', $tenantId)->where('user_id', $user->id)->where('status', 'submitted')->where('passed', true)->count(),
            'exams_perfect' => ExamAttempt::where('tenant_id', $tenantId)->where('user_id', $user->id)->where('status', 'submitted')->where('percentage', 100)->count(),
            'points_total' => $this->totalPoints($user, $tenantId),
            'logins' => (int) ($user->metadata['login_count'] ?? 0),
        ];
    }

    private function criteriaMet(array $criteria, array $stats): bool
    {
        foreach ($criteria as $key => $value) {
            if (($stats[$key] ?? 0) < (int) $value) {
                return false;
            }
        }

        return count($criteria) > 0;
    }
}
