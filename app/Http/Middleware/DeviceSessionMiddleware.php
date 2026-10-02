<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeviceSessionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        $requestDevice = $request->header('X-Device-Id');

        // A token bound to a device is only usable from that device: requests
        // without the header (or with a different one) are rejected so a
        // stolen token cannot be replayed without the device identity.
        if ($token && ! empty($token->device_id)) {
            if (! $requestDevice || ! hash_equals($token->device_id, $requestDevice)) {
                return response()->json([
                    'message' => 'Session is bound to another device. Please log in again from this device.',
                ], 401);
            }
        }

        return $next($request);
    }
}
