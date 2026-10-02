<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Uid\UuidV7;

class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenants = $request->user()->tenants()
            ->wherePivot('status', 'active')
            ->get();

        return response()->json([
            'tenants' => TenantResource::collection($tenants),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_if(
            ! $user->is_super_admin && ! $user->hasRole('Teacher'),
            403,
            'Only teachers can create institutions.'
        );

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:tenants,slug',
            'email' => 'required|email|max:255',
        ]);

        $tenant = Tenant::create([
            'id' => UuidV7::generate(),
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'email' => $validated['email'],
            'owner_id' => $request->user()->id,
        ]);

        $tenant->settings()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'academy_name' => $validated['name'],
        ]);

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $request->user()->id,
            'role' => 'teacher',
            'status' => 'active',
        ]);

        return response()->json([
            'tenant' => new TenantResource($tenant->fresh()->load('settings')),
        ], 201);
    }

    public function current(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id')
            ?? $request->input('tenant_id');

        if (! $tenantId) {
            return response()->json([
                'message' => 'No tenant selected.',
            ], 404);
        }

        $tenant = $request->user()->tenants()
            ->where('tenant_id', $tenantId)
            ->wherePivot('status', 'active')
            ->first();

        if (! $tenant) {
            return response()->json([
                'message' => 'Tenant not found or access denied.',
            ], 404);
        }

        return response()->json([
            'tenant' => new TenantResource($tenant->load('settings')),
        ]);
    }

    public function switch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|string|exists:tenants,id',
        ]);

        $tenant = $request->user()->tenants()
            ->where('tenant_id', $validated['tenant_id'])
            ->wherePivot('status', 'active')
            ->first();

        if (! $tenant) {
            throw ValidationException::withMessages([
                'tenant_id' => ['Tenant not found or access denied.'],
            ]);
        }

        return response()->json([
            'message' => 'Tenant switched successfully.',
            'tenant' => new TenantResource($tenant->load('settings')),
        ]);
    }
}
