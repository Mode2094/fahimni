<?php

namespace App\Integrations;

use App\Integrations\Concerns\RefreshesOAuthTokens;
use App\Integrations\Contracts\FileStorageDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class GoogleDriveDriver extends BaseDriver implements FileStorageDriver
{
    use RefreshesOAuthTokens;

    private const API = 'https://www.googleapis.com/drive/v3';

    public static function label(): string
    {
        return 'Google Drive';
    }

    public static function category(): string
    {
        return 'storage';
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
            $response = $this->drive()->get(self::API.'/about', ['fields' => 'user(emailAddress,displayName)']);

            if (! $response->successful()) {
                return $this->failure('Google Drive connection failed: '.($response->json('error')['message'] ?? $response->status()));
            }

            $user = $response->json('user');
            $this->logEvent('test_connection', ['account' => $user['emailAddress'] ?? null]);

            return $this->success('Google Drive connected successfully.', [
                'details' => ['account' => ($user['displayName'] ?? '').' <'.($user['emailAddress'] ?? '').'>'],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('Google Drive connection failed: '.$e->getMessage());
        }
    }

    private function drive(): PendingRequest
    {
        return Http::withToken($this->accessToken())->acceptJson()->timeout(30);
    }

    public function listFiles(?string $folder = null): array
    {
        $query = "trashed = false and mimeType != 'application/vnd.google-apps.folder'";
        $query .= " and 'me' in owners";

        $response = $this->drive()->get(self::API.'/files', [
            'pageSize' => 100,
            'fields' => 'files(id,name,size,mimeType,thumbnailLink,webViewLink,createdTime)',
            'q' => $query,
            'orderBy' => 'modifiedTime desc',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Google Drive list failed: '.($response->json('error')['message'] ?? $response->status()));
        }

        return array_map(fn ($file) => [
            'id' => $file['id'],
            'name' => $file['name'],
            'size_bytes' => isset($file['size']) ? (int) $file['size'] : null,
            'mime_type' => $file['mimeType'] ?? null,
            'link' => $file['webViewLink'] ?? null,
            'thumbnail_url' => $file['thumbnailLink'] ?? null,
        ], $response->json('files', []));
    }

    public function getFile(string $fileId): array
    {
        $response = $this->drive()->get(self::API."/files/{$fileId}", [
            'fields' => 'id,name,size,mimeType,webViewLink,thumbnailLink',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Google Drive file lookup failed: '.($response->json('error')['message'] ?? $response->status()));
        }

        $file = $response->json();

        return [
            'id' => $file['id'],
            'name' => $file['name'],
            'size_bytes' => isset($file['size']) ? (int) $file['size'] : null,
            'mime_type' => $file['mimeType'] ?? null,
            'link' => $file['webViewLink'] ?? null,
            'download_url' => $this->apiUrl($file),
        ];
    }

    private function apiUrl(array $file): ?string
    {
        if ($file['mimeType'] === 'application/vnd.google-apps.document') {
            return self::API."/files/{$file['id']}/export?mimeType=application/pdf&access_token=".$this->accessToken();
        }

        if ($file['mimeType'] === 'application/vnd.google-apps.spreadsheet') {
            return self::API."/files/{$file['id']}/export?mimeType=application/vnd.openxmlformats-officedocument.spreadsheetml.sheet&access_token=".$this->accessToken();
        }

        return self::API."/files/{$file['id']}?alt=media&access_token=".$this->accessToken();
    }

    public function importFile(string $fileId): array
    {
        $meta = $this->getFile($fileId);

        $url = $this->apiUrl(['id' => $fileId, 'mimeType' => $meta['mime_type']]);

        $response = $this->drive()->timeout(300)->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Google Drive download failed: '.$response->status());
        }

        $extension = $this->guessExtension($meta['mime_type'] ?? null, $meta['name'] ?? 'file');
        $tenantId = $this->integration->tenant_id ?? 'platform';
        $path = 'imports/'.$tenantId.'/'.now()->format('Y/m/d').'/'.bin2hex(random_bytes(8)).'.'.$extension;

        Storage::disk('local')->put($path, $response->body());

        $this->logEvent('file_imported', ['file_id' => $fileId, 'name' => $meta['name'] ?? null]);

        return [
            'storage_path' => $path,
            'size_bytes' => $meta['size_bytes'],
            'mime_type' => $meta['mime_type'],
            'original_name' => $meta['name'] ?? 'imported-file',
        ];
    }

    private function guessExtension(?string $mimeType, string $name): string
    {
        if (str_contains($name, '.')) {
            return str($name)->afterLast('.')->lower()->toString();
        }

        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
            default => 'bin',
        };
    }
}
