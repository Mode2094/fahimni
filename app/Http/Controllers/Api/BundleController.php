<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bundle\StoreBundleRequest;
use App\Http\Requests\Bundle\UpdateBundleRequest;
use App\Models\Bundle;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\StudentGamificationService;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Course bundles — a named package of existing courses.
 *
 * Bundles only group courses; they never copy content. Enrolling a student in
 * a bundle fans out to the normal EnrollmentService per course, so seat
 * accounting, per-course caps, duplicate protection and term rules stay the
 * single source of truth.
 */
class BundleController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Bundle::class);

        $staff = $this->isTenantStaff($request);

        $bundles = Bundle::withCount('courses')
            ->with('courses:id,title,slug,is_published')
            ->when(! $staff, fn ($q) => $q->where('is_published', true))
            ->when($request->filled('is_published'), fn ($q) => $q->where('is_published', $request->boolean('is_published')))
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['bundles' => $bundles]);
    }

    public function store(StoreBundleRequest $request): JsonResponse
    {
        $this->authorize('create', Bundle::class);

        $tenantId = (string) $request->current_tenant->id;

        $courseIds = $this->validateCourseIds($tenantId, $request->input('course_ids', []));

        $bundle = Bundle::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'title' => $request->title,
            'slug' => $this->uniqueSlug($tenantId, $request->input('slug') ?: $request->title),
            'description' => $request->input('description'),
            'short_description' => $request->input('short_description'),
            'thumbnail_url' => $request->input('thumbnail_url'),
            'price' => $request->input('price'),
            'currency' => $request->input('currency', 'JOD'),
            'is_published' => $request->boolean('is_published', false),
            'is_featured' => $request->boolean('is_featured', false),
            'sort_order' => (int) $request->input('sort_order', 0),
            'metadata' => $request->input('metadata', []),
        ]);

        $this->syncCourses($bundle, $courseIds);

        return response()->json(['bundle' => $this->detail($bundle)], 201);
    }

    public function show(string $id): JsonResponse
    {
        $bundle = Bundle::findOrFail($id);

        $this->authorize('view', $bundle);

        return response()->json(['bundle' => $this->detail($bundle)]);
    }

    public function update(UpdateBundleRequest $request, string $id): JsonResponse
    {
        $bundle = Bundle::findOrFail($id);

        $this->authorize('update', $bundle);

        $data = $request->only([
            'title', 'description', 'short_description', 'thumbnail_url',
            'price', 'currency', 'sort_order', 'metadata',
        ]);

        if ($request->filled('slug') && $request->input('slug') !== $bundle->slug) {
            $data['slug'] = $this->uniqueSlug($bundle->tenant_id, $request->input('slug'), $bundle->id);
        }

        if ($request->has('is_published')) {
            $data['is_published'] = $request->boolean('is_published');
        }
        if ($request->has('is_featured')) {
            $data['is_featured'] = $request->boolean('is_featured');
        }

        $bundle->update($data);

        if ($request->has('course_ids')) {
            $courseIds = $this->validateCourseIds($bundle->tenant_id, $request->input('course_ids', []));
            $this->syncCourses($bundle, $courseIds);
        }

        return response()->json(['bundle' => $this->detail($bundle->fresh())]);
    }

    public function destroy(string $id): JsonResponse
    {
        $bundle = Bundle::findOrFail($id);

        $this->authorize('delete', $bundle);

        // Pivot rows cascade; the member courses themselves are untouched.
        $bundle->courses()->detach();
        $bundle->delete();

        return response()->json(['message' => 'Bundle deleted successfully.']);
    }

    /**
     * Enroll one student into every course of the bundle.
     *
     * Existing enrollments are left as-is (never downgraded or reset); only
     * missing ones are created, each through the normal seat/cap checks.
     */
    public function enroll(Request $request, string $id): JsonResponse
    {
        $this->authorize('create', Enrollment::class);

        $bundle = Bundle::with('courses')->findOrFail($id);

        $this->authorize('view', $bundle);

        $validated = $request->validate([
            'user_id' => 'required|string|exists:users,id',
        ]);

        $tenantId = (string) $request->current_tenant->id;
        $student = User::findOrFail($validated['user_id']);

        $service = app(EnrollmentService::class);
        $service->assertTenantStudent($tenantId, $student);

        $created = [];
        $alreadyEnrolled = [];
        $failed = [];

        foreach ($bundle->courses as $course) {
            $exists = Enrollment::where('course_id', $course->id)
                ->where('user_id', $student->id)
                ->effective()
                ->exists();

            if ($exists) {
                $alreadyEnrolled[] = $course->id;

                continue;
            }

            try {
                $enrollment = $service->enroll($course, $student, [
                    'source' => 'bundle',
                    'metadata' => ['bundle_id' => $bundle->id, 'bundle_title' => $bundle->title],
                ]);
                $created[] = ['course_id' => $course->id, 'enrollment_id' => $enrollment->id];
            } catch (\Throwable $e) {
                $failed[] = ['course_id' => $course->id, 'message' => $e->getMessage()];
            }
        }

        if ($created) {
            app(StudentGamificationService::class)->logActivity(
                $student,
                $tenantId,
                'enrollment',
                'سجّل في الحزمة: '.$bundle->title,
                ['bundle_id' => $bundle->id, 'courses' => array_column($created, 'course_id')]
            );
            app(StudentGamificationService::class)->checkAchievements($student, $tenantId);
        }

        return response()->json([
            'message' => count($created).' course(s) enrolled.',
            'enrolled' => $created,
            'already_enrolled' => $alreadyEnrolled,
            'failed' => $failed,
        ], $created ? 201 : 200);
    }

    /**
     * Every referenced course must belong to the same tenant — a crafted
     * course id from another academy can never be added to the bundle.
     */
    private function validateCourseIds(string $tenantId, array $courseIds): array
    {
        $courseIds = array_values(array_unique(array_filter($courseIds)));

        if (! $courseIds) {
            return [];
        }

        $valid = Course::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $courseIds)
            ->pluck('id')
            ->all();

        if (count($valid) !== count($courseIds)) {
            abort(422, 'One or more courses do not belong to this tenant.');
        }

        return $valid;
    }

    private function syncCourses(Bundle $bundle, array $courseIds): void
    {
        $sync = [];
        foreach (array_values($courseIds) as $index => $courseId) {
            $sync[$courseId] = ['sort_order' => $index];
        }

        $bundle->courses()->sync($sync);
    }

    private function uniqueSlug(string $tenantId, string $source, ?string $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'bundle';
        $slug = $base;
        $i = 1;

        while (Bundle::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    private function detail(Bundle $bundle): array
    {
        $bundle->loadCount('courses');
        $bundle->load('courses:id,title,slug,short_description,thumbnail_url,cover_url,price,is_published,level');

        return array_merge($bundle->toArray(), [
            'courses_count' => $bundle->courses_count,
        ]);
    }
}
