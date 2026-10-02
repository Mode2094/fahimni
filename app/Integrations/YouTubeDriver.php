<?php

namespace App\Integrations;

use App\Integrations\Concerns\RefreshesOAuthTokens;
use App\Integrations\Contracts\VideoHostDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class YouTubeDriver extends BaseDriver implements VideoHostDriver
{
    use RefreshesOAuthTokens;

    private const API = 'https://www.googleapis.com/youtube/v3';

    private const UPLOAD_API = 'https://www.googleapis.com/upload/youtube/v3';

    public static function label(): string
    {
        return 'YouTube';
    }

    public static function category(): string
    {
        return 'video';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    public function test(): array
    {
        if (! $this->configured()) {
            return $this->failure('Google account is not connected.');
        }

        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->timeout(30)
                ->get(self::API.'/channels', ['part' => 'snippet', 'mine' => 'true']);

            if (! $response->successful()) {
                return $this->failure('YouTube connection failed: '.($response->json('error')['message'] ?? $response->status()));
            }

            $channel = $response->json('items.0.snippet') ?? [];
            $this->logEvent('test_connection', ['channel' => $channel['title'] ?? null]);

            return $this->success('YouTube connected successfully.', [
                'details' => ['channel' => $channel['title'] ?? 'YouTube channel'],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('YouTube connection failed: '.$e->getMessage());
        }
    }

    private function youtube(): PendingRequest
    {
        return Http::withToken($this->accessToken())->acceptJson()->timeout(30);
    }

    public function listVideos(): array
    {
        $response = $this->youtube()->get(self::API.'/videos', [
            'part' => 'snippet,contentDetails,status',
            'mine' => 'true',
            'maxResults' => 50,
            'order' => 'date',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('YouTube list failed: '.($response->json('error')['message'] ?? $response->status()));
        }

        return array_map(fn ($item) => [
            'id' => $item['id'],
            'title' => $item['snippet']['title'] ?? null,
            'thumbnail_url' => $item['snippet']['thumbnails']['medium']['url'] ?? null,
            'duration' => $this->parseDuration($item['contentDetails']['duration'] ?? null),
            'privacy' => $item['status']['privacyStatus'] ?? null,
        ], $response->json('items', []));
    }

    public function getEmbedUrl(string $videoId): string
    {
        return "https://www.youtube.com/embed/{$videoId}";
    }

    public function uploadVideo(string $path, string $title, ?string $description = null): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException('Video file not found on the server.');
        }

        $metadata = [
            'snippet' => [
                'title' => $title,
                'description' => $description ?? '',
                'categoryId' => '27',
            ],
            'status' => ['privacyStatus' => $this->config('privacy', 'unlisted')],
        ];

        $initResponse = Http::withToken($this->accessToken())
            ->acceptJson()
            ->withHeaders(['X-Upload-Content-Length' => (string) filesize($path)])
            ->timeout(60)
            ->post(self::UPLOAD_API.'/videos?uploadType=resumable&part=snippet,status', $metadata);

        if (! $initResponse->successful()) {
            throw new \RuntimeException('YouTube upload init failed: '.($initResponse->json('error')['message'] ?? $initResponse->status()));
        }

        $location = $initResponse->header('Location');

        if (! $location) {
            throw new \RuntimeException('YouTube upload session not created.');
        }

        $uploadResponse = Http::withToken($this->accessToken())
            ->withBody(file_get_contents($path), 'video/*')
            ->timeout(900)
            ->put($location);

        if (! $uploadResponse->successful()) {
            throw new \RuntimeException('YouTube upload failed: '.($uploadResponse->json('error')['message'] ?? $uploadResponse->status()));
        }

        $data = $uploadResponse->json();
        $this->logEvent('video_uploaded', ['video_id' => $data['id'] ?? null, 'title' => $title]);

        return [
            'id' => $data['id'] ?? '',
            'embed_url' => isset($data['id']) ? $this->getEmbedUrl($data['id']) : '',
            'thumbnail_url' => "https://img.youtube.com/vi/{$data['id']}/hqdefault.jpg",
        ];
    }

    private function parseDuration(?string $isoDuration): ?int
    {
        if (! $isoDuration) {
            return null;
        }

        preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $isoDuration, $m);

        return (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
    }
}
