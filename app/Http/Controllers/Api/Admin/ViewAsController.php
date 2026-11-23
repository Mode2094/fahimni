<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Safe, read-only "View-As" for platform admins.
 *
 * A super admin can switch the browser session to a teacher or student to see
 * exactly what that account sees. The switch is stored server-side in the
 * session and the impersonated session is force-limited to reads by
 * {@see \App\Http\Middleware\BlockViewAsWrites} — no write of any kind (grades,
 * payments, enrollments, deletions) can pass while viewing. Another admin can
 * never be impersonated, so admin power is never borrowed, and the acting admin
 * is preserved so exiting restores them.
 */
class ViewAsController extends Controller
{
    private const SESSION_KEY = 'view_as';

    /** Current view-as state for the banner (null when not viewing). */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['view_as' => $this->state($request)]);
    }

    public function start(Request $request, string $userId): JsonResponse
    {
        $actor = $request->user();

        if (! $actor || ! $actor->is_super_admin) {
            return response()->json(['message' => 'Only a platform admin can use view-as.'], 403);
        }

        if ($this->state($request)) {
            return response()->json(['message' => 'A view-as session is already active — exit it first.'], 409);
        }

        $target = User::find($userId);

        if (! $target) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        if ($target->id === $actor->id) {
            return response()->json(['message' => 'You are already signed in as this account.'], 422);
        }

        // Never borrow another admin's power — only teacher/student accounts
        // (and any non-admin account) may be previewed.
        if ($target->is_super_admin) {
            return response()->json(['message' => 'Administrator accounts cannot be previewed.'], 403);
        }

        if (! $request->hasSession()) {
            return response()->json(['message' => 'View-as requires a browser session.'], 400);
        }

        $request->session()->put(self::SESSION_KEY, [
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'user_id' => $target->id,
            'read_only' => true,
            'started_at' => now()->toIso8601String(),
        ]);

        Auth::guard('web')->login($target);
        $request->session()->regenerate();

        AuditLogger::log($request, 'admin.view_as.start', 'user', $target->id, [
            'actor_id' => $actor->id,
            'read_only' => true,
        ]);

        return response()->json([
            'user' => new UserResource($target->load('tenants')),
            'view_as' => $this->state($request),
        ]);
    }

    public function stop(Request $request): JsonResponse
    {
        $state = $this->state($request);

        if (! $state) {
            return response()->json(['message' => 'No view-as session is active.'], 422);
        }

        $actor = User::find($state['actor_id']);

        if (! $actor || ! $actor->is_super_admin) {
            // The acting admin vanished or lost admin rights while viewing:
            // never leave the impersonated session authenticated.
            if ($request->hasSession()) {
                $request->session()->forget(self::SESSION_KEY);
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['message' => 'View-as ended. Please sign in again.'], 401);
        }

        if (! $request->hasSession()) {
            return response()->json(['message' => 'View-as requires a browser session.'], 400);
        }

        $viewedUserId = $state['user_id'];

        Auth::guard('web')->login($actor);
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerate();

        AuditLogger::log($request, 'admin.view_as.stop', 'user', $viewedUserId, [
            'actor_id' => $actor->id,
        ]);

        return response()->json([
            'user' => new UserResource($actor->load('tenants')),
            'view_as' => null,
        ]);
    }

    /**
     * @return array{active: bool, read_only: bool, actor_id: ?string, actor_name: ?string, user_id: string, user_name: ?string, started_at: ?string}|null
     */
    private function state(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state) || empty($state['user_id'])) {
            return null;
        }

        $viewed = User::find($state['user_id']);

        return [
            'active' => true,
            'read_only' => true,
            'actor_id' => $state['actor_id'] ?? null,
            'actor_name' => $state['actor_name'] ?? null,
            'user_id' => $state['user_id'],
            'user_name' => $viewed?->name,
            'started_at' => $state['started_at'] ?? null,
        ];
    }
}
