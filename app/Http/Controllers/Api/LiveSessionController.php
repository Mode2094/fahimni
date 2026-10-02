<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\ZapierDriver;
use App\Live\LiveProviderManager;
use App\Media\MediaQuotaService;
use App\Media\MediaUsageService;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\LiveSession;
use App\Plans\Exceptions\FeatureUnavailableException;
use App\Plans\Exceptions\QuotaExceededException;
use App\Plans\PlanEngine;
use App\Rules\TenantScopedExists;
use App\Rules\ValidGoogleMeetUrl;
use App\Services\IntegrationService;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\UuidV7;

class LiveSessionController extends Controller
{
    use AuthorizesTenantAccess;

    /** الجلسات القديمة بمزودات محذوفة (zoom/jitsi/teams/youtube/bunny/cloudflare/browser) — بياناتها من metadata فقط بلا أي مزود. */
    private const LEGACY_METADATA_ONLY_PROVIDERS = [
        'zoom', 'jitsi', 'teams', 'youtube', 'bunny', 'cloudflare', 'browser', 'custom',
    ];

    /** المزود الوحيد لإنشاء جلسات جديدة: Google Meet (رابط المعلم). */
    private const CREATABLE_PROVIDERS = ['google_meet'];

    public function __construct(
        private readonly IntegrationService $integrations,
        private readonly LiveProviderManager $liveProviders,
    ) {}

    /**
     * المزودون المتاحون للمعلم (المفعّل + المكوّن فقط).
     */
    public function providers(): JsonResponse
    {
        $out = [];

        foreach ($this->liveProviders->enabledProviders() as $slug => $driver) {
            $out[] = [
                'provider' => $slug,
                'label' => $driver->label(),
                'player_type' => $driver->playerType(),
            ];
        }

        return response()->json(['providers' => $out]);
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->current_tenant->id;

        $sessions = LiveSession::where('tenant_id', $tenantId)
            ->with('course:id,title')
            ->orderByDesc('scheduled_at')
            ->get();

        return response()->json([
            'sessions' => $sessions->map(fn ($s) => $this->payload($s, $request)),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->current_tenant->id;
        $session = LiveSession::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json(['session' => $this->payload($session, $request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->current_tenant->id;

        // الحصة تُفحص + الجلسة تُنشأ داخل قفل ذري — يمنع تجاوز quota بالتزامن.
        $quota = app(MediaQuotaService::class);

        try {
            return $quota->withLiveCreationLock($tenantId, fn () => $this->createSessionUnderLock($request));
        } catch (FeatureUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (QuotaExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    private function createSessionUnderLock(Request $request): JsonResponse
    {
        $tenantId = $request->current_tenant->id;

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'provider' => 'nullable|string|in:'.implode(',', self::CREATABLE_PROVIDERS),
            'meeting_url' => 'required|string|max:500',
            'course_id' => ['nullable', 'string', new TenantScopedExists('courses')],
            'lesson_id' => ['nullable', 'string'],
            'scheduled_at' => 'nullable|date',
            'max_participants' => 'nullable|integer|min:1',
            'record_enabled' => 'nullable|boolean',
            'duration_minutes' => 'nullable|integer|min:5|max:600',
        ]);

        $provider = $validated['provider'] ?? $this->liveProviders->defaultProvider();

        // 1) المزود مفعّل ومكوّن؟ (لا يظهر للمعلم لو Disabled)
        $driver = $this->liveProviders->resolve($provider);

        if (! $driver->isEnabled()) {
            return response()->json(['message' => 'This live provider is disabled by the platform.'], 403);
        }

        if (! $driver->isConfigured()) {
            return response()->json(['message' => 'This live provider is not configured yet.'], 422);
        }

        // Google Meet: المعلم يُلصق رابط الحصة — إلزامي + صيغة صحيحة.
        $request->validate([
            'meeting_url' => ['required', 'string', 'max:500', new ValidGoogleMeetUrl],
        ]);

        unset($validated['meeting_url']); // لا يُخزن في $validated — يذهب لـ metadata فقط.

        // 2) ربط الجلسة بالدرس: محتوى نوع live داخل الدرس نفسه.
        $lessonContentId = null;
        $courseId = $validated['course_id'] ?? null;

        if (! empty($validated['lesson_id'])) {
            $lesson = Lesson::withoutTenant()
                ->where('tenant_id', $tenantId)
                ->find($validated['lesson_id']);

            if (! $lesson) {
                return response()->json(['message' => 'Lesson not found in this tenant.'], 404);
            }

            $lessonContentId = LessonContent::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'lesson_id' => $lesson->id,
                'content_type' => 'live',
                'sort_order' => 0,
                'is_required' => false,
                'title' => $validated['title'],
                'metadata' => [],
            ])->id;

            $courseId ??= $lesson->section()->first()?->course_id;
        }

        // 3) إنشاء الجلسة — الرابط في metadata (meeting_url = start_url).
        $meetingUrl = $request->input('meeting_url');
        $path = parse_url((string) $meetingUrl, PHP_URL_PATH) ?? '';

        $metadata = [
            'meeting_url' => $meetingUrl,
            'start_url' => $meetingUrl, // Meet لا يفصل host/join — الرابط نفسه.
            'meeting_id' => ltrim($path, '/'),
            'meeting_password' => null,
        ];

        $session = LiveSession::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'user_id' => $request->user()->id,
            'lesson_content_id' => $lessonContentId,
            'course_id' => $courseId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'provider' => $provider,
            'room_name' => 'room-'.strtolower(str()->random(10)),
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'status' => 'scheduled',
            'max_participants' => $validated['max_participants'] ?? 100,
            'record_enabled' => $validated['record_enabled'] ?? false,
            'config' => ['duration_minutes' => $validated['duration_minutes'] ?? 60],
            'metadata' => $metadata,
        ]);

        // 4) Google Meet لا يحتاج تحضير موارد خارجية — الرابط من المعلم.

        return response()->json(['session' => $this->payload($session->fresh(), $request)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $session = LiveSession::where('tenant_id', $request->current_tenant->id)
            ->findOrFail($id);

        $this->requireTenantStaff($request, $session->tenant_id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'scheduled_at' => 'nullable|date',
            'max_participants' => 'nullable|integer|min:1',
            'record_enabled' => 'nullable|boolean',
            'duration_minutes' => 'nullable|integer|min:5|max:600',
            'meeting_url' => 'sometimes|string|max:500',
        ]);

        // لا يمكن تغيير المزود بعد الإنشاء (الأصل مربوط بالجلسة).
        // meeting_url لا يُخزن في جدول live_sessions — يذهب فقط لـ metadata.
        $session->update(\Illuminate\Support\Arr::except($validated, ['meeting_url']));

        if (array_key_exists('duration_minutes', $validated)) {
            $config = $session->config ?? [];
            $config['duration_minutes'] = (int) $validated['duration_minutes'];
            $session->update(['config' => $config]);
        }

        // Google Meet: تحديث الرابط في metadata عند تغييره.
        if ($session->provider === 'google_meet' && array_key_exists('meeting_url', $validated)) {
            $meetingUrl = $validated['meeting_url'];

            if (! empty($meetingUrl)) {
                $request->validate([
                    'meeting_url' => ['sometimes', 'string', 'max:500', new ValidGoogleMeetUrl],
                ]);
            }

            $metadata = $session->metadata ?? [];
            $metadata['meeting_url'] = $meetingUrl;
            $metadata['start_url'] = $meetingUrl;
            $metadata['meeting_id'] = ltrim(parse_url((string) $meetingUrl, PHP_URL_PATH) ?? '', '/');
            $session->update(['metadata' => $metadata]);
        }

        return response()->json(['session' => $this->payload($session->fresh(), $request)]);
    }

    /**
     * جلسة البث المرتبطة بدرس (لإدارتها من بطاقة الدرس).
     */
    public function byLesson(Request $request, string $lessonId): JsonResponse
    {
        $tenantId = $request->current_tenant->id;

        $content = LessonContent::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('lesson_id', $lessonId)
            ->where('content_type', 'live')
            ->first();

        if (! $content) {
            return response()->json(['session' => null]);
        }

        $session = LiveSession::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('lesson_content_id', $content->id)
            ->first();

        return response()->json(['session' => $session ? $this->payload($session, $request) : null]);
    }

    /**
     * تذكرة دخول موثوقة — المصدر الوحيد للحقيقة من الـ Backend.
     *
     * - المعلم (مخوّل): role = host.
     * - الطالب (مسجل بالكورس): role = participant.
     * - أي شخص آخر: 403 — الرابط لا يصل لغير المصرح لهم.
     */
    public function joinTicket(Request $request, string $id): JsonResponse
    {
        $session = LiveSession::withoutTenant()->findOrFail($id);
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($session->status === 'ended') {
            return response()->json(['message' => 'This live session has ended.'], 403);
        }

        // ميزة البث مفعلة في خطة المستأجر؟
        $tenantId = $session->tenant_id;
        if (! app(PlanEngine::class)->hasFeature($tenantId, 'live_stream_enabled')) {
            return response()->json(['message' => 'Live streaming is not included in your plan.'], 403);
        }

        $isTeacher = $this->isTenantStaff($request, $tenantId);

        // جلسات قديمة بمزودات محذوفة: تذكرة من البيانات المخزنة بلا أي مزود —
        // نفس قواعد الصلاحية: المعلم Host، والمسجل Participant، وغير المصرح 403.
        if (in_array((string) $session->provider, self::LEGACY_METADATA_ONLY_PROVIDERS, true)) {
            if (! $isTeacher) {
                if (! $this->isEnrolledForSession($session, $request)) {
                    return response()->json(['message' => 'You must be enrolled in the course to join this live session.'], 403);
                }
            }

            $metadata = $session->metadata ?? [];
            $isHost = $isTeacher || $session->user_id === $user->id;

            return response()->json([
                'role' => $isHost ? 'host' : 'participant',
                'display_name' => $user->name,
                'room' => $session->room_name,
                'jwt' => null,
                'jwt_enabled' => false,
                'player_type' => 'meeting',
                'meeting_url' => $metadata['meeting_url'] ?? null,
                'start_url' => $isHost ? ($metadata['start_url'] ?? $metadata['meeting_url'] ?? null) : null,
                'session_status' => $session->status,
                'scheduled_at' => $session->scheduled_at?->toIso8601String(),
            ]);
        }

        if ($isTeacher) {
            $ticket = $this->liveProviders->providerFor($session)->joinTicket($session, $user, 'host');

            return response()->json([
                ...$ticket,
                'session_status' => $session->status,
                'scheduled_at' => $session->scheduled_at?->toIso8601String(),
            ]);
        }

        // الطالب: مسجل بالكورس المرتبط بالجلسة فقط.
        if (! $this->isEnrolledForSession($session, $request)) {
            return response()->json(['message' => 'You must be enrolled in the course to join this live session.'], 403);
        }

        $ticket = $this->liveProviders->providerFor($session)->joinTicket($session, $user, 'participant');

        return response()->json([
            ...$ticket,
            'session_status' => $session->status,
            'scheduled_at' => $session->scheduled_at?->toIso8601String(),
        ]);
    }

    public function start(Request $request, string $id): JsonResponse
    {
        $session = LiveSession::where('tenant_id', $request->current_tenant->id)
            ->findOrFail($id);

        $this->requireTenantStaff($request, $session->tenant_id);

        if (in_array($session->status, ['ended', 'cancelled'], true)) {
            return response()->json(['message' => 'A '.$session->status.' session cannot be started again.'], 422);
        }

        // حد دقائق البث الشهري — يُفرض قبل البدء (لا تبدأ جلسة فوق الحصة).
        try {
            $this->assertCanStartLive($session->tenant_id);
        } catch (QuotaExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            $this->liveProviders->providerFor($session)->startSession($session);
        } catch (\Throwable $e) {
            Log::warning('live.session_start_failed', ['session' => $session->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to start the live session. Check the provider configuration.'], 502);
        }

        $session->update(['status' => 'live', 'started_at' => now()]);

        $this->fireZapierEvent('live_session_started', $session, $request->current_tenant->id);

        return response()->json(['session' => $this->payload($session->fresh(), $request)]);
    }

    public function end(Request $request, string $id): JsonResponse
    {
        $session = LiveSession::where('tenant_id', $request->current_tenant->id)
            ->findOrFail($id);

        $this->requireTenantStaff($request, $session->tenant_id);

        if ($session->status !== 'live') {
            return response()->json(['message' => 'Session is not live.'], 422);
        }

        // Google Meet: الرابط خارجي — لا شيء يُوقف لدى مزود.
        try {
            $this->liveProviders->providerFor($session)->stopSession($session);
        } catch (\Throwable $e) {
            Log::warning('live.session_stop_failed', ['session' => $session->id, 'error' => $e->getMessage()]);
        }

        $session->update(['status' => 'ended', 'ended_at' => now()]);

        return response()->json(['session' => $this->payload($session->fresh(), $request)]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $session = LiveSession::where('tenant_id', $request->current_tenant->id)
            ->findOrFail($id);

        $this->requireTenantStaff($request, $session->tenant_id);

        if (in_array($session->status, ['live', 'ended'], true)) {
            return response()->json(['message' => 'Cannot cancel a session that is live or ended.'], 422);
        }

        $this->liveProviders->providerFor($session)->cancelSession($session);

        $session->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return response()->json(['session' => $this->payload($session->fresh(), $request)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $session = LiveSession::where('tenant_id', $request->current_tenant->id)
            ->findOrFail($id);

        $this->requireTenantStaff($request, $session->tenant_id);

        // لا حدث خارجي يُحذف (Meet) — الحذف محلي فقط.
        try {
            $this->liveProviders->providerFor($session)->destroySession($session);
        } catch (\Throwable $e) {
            Log::warning('live.session_destroy_failed', ['session' => $session->id, 'error' => $e->getMessage()]);
        }

        $session->delete();

        return response()->json(['message' => 'Live session deleted.'], 200);
    }

    private function fireZapierEvent(string $event, LiveSession $session, string $tenantId): void
    {
        try {
            $driver = $this->integrations->driver('zapier', $tenantId);

            if ($driver instanceof ZapierDriver) {
                $driver->trigger($event, [
                    'session_id' => $session->id,
                    'title' => $session->title,
                    'join_url' => ($session->metadata ?? [])['meeting_url'] ?? null,
                    'provider' => $session->provider,
                ]);
            }
        } catch (\Throwable) {
            // Zapier events are best-effort.
        }
    }

    private function payload(LiveSession $session, ?Request $request = null): array
    {
        $frontend = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
        $metadata = $session->metadata ?? [];

        // start_url grants host control — only tenant staff may see it.
        $isStaff = $request ? $this->isTenantStaff($request, $session->tenant_id) : true;

        // Students may watch only when enrolled in the owning course.
        $canJoin = $isStaff || $this->isEnrolledForSession($session, $request);

        // Google Meet: watch payload من المزود (meeting_url). الجلسات القديمة
        // بمزودات محذوفة تعمل من البيانات المخزنة في metadata فقط.
        $isLegacy = in_array((string) $session->provider, self::LEGACY_METADATA_ONLY_PROVIDERS, true);

        if ($isLegacy) {
            $watch = [
                'player_type' => 'meeting',
                'stream_url' => null,
                'meeting_url' => $metadata['meeting_url'] ?? null,
            ];
        } else {
            try {
                $watch = $this->liveProviders->providerFor($session)->watchPayload($session);
            } catch (\Throwable) {
                // مزود غير مدعوم — نعتمد على البيانات المخزنة.
                $watch = [
                    'player_type' => 'meeting',
                    'stream_url' => null,
                    'meeting_url' => $metadata['meeting_url'] ?? null,
                ];
            }
        }

        return [
            'id' => $session->id,
            'title' => $session->title,
            'description' => $session->description,
            'provider' => $session->provider,
            'room_name' => $session->room_name,
            'meeting_url' => $canJoin ? ($watch['meeting_url'] ?? $metadata['meeting_url'] ?? null) : null,
            'player_type' => $watch['player_type'] ?? 'meeting',
            'is_embedded' => false,
            'meeting_id' => $isStaff ? ($metadata['meeting_id'] ?? null) : null,
            'meeting_password' => $isStaff ? ($metadata['meeting_password'] ?? null) : null,
            'start_url' => $isStaff ? ($metadata['start_url'] ?? null) : null,
            'meeting_sdk' => false,
            'meeting_number' => null,
            'meeting_signature' => null,
            'sdk_key' => null,
            'zak' => null,
            'rtmps_url' => null,
            'rtmps_key' => null,
            'stream_url' => null,
            'broadcast_state' => $metadata['broadcast_state'] ?? null,
            'broadcast_error' => $isStaff ? ($metadata['broadcast_error'] ?? null) : null,
            'pipeline_ready' => null,
            'join_path' => '/live/'.$session->id,
            'join_url' => $frontend.'/live/'.$session->id,
            'scheduled_at' => $session->scheduled_at?->toIso8601String(),
            'started_at' => $session->started_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'cancelled_at' => $session->cancelled_at?->toIso8601String(),
            'status' => $session->status,
            'max_participants' => $session->max_participants,
            'duration_minutes' => (int) (($session->config ?? [])['duration_minutes'] ?? 60),
            'record_enabled' => $session->record_enabled,
            'record_url' => $isStaff ? $session->record_url : null,
        ];
    }

    /**
     * True when the requesting user is enrolled in the course that owns the
     * live session (active/completed) — the join link stays hidden otherwise.
     */
    private function isEnrolledForSession(LiveSession $session, ?Request $request): bool
    {
        if (! $request?->user()) {
            return false;
        }

        $courseId = $session->course_id;

        if (! $courseId) {
            $courseId = $session->lessonContent?->lesson?->section?->course_id;
        }

        if (! $courseId) {
            // جلسة عامة بلا كورس: نسمح لأعضاء المستأجر النشطين فقط.
            return $request->user()->tenants()
                ->where('tenant_id', $session->tenant_id)
                ->wherePivot('status', 'active')
                ->exists();
        }

        return Enrollment::where('course_id', $courseId)
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['active', 'completed'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    // ─── Quota enforcement (atomic, race-safe) ───

    /**
     * يُفرض حد الدقائق قبل البدء — لا تبدأ جلسة فوق الحصة الشهرية.
     */
    private function assertCanStartLive(string $tenantId): void
    {
        $engine = app(PlanEngine::class);
        $limit = $engine->limit($tenantId, 'live_minutes_limit');

        if ($limit === null) {
            return;
        }

        $used = (int) app(MediaUsageService::class)->monthly($tenantId, now()->format('Y-m'), 'live_minutes');

        if ($used >= $limit) {
            throw new QuotaExceededException('دقائق البث', $used, $limit);
        }
    }
}