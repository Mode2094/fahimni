<?php

namespace App\Http\Requests\Lesson;

use Illuminate\Foundation\Http\FormRequest;

class StoreLessonContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'lesson_id' => 'required|string|exists:lessons,id',
            'content_type' => 'required|string|in:video,text,file,live',
            'sort_order' => 'nullable|integer|min:0',
            'is_required' => 'nullable|boolean',
            'title' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
        ];

        $contentType = $this->input('content_type');

        if ($contentType === 'video') {
            $rules['content_data.provider'] = 'required|string|max:50';
            $rules['content_data.provider_video_id'] = 'required|string|max:500';
            $rules['content_data.title'] = 'nullable|string|max:255';
            $rules['content_data.description'] = 'nullable|string';
            $rules['content_data.thumbnail_url'] = 'nullable|string|max:500';
            $rules['content_data.duration'] = 'nullable|integer|min:0';
            $rules['content_data.file_size_bytes'] = 'nullable|integer|min:0';
            $rules['content_data.config'] = 'nullable|array';
        } elseif ($contentType === 'text') {
            $rules['content_data.body'] = 'required|string';
            $rules['content_data.format'] = 'nullable|string|in:html,markdown,plain|max:20';
            $rules['content_data.word_count'] = 'nullable|integer|min:0';
            $rules['content_data.reading_time_minutes'] = 'nullable|integer|min:0';
        } elseif ($contentType === 'file') {
            $rules['content_data.original_name'] = 'required|string|max:255';
            $rules['content_data.storage_path'] = 'required|string|max:500';
            $rules['content_data.mime_type'] = 'required|string|max:100';
            $rules['content_data.size_bytes'] = 'required|integer|min:0';
            $rules['content_data.extension'] = 'required|string|max:20';
            $rules['content_data.is_downloadable'] = 'nullable|boolean';
            $rules['content_data.thumbnail_url'] = 'nullable|string|max:500';
            $rules['content_data.metadata'] = 'nullable|array';
        } elseif ($contentType === 'live') {
            $rules['content_data.title'] = 'required|string|max:255';
            $rules['content_data.description'] = 'nullable|string';
            $rules['content_data.provider'] = 'nullable|string|max:50';
            $rules['content_data.room_name'] = 'required|string|max:255';
            $rules['content_data.scheduled_at'] = 'nullable|date';
            $rules['content_data.max_participants'] = 'nullable|integer|min:0';
            $rules['content_data.record_enabled'] = 'nullable|boolean';
            $rules['content_data.config'] = 'nullable|array';
        }

        return $rules;
    }
}
