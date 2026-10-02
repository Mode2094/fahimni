<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardRedemptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'card_id' => $this->card_id,
            'course_id' => $this->course_id,
            'student_id' => $this->student_id,
            'enrollment_id' => $this->enrollment_id,
            'redeemed_at' => $this->redeemed_at,
            'ip_address' => $this->ip_address,
            'card' => new ActivationCardResource($this->whenLoaded('card')),
            'course' => new CourseResource($this->whenLoaded('course')),
            'student' => new UserResource($this->whenLoaded('student')),
            'enrollment' => new EnrollmentResource($this->whenLoaded('enrollment')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
