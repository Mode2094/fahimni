<?php

namespace App\Integrations;

use App\Integrations\Concerns\RefreshesOAuthTokens;
use App\Integrations\Contracts\FileStorageDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class OneDriveDriver extends BaseDriver implements FileStorageDriver
{
    use RefreshesOAuthTokens;

    private const API = 'https://graph.microsoft.com/v1.0';

    public static function label(): string
    {
        return 'OneDrive';
    }

    public static function category(): string
    {
        return 'storage';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    }

    public function test(): array
    {
        if (! $this->configured()) {
            return $this->failure('Microsoft account is not connected.');
        }

        try {
            $response = $this->graph()->get(self::API.'/me/drive', ['select' => 'owner,quota']);

            if (! $response->successful()) {
                return $this->failure('OneDrive connection failed: '.($response->json('error')['message'] ?? $response->status()));
            }

            $drive = $response->json();
            $this->logEvent('test_connection', ['account' => $drive['owner']['user']['email'] ?? null]);

            return $this->success('OneDrive connected successfully.', [
                'details' => ['account' => $drive['owner']['user']['displayName'] ?? 'OneDrive account'],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('OneDrive connection failed: '.$e->getMessage());
        }
    }

    private function graph(): PendingRequest
    {
        return Http::withToken($this->accessToken())->acceptJson()->timeout(30);
    }

    public function listFiles(?string $folder = null): array
    {
        $path = $folder ? '/me/drive/items/'.$folder.'/children' : '/me/drive/root/children';

        $response = $this->graph()->get(self::API.$path, [
            'select' => 'id,name,size,file,folder,webUrl,fileSystemInfo',
            'top' => 100,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OneDrive list failed: '.($response->json('error')['message'] ?? $response->status()));
        }

        return array_map(function ($item) {
            if (isset($item['folder'])) {
                return [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'size_bytes' => null,
                    'mime_type' => 'folder',
                    'link' => $item['webUrl'] ?? null,
                    'thumbnail_url' => null,
                    'is_folder' => true,
                ];
            }

            return [
                'id' => $item['id'],
                'name' => $item['name'],
                'size_bytes' => $item['size'] ?? null,
                'mime_type' => $item['file']['mimeType'] ?? 'application/octet-stream',
                'link' => $item['webUrl'] ?? null,
                'thumbnail_url' => null,
            ];
        }, $response->json('value', []));
    }

    public function getFile(string $fileId): array
    {
        $response = $this->graph()->get(self::API."/me/drive/items/{$fileId}", [
            'select' => 'id,name,size,file,webUrl',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OneDrive file lookup failed: '.($response->json('error')['message'] ?? $response->status()));
        }

        $item = $response->json();

        return [
            'id' => $item['id'],
            'name' => $item['name'],
            'size_bytes' => $item['size'] ?? null,
            'mime_type' => $item['file']['mimeType'] ?? 'application/octet-stream',
            'link' => $item['webUrl'] ?? null,
            'download_url' => self::API."/me/drive/items/{$item['id']}/content",
        ];
    }

    public function importFile(string $fileId): array
    {
        $meta = $this->getFile($fileId);

        $response = $this->graph()->timeout(300)->get(self::API."/me/drive/items/{$fileId}/content");

        if (! $response->successful()) {
            throw new \RuntimeException('OneDrive download failed: '.($response->json('error')['message'] ?? $response->status()));
        }

        $name = $meta['name'] ?? 'imported-file';
        $extension = str_contains($name, '.') ? str($name)->afterLast('.')->lower()->toString() : 'bin';
        $tenantId = $this->integration->tenant_id ?? 'platform';
        $path = 'imports/'.$tenantId.'/'.now()->format('Y/m/d').'/'.bin2hex(random_bytes(8)).'.'.$extension;

        Storage::disk('local')->put($path, $response->body());

        $this->logEvent('file_imported', ['file_id' => $fileId, 'name' => $name]);

        return [
            'storage_path' => $path,
            'size_bytes' => $meta['size_bytes'],
            'mime_type' => $meta['mime_type'],
            'original_name' => $name,
        ];
    }
}
