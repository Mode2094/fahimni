<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\WebSessionAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class UserController extends Controller
{
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:50',
            'avatar_url' => 'sometimes|url|max:500',
            'locale' => 'sometimes|string|in:ar,en',
        ]);

        $request->user()->update($validated);

        return response()->json([
            'user' => new UserResource($request->user()->fresh()),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|current_password',
            'password' => 'required|min:8|confirmed',
        ]);

        $user = $request->user();
        // Session-authenticated (web) requests hold a TransientToken with no
        // id; only personal access tokens can be compared/excluded.
        $currentToken = $user->currentAccessToken();
        $currentTokenId = $currentToken instanceof PersonalAccessToken ? $currentToken->id : null;

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        // A changed password may indicate a compromised credential: revoke
        // every session except the one performing the change. Bumping the
        // account version invalidates every other web session on its next
        // request; the current session adopts the new value below.
        $user->increment('auth_version');
        WebSessionAuth::adoptCurrentVersion($request, $user);

        $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        return response()->json(['message' => 'Password updated successfully.']);
    }
}
