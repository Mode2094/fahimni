<?php

namespace App\Http\Middleware;

use App\Models\PlatformSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MaintenanceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) PlatformSetting::get('maintenance_mode', false)) {
            if ($request->is('api/v1/auth/*', 'api/v1/platform-settings')) {
                return $next($request);
            }

            $user = $request->user();

            if (! $user || ! $user->is_super_admin) {
                return response()->json([
                    'message' => 'The platform is under maintenance. Please try again later.',
                ], 503);
            }
        }

        return $next($request);
    }
}
