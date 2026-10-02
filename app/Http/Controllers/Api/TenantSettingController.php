<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenantSetting\TenantSettingRequest;
use App\Http\Resources\TenantSettingResource;
use App\Models\TenantSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class TenantSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id');

        // Read path must never write: resolve (without persisting), authorize,
        // and only then create the row when the caller is actually allowed to.
        $settings = TenantSetting::firstOrNew(
            ['tenant_id' => $tenantId],
            ['id' => UuidV7::generate(), 'tenant_id' => $tenantId],
        );

        $this->authorize('view', $settings);

        if (! $settings->exists) {
            $settings->save();
        }

        return response()->json([
            'settings' => new TenantSettingResource($settings),
        ]);
    }

    public function update(TenantSettingRequest $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id');

        // Authorize before any mutation: an unauthorized member must not
        // cause a row to be inserted as a side effect.
        $settings = TenantSetting::firstOrNew(
            ['tenant_id' => $tenantId],
            ['id' => UuidV7::generate(), 'tenant_id' => $tenantId],
        );

        $this->authorize('update', $settings);

        if (! $settings->exists) {
            $settings->save();
        }

        $settings->update($request->validated());

        return response()->json([
            'settings' => new TenantSettingResource($settings),
        ]);
    }
}
