<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherAvailability;
use App\Plans\QuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class TeacherAvailabilityController extends Controller
{
    private function ensureTeacher(Request $request): void
    {
        $pivot = $request->user()->tenantPivots()
            ->where('tenant_id', $request->current_tenant->id)
            ->first();

        abort_if(! $pivot || ! in_array($pivot->role, ['teacher', 'owner', 'admin'], true), 403, 'Teacher access required.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $availabilities = TeacherAvailability::where('tenant_id', $request->current_tenant->id)
            ->where('user_id', $request->user()->id)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return response()->json(['availabilities' => $availabilities]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        app(QuotaService::class)->assertCan($request->current_tenant->id, 'appointments');

        $validated = $request->validate([
            'day_of_week' => 'required|integer|between:1,7',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'slot_minutes' => 'nullable|integer|between:15,180',
            'is_active' => 'nullable|boolean',
        ]);

        $availability = TeacherAvailability::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $request->current_tenant->id,
            'user_id' => $request->user()->id,
            'day_of_week' => $validated['day_of_week'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'slot_minutes' => $validated['slot_minutes'] ?? 30,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['availability' => $availability], 201);
    }

    public function update(Request $request, TeacherAvailability $availability): JsonResponse
    {
        $this->ensureTeacher($request);

        abort_if(
            $availability->tenant_id !== $request->current_tenant->id || $availability->user_id !== $request->user()->id,
            404,
            'Availability not found.'
        );

        $validated = $request->validate([
            'day_of_week' => 'sometimes|integer|between:1,7',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'slot_minutes' => 'nullable|integer|between:15,180',
            'is_active' => 'sometimes|boolean',
        ]);

        $availability->update($validated);

        return response()->json(['availability' => $availability->fresh()]);
    }

    public function destroy(Request $request, TeacherAvailability $availability): JsonResponse
    {
        $this->ensureTeacher($request);

        abort_if(
            $availability->tenant_id !== $request->current_tenant->id || $availability->user_id !== $request->user()->id,
            404,
            'Availability not found.'
        );

        $availability->delete();

        return response()->json(['message' => 'Availability deleted.']);
    }
}
