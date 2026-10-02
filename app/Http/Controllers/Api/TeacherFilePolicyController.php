<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantSetting;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class TeacherFilePolicyController extends Controller
{
    use AuthorizesTenantAccess;

    public function policy(Request $request): JsonResponse
    {
        $this->requireTenantStaff($request);

        $tenantId = $request->current_tenant->id;

        $settings = TenantSetting::firstOrCreate(
            ['tenant_id' => $tenantId],
            ['id' => UuidV7::generate(), 'tenant_id' => $tenantId],
        );

        return response()->json([
            'policy' => [
                'file_downloads_enabled' => (bool) $settings->file_downloads_enabled,
            ],
        ]);
    }

    public function updatePolicy(Request $request): JsonResponse
    {
        $this->requireTenantStaff($request);

        $tenantId = $request->current_tenant->id;

        $validated = $request->validate([
            'file_downloads_enabled' => 'required|boolean',
        ]);

        $settings = TenantSetting::firstOrCreate(
            ['tenant_id' => $tenantId],
            ['id' => UuidV7::generate(), 'tenant_id' => $tenantId],
        );

        $settings->update([
            'file_downloads_enabled' => $validated['file_downloads_enabled'],
        ]);

        return response()->json([
            'policy' => [
                'file_downloads_enabled' => (bool) $settings->file_downloads_enabled,
            ],
        ]);
    }
}
