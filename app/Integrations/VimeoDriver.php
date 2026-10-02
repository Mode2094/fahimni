<?php

namespace App\Integrations;

use App\Integrations\Concerns\RefreshesOAuthTokens;
use App\Integrations\Contracts\VideoHostDriver;
use Illuminate\Support\Facades\Http;

class VimeoDriver extends BaseDriver implements VideoHostDriver
{
    use RefreshesOAuthTokens;

    private const API = 'https://api.vimeo.com';

    public static function label(): string
    {
        return 'Vimeo';
    }

    public static function category(): string
    {
        return 'video';
    }

    protected function tokenEndpoint(): string
    {
        return self::API.'/oauth/access_token';
    }

    public function test(): array
    {
        if (! $this->configured()) {
            return $this->failure('Vimeo account is not connected.');
        }

        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->timeout(30)
                ->get(self::API.'/me');

            if (! $response->successful()) {
                return $this->failure('Vimeo connection failed: '.($response->json('error') ?? $response->status()));
            }

            $me = $response->json();
            $this->logEvent('test_connection', ['account' => $me['name'] ?? null]);

            return $this->success('Vimeo connected successfully.', [
                'details' => ['account' => $me['name'].' <'.($me['user']['name'] ?? '').'>'],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('Vimeo connection failed: '.$e->getMessage());
        }
    }

    public function listVideos(): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30)
            ->get(self::API.'/me/videos', ['per_page' => 50]);

        if (! $response->successful()) {
            throw new \RuntimeException('Vimeo list failed: '.($response->json('error') ?? $response->status()));
        }

        return array_map(fn ($item) => [
            'id' => str($item['uri'])->afterLast('/')->toString(),
            'title' => $item['name'] ?? null,
            'thumbnail_url' => $item['pictures']['sizes'][2]['link'] ?? ($item['pictures']['sizes'][0]['link'] ?? null),
            'duration' => $item['duration'] ?? null,
            'privacy' => $item['privacy']['view'] ?? null,
        ], $response->json('data', []));
    }

    public function getEmbedUrl(string $videoId): string
    {
        return "https://player.vimeo.com/video/{$videoId}";
    }

    public function uploadVideo(string $path, string $title, ?string $description = null): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException('Video file not found on the server.');
        }

        $ticketResponse = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(60)
            ->post(self::API.'/me/videos', [
                'name' => $title,
                'description' => $description ?? '',
                'privacy' => ['view' => $this->config('privacy', 'anybody')],
                'upload' => [
                    'approach' => 'post',
                    'size' => filesize($path),
                ],
            ]);

        if (! $ticketResponse->successful()) {
            throw new \RuntimeException('Vimeo upload ticket failed: '.($ticketResponse->json('error') ?? $ticketResponse->status()));
        }

        $ticket = $ticketResponse->json();
        $uploadLink = $ticket['upload']['upload_link'] ?? null;

        if (! $uploadLink) {
            throw new \RuntimeException('Vimeo upload link missing.');
        }

        $uploadResponse = Http::withToken($this->accessToken())
            ->withBody(file_get_contents($path), 'video/mp4')
            ->timeout(900)
            ->put($uploadLink);

        if (! $uploadResponse->successful()) {
            throw new \RuntimeException('Vimeo upload failed: '.($uploadResponse->json('error') ?? $uploadResponse->status()));
        }

        $videoId = str($ticket['uri'])->afterLast('/')->toString();

        Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30)
            ->patch(self::API."/videos/{$videoId}?upload=complete");

        $this->logEvent('video_uploaded', ['video_id' => $videoId, 'title' => $title]);

        return [
            'id' => $videoId,
            'embed_url' => $this->getEmbedUrl($videoId),
            'thumbnail_url' => null,
        ];
    }
}
