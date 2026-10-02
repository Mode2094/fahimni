<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\StudentAppService;
use App\Services\TeacherCodeService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher-facing Student Mobile App panel.
 * Shows the feature status, the teacher's join code, the store links the
 * Super Admin configured, and the deep link used for the QR code.
 */
class TeacherStudentAppController extends Controller
{
    public function __construct(
        private readonly StudentAppService $app,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $tenant = $request->current_tenant;

        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'Tenant context missing.'], 400);
        }

        return response()->json([
            'student_app' => $this->app->teacherPayload($tenant),
        ]);
    }

    /**
     * Separate, guarded action — only for future regeneration needs.
     * Requires an explicit `confirm` flag, logs an audit trail, and keeps the
     * current code when the feature/toggle rules would not allow it.
     */
    public function regenerate(Request $request): JsonResponse
    {
        $tenant = $request->current_tenant;

        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'Tenant context missing.'], 400);
        }

        $request->validate([
            'confirm' => 'required|boolean|accepted',
        ]);

        if (! $this->app->platformEnabled()) {
            return response()->json(['message' => 'The student app is disabled.'], 409);
        }

        $previous = $tenant->teacher_code;

        // Always mint a brand-new code on regenerate (the current one is
        // still in the DB, so the collision check guarantees a different code).
        $tenant->update([
            'teacher_code' => app(TeacherCodeService::class)->generate(),
            'teacher_code_generated_at' => now(),
        ]);

        AuditLogger::log($request, 'student_app.code_regenerated', 'tenant', $tenant->id, [
            'previous_code' => $previous,
            'new_code' => $tenant->teacher_code,
        ]);

        return response()->json([
            'message' => 'Teacher code regenerated. Old code is no longer valid.',
            'student_app' => $this->app->teacherPayload($tenant->fresh()),
        ]);
    }
}
