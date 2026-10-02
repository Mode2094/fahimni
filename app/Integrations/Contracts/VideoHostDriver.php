<?php

namespace App\Integrations\Contracts;

interface VideoHostDriver extends Driver
{
    /**
     * @return array<int, array{id: string, title: ?string, thumbnail_url: ?string, duration: ?int, privacy: ?string}>
     */
    public function listVideos(): array;

    public function getEmbedUrl(string $videoId): string;

    /**
     * @return array{id: string, embed_url: string, thumbnail_url: ?string}
     */
    public function uploadVideo(string $path, string $title, ?string $description = null): array;
}
