<?php

namespace App\Integrations;

use App\Integrations\Concerns\RefreshesOAuthTokens;
use App\Integrations\Contracts\FileStorageDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DropboxDriver extends BaseDriver implements FileStorageDriver
{
    use RefreshesOAuthTokens;

    private const API = 'https://api.dropboxapi.com/2';

    public static function label(): string
    {
        return 'Dropbox';
    }

    public static function category(): string
    {
        return 'storage';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://api.dropboxapi.com/oauth2/token';
    }

    public function test(): array
    {
        if (! $this->configured()) {
            return $this->failure('Dropbox credentials are missing.');
        }

        try {
            $response = $this->dropbox()->post(self::API.'/users/get_current_account');

            if (! $response->successful()) {
                return $this->failure('Dropbox connection failed: '.($response->json('error_summary') ?? $response->status()));
            }

            $account = $response->json();
            $this->logEvent('test_connection', ['account' => $account['email'] ?? null]);

            return $this->success('Dropbox connected successfully.', [
                'details' => ['account' => $account['name']['display_name'].' <'.($account['email'] ?? '').'>'],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('Dropbox connection failed: '.$e->getMessage());
        }
    }

    private function dropbox(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    public function listFiles(?string $folder = null): array
    {
        $response = $this->dropbox()->post(self::API.'/files/list_folder', [
            'path' => $folder ?: '',
            'limit' => 100,
            'recursive' => false,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Dropbox list failed: '.($response->json('error_summary') ?? $response->status()));
        }

        return array_map(function ($entry) {
            $isFolder = $entry['.tag'] === 'folder';

            return [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'size_bytes' => $isFolder ? null : ($entry['size'] ?? null),
                'mime_type' => null,
                'link' => null,
                'thumbnail_url' => null,
                'is_folder' => $isFolder,
                'path_lower' => $entry['path_lower'] ?? null,
            ];
        }, $response->json('entries', []));
    }

    public function getFile(string $fileId): array
    {
        $meta = $this->fileMetadata($fileId);

        $temporaryLink = Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->post(self::API.'/files/get_temporary_link', ['path' => $meta['path_lower'] ?? $fileId])
            ->json();

        return [
            'id' => $meta['id'],
            'name' => $meta['name'],
            'size_bytes' => $meta['size'] ?? null,
            'mime_type' => null,
            'link' => $temporaryLink['link'] ?? null,
            'download_url' => $temporaryLink['link'] ?? null,
        ];
    }

    private function fileMetadata(string $fileId): array
    {
        $response = $this->dropbox()->post(self::API.'/files/get_metadata', ['path' => $fileId]);

        if (! $response->successful()) {
            throw new \RuntimeException('Dropbox file lookup failed: '.($response->json('error_summary') ?? $response->status()));
        }

        return $response->json();
    }

    public function importFile(string $fileId): array
    {
        $meta = $this->fileMetadata($fileId);
        $path = $meta['path_lower'] ?? $fileId;

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(300)
            ->post(self::API.'/files/download', ['path' => $path]);

        if (! $response->successful()) {
            throw new \RuntimeException('Dropbox download failed: '.($response->json('error_summary') ?? $response->status()));
        }

        $name = $meta['name'] ?? 'imported-file';
        $extension = str_contains($name, '.') ? str($name)->afterLast('.')->lower()->toString() : 'bin';
        $tenantId = $this->integration->tenant_id ?? 'platform';
        $storagePath = 'imports/'.$tenantId.'/'.now()->format('Y/m/d').'/'.bin2hex(random_bytes(8)).'.'.$extension;

        Storage::disk('local')->put($storagePath, $response->body());

        $this->logEvent('file_imported', ['file_id' => $fileId, 'name' => $name]);

        return [
            'storage_path' => $storagePath,
            'size_bytes' => $meta['size'] ?? null,
            'mime_type' => null,
            'original_name' => $name,
        ];
    }
}
