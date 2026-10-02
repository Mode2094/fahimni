<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivationCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'batch_id' => $this->batch_id,
            'code' => $this->code,
            'status' => $this->status,
            'student_id' => $this->student_id,
            'activated_at' => $this->activated_at,
            'expires_at' => $this->expires_at,
            'used_at' => $this->used_at,
            'cancelled_at' => $this->cancelled_at,
            'course' => new CourseResource($this->whenLoaded('course')),
            'student' => new UserResource($this->whenLoaded('student')),
            'redemptions' => CardRedemptionResource::collection($this->whenLoaded('redemptions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
