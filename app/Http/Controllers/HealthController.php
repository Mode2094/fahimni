<?php

namespace App\Http\Controllers;

use App\Monitoring\HealthService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __construct(private readonly HealthService $health)
    {
    }

    /**
     * Legacy aggregate health probe (/api/health).
     * 200 ok / 503 degraded. Shape preserved for existing checks.
     */
    public function __invoke(): JsonResponse
    {
        $payload = $this->health->legacy();
        $status = $payload['status'] === 'ok' ? 200 : 503;

        return response()->json($payload, $status);
    }

    /**
     * Liveness probe (/api/health/live).
     * The process is alive. No dependency checks — safe to poll aggressively.
     */
    public function live(): JsonResponse
    {
        return response()->json($this->health->live());
    }

    /**
     * Readiness probe (/api/health/ready).
     * The app can serve traffic: DB + Redis + filesystem + queue + config.
     * 200 healthy / 503 not ready.
     */
    public function ready(): JsonResponse
    {
        $payload = $this->health->ready();
        $status = $payload['status'] === 'ok' ? 200 : 503;

        return response()->json($payload, $status);
    }
}