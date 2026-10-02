<?php

namespace App\Integrations\Contracts;

interface FileStorageDriver extends Driver
{
    /**
     * @return array<int, array{id: string, name: string, size_bytes: ?int, mime_type: ?string, link: ?string, thumbnail_url: ?string}>
     */
    public function listFiles(?string $folder = null): array;

    /**
     * @return array{id: string, name: string, size_bytes: ?int, mime_type: ?string, link: ?string, download_url: ?string}
     */
    public function getFile(string $fileId): array;

    /**
     * Copy a remote file into the platform's private storage.
     *
     * @return array{storage_path: string, size_bytes: ?int, mime_type: ?string, original_name: string}
     */
    public function importFile(string $fileId): array;
}
