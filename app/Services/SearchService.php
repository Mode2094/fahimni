<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as BasePaginator;

class SearchService
{
    private const MIN_QUERY_LENGTH = 3;

    private function normalizeQuery(string $query): string
    {
        return mb_strtolower(trim($query), 'UTF-8');
    }

    private function emptyPaginator(int $perPage): LengthAwarePaginator
    {
        return new BasePaginator([], $perPage, 1);
    }

    public function searchCourses(string $query, string $tenantId, int $perPage = 10): LengthAwarePaginator
    {
        $term = $this->normalizeQuery($query);

        if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return $this->emptyPaginator($perPage);
        }

        return Course::byTenant($tenantId)
            ->where(function (Builder $q) use ($term) {
                $q->where('title', 'ilike', "%{$term}%")
                    ->orWhere('description', 'ilike', "%{$term}%")
                    ->orWhere('short_description', 'ilike', "%{$term}%")
                    ->orWhere('tags', 'ilike', "%{$term}%");
            })
            ->where('is_published', true)
            ->with('sections.lessons')
            ->orderBy('sort_order')
            ->paginate($perPage);
    }

    public function searchLessons(string $query, string $tenantId, int $perPage = 10): LengthAwarePaginator
    {
        $term = $this->normalizeQuery($query);

        if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return $this->emptyPaginator($perPage);
        }

        return Lesson::byTenant($tenantId)
            ->where(function (Builder $q) use ($term) {
                $q->where('title', 'ilike', "%{$term}%")
                    ->orWhere('description', 'ilike', "%{$term}%")
                    ->orWhereHas('contents.text', fn (Builder $cq) => $cq->where('body', 'ilike', "%{$term}%"));
            })
            ->with('section.course', 'contents')
            ->orderBy('sort_order')
            ->paginate($perPage);
    }

    public function searchStudents(string $query, string $tenantId, int $perPage = 10): LengthAwarePaginator
    {
        $term = $this->normalizeQuery($query);

        if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return $this->emptyPaginator($perPage);
        }

        return User::whereExists(function ($q) use ($tenantId) {
            $q->selectRaw('1')
                ->from('tenant_user')
                ->whereColumn('tenant_user.user_id', 'users.id')
                ->where('tenant_user.tenant_id', $tenantId)
                ->where('tenant_user.role', 'student');
        })
            ->where(function (Builder $q) use ($term) {
                $q->where('name', 'ilike', "%{$term}%")
                    ->orWhere('email', 'ilike', "%{$term}%");
            })
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function global(string $query, string $tenantId, bool $includeStudents = false): array
    {
        return [
            'courses' => $this->searchCourses($query, $tenantId, 5)->items(),
            'lessons' => $this->searchLessons($query, $tenantId, 5)->items(),
            'students' => $includeStudents ? $this->searchStudents($query, $tenantId, 5)->items() : [],
        ];
    }
}
