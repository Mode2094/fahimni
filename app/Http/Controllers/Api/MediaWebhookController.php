<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Media\MediaWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ويبهوك المزود العام — يستقبل أحداث Bunny Stream (فيديوهات مسجلة).
 * بنفس المسار: /api/v1/media/webhook/{provider}
 */
class MediaWebhookController extends Controller
{
    public function __construct(private readonly MediaWebhookService $webhooks) {}

    public function handle(Request $request, ?string $provider = null): JsonResponse
    {
        $result = $this->webhooks->handle($request, $provider);

        if (! $result['accepted']) {
            $status = match ($result['reason']) {
                'invalid_signature' => 401,
                'event_too_old' => 422,
                default => 503,
            };

            return response()->json(['message' => $result['reason']], $status);
        }

        return response()->json([
            'message' => 'OK',
            'event_id' => $result['event_id'],
            'reason' => $result['reason'],
        ]);
    }
}
