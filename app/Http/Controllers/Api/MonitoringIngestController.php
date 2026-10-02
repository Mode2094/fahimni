<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FrontendError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MonitoringIngestController — accepts minimal, structured client-side error
 * events from the Next.js app (and future mobile builds).
 *
 * Security rules:
 *  - public endpoint but heavily rate-limited (throttle:monitoring-ingest)
 *  - payload size capped (16 KB) and JSON-only
 *  - only whitelisted string fields are stored; anything else is dropped
 *  - no tokens, cookies, authorization headers or raw bodies are accepted
 *  - message and URL are truncated; stack is hashed (sha256) — never stored raw
 */
class MonitoringIngestController extends Controller
{
    private const MAX_BODY_BYTES = 16384;

    public function __invoke(Request $request): JsonResponse
    {
        $content = $request->getContent();

        if (strlen((string) $content) > self::MAX_BODY_BYTES) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }

        try {
            $data = json_decode((string) $content, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return response()->json(['message' => 'Invalid JSON.'], 422);
        }

        if (! is_array($data) || $data === []) {
            return response()->json(['message' => 'Empty payload.'], 422);
        }

        $message = is_string($data['message'] ?? null) ? substr($data['message'], 0, 2000) : null;
        $url = is_string($data['url'] ?? null) ? substr($data['url'], 0, 1000) : null;
        $stack = is_string($data['stack'] ?? null) ? $data['stack'] : null;
        $type = in_array($data['type'] ?? null, ['error', 'unhandledrejection', 'http', 'proxy', 'console'], true)
            ? $data['type']
            : 'error';

        $metadata = is_array($data['metadata'] ?? null) ? array_filter($data['metadata'], fn ($v) => is_string($v) || is_int($v)) : [];
        $metadata = array_slice($metadata, 0, 20);

        // The header is attacker-controlled on this public endpoint: only
        // keep it when it references a real, active tenant (no poisoning the
        // admin monitoring view with arbitrary tenant ids).
        $tenantId = $request->header('X-Tenant-Id');
        if (is_string($tenantId) && strlen($tenantId) > 36) {
            $tenantId = substr($tenantId, 0, 36);
        }

        if (is_string($tenantId) && $tenantId !== '') {
            $exists = \App\Models\Tenant::whereKey($tenantId)->where('status', 'active')->exists();
            $tenantId = $exists ? $tenantId : null;
        } else {
            $tenantId = null;
        }

        try {
            FrontendError::create([
                'tenant_id' => $tenantId,
                'app' => in_array($data['app'] ?? null, ['web', 'mobile'], true) ? $data['app'] : 'web',
                'url' => $url,
                'method' => is_string($data['method'] ?? null) ? strtoupper(substr($data['method'], 0, 10)) : null,
                'status' => is_int($data['status'] ?? null) ? (int) $data['status'] : null,
                'type' => $type,
                'message' => $message,
                'stack_hash' => $stack !== null ? hash('sha256', $stack) : null,
                'user_agent' => substr((string) $request->userAgent(), 0, 400),
                'locale' => is_string($data['locale'] ?? null) ? substr($data['locale'], 0, 10) : null,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Ingest must never break the client request path or leak details.
            \Illuminate\Support\Facades\Log::channel('monitoring')->warning('monitoring.ingest_failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not store event.'], 500);
        }

        return response()->json(['status' => 'accepted'], 202);
    }
}