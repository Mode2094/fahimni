<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'submission_id' => $this->submission_id,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'comment' => $this->comment,
            'created_at' => $this->created_at,
        ];
    }
}
