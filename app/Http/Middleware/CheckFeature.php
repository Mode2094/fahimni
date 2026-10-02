<?php

namespace App\Http\Middleware;

use App\Services\FeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeature
{
    public function __construct(
        private readonly FeatureService $featureService,
    ) {}

    public function handle(Request $request, Closure $next, string $featureKey = ''): Response
    {
        if (blank($featureKey)) {
            return response()->json([
                'message' => 'Feature key is required.',
            ], 400);
        }

        // Platform admins can always pass (they manage the platform itself).
        if ($request->user()?->is_super_admin) {
            return $next($request);
        }

        if (! $this->featureService->hasFeature($featureKey)) {
            return response()->json([
                'message' => 'هذه الميزة غير متاحة في خطتك الحالية. قم بترقية خطتك لتفعيلها.',
            ], 403);
        }

        return $next($request);
    }
}
