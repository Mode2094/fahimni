<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantSetting;
use App\Support\LeaderboardPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

/**
 * Teacher control over the student leaderboard's privacy mode.
 * See App\Support\LeaderboardPolicy for the modes and the default.
 */
class TeacherLeaderboardController extends Controller
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

        return response()->json([
            'policy' => ['mode' => LeaderboardPolicy::mode($this->tenantId($request))],
            'modes' => LeaderboardPolicy::MODES,
        ]);
    }

    public function updatePolicy(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $validated = $request->validate([
            'mode' => 'required|string|in:'.implode(',', LeaderboardPolicy::MODES),
        ]);

        $tenantId = $this->tenantId($request);
        $setting = TenantSetting::firstOrCreate(
            ['tenant_id' => $tenantId],
            ['id' => UuidV7::generate(), 'tenant_id' => $tenantId]
        );

        $metadata = $setting->metadata ?? [];
        $metadata['leaderboard_mode'] = $validated['mode'];
        $setting->update(['metadata' => $metadata]);

        return response()->json([
            'message' => 'Leaderboard policy updated.',
            'policy' => ['mode' => $validated['mode']],
        ]);
    }
}
