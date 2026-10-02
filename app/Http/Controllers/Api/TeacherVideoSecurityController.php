<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VideoProtectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherVideoSecurityController extends Controller
{
    public function __construct(
        private readonly VideoProtectionService $protection,
    ) {}

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

    /**
     * إعدادات حماية الفيديو (العلامة المائية) للمعلم.
     * تعرض حالة الميزة في الخطة + الإعدادات الحالية.
     */
    public function show(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $tenantId = $this->tenantId($request);

        $featureAvailable = $this->protection->watermarkFeatureEnabled($tenantId);

        return response()->json([
            'feature_available' => $featureAvailable,
            'feature_key' => 'video_watermark',
            'settings' => $this->protection->teacherWatermarkConfig($tenantId),
        ]);
    }

    /**
     * حفظ إعدادات العلامة المائية.
     * يُرفض (403) إذا كانت الخطة لا تشمل الميزة — لا يمكن تجاوز Feature Engine.
     */
    public function update(Request $request): JsonResponse
    {
        $this->ensureTeacher($request);

        $tenantId = $this->tenantId($request);

        if (! $this->protection->watermarkFeatureEnabled($tenantId)) {
            return response()->json([
                'message' => 'Video watermark is not available in your current plan.',
                'code' => 'FEATURE_UNAVAILABLE',
            ], 403);
        }

        $validated = $request->validate([
            'enabled' => 'sometimes|boolean',
            'show_id' => 'sometimes|boolean',
            'opacity' => 'sometimes|in:low,medium,high',
            'speed' => 'sometimes|in:slow,medium,fast',
            'size' => 'sometimes|in:small,medium,large',
        ]);

        $current = $this->protection->teacherWatermarkConfig($tenantId);

        $settings = $this->protection->saveTeacherWatermarkConfig($tenantId, [
            'enabled' => $validated['enabled'] ?? $current['enabled'],
            'show_id' => $validated['show_id'] ?? $current['show_id'],
            'opacity' => $validated['opacity'] ?? $current['opacity'],
            'speed' => $validated['speed'] ?? $current['speed'],
            'size' => $validated['size'] ?? $current['size'],
        ]);

        return response()->json([
            'message' => 'Video security settings saved.',
            'settings' => $settings,
        ]);
    }
}
