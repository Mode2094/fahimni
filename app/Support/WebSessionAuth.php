<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Single entry point for creating a first-party web session.
 *
 * Keeping the session bookkeeping in one place guarantees that every web
 * sign-in path (password, registration, 2FA, Google) records the account's
 * current `auth_version`. Without it, a password change or an admin
 * deactivation could not revoke sessions created by a different controller.
 */
class WebSessionAuth
{
    public static function login(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);

        // New session id on privilege change (session fixation defence).
        $request->session()->regenerate();

        // The marker EnsureAccountActive compares against users.auth_version.
        $request->session()->put('auth.version', (int) $user->auth_version);
        // Web sessions are always persistent ("remember me"): the cookie has
        // a Max-Age and the server-side session slides on every request.
        $request->session()->put('auth.remember', true);
    }

    /**
     * Keep the session that performed a security change alive after bumping
     * the account version, while every other session is invalidated.
     */
    public static function adoptCurrentVersion(Request $request, User $user): void
    {
        if ($request->hasSession()) {
            $request->session()->put('auth.version', (int) $user->auth_version);
        }
    }
}
