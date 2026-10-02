<?php

namespace App\Http\Middleware;

use App\Monitoring\MetricsRegistry;
use App\Models\MonitoringSlowRequest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * MetricsMiddleware — records safe, aggregate HTTP metrics for every request.
 *
 * What is recorded (safe, no PII):
 *  - counters: requests.total / 2xx / 3xx / 4xx / 5xx / duration_ms (total)
 *  - slow requests (> MONITORING_SLOW_MS) → monitoring log + optional DB row
 *  - security counters: auth.failed_logins (401 on auth routes),
 *    rate_limit.hits (429), tenant.access_denied (403 on tenant routes)
 *
 * What is NEVER recorded: bodies, headers, cookies, tokens, passwords.
 * Only the request path is logged for slow requests (no query string).
 */
class MetricsMiddleware
{
    private const AUTH_PATHS = [
        '/api/v1/auth/login',
        '/api/v1/mobile/auth/login',
        '/api/v1/auth/2fa/confirm',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('monitoring.enabled', true)) {
            return $next($request);
        }

        $start = hrtime(true);

        $response = $next($request);

        $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);
        $status = $response->getStatusCode();
        $path = $request->path();

        $this->recordCounters($path, $status, $durationMs, $request);

        return $response;
    }

    private function recordCounters(string $path, int $status, int $durationMs, Request $request): void
    {
        try {
            $bucket = match (true) {
                $status < 300 => '2xx',
                $status < 400 => '3xx',
                $status < 500 => '4xx',
                default => '5xx',
            };

            MetricsRegistry::increment('requests.total');
            MetricsRegistry::increment('requests.'.$bucket);
            MetricsRegistry::increment('requests.duration_ms', $durationMs);

            $max = MetricsRegistry::counter('requests.max_duration_ms');
            if ($durationMs > $max) {
                MetricsRegistry::increment('requests.max_duration_ms', $durationMs - $max);
            }

            // Security-oriented signals (no identities, just counters).
            if ($status === 429) {
                MetricsRegistry::increment('security.rate_limit_hits');
            }

            if ($status === 401 && $this->isAuthPath($path)) {
                MetricsRegistry::increment('security.failed_logins');
            }

            if ($status === 403 && str_starts_with($path, 'api/')) {
                MetricsRegistry::increment('security.access_denied');
            }

            // Slow requests: log + optional persistence (path only).
            $slowMs = (int) config('monitoring.request.slow_threshold_ms', 1000);
            if ($durationMs >= $slowMs) {
                $payload = [
                    'method' => $request->method(),
                    'path' => $path,
                    'status' => $status,
                    'duration_ms' => $durationMs,
                    'route' => optional($request->route())?->getName(),
                ];

                Log::channel('monitoring')->warning('slow_request', $payload);

                if (config('monitoring.request.persist_slow', true)) {
                    $this->persistSlowRequest($payload);
                }
            }
        } catch (\Throwable) {
            // Monitoring must never break a request.
        }
    }

    private function isAuthPath(string $path): bool
    {
        foreach (self::AUTH_PATHS as $authPath) {
            if ($path === ltrim($authPath, '/') || $path === $authPath) {
                return true;
            }
        }

        return false;
    }

    private function persistSlowRequest(array $payload): void
    {
        try {
            MonitoringSlowRequest::create([
                'method' => $payload['method'],
                'path' => substr((string) $payload['path'], 0, 500),
                'status' => $payload['status'],
                'duration_ms' => $payload['duration_ms'],
                'route' => $payload['route'] !== null ? substr((string) $payload['route'], 0, 190) : null,
                'source' => 'api',
                'occurred_at' => now(),
            ]);
        } catch (\Throwable) {
            // Persistence failure must not break the request.
        }
    }
}