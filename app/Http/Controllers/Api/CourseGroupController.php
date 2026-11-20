<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Lesson;
use App\Models\User;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Course groups — named student groups INSIDE one course ("Grade 10 — A/B/C")
 * without cloning the course.
 *
 * Strictly separate from community/forum groups and from academic terms. Group
 * membership is never an enrolment, so belonging to several groups still costs
 * a single seat. Lessons/exams shared to a group are hidden from students who
 * are not members (enforced in the public lesson/exam endpoints).
 */
class CourseGroupController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request, string $courseId): JsonResponse
    {
        $course = Course::findOrFail($courseId);
        $this->authorize('view', $course);

        $groups = CourseGroup::where('course_id', $course->id)
            ->withCount(['members', 'lessons', 'exams'])
            ->orderBy('sort_order')
            ->get();

        // Candidates for assignment — real data only (enrolled students, this
        // course's lessons and exams). No fabricated rows.
        $studentIds = DB::table('enrollments')
            ->where('course_id', $course->id)
            ->pluck('user_id')->unique()->values();

        $students = User::whereIn('id', $studentIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]);

        $lessons = Lesson::whereHas('section', fn ($q) => $q->where('course_id', $course->id))
            ->orderBy('sort_order')
            ->get(['id', 'title'])
            ->map(fn (Lesson $l) => ['id' => $l->id, 'title' => $l->title]);

        $exams = Exam::where('course_id', $course->id)
            ->orderBy('sort_order')
            ->get(['id', 'title', 'is_published'])
            ->map(fn (Exam $e) => ['id' => $e->id, 'title' => $e->title, 'is_published' => (bool) $e->is_published]);

        return response()->json([
            'groups' => $groups->map(fn (CourseGroup $g) => $this->groupSummary($g)),
            'students' => $students,
            'lessons' => $lessons,
            'exams' => $exams,
        ]);
    }

    public function store(Request $request, string $courseId): JsonResponse
    {
        $course = Course::findOrFail($courseId);
        $this->authorize('update', $course);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $group = CourseGroup::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $course->tenant_id,
            'course_id' => $course->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['group' => $this->groupDetail($group)], 201);
    }

    public function show(string $groupId): JsonResponse
    {
        $group = CourseGroup::findOrFail($groupId);
        $this->authorize('view', $group->course);

        return response()->json(['group' => $this->groupDetail($group)]);
    }

    public function update(Request $request, string $groupId): JsonResponse
    {
        $group = CourseGroup::findOrFail($groupId);
        $this->authorize('update', $group->course);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $group->update($validated);

        return response()->json(['group' => $this->groupDetail($group->fresh())]);
    }

    public function destroy(string $groupId): JsonResponse
    {
        $group = CourseGroup::findOrFail($groupId);
        $this->authorize('update', $group->course);

        // Deleting a group never deletes students, enrolments, lessons, exams
        // or grades — only the group and its assignment rows.
        $group->delete();

        return response()->json(['deleted' => true]);
    }

    public function syncMembers(Request $request, string $groupId): JsonResponse
    {
        $group = CourseGroup::findOrFail($groupId);
        $this->authorize('update', $group->course);

        $validated = $request->validate([
            'user_ids' => 'present|array',
            'user_ids.*' => 'string',
        ]);

        // Only students actually enrolled in this course may be assigned, so a
        // group can never become a back door into another course's roster.
        $allowed = DB::table('enrollments')
            ->where('course_id', $group->course_id)
            ->whereIn('user_id', $validated['user_ids'])
            ->pluck('user_id')->all();

        $group->members()->sync($allowed);

        return response()->json(['group' => $this->groupDetail($group->fresh())]);
    }

    public function syncLessons(Request $request, string $groupId): JsonResponse
    {
        $group = CourseGroup::findOrFail($groupId);
        $this->authorize('update', $group->course);

        $validated = $request->validate([
            'lesson_ids' => 'present|array',
            'lesson_ids.*' => 'string',
        ]);

        $allowed = Lesson::whereHas('section', fn ($q) => $q->where('course_id', $group->course_id))
            ->whereIn('id', $validated['lesson_ids'])
            ->pluck('id')->all();

        $group->lessons()->sync($allowed);

        return response()->json(['group' => $this->groupDetail($group->fresh())]);
    }

    public function syncExams(Request $request, string $groupId): JsonResponse
    {
        $group = CourseGroup::findOrFail($groupId);
        $this->authorize('update', $group->course);

        $validated = $request->validate([
            'exam_ids' => 'present|array',
            'exam_ids.*' => 'string',
        ]);

        $allowed = Exam::where('course_id', $group->course_id)
            ->whereIn('id', $validated['exam_ids'])
            ->pluck('id')->all();

        $group->exams()->sync($allowed);

        return response()->json(['group' => $this->groupDetail($group->fresh())]);
    }

    /**
     * Results for a group, with the whole-course comparison so a teacher can
     * see group vs. all. Every number is derived from real submitted attempts.
     */
    public function results(string $groupId): JsonResponse
    {
        $group = CourseGroup::findOrFail($groupId);
        $this->authorize('view', $group->course);

        $memberIds = $group->members()->pluck('users.id')->all();
        $examIds = Exam::where('course_id', $group->course_id)->pluck('id')->all();

        $attempts = ExamAttempt::whereIn('exam_id', $examIds)
            ->where('status', 'submitted')
            ->with('student:id,name')
            ->get();

        $forMembers = $attempts->whereIn('user_id', $memberIds);
        $all = $attempts;

        return response()->json([
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'members_count' => count($memberIds),
                'submitted_attempts' => $forMembers->count(),
                'average_percentage' => round((float) $forMembers->avg('percentage'), 2),
                'pass_rate' => $forMembers->count() > 0
                    ? round($forMembers->where('passed', true)->count() / $forMembers->count() * 100, 2)
                    : 0,
            ],
            'all' => [
                'submitted_attempts' => $all->count(),
                'average_percentage' => round((float) $all->avg('percentage'), 2),
                'pass_rate' => $all->count() > 0
                    ? round($all->where('passed', true)->count() / $all->count() * 100, 2)
                    : 0,
            ],
            'per_student' => $forMembers->groupBy('user_id')->map(function ($rows) {
                $first = $rows->first();
                $name = $first->student?->name ?? '';

                return [
                    'user_id' => $first->user_id,
                    'name' => $name,
                    'attempts' => $rows->count(),
                    'average_percentage' => round((float) $rows->avg('percentage'), 2),
                ];
            })->values(),
        ]);
    }

    private function groupSummary(CourseGroup $g): array
    {
        return [
            'id' => $g->id,
            'course_id' => $g->course_id,
            'name' => $g->name,
            'description' => $g->description,
            'sort_order' => $g->sort_order,
            'is_active' => (bool) $g->is_active,
            'members_count' => $g->members_count ?? $g->members()->count(),
            'lessons_count' => $g->lessons_count ?? $g->lessons()->count(),
            'exams_count' => $g->exams_count ?? $g->exams()->count(),
        ];
    }

    private function groupDetail(CourseGroup $g): array
    {
        return array_merge($this->groupSummary($g), [
            'member_ids' => $g->members()->pluck('users.id')->all(),
            'lesson_ids' => $g->lessons()->pluck('lessons.id')->all(),
            'exam_ids' => $g->exams()->pluck('exams.id')->all(),
        ]);
    }
}
