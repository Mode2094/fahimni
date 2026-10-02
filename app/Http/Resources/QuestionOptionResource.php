<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        $isCorrectVisible = $user && (
            $user->is_super_admin
            || $this->isTenantStaff($request, $this->tenant_id ?? null)
        );

        $data = [
            'id' => $this->id,
            'question_id' => $this->question_id,
            'option_text' => $this->option_text,
            'sort_order' => $this->sort_order,
        ];

        if ($isCorrectVisible) {
            $data['is_correct'] = $this->is_correct;
        }

        return $data;
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
