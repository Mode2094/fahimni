<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\ExamAttempt;
use App\Models\StudentAchievement;
use App\Models\StudentDeviceLimit;
use App\Models\StudentPoint;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\StudentGamificationService;
use App\Support\DevicePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class TeacherDeviceController extends Controller
{
    private function tenantId(Request $request): string
    {
        return $request->current_tenant->id;
    }

    private function ensureTeacher(Request $request): void
    {
        $pivot = $request->user()->tenantPivots()
            ->where('tenant_id', $this->tenantId($request))
            ->first();

        abort_if(! $pivot || ! in_array($pivot->role, ['teacher', 'owner', 'admin'], true), 403, 'Teacher access required.');
    }

    public function policy(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $setting = TenantSetting::firstOrCreate(
            ['tenant_id' => $this->tenantId($request)],
            ['id' => UuidV7::generate(), 'tenant_id' => $this->tenantId($request)]
        );
        $metadata = $setting->metadata ?? [];

        return response()->json([
            'policy' => [
                'enabled' => (bool) ($metadata['device_policy_enabled'] ?? true),
                'max_devices' => max(1, (int) ($metadata['device_policy_max_devices'] ?? 1)),
            ],
        ]);
    }

    public function updatePolicy(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'max_devices' => 'required|integer|min:1|max:20',
        ]);

        $setting = TenantSetting::firstOrCreate(
            ['tenant_id' => $this->tenantId($request)],
            ['id' => UuidV7::generate(), 'tenant_id' => $this->tenantId($request)]
        );

        $metadata = $setting->metadata ?? [];
        $metadata['device_policy_enabled'] = $validated['enabled'];
        $metadata['device_policy_max_devices'] = $validated['max_devices'];

        $setting->update(['metadata' => $metadata]);

        return response()->json([
            'message' => 'Device policy updated.',
            'policy' => ['enabled' => $validated['enabled'], 'max_devices' => $validated['max_devices']],
        ]);
    }

    public function students(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $tenantId = $this->tenantId($request);

        $userIds = Enrollment::where('tenant_id', $tenantId)
            ->pluck('user_id')
            ->unique()
            ->values();

        $students = User::whereIn('id', $userIds)
            ->orderBy('name')
            ->get();

        $limits = StudentDeviceLimit::where('tenant_id', $tenantId)->get()->keyBy('student_id');
        $deviceCounts = UserDevice::where('tenant_id', $tenantId)
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $enrollmentCounts = Enrollment::where('tenant_id', $tenantId)
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $attemptStats = ExamAttempt::where('tenant_id', $tenantId)
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, count(*) as attempts, avg(percentage) as avg_percentage, sum(case when passed then 1 else 0 end) as passed')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $defaultMax = DevicePolicy::maxDevices($tenantId);

        $gamification = app(StudentGamificationService::class);

        $points = StudentPoint::where('tenant_id', $tenantId)
            ->selectRaw('user_id, SUM(points) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $achievementCounts = StudentAchievement::where('tenant_id', $tenantId)
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        return response()->json([
            'students' => $students->map(fn (User $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'email' => $s->email,
                'avatar_url' => $s->avatar_url,
                'joined_at' => $s->created_at?->toIso8601String(),
                'last_login_at' => $s->last_login_at?->toIso8601String(),
                'courses_count' => (int) ($enrollmentCounts[$s->id] ?? 0),
                'attempts_count' => (int) ($attemptStats[$s->id]->attempts ?? 0),
                'avg_percentage' => round((float) ($attemptStats[$s->id]->avg_percentage ?? 0), 1),
                'passed_count' => (int) ($attemptStats[$s->id]->passed ?? 0),
                'points' => (int) ($points[$s->id] ?? 0),
                'achievements_count' => (int) ($achievementCounts[$s->id] ?? 0),
                'devices_count' => (int) ($deviceCounts[$s->id] ?? 0),
                'max_devices' => $limits->has($s->id) ? $limits[$s->id]->max_devices : $defaultMax,
                'override' => $limits->has($s->id),
            ]),
        ]);
    }

    public function showStudent(Request $request, string $studentId): JsonResponse
    {
        $this->ensureTeacher($request);

        $tenantId = $this->tenantId($request);
        $student = User::whereIn('id', Enrollment::where('tenant_id', $tenantId)->pluck('user_id'))
            ->findOrFail($studentId);

        $enrollments = Enrollment::with(['course'])
            ->where('tenant_id', $tenantId)
            ->where('user_id', $student->id)
            ->get();

        $attempts = ExamAttempt::with(['exam'])
            ->where('tenant_id', $tenantId)
            ->where('user_id', $student->id)
            ->orderByDesc('submitted_at')
            ->get();

        $limit = StudentDeviceLimit::where('tenant_id', $tenantId)->where('student_id', $student->id)->first();
        $devices = UserDevice::where('tenant_id', $tenantId)
            ->where('user_id', $student->id)
            ->orderByDesc('last_seen_at')
            ->get();

        $gamification = app(StudentGamificationService::class);
        $achievements = $gamification->achievementsList($student, $tenantId);

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'avatar_url' => $student->avatar_url,
                'joined_at' => $student->created_at?->toIso8601String(),
                'last_login_at' => $student->last_login_at?->toIso8601String(),
                'stats' => [
                    'points' => $gamification->totalPoints($student, $tenantId),
                    'rank' => $gamification->rankOf($student, $tenantId),
                    'achievements' => $achievements,
                    'unlocked_count' => collect($achievements)->where('unlocked', true)->count(),
                ],
                'points_history' => $gamification->pointsHistory($student, $tenantId, 20),
                'activity' => $gamification->activityFeed($student, $tenantId, 20),
                'enrollments' => $enrollments->map(fn ($e) => [
                    'id' => $e->id,
                    'course_id' => $e->course_id,
                    'course_title' => $e->course?->title,
                    'status' => $e->status,
                    'progress_percent' => (float) $e->progress_percent,
                    'enrolled_at' => $e->enrolled_at?->toIso8601String(),
                    'completed_at' => $e->completed_at?->toIso8601String(),
                    'source' => $e->source,
                ]),
                'attempts' => $attempts->map(fn ($a) => [
                    'id' => $a->id,
                    'exam_title' => $a->exam?->title,
                    'score' => (float) $a->score,
                    'total_points' => (float) $a->total_points,
                    'percentage' => (float) $a->percentage,
                    'passed' => (bool) $a->passed,
                    'submitted_at' => $a->submitted_at?->toIso8601String(),
                ]),
                'devices' => $devices->map(fn ($d) => [
                    'id' => $d->id,
                    'device_id' => $d->device_id,
                    'device_name' => $d->device_name,
                    // IP addresses of students are sensitive: mask the last
                    // octet so staff see origin ranges, not exact addresses.
                    'ip_address' => $this->maskIp($d->ip_address),
                    'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                ]),
                'max_devices' => $limit?->max_devices ?? DevicePolicy::maxDevices($tenantId),
                'override' => (bool) $limit,
            ],
        ]);
    }

    public function setStudentLimit(Request $request, string $studentId): JsonResponse
    {
        $this->ensureTeacher($request);

        $tenantId = $this->tenantId($request);
        $student = User::whereIn('id', Enrollment::where('tenant_id', $tenantId)->pluck('user_id'))
            ->findOrFail($studentId);

        $validated = $request->validate([
            'max_devices' => 'required|integer|min:1|max:20',
            'notes' => 'nullable|string|max:500',
        ]);

        StudentDeviceLimit::updateOrCreate(
            ['tenant_id' => $tenantId, 'student_id' => $student->id],
            ['max_devices' => $validated['max_devices'], 'notes' => $validated['notes'] ?? null]
        );

        return response()->json(['message' => 'Student device limit updated.']);
    }

    public function removeStudentLimit(Request $request, string $studentId): JsonResponse
    {
        $this->ensureTeacher($request);

        $tenantId = $this->tenantId($request);

        StudentDeviceLimit::where('tenant_id', $tenantId)
            ->where('student_id', $studentId)
            ->delete();

        return response()->json(['message' => 'Student limit removed — using platform default.']);
    }

    public function resetDevices(Request $request, string $studentId): JsonResponse
    {
        $this->ensureTeacher($request);

        $tenantId = $this->tenantId($request);
        $student = User::whereIn('id', Enrollment::where('tenant_id', $tenantId)->pluck('user_id'))
            ->findOrFail($studentId);

        $deleted = UserDevice::where('tenant_id', $tenantId)
            ->where('user_id', $student->id)
            ->delete();

        return response()->json([
            'message' => "Devices reset for {$student->name}. They can now log in from a new device.",
            'deleted' => $deleted,
        ]);
    }

    public function studentActivity(Request $request, string $studentId): JsonResponse
    {
        $this->ensureTeacher($request);

        $tenantId = $this->tenantId($request);
        $student = User::whereIn('id', Enrollment::where('tenant_id', $tenantId)->pluck('user_id'))
            ->findOrFail($studentId);

        return response()->json([
            'activity' => app(StudentGamificationService::class)->activityFeed($student, $tenantId, 100),
        ]);
    }

    private function maskIp(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }

        if (str_contains($ip, ':')) {
            // IPv6: keep the first 4 hextets.
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 4)).'::/64';
        }

        $parts = explode('.', $ip);

        if (count($parts) !== 4) {
            return 'x.x.x.x';
        }

        $parts[3] = 'x';

        return implode('.', $parts);
    }
}
