<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonContentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'content_type' => $this->content_type,
            'sort_order' => $this->sort_order,
            'is_required' => $this->is_required,
            'title' => $this->title,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        if ($this->relationLoaded('video') && $this->video) {
            $data['video'] = $this->sanitizedVideo($this->video);
        }

        if ($this->relationLoaded('text')) {
            $data['text'] = $this->text;
        }

        if ($this->relationLoaded('file')) {
            $data['file'] = $this->file;
        }

        if ($this->relationLoaded('liveSession')) {
            $data['live_session'] = $this->liveSession;
        }

        return $data;
    }

    /**
     * Never expose the raw storage path or file location.
     * Uploaded videos are only playable through the signed streaming endpoint.
     */
    private function sanitizedVideo($video): array
    {
        $config = $video->config ?? [];

        return [
            'id' => $video->id,
            'provider' => $video->provider,
            'title' => $video->title,
            'description' => $video->description,
            'thumbnail_url' => $video->thumbnail_url,
            'duration' => (int) $video->duration,
            'status' => $video->status,
            'file_size_bytes' => $video->file_size_bytes,
            'is_uploaded' => (bool) $video->is_uploaded,
            'mime_type' => $video->mime_type,
            'original_name' => $video->is_uploaded ? $video->original_name : null,
            'protection' => [
                'allow_download' => (bool) ($config['allow_download'] ?? false),
                'watermark_enabled' => (bool) ($config['watermark_enabled'] ?? true),
                'pause_on_hidden' => (bool) ($config['pause_on_hidden'] ?? true),
                'block_picture_in_picture' => (bool) ($config['block_picture_in_picture'] ?? true),
            ],
        ];
    }
}
