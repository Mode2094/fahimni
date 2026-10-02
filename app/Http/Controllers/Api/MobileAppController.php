<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\TenantUser;
use App\Services\StudentAppService;
use App\Services\VideoProtectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student Mobile App API.
 *
 * Every endpoint is server-authoritative: the app's teacher context comes
 * from the X-Tenant-Id header (set from the validated teacher code), and the
 * backend ALWAYS re-checks that the authenticated student is an active
 * member of that tenant. Resource ownership (course → teacher) is enforced
 * on top. Nothing here trusts teacher_id/student_id/course_id from the client.
 */
class MobileAppController extends Controller
{
    public function __construct(
        private readonly StudentAppService $app,
        private readonly VideoProtectionService $protection,
    ) {}

    /**
     * POST /api/v1/mobile/teacher-code/validate — public, throttled.
     * Validates the code against every backend rule and returns the teacher
     * context + app configuration (branding comes from the same landing-page
     * source as the web).
     */
    public function validateCode(Request $request): JsonResponse
    {
        $request->validate([
            'teacher_code' => 'required|string|max:20',
        ]);

        $validation = $this->app->validateTeacherCode((string) $request->input('teacher_code'));

        if (! $validation['ok'] || ! $validation['tenant']) {
            return response()->json([
                'message' => $this->codeErrorMessage($validation['reason']),
                'reason' => $validation['reason'],
            ], $validation['reason'] === 'feature_disabled' ? 503 : 422);
        }

        return response()->json([
            'valid' => true,
            'teacher' => $this->app->appContext($validation['tenant']),
        ]);
    }

    /**
     * GET /api/v1/mobile/teacher/context — authenticated.
     * Server re-validates membership and returns the current teacher context.
     */
    public function context(Request $request): JsonResponse
    {
        $tenant = $this->ensureStudentContext($request);

        return response()->json([
            'teacher' => $this->app->appContext($tenant),
        ]);
    }

    /**
     * GET /api/v1/mobile/site — authenticated.
     * Same teacher landing page as the web (same DB source, never a copy).
     */
    public function site(Request $request): JsonResponse
    {
        $tenant = $this->ensureStudentContext($request);
        $setting = $tenant->settings;

        return response()->json([
            'site' => TeacherSiteController::sitePayload(
                $tenant,
                $setting,
                $setting?->metadata['site_config'] ?? []
            ),
        ]);
    }

    /**
     * GET /api/v1/mobile/home — authenticated.
     * Branding + my enrolled courses + visible announcements in one call.
     */
    public function home(Request $request): JsonResponse
    {
        $tenant = $this->ensureStudentContext($request);
        $user = $request->user();

        $setting = $tenant->settings;

        $site = TeacherSiteController::sitePayload(
            $tenant,
            $setting,
            $setting?->metadata['site_config'] ?? []
        );

        $enrollments = Enrollment::with('course')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->orderByDesc('enrolled_at')
            ->get();

        $announcements = Announcement::where('tenant_id', $tenant->id)
            ->where('status', 'sent')
            ->whereNotNull('published_at')
            ->where(function ($q) use ($user, $tenant) {
                $q->where('audience', 'all')
                    ->orWhere('audience', 'students')
                    ->orWhere(function ($courseQ) use ($user, $tenant) {
                        $courseQ->where('audience', 'course')
                            ->whereExists(function ($exists) use ($user, $tenant) {
                                $exists->selectRaw('1')
                                    ->from('enrollments')
                                    ->whereColumn('enrollments.course_id', 'announcements.course_id')
                                    ->where('enrollments.user_id', $user->id)
                                    ->where('enrollments.tenant_id', $tenant->id);
                            });
                    });
            })
            ->orderByDesc('published_at')
            ->limit(10)
            ->get();

        return response()->json([
            'teacher' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
            ],
            'site' => $site,
            'enrolled_courses' => $enrollments->map(function (Enrollment $enrollment) {
                $course = $enrollment->course;

                return [
                    'enrollment_id' => $enrollment->id,
                    'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
                    'course' => $this->coursePayload($course),
                ];
            }),
            'announcements' => $announcements->map(fn (Announcement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'body' => $a->body,
                'published_at' => $a->published_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * GET /api/v1/mobile/courses — authenticated.
     * Published courses of the current teacher + the student's enrollment state.
     */
    public function courses(Request $request): JsonResponse
    {
        $tenant = $this->ensureStudentContext($request);
        $user = $request->user();

        $enrolledIds = Enrollment::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->pluck('course_id')
            ->flip();

        $courses = Course::where('tenant_id', $tenant->id)
            ->where('is_published', true)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'courses' => $courses->map(fn (Course $c) => [
                ...$this->coursePayload($c),
                'is_enrolled' => $enrolledIds->has($c->id),
            ]),
        ]);
    }

    /**
     * GET /api/v1/mobile/courses/{course} — authenticated.
     * Course detail + sections/lessons with lock state (drip, preview,
     * enrollment) identical to the web learning experience.
     */
    public function course(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->ensureStudentContext($request);
        $user = $request->user();

        $course = Course::where('tenant_id', $tenant->id)
            ->with(['sections.lessons.contents.file'])
            ->find($courseId);

        if (! $course) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        $enrollment = Enrollment::where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->first();

        $enrolled = $enrollment !== null;

        // Drip content unlocks gradually after enrollment.
        $dripEnabled = (bool) $course->drip_enabled && $enrolled;
        $intervalDays = max(1, (int) $course->drip_interval_days);
        $position = 0;

        $sections = $course->sections->map(function ($section) use ($enrolled, $dripEnabled, $intervalDays, &$position, $enrollment) {
            return [
                'id' => $section->id,
                'title' => $section->title,
                'sort_order' => $section->sort_order,
                'lessons' => $section->lessons->map(function ($lesson) use ($enrolled, $dripEnabled, $intervalDays, &$position, $enrollment) {
                    $position++;
                    $unlockAt = null;

                    if ($dripEnabled) {
                        $unlockAt = $enrollment?->enrolled_at?->addDays(($position - 1) * $intervalDays);
                    }

                    $locked = ! ($enrolled || $lesson->is_free_preview);
                    if ($dripEnabled && ! $lesson->is_free_preview) {
                        $locked = $unlockAt ? now()->lt($unlockAt) : true;
                    }

                    return [
                        'id' => $lesson->id,
                        'title' => $lesson->title,
                        'lesson_type' => $lesson->lesson_type,
                        'duration_minutes' => $lesson->duration_minutes,
                        'is_free_preview' => (bool) $lesson->is_free_preview,
                        'locked' => $locked,
                        'unlock_at' => $locked ? $unlockAt?->toIso8601String() : null,
                        'position' => $position,
                    ];
                }),
            ];
        });

        return response()->json([
            'course' => $this->coursePayload($course),
            'enrolled' => $enrolled,
            'sections' => $sections,
            'drip' => [
                'enabled' => $dripEnabled,
                'interval_days' => $dripEnabled ? $intervalDays : null,
            ],
        ]);
    }

    /**
     * GET /api/v1/mobile/lessons/{lesson} — authenticated.
     * Lesson contents with protected (signed, short-lived, user-bound) video
     * playback and the tenant's watermark config — the same protection the web
     * uses. Course ownership + enrollment are enforced before any payload leaks.
     */
    public function lesson(Request $request, string $lessonId): JsonResponse
    {
        $tenant = $this->ensureStudentContext($request);
        $user = $request->user();

        $lesson = Lesson::where('id', $lessonId)
            ->where('tenant_id', $tenant->id)
            ->with(['contents.video', 'contents.text', 'contents.file', 'section'])
            ->first();

        if (! $lesson || ! $lesson->section) {
            return response()->json(['message' => 'Lesson not found.'], 404);
        }

        $course = Course::where('id', $lesson->section->course_id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $course) {
            return response()->json(['message' => 'Lesson not found.'], 404);
        }

        $enrollment = Enrollment::where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->first();

        $enrolled = $enrollment !== null;

        if (! $lesson->is_free_preview && ! $enrolled) {
            return response()->json(['message' => 'This lesson is locked. Enroll to access it.'], 403);
        }

        if ((bool) $course->drip_enabled && ! $lesson->is_free_preview) {
            $intervalDays = max(1, (int) $course->drip_interval_days);
            $position = $lesson->positionInCourse();
            $unlockAt = $enrollment?->enrolled_at?->addDays(max(0, $position - 1) * $intervalDays);

            if ($unlockAt && now()->lt($unlockAt)) {
                return response()->json([
                    'message' => 'Lesson locked',
                    'unlock_at' => $unlockAt->toIso8601String(),
                ], 423);
            }
        }

        $settings = $tenant->settings;
        $downloadsEnabled = (bool) ($settings?->file_downloads_enabled ?? true);

        $contents = $lesson->contents->map(function ($content) use ($user, $downloadsEnabled) {
            $payload = [
                'id' => $content->id,
                'content_type' => $content->content_type,
                'sort_order' => $content->sort_order,
                'is_required' => $content->is_required,
                'title' => $content->title,
            ];

            if ($content->video && $user) {
                $payload['video'] = $content->video->provider === 'bunny'
                    ? $this->protection->providerPlaybackPayload($content->video, $user)
                    : $this->protection->playbackPayload($content->video, $user);
            } elseif ($content->video) {
                $payload['video'] = [
                    'type' => 'external',
                    'provider' => $content->video->provider,
                    'title' => $content->video->title,
                    'duration' => (int) $content->video->duration,
                ];
            }

            if ($content->text) {
                $payload['text'] = [
                    'body' => $content->text->body,
                    'format' => $content->text->format,
                ];
            }

            if ($content->file) {
                $payload['file'] = [
                    'name' => $content->file->original_name,
                    'mime_type' => $content->file->mime_type,
                    'size_bytes' => $content->file->size_bytes,
                    'previewable' => $this->isPreviewable($content->file),
                    'is_downloadable' => $downloadsEnabled && (bool) $content->file->is_downloadable,
                    'url' => '/lessons/'.$content->lesson_id.'/file-ticket?tenant_id='.$content->tenant_id,
                ];
            }

            return $payload;
        });

        return response()->json([
            'course' => $this->coursePayload($course),
            'lesson' => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'is_free_preview' => (bool) $lesson->is_free_preview,
                'enrolled' => $enrolled,
            ],
            'contents' => $contents,
        ]);
    }

    /**
     * GET /api/v1/mobile/announcements — authenticated.
     */
    public function announcements(Request $request): JsonResponse
    {
        $tenant = $this->ensureStudentContext($request);
        $user = $request->user();

        $announcements = Announcement::where('tenant_id', $tenant->id)
            ->where('status', 'sent')
            ->whereNotNull('published_at')
            ->where(function ($q) use ($user, $tenant) {
                $q->where('audience', 'all')
                    ->orWhere('audience', 'students')
                    ->orWhere(function ($courseQ) use ($user, $tenant) {
                        $courseQ->where('audience', 'course')
                            ->whereExists(function ($exists) use ($user, $tenant) {
                                $exists->selectRaw('1')
                                    ->from('enrollments')
                                    ->whereColumn('enrollments.course_id', 'announcements.course_id')
                                    ->where('enrollments.user_id', $user->id)
                                    ->where('enrollments.tenant_id', $tenant->id);
                            });
                    });
            })
            ->orderByDesc('published_at')
            ->get();

        return response()->json([
            'announcements' => $announcements->map(fn (Announcement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'body' => $a->body,
                'published_at' => $a->published_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Server-side membership check: the authenticated student must be an
     * ACTIVE student of the tenant identified by X-Tenant-Id. The tenant id
     * is only used to look up — never trusted as proof of access.
     */
    private function ensureStudentContext(Request $request)
    {
        $tenantId = $request->header('X-Tenant-Id') ?? $request->input('tenant_id');

        if (! $tenantId) {
            abort(400, 'Teacher context missing.');
        }

        $pivot = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->first();

        abort_if(! $pivot || $pivot->role !== 'student', 403, 'You are not enrolled with this teacher.');

        $tenant = $pivot->tenant;

        abort_if(! $tenant || $tenant->status !== 'active', 403, 'Teacher platform is not active.');

        return $tenant;
    }

    private function coursePayload(Course $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'description' => $course->description,
            'short_description' => $course->short_description,
            'duration_hours' => $course->duration_hours,
            'duration_minutes' => $course->duration_minutes,
            'level' => $course->level,
            'price' => $course->price,
            'currency' => $course->currency,
            'is_published' => (bool) $course->is_published,
            'prerequisites' => $course->prerequisites,
            'lessons_count' => $course->lessons_count,
            'sections_count' => $course->sections_count,
        ];
    }

    private function isPreviewable($file): bool
    {
        $previewable = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

        return in_array(strtolower((string) ($file->extension ?? '')), $previewable, true);
    }

    private function codeErrorMessage(?string $reason): string
    {
        return match ($reason) {
            'code_not_found' => 'Teacher code not found. Check the code and try again.',
            'teacher_inactive' => 'This teacher account is not active.',
            'feature_disabled' => 'The student app is currently under development.',
            'subscription_inactive' => 'This teacher subscription is inactive.',
            'plan_denied' => 'The student app is not included in this teacher plan.',
            default => 'Teacher code is invalid.',
        };
    }
}
