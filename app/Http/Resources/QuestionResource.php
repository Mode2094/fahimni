<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionResource extends JsonResource
{
    /**
     * When true, answer-key fields (explanation, grading metadata) are
     * included. Staff members and super admins always see them; students
     * only when the request explicitly opts in via with=answer-key (used by
     * teacher-facing routes, never by student attempt views).
     */
    private bool $withAnswerKey = false;

    public function withAnswerKey(bool $value = true): static
    {
        $this->withAnswerKey = $value;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $user = $request->user();

        $isStaff = $user?->is_super_admin
            || $this->isTenantStaff($request, $this->tenant_id ?? null);

        $revealKey = $isStaff || $this->withAnswerKey;

        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'section_id' => $this->section_id,
            'question_type' => $this->question_type,
            'question_text' => $this->question_text,
            'difficulty' => $this->difficulty,
            'category' => $this->category,
            'points' => $this->points,
            'explanation' => $this->when($revealKey, $this->explanation),
            'is_active' => $this->is_active,
            'metadata' => $this->when($revealKey, $this->metadata ?? []),
            'options' => QuestionOptionResource::collection($this->whenLoaded('options')),
            'exams' => $this->whenLoaded('exams', fn () => $this->exams->map(fn ($e) => [
                'id' => $e->id,
                'title' => $e->title,
                'course_id' => $e->course_id,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function isTenantStaff(Request $request, ?string $tenantId): bool
    {
        $user = $request->user();

        if (! $user || ! $tenantId) {
            return false;
        }

        return $user->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', ['teacher', 'owner', 'admin'])
            ->where('status', 'active')
            ->exists();
    }
}
