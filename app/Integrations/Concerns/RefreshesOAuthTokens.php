<?php

namespace App\Integrations\Concerns;

use Illuminate\Support\Facades\Http;

trait RefreshesOAuthTokens
{
    /**
     * Exchange an authorization code for tokens and persist them.
     *
     * @param  array<string, mixed>  $tokenResponse
     */
    protected function persistTokens(array $tokenResponse, array $extra = []): void
    {
        $this->integration->fillCredentials([
            'access_token' => $tokenResponse['access_token'] ?? null,
            'refresh_token' => $tokenResponse['refresh_token'] ?? $this->credential('refresh_token'),
            'expires_at' => now()->addSeconds((int) ($tokenResponse['expires_in'] ?? 3600))->getTimestamp(),
        ] + $extra);

        $this->integration->connected_at = now();
        $this->integration->save();
    }

    protected function accessToken(): ?string
    {
        $token = $this->credential('access_token');
        $expiresAt = (int) $this->credential('expires_at', 0);

        if ($token && $expiresAt && now()->getTimestamp() < $expiresAt - 60) {
            return $token;
        }

        if (! $this->refreshAccessToken()) {
            return $this->credential('access_token');
        }

        return $this->credential('access_token');
    }

    protected function refreshAccessToken(): bool
    {
        $refreshToken = $this->credential('refresh_token');
        $clientId = $this->credential('client_id');
        $clientSecret = $this->credential('client_secret');

        if (! $refreshToken || ! $clientId || ! $clientSecret) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(30)->post($this->tokenEndpoint(), [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

            if (! $response->successful()) {
                return false;
            }

            $this->persistTokens($response->json());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    abstract protected function tokenEndpoint(): string;
}
