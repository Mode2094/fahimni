<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaidService;
use App\Plans\QuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\Uid\UuidV7;

class TeacherServiceController extends Controller
{
    private function ensureTeacher(Request $request): void
    {
        $pivot = $request->user()->tenantPivots()
            ->where('tenant_id', $request->current_tenant->id)
            ->first();

        abort_if(! $pivot || ! in_array($pivot->role, ['teacher', 'owner', 'admin'], true), 403, 'Teacher access required.');
    }

    private function owns(Request $request, PaidService $service): void
    {
        abort_if(
            $service->tenant_id !== $request->current_tenant->id || $service->user_id !== $request->user()->id,
            404,
            'Service not found.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $services = PaidService::where('tenant_id', $request->current_tenant->id)
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['services' => $services]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        app(QuotaService::class)->assertCan($request->current_tenant->id, 'services');

        $validated = $request->validate([
            'type' => ['required', Rule::in(PaidService::TYPES)],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'price' => 'required|numeric|min:0|max:100000',
            'currency' => 'nullable|string|size:3',
            'duration_minutes' => 'required|integer|between:15,480',
            'is_active' => 'nullable|boolean',
        ]);

        $service = PaidService::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $request->current_tenant->id,
            'user_id' => $request->user()->id,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'currency' => $validated['currency'] ?? 'JOD',
            'duration_minutes' => $validated['duration_minutes'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['service' => $service], 201);
    }

    public function show(Request $request, PaidService $service): JsonResponse
    {
        $this->ensureTeacher($request);
        $this->owns($request, $service);

        return response()->json(['service' => $service->load('bookings')]);
    }

    public function update(Request $request, PaidService $service): JsonResponse
    {
        $this->ensureTeacher($request);
        $this->owns($request, $service);

        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(PaidService::TYPES)],
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'price' => 'sometimes|numeric|min:0|max:100000',
            'currency' => 'nullable|string|size:3',
            'duration_minutes' => 'sometimes|integer|between:15,480',
            'is_active' => 'sometimes|boolean',
        ]);

        $service->update($validated);

        return response()->json(['service' => $service->fresh()]);
    }

    public function destroy(Request $request, PaidService $service): JsonResponse
    {
        $this->ensureTeacher($request);
        $this->owns($request, $service);

        $service->delete();

        return response()->json(['message' => 'Service deleted.']);
    }
}
