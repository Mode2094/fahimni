<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivationCard;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Post;
use App\Models\SubscriptionPlan;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TicketMessage;
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

    public function sections(Request $request, string $courseId): JsonResponse
    {
        $course = Course::where('is_published', true)
            ->with(['sections.lessons.contents.file'])
            ->find($courseId);

        if (! $course) {
            // Students with an active enrollment may access the course even
            // while it is unpublished (e.g. they activated a card for it).
            $course = Course::with(['sections.lessons.contents.file'])
                ->find($courseId);

            $user = $request->user() ?? auth('sanctum')->user();
            $enrolled = $user && $user->enrollments()
                ->where('course_id', $courseId)
                ->whereIn('status', ['active', 'completed'])
                ->exists();

            if (! $course || ! $enrolled) {
                abort(404, 'Course not found.');
            }
        }

        $settings = TenantSetting::where('tenant_id', $course->tenant_id)->first();
        $downloadsEnabled = (bool) ($settings?->file_downloads_enabled ?? true);

        $user = $request->user() ?? auth('sanctum')->user();
        $enrolled = false;
        $enrollment = null;

        if ($user) {
            // المالك/طاقم المؤسسة (معلم/مدير) يعتبر "مسجلاً" تلقائياً —
            // يملك الكورس ولا يحتاج تسجيلاً رسمياً.
            $isOwner = $user->is_super_admin
                || $user->tenants()
                    ->where('tenant_id', $course->tenant_id)
                    ->wherePivotIn('role', ['teacher', 'owner', 'admin'])
                    ->wherePivot('status', 'active')
                    ->exists();

            $enrollment = $user->enrollments()
                ->where('course_id', $course->id)
                ->whereIn('status', ['active', 'completed'])
                ->first();

            $enrolled = $isOwner || $enrollment !== null;
        }

        // Drip content: lessons unlock gradually after enrollment.
        $dripEnabled = (bool) $course->drip_enabled && $enrolled;
        $intervalDays = max(1, (int) $course->drip_interval_days);
        $position = 0;

        $sections = $course->sections->map(function ($section) use ($enrolled, $dripEnabled, $intervalDays, &$position, $enrollment, $course) {
            return [
                'id' => $section->id,
                'title' => $section->title,
                'sort_order' => $section->sort_order,
                'lessons' => $section->lessons->map(function ($lesson) use ($enrolled, $dripEnabled, $intervalDays, &$position, $enrollment, $course) {
                    $position++;
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
                }),
            ];
        });

        return response()->json([
            'course' => $this->coursePayload($course),
            'sections' => $sections,
            'drip' => [
                'enabled' => $dripEnabled,
                'interval_days' => $dripEnabled ? $intervalDays : null,
            ],
            'file_downloads_enabled' => $downloadsEnabled,
        ]);
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
            // (card-activated courses that are not yet public).
            $course = Course::find($courseId);

            $enrolled = $user && $course && $user->enrollments()
                ->where('course_id', $course->id)
                ->whereIn('status', ['active', 'completed'])
                ->exists();

            if (! $course || ! $enrolled) {
                abort(404, 'Course not found.');
            }
        }

        $lesson = Lesson::where('id', $lessonId)
            ->whereHas('section', fn ($q) => $q->where('course_id', $course->id))
            ->with(['contents.video', 'contents.text', 'contents.file', 'contents.liveSession'])
            ->firstOrFail();

        $enrollment = null;
        if ($user) {
            $enrollment = $user->enrollments()
                ->where('course_id', $course->id)
                ->whereIn('status', ['active', 'completed'])
                ->first();
        }

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

        $protection = app(VideoProtectionService::class);

        $settings = TenantSetting::where('tenant_id', $course->tenant_id)->first();
        $downloadsEnabled = (bool) ($settings?->file_downloads_enabled ?? true);

        $contents = $lesson->contents->map(function ($content) use ($user, $protection, $downloadsEnabled) {
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
