<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FrontendError;
use App\Models\MonitoringAlert;
use App\Models\MonitoringSnapshot;
use App\Models\MonitoringSlowRequest;
use App\Monitoring\HealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MonitoringController — super-admin-only observability API.
 *
 * Returns aggregate/derived values only. Never exposes secrets, credentials,
 * tokens or raw payloads. Every endpoint is rate-limited (throttle:monitoring).
 */
class MonitoringController extends Controller
{
    public function summary(Request $request, HealthService $health): JsonResponse
    {
        $snapshot = MonitoringSnapshot::query()->orderByDesc('collected_at')->first();

        $openAlerts = MonitoringAlert::query()
            ->where('status', MonitoringAlert::STATUS_OPEN)
            ->orderByDesc('last_seen_at')
            ->limit(20)
            ->get();

        $ready = $health->ready();

        $appCounters = $snapshot?->payload['app'] ?? [];

        return response()->json([
            'status' => $ready['status'] === 'ok' ? 'healthy' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'health' => $ready,
            'host' => $snapshot?->payload['host'] ?? null,
            'database' => $snapshot?->payload['database'] ?? null,
            'redis' => $snapshot?->payload['redis'] ?? null,
            'queue' => $snapshot?->payload['queue'] ?? null,
            'backup' => $snapshot?->payload['backup'] ?? null,
            'storage' => $snapshot?->payload['storage'] ?? null,
            'security' => $snapshot?->payload['security'] ?? null,
            'app' => $appCounters,
            'snapshot_at' => $snapshot?->collected_at?->toIso8601String(),
            'alerts' => $openAlerts->map(fn (MonitoringAlert $alert) => [
                'id' => $alert->id,
                'level' => $alert->level,
                'source' => $alert->source,
                'message' => $alert->message,
                'occurrences' => $alert->occurrences,
                'first_seen_at' => $alert->first_seen_at?->toIso8601String(),
                'last_seen_at' => $alert->last_seen_at?->toIso8601String(),
            ]),
        ]);
    }

    public function snapshots(Request $request): JsonResponse
    {
        $snapshots = MonitoringSnapshot::query()
            ->orderByDesc('collected_at')
            ->limit((int) $request->input('limit', 24))
            ->get();

        return response()->json([
            'snapshots' => $snapshots->map(fn (MonitoringSnapshot $s) => [
                'id' => $s->id,
                'kind' => $s->kind,
                'collected_at' => $s->collected_at?->toIso8601String(),
                'duration_ms' => $s->duration_ms,
                'payload' => $s->payload,
            ]),
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $query = MonitoringAlert::query()
            ->orderByDesc('last_seen_at');

        if ($request->has('status') && in_array($request->input('status'), ['open', 'resolved'], true)) {
            $query->where('status', $request->input('status'));
        }

        $alerts = $query->limit((int) $request->input('limit', 100))->get();

        return response()->json([
            'alerts' => $alerts->map(fn (MonitoringAlert $alert) => [
                'id' => $alert->id,
                'level' => $alert->level,
                'source' => $alert->source,
                'message' => $alert->message,
                'status' => $alert->status,
                'occurrences' => $alert->occurrences,
                'first_seen_at' => $alert->first_seen_at?->toIso8601String(),
                'last_seen_at' => $alert->last_seen_at?->toIso8601String(),
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
            ]),
        ]);
    }

    public function resolveAlert(Request $request, int $alertId): JsonResponse
    {
        $alert = MonitoringAlert::findOrFail($alertId);

        if ($alert->status !== MonitoringAlert::STATUS_OPEN) {
            return response()->json(['message' => 'Alert is already resolved.'], 422);
        }

        $alert->resolve();

        return response()->json(['message' => 'Alert resolved.', 'alert' => $alert->id]);
    }

    public function slowRequests(Request $request): JsonResponse
    {
        $query = MonitoringSlowRequest::query()
            ->orderByDesc('occurred_at')
            ->limit((int) $request->input('limit', 100));

        $rows = $query->get();

        return response()->json([
            'slow_requests' => $rows->map(fn (MonitoringSlowRequest $r) => [
                'id' => $r->id,
                'method' => $r->method,
                'path' => $r->path,
                'status' => $r->status,
                'duration_ms' => $r->duration_ms,
                'route' => $r->route,
                'occurred_at' => $r->occurred_at?->toIso8601String(),
            ]),
        ]);
    }

    public function frontendErrors(Request $request): JsonResponse
    {
        $errors = FrontendError::query()
            ->orderByDesc('created_at')
            ->limit((int) $request->input('limit', 100))
            ->get();

        return response()->json([
            'errors' => $errors->map(fn (FrontendError $e) => [
                'id' => $e->id,
                'app' => $e->app,
                'type' => $e->type,
                'message' => $e->message,
                'url' => $e->url,
                'status' => $e->status,
                'stack_hash' => $e->stack_hash,
                'created_at' => $e->created_at?->toIso8601String(),
            ]),
        ]);
    }
}