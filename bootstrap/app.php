<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\CheckFeature;
use App\Http\Middleware\DeviceSessionMiddleware;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\MaintenanceMiddleware;
use App\Http\Middleware\MetricsMiddleware;
use App\Http\Middleware\RequireSubscription;
use App\Http\Middleware\TenantMiddleware;
use App\Plans\Exceptions\FeatureUnavailableException;
use App\Plans\Exceptions\QuotaExceededException;
use App\Support\ErrorLogger;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => TenantMiddleware::class,
            'feature' => CheckFeature::class,
            'maintenance' => MaintenanceMiddleware::class,
            'device' => DeviceSessionMiddleware::class,
            'admin' => AdminMiddleware::class,
            'role' => EnsureRole::class,
            'subscription' => RequireSubscription::class,
        ]);

        // All traffic enters through our own nginx (or, once deployed, the
        // Cloudflare edge → nginx). Only private-network hops are trusted —
        // never `*` — so a client-supplied X-Forwarded-For cannot spoof the
        // IP used by rate limiting and audit logs even if the app port leaks.
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '::1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);

        $middleware->appendToGroup('api', MaintenanceMiddleware::class);
        $middleware->appendToGroup('api', 'throttle:api');
        $middleware->appendToGroup('api', MetricsMiddleware::class);

        // API-only app: unauthenticated requests must return 401 JSON,
        // never redirect to a non-existent web "login" route.
        $middleware->redirectGuestsTo(null);

        $middleware->statefulApi();
    })
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up'
    )
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Business-rule rejections (quota limits) are expected 4xx outcomes,
        // not production errors — reporting them pollutes error monitoring.
        $exceptions->dontReport([
            \App\Plans\Exceptions\QuotaExceededException::class,
        ]);

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        $exceptions->render(function (FeatureUnavailableException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 403);
        });

        $exceptions->render(function (QuotaExceededException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 403);
        });

        $exceptions->report(function (Throwable $e) {
            ErrorLogger::log($e);
        });
    })
    ->create();
