<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    use AuthorizesTenantAccess;

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->header('X-Tenant-Id');

        if ($tenantId && ! $this->isTenantMember($request, $tenantId)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $request->validate([
            'token' => 'required|string|max:500|regex:/^[A-Za-z0-9:_\-\.\/=+]{10,500}$/',
            'platform' => 'required|in:web,android,ios',
            'device_id' => 'nullable|string|max:100',
        ]);

        PushToken::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'token' => $request->token,
            ],
            [
                'platform' => $request->platform,
                'device_id' => $request->device_id,
                'last_used_at' => now(),
            ]
        );

        return response()->json(['message' => 'Push token registered.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->header('X-Tenant-Id');

        if ($tenantId && ! $this->isTenantMember($request, $tenantId)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $request->validate([
            'token' => 'required|string|max:500',
        ]);

        PushToken::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('token', $request->token)
            ->delete();

        return response()->json(['message' => 'Push token removed.']);
    }
}
