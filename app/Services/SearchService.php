<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as BasePaginator;
use Illuminate\Support\Facades\DB;

class SearchService
{
    private const MIN_QUERY_LENGTH = 3;

    private function normalizeQuery(string $query): string
    {
        return mb_strtolower(trim($query), 'UTF-8');
    }

    /**
     * Case-insensitive operator that the current driver understands. Postgres
     * gets `ilike`; SQLite (tests) has no `ilike` and uses `like`, which is
     * already case-insensitive for ASCII. Same convention as LessonNoteService.
     */
    private function likeOp(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
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
                $op = $this->likeOp();
                $q->where('title', $op, "%{$term}%")
                    ->orWhere('description', $op, "%{$term}%")
                    ->orWhere('short_description', $op, "%{$term}%")
                    ->orWhere('tags', $op, "%{$term}%");
            })
            ->where('is_published', true)
            ->with('sections.lessons')
            ->orderBy('sort_order')
            ->paginate($perPage);
    }

    /**
     * Search lessons. When `$visibleToUserId` is provided (a student caller),
     * results are limited to lessons the student's course groups allow — so a
     * group-restricted lesson never surfaces to a non-member through search.
     */
    public function searchLessons(string $query, string $tenantId, int $perPage = 10, ?string $visibleToUserId = null): LengthAwarePaginator
    {
        $term = $this->normalizeQuery($query);

        if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return $this->emptyPaginator($perPage);
        }

        $builder = Lesson::byTenant($tenantId)
            ->where(function (Builder $q) use ($term) {
                $op = $this->likeOp();
                $q->where('title', $op, "%{$term}%")
                    ->orWhere('description', $op, "%{$term}%")
                    ->orWhereHas('contents.text', fn (Builder $cq) => $cq->where('body', $op, "%{$term}%"));
            })
            ->with('section.course', 'contents');

        if ($visibleToUserId !== null) {
            $builder->visibleToStudent($visibleToUserId);
        }

        return $builder
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
                $op = $this->likeOp();
                $q->where('name', $op, "%{$term}%")
                    ->orWhere('email', $op, "%{$term}%");
            })
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function global(string $query, string $tenantId, bool $includeStudents = false, ?string $visibleToUserId = null): array
    {
        return [
            'courses' => $this->searchCourses($query, $tenantId, 5)->items(),
            'lessons' => $this->searchLessons($query, $tenantId, 5, $visibleToUserId)->items(),
            'students' => $includeStudents ? $this->searchStudents($query, $tenantId, 5)->items() : [],
        ];
    }
}
