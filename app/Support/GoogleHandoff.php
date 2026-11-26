<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Single-use, short-lived handoff ticket for the Google OAuth cross-host flow.
 *
 * A teacher's own domain is a different site from the platform: the central
 * OAuth callback (on `api.fahimni.me`) can prove WHO the student is, but the
 * session cookie it would set belongs to the platform host and never reaches
 * `teacher.com`. Instead the callback mints an opaque ticket that the teacher
 * host's BFF redeems for its OWN host-only session.
 *
 * Security invariants (see docs/CUSTOM_DOMAIN_OAUTH_HANDOFF.md):
 *   • the id is 256 bits of CSPRNG — unguessable;
 *   • stored server-side only, so no credential/token ever rides the URL;
 *   • consumed exactly once (delete-on-read) — a replay finds nothing;
 *   • TTL ≤ 90 s;
 *   • bound to the destination host and to the initiating browser (binding
 *     hash), so a ticket minted for one host/browser cannot be redeemed on
 *     another (login-CSRF / session fixation / cross-tenant defence).
 */
class GoogleHandoff
{
    private const PREFIX = 'google:handoff:';

    private const TTL_SECONDS = 90;

    /**
     * Store the claims and return the opaque, single-use ticket id.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function issue(array $claims): string
    {
        $id = bin2hex(random_bytes(32));

        Cache::put(self::PREFIX.$id, $claims + ['exp' => time() + self::TTL_SECONDS], self::TTL_SECONDS);

        return $id;
    }

    /**
     * Atomically consume a ticket. Returns null when it is unknown, malformed,
     * already used or expired — every one of which must fail closed.
     *
     * @return array<string, mixed>|null
     */
    public static function consume(string $id): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $id)) {
            return null;
        }

        // pull() = get + forget: the first reader wins, a replay gets null.
        $claims = Cache::pull(self::PREFIX.$id);

        if (! is_array($claims)) {
            return null;
        }

        if ((int) ($claims['exp'] ?? 0) < time()) {
            return null;
        }

        return $claims;
    }

    /** HMAC of the browser-binding secret, keyed by the app key. */
    public static function bindingHash(string $raw): string
    {
        return hash_hmac('sha256', $raw, (string) config('app.key'));
    }
}
