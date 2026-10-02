<?php

namespace App\Services;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushService
{
    public function send(User $user, string $title, ?string $body = null, array $data = [], ?string $tenantId = null): void
    {
        $tokens = PushToken::where('user_id', $user->id);

        if ($tenantId) {
            $tokens->where('tenant_id', $tenantId);
        }

        $tokens->pluck('token')->each(function (string $token) use ($title, $body, $data, $user) {
            $this->sendToToken($token, $title, $body, $data, $user->id);
        });
    }

    public function sendToToken(string $token, string $title, ?string $body = null, array $data = [], ?string $userId = null): void
    {
        if (! config('fcm.enabled')) {
            Log::debug('Push notification (FCM disabled, not sent)', [
                'title' => $title,
            ]);

            return;
        }

        $token = $this->parseToken($token);

        if (! $token) {
            Log::warning('Push notification skipped: malformed token.', ['title' => $title]);

            return;
        }

        $accessToken = $this->accessToken();

        if (! $accessToken) {
            Log::warning('Push notification skipped: FCM credentials missing.', ['title' => $title]);

            return;
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(config('fcm.timeout'))
                ->post('https://fcm.googleapis.com/v1/projects/'.$this->projectId().'/messages:send', [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body ?? '',
                        ],
                        'data' => array_map('strval', $data),
                        'apns' => $this->apnsPayload(),
                        'android' => ['priority' => 'high'],
                    ],
                ]);

            if ($response->failed() && $this->isInvalidToken($response)) {
                // Scope the cleanup to the token's owner: a colliding token
                // string registered by another user must never be removed.
                PushToken::when($userId, fn ($q) => $q->where('user_id', $userId))
                    ->where('token', $token)
                    ->delete();
            }
        } catch (\Throwable $e) {
            Log::error('Push notification failed', ['error' => $e->getMessage(), 'title' => $title]);
        }
    }

    /**
     * Accepts both raw registration tokens and the full
     * `projects/{project}/messages/{msg}` style tokens.
     */
    private function parseToken(string $token): string
    {
        return str_contains($token, ':') && str_starts_with($token, 'projects/')
            ? $token
            : $token;
    }

    private function isInvalidToken(Response $response): bool
    {
        return $response->status() === 404
            || str_contains((string) $response->json('error.message', ''), 'UNREGISTERED');
    }

    private function projectId(): string
    {
        return config('fcm.project_id')
            ?? $this->credentials()['project_id']
            ?? '';
    }

    /**
     * Builds a Google OAuth2 bearer token (RS256 JWT) from the service account.
     * The token is valid for 1 hour; it is cached for 55 minutes to avoid an
     * HTTP round-trip on every push.
     */
    private function accessToken(): ?string
    {
        return Cache::remember('fcm_oauth_access_token', now()->addMinutes(55), function () {
            return $this->fetchAccessToken();
        });
    }

    private function fetchAccessToken(): ?string
    {
        $credentials = $this->credentials();

        if (! $credentials || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            return null;
        }

        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = '';

        openssl_sign(
            "$header.$payload",
            $signature,
            $credentials['private_key'],
            OPENSSL_ALGO_SHA256
        );

        $jwt = "$header.$payload.".$this->base64UrlEncode($signature);

        try {
            $response = Http::timeout(config('fcm.timeout'))
                ->asForm()
                ->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

            return $response->json('access_token');
        } catch (\Throwable $e) {
            Log::error('FCM token exchange failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function credentials(): ?array
    {
        if (config('fcm.credentials_json')) {
            $decoded = json_decode(config('fcm.credentials_json'), true);

            return is_array($decoded) ? $decoded : null;
        }

        $path = config('fcm.credentials_path');

        if ($path && file_exists($path)) {
            return json_decode(file_get_contents($path), true) ?: null;
        }

        return null;
    }

    private function apnsPayload(): array
    {
        if (! config('fcm.apns_auth_key')) {
            return [];
        }

        return [
            'headers' => [
                'apns-push-type' => 'alert',
                'apns-priority' => '10',
            ],
            'payload' => [
                'aps' => ['sound' => 'default'],
            ],
        ];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
