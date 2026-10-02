<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Live\LiveProviderManager;
use Illuminate\Http\JsonResponse;

/**
 * لوحة الأدمن — البث المباشر (Google Meet فقط).
 *
 * - لا اعتمادات ولا API ولا اختبار اتصال: المعلم يلصق رابط meet.google.com
 *   الخاص به عند إنشاء الحصة، وجاهز دائماً.
 */
class AdminLiveProviderController extends Controller
{
    public function __construct(
        private readonly LiveProviderManager $liveProviders,
    ) {}

    /**
     * حالة المزود الوحيد (Google Meet) — جاهز + افتراضي بلا أسرار.
     */
    public function index(): JsonResponse
    {
        $status = $this->liveProviders->adminStatus();

        $status['default_provider'] = $this->liveProviders->defaultProvider();

        return response()->json(['providers' => $status]);
    }
}