<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivationCard;
use App\Models\Bundle;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Lesson;
use App\Models\Post;
use App\Models\SubscriptionPlan;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\VideoProtectionService;
use App\Support\FeatureCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class PublicSiteController extends Controller
{
    public function contactStore(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'subject' => 'nullable|string|max:200',
            'message' => 'required|string|max:10000',
        ]);

        $subject = $request->subject
            ? '['.$request->name.'] '.$request->subject
            : 'رسالة من '.$request->name;

        $ticket = SupportTicket::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => null,
            'user_id' => null,
            'subject' => mb_substr($subject, 0, 200),
            'category' => 'contact',
            'priority' => 'medium',
            'channel' => 'email',
            'status' => 'open',
            'metadata' => ['from' => 'contact-page', 'visitor_email' => $request->email],
        ]);

        TicketMessage::create([
            'id' => UuidV7::generate(),
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'body' => $request->message,
            'metadata' => ['via' => 'contact-page', 'visitor_name' => $request->name, 'visitor_email' => $request->email],
        ]);

        return response()->json(['message' => 'Message received.'], 201);
    }

    public function cardInfo(string $code): JsonResponse
    {
        $card = ActivationCard::where('code', $code)->first();

        if (! $card) {
            return response()->json(['message' => 'Card not found.'], 404);
        }

        $course = $card->course()->withTrashed()->first();
        $tenant = $card->tenant;

        return response()->json([
            'card' => [
                'code' => $card->code,
                'status' => $card->status,
                'expires_at' => $card->expires_at?->toIso8601String(),
                'course' => $course ? [
                    'id' => $course->id,
                    'title' => $course->title,
                ] : null,
                'tenant' => $tenant ? [
                    'id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'name' => $tenant->name,
                ] : null,
            ],
        ]);
    }

    /**
     * SEO sitemap feed: every indexable public teacher site + their published
     * blog posts. Read-only, throttled. Only public data — no PII.
     */
    public function sitemap(): JsonResponse
    {
        $tenants = Tenant::where('status', 'active')->orderByDesc('updated_at')->get(['id', 'slug', 'updated_at']);

        $teachers = $tenants->map(function (Tenant $tenant) {
            $posts = Post::where('tenant_id', $tenant->id)
                ->where('status', 'published')
                ->orderByDesc('published_at')
                ->get(['slug', 'published_at', 'updated_at']);

            return [
                'slug' => $tenant->slug,
                'updated_at' => $tenant->updated_at?->toIso8601String(),
                'posts' => $posts->map(fn (Post $p) => [
                    'slug' => $p->slug,
                    'updated_at' => ($p->published_at ?? $p->updated_at)?->toIso8601String(),
                ])->values(),
            ];
        })->values();

        return response()->json(['teachers' => $teachers]);
    }

    public function show(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('status', 'active')->first();

        if (! $tenant) {
            return response()->json(['message' => 'Site not found.'], 404);
        }

        $settings = TenantSetting::where('tenant_id', $tenant->id)->first();

        return response()->json([
            'site' => TeacherSiteController::sitePayload(
                $tenant,
                $settings,
                $settings?->metadata['site_config'] ?? []
            ),
        ]);
    }

    public function courses(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('status', 'active')->first();

        if (! $tenant) {
            return response()->json(['message' => 'Site not found.'], 404);
        }

        $courses = Course::where('tenant_id', $tenant->id)
            ->where('is_published', true)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'courses' => $courses->map(fn (Course $c) => $this->coursePayload($c)),
        ]);
    }

    /**
     * Public course detail for a teacher's own site (/t/{slug}/courses/{course}).
     * Only a PUBLISHED course that belongs to the active tenant slug is ever
     * returned, so a course id from another academy can never be rendered under
     * this teacher's brand, and an unpublished course simply 404s — a deleted
     * or hidden course never leaves a page exposing private content.
     *
     * The curriculum payload itself is produced by `sections()`, so the public
     * course page and the enrolled lesson player never drift apart.
     */
    public function course(Request $request, string $slug, string $courseId): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('status', 'active')->first();

        if (! $tenant) {
            return response()->json(['message' => 'Site not found.'], 404);
        }

        $owned = Course::where('tenant_id', $tenant->id)
            ->where('is_published', true)
            ->whereKey($courseId)
            ->exists();

        if (! $owned) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        return $this->sections($request, $courseId);
    }

    /**
     * Published course bundles for a teacher's public site. Only courses that
     * are themselves published are exposed inside a bundle, so an unpublished
     * draft course can never leak through the package listing.
     */
    public function bundles(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('status', 'active')->first();

        if (! $tenant) {
            return response()->json(['message' => 'Site not found.'], 404);
        }

        $bundles = Bundle::where('tenant_id', $tenant->id)
            ->where('is_published', true)
            ->withCount(['courses' => fn ($q) => $q->where('courses.is_published', true)])
            ->with(['courses' => fn ($q) => $q
                ->where('courses.is_published', true)
                ->select('courses.id', 'courses.title', 'courses.slug', 'courses.thumbnail_url', 'courses.price', 'courses.level'),
            ])
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'bundles' => $bundles->map(fn (Bundle $b) => [
                'id' => $b->id,
                'title' => $b->title,
                'slug' => $b->slug,
                'description' => $b->description,
                'short_description' => $b->short_description,
                'thumbnail_url' => $b->thumbnail_url,
                'price' => $b->price,
                'currency' => $b->currency,
                'courses_count' => $b->courses_count,
                'courses' => $b->courses->map(fn (Course $c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'slug' => $c->slug,
                    'thumbnail_url' => $c->thumbnail_url,
                    'price' => $c->price,
                    'level' => $c->level,
                ])->values(),
            ])->values(),
        ]);
    }

    public function sections(Request $request, string $courseId): JsonResponse
    {
        $user = $request->user() ?? auth('sanctum')->user();

        $course = Course::where('is_published', true)
            ->with(['sections.lessons.contents.file'])
            ->find($courseId);

        if (! $course) {
            // Students with an active enrollment may access the course even
            // while it is unpublished (e.g. they activated a card for it).
            // The course author and authorised tenant staff may always open a
            // draft course too — that is what powers "preview as student".
            $course = Course::with(['sections.lessons.contents.file'])
                ->find($courseId);

            $isStaff = $course && $this->isTenantStaff($user, $course->tenant_id);

            $enrolled = $user && $course && $user->enrollments()
                ->where('course_id', $courseId)
                ->effective()
                ->exists();

            if (! $course || ! ($enrolled || $isStaff)) {
                abort(404, 'Course not found.');
            }
        }

        $settings = TenantSetting::where('tenant_id', $course->tenant_id)->first();
        $downloadsEnabled = (bool) ($settings?->file_downloads_enabled ?? true);

        $enrollment = null;

        // المالك/طاقم المؤسسة (معلم/مدير) يعتبر "مسجلاً" تلقائياً — يملك الكورس
        // ولا يحتاج تسجيلاً رسمياً، ويُعدّ في وضع "المعاينة كطالب".
        $isOwner = $this->isTenantStaff($user, $course->tenant_id);
        $preview = $isOwner;

        if ($user) {
            $enrollment = $user->enrollments()
                ->where('course_id', $course->id)
                ->effective()
                ->first();
        }

        $enrolled = $isOwner || $enrollment !== null;

        // Course groups: a lesson restricted to groups is only visible to their
        // members. Staff/owner always see everything. Membership never creates
        // an enrolment, so seats are unaffected.
        $memberGroupIds = ($user && ! $isOwner)
            ? \Illuminate\Support\Facades\DB::table('course_group_members as m')
                ->join('course_groups as g', 'g.id', '=', 'm.course_group_id')
                ->where('g.course_id', $course->id)
                ->where('g.is_active', true)
                ->where('m.user_id', $user->id)
                ->pluck('g.id')->all()
            : [];

        $lessonGroupIds = \Illuminate\Support\Facades\DB::table('course_group_lesson as l')
            ->join('course_groups as g', 'g.id', '=', 'l.course_group_id')
            ->where('g.course_id', $course->id)
            ->get(['l.lesson_id', 'l.course_group_id'])
            ->groupBy('lesson_id')
            ->map(fn ($rows) => $rows->pluck('course_group_id')->all())
            ->all();

        $lessonVisible = function (string $lessonId) use ($isOwner, $lessonGroupIds, $memberGroupIds): bool {
            if ($isOwner || ! isset($lessonGroupIds[$lessonId])) {
                return true;
            }

            return count(array_intersect($lessonGroupIds[$lessonId], $memberGroupIds)) > 0;
        };

        // Drip content: lessons unlock gradually after enrollment. Staff in
        // preview mode always see everything unlocked, so drip is never applied
        // to them (they have no enrollment date to drip from).
        $dripEnabled = (bool) $course->drip_enabled && $enrolled && ! $isOwner;
        $intervalDays = max(1, (int) $course->drip_interval_days);
        $position = 0;

        $sections = $course->sections->map(function ($section) use ($enrolled, $dripEnabled, $intervalDays, &$position, $enrollment, $course, $lessonVisible, $isOwner) {
            return [
                'id' => $section->id,
                'title' => $section->title,
                'sort_order' => $section->sort_order,
                'lessons' => $section->lessons->map(function ($lesson) use ($enrolled, $dripEnabled, $intervalDays, &$position, $enrollment, $course, $lessonVisible, $isOwner) {
                    $position++;

                    // Hidden for this student's group — not merely locked.
                    if (! $lessonVisible($lesson->id)) {
                        return null;
                    }

                    $unlockAt = null;

                    if ($dripEnabled) {
                        $unlockAt = $enrollment?->enrolled_at?->addDays(($position - 1) * $intervalDays);
                    }

                    $locked = ! ($enrolled || $lesson->is_free_preview);
                    if ($dripEnabled && ! $lesson->is_free_preview) {
                        $locked = $unlockAt ? now()->lt($unlockAt) : true;
                    }

                    $file = $lesson->contents->where('content_type', 'file')->first()?->file;

                    return [
                        'id' => $lesson->id,
                        'title' => $lesson->title,
                        'lesson_type' => $lesson->lesson_type,
                        'duration_minutes' => $lesson->duration_minutes,
                        'is_free_preview' => (bool) $lesson->is_free_preview,
                        'locked' => $locked,
                        'unlock_at' => $locked ? $unlockAt?->toIso8601String() : null,
                        'position' => $position,
                        'file_url' => ! $locked && $file
                            ? '/lessons/'.$lesson->id.'/file-ticket?tenant_id='.$course->tenant_id
                            : null,
                        'file_name' => ! $locked && $file ? ($file->original_name ?? null) : null,
                        'file_previewable' => ! $locked && $file ? $this->isPreviewable($file) : false,
                    ];
                })->filter()->values(),
            ];
        });

        return response()->json([
            'course' => $this->coursePayload($course),
            'sections' => $sections,
            // Whether the caller is enrolled (owner/staff count as enrolled):
            // drives the "Enrolled / Guest" badge on the lesson page.
            'is_enrolled' => $enrolled,
            // True when the viewer is the course author / authorised staff, so
            // the client can flag "preview as student". Purely informational —
            // the server already authorised the request above.
            'preview' => $preview,
            'drip' => [
                'enabled' => $dripEnabled,
                'interval_days' => $dripEnabled ? $intervalDays : null,
            ],
            'file_downloads_enabled' => $downloadsEnabled,
        ]);
    }

    /**
     * The course author and authorised tenant staff (teacher/owner/admin) for
     * this course's tenant. Super admins always qualify. Gates "preview as
     * student" access to draft (unpublished) content; a student can never
     * satisfy it, so `?preview=1` can never bypass publishing or a subscription.
     */
    private function isTenantStaff(?User $user, string $tenantId): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->is_super_admin) {
            return true;
        }

        return $user->tenants()
            ->where('tenant_id', $tenantId)
            ->wherePivotIn('role', ['teacher', 'owner', 'admin'])
            ->wherePivot('status', 'active')
            ->exists();
    }

    private function isPreviewable($file): bool
    {
        $previewable = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

        return in_array(strtolower((string) ($file->extension ?? '')), $previewable, true);
    }

    /**
     * Lesson content for enrolled students (or free-preview lessons).
     * Uploaded videos are returned as signed, short-lived, user-bound stream URLs —
     * never as a public file link.
     */
    public function lesson(Request $request, string $courseId, string $lessonId): JsonResponse
    {
        $course = Course::where('is_published', true)
            ->find($courseId);

        $user = $request->user() ?? auth('sanctum')->user();

        if (! $course) {
            // Enrolled students may access lessons of unpublished courses
            // (card-activated courses that are not yet public). The course
            // author / authorised staff may always open a draft lesson — this
            // powers "preview as student" for unpublished content.
            $course = Course::find($courseId);

            $isStaff = $course && $this->isTenantStaff($user, $course->tenant_id);

            $enrolled = $user && $course && $user->enrollments()
                ->where('course_id', $course->id)
                ->effective()
                ->exists();

            if (! $course || ! ($enrolled || $isStaff)) {
                abort(404, 'Course not found.');
            }
        }

        $lesson = Lesson::where('id', $lessonId)
            ->whereHas('section', fn ($q) => $q->where('course_id', $course->id))
            ->with(['contents.video', 'contents.text', 'contents.file', 'contents.liveSession'])
            ->firstOrFail();

        $enrollment = null;

        // المالك/طاقم المؤسسة (معلم/مدير) يملك الكورس ولا يحتاج تسجيلاً
        // رسمياً — نفس منطق sections() حتى لا يُحجب المعلم عن درسـه.
        $isOwner = $this->isTenantStaff($user, $course->tenant_id);

        if ($user) {
            $enrollment = $user->enrollments()
                ->where('course_id', $course->id)
                ->effective()
                ->first();
        }

        $enrolled = $enrollment !== null || $isOwner;

        // Course-group restriction: a lesson shared to specific groups is only
        // reachable by their members (staff always reach it). Enforced before
        // the free-preview allowance so a restricted lesson never leaks.
        if (! $isOwner) {
            $groupIds = $lesson->groups()->pluck('course_groups.id')->all();
            if ($groupIds) {
                $isMember = $user && $lesson->groups()
                    ->whereHas('members', fn ($m) => $m->where('users.id', $user->id))
                    ->exists();
                if (! $isMember) {
                    return response()->json(['message' => 'This lesson is not available for your group.'], 403);
                }
            }
        }

        if (! $lesson->is_free_preview && ! $enrolled) {
            return response()->json(['message' => 'This lesson is locked. Enroll to access it.'], 403);
        }

        if ((bool) $course->drip_enabled && ! $lesson->is_free_preview && ! $isOwner) {
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

        $protection = app(VideoProtectionService::class);

        $settings = TenantSetting::where('tenant_id', $course->tenant_id)->first();
        $downloadsEnabled = (bool) ($settings?->file_downloads_enabled ?? true);

        $contents = $lesson->contents->map(function ($content) use ($user, $protection, $downloadsEnabled, $lesson) {
            $payload = [
                'id' => $content->id,
                'content_type' => $content->content_type,
                'sort_order' => $content->sort_order,
                'is_required' => $content->is_required,
                'title' => $content->title,
            ];

            if ($content->video && $user) {
                // فيديوهات Bunny المسجلة تأخذ رابط HLS مباشر —
                // لا يُعامل الفيديو المرفوع على أنه رابط خارجي.
                $payload['video'] = $content->video->provider === 'bunny'
                    ? $protection->providerPlaybackPayload($content->video, $user)
                    : $protection->playbackPayload($content->video, $user);
            } elseif ($content->video) {
                $payload['video'] = [
                    'type' => 'external',
                    'provider' => $content->video->provider,
                    'title' => $content->video->title,
                    'duration' => (int) $content->video->duration,
                ];

                // A free-preview lesson can be watched by a guest. When the
                // video lives on an external public host (YouTube/Vimeo) the
                // provider id is already part of the public embed URL, so
                // exposing it leaks nothing and lets the storefront preview it.
                // A hosted (Bunny) stream stays hidden — only a "requires
                // login" flag is sent, never a stream URL.
                if (in_array($content->video->provider, ['youtube', 'vimeo'], true)) {
                    $payload['video']['provider_video_id'] = $content->video->provider_video_id;
                    $payload['video']['requires_login'] = ! $lesson->is_free_preview;
                } else {
                    $payload['video']['requires_login'] = true;
                }
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

            // البث المباشر داخل الدرس: الحالة + رابط Google Meet + التسجيل.
            if ($content->liveSession) {
                $live = $content->liveSession;

                $payload['live'] = [
                    'id' => $live->id,
                    'title' => $live->title,
                    'provider' => $live->provider,
                    'meeting_url' => ($live->metadata ?? [])['meeting_url'] ?? null,
                    'status' => $live->status,
                    'stream_url' => null,
                    'player_type' => 'meeting',
                    'record_url' => $live->record_url,
                    'started_at' => $live->started_at?->toIso8601String(),
                    'ended_at' => $live->ended_at?->toIso8601String(),
                    'scheduled_at' => $live->scheduled_at?->toIso8601String(),
                    'record_enabled' => (bool) $live->record_enabled,
                ];
            }

            return $payload;
        });

        // A video lesson may carry one attached exercise. It is the SAME exam
        // engine as a standalone exam (one attempt system, one grading system),
        // never a duplicated score. Unpublished exams are never exposed, and a
        // foreign-tenant exam id can never be reached (tenant scope + explicit
        // match on the exam's tenant).
        $exercise = null;
        $attachedExamId = $lesson->metadata['exercise_exam_id'] ?? null;

        if ($attachedExamId && $user) {
            $exam = Exam::where('id', $attachedExamId)
                ->where('tenant_id', $course->tenant_id)
                ->withCount('questions')
                ->first();

            if ($exam && ($exam->is_published || $isOwner)) {
                $attempts = ExamAttempt::where('exam_id', $exam->id)
                    ->where('user_id', $user->id)
                    ->where('status', 'submitted')
                    ->orderByDesc('created_at')
                    ->get(['id', 'score', 'total_points', 'percentage', 'passed']);

                $used = $attempts->count();
                $attemptsLeft = $exam->max_attempts > 0 ? max(0, $exam->max_attempts - $used) : -1;
                $best = $attempts->max('percentage');
                $last = $attempts->first();

                $exercise = [
                    'exam_id' => $exam->id,
                    'title' => $exam->title,
                    'description' => $exam->description,
                    'questions_count' => (int) $exam->questions_count,
                    'duration_minutes' => $exam->duration_minutes,
                    'pass_mark_percent' => (float) $exam->pass_mark_percent,
                    'max_attempts' => $exam->max_attempts,
                    'attempts_used' => $used,
                    'attempts_left' => $attemptsLeft,
                    'best_percentage' => $best !== null ? (float) $best : null,
                    'position' => $lesson->metadata['exercise_position'] ?? 'after_video',
                    'last' => $last ? [
                        'percentage' => (float) $last->percentage,
                        'passed' => (bool) $last->passed,
                    ] : null,
                ];
            }
        }

        return response()->json([
            'course' => $this->coursePayload($course),
            'lesson' => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'is_free_preview' => (bool) $lesson->is_free_preview,
                'enrolled' => $enrolled,
            ],
            // True when the viewer is the author / authorised staff.
            'preview' => $isOwner,
            'contents' => $contents,
            'exercise' => $exercise,
        ]);
    }

    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::where('is_active', true)
            ->with('features')
            ->orderBy('monthly_price')
            ->get();

        return response()->json([
            'plans' => $plans->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'monthly_price' => (float) $p->monthly_price,
                'annual_price' => $p->annual_price !== null ? (float) $p->annual_price : null,
                'currency' => $p->currency ?? 'USD',
                'max_courses' => $p->max_courses,
                'max_students' => $p->max_students,
                'popular' => (bool) $p->is_popular,
                'features' => FeatureCatalog::localizeFeatures($p->features),
            ]),
        ]);
    }

    private function coursePayload(Course $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'description' => $course->description,
            'short_description' => $course->short_description,
            'thumbnail_url' => $course->thumbnail_url,
            'cover_url' => $course->cover_url,
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
}
