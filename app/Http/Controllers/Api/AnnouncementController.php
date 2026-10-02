<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Course;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class AnnouncementController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Announcements visible to the current user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->current_tenant?->id ?? $request->header('X-Tenant-Id');

        $role = $user->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->first()?->role;

        $isStaff = in_array($role, ['teacher', 'owner', 'admin'], true);

        $query = Announcement::where('tenant_id', $tenantId);

        // (Sent AND audience-visible) OR (own drafts — so teachers can
        // manage announcements before publishing them).
        $query->where(function ($q) use ($role, $user, $tenantId) {
            $q->where('status', 'sent')
                ->whereNotNull('published_at');

            $q->where(function ($aud) use ($role, $user, $tenantId) {
                $aud->where('audience', 'all');

                $studentRoles = ['student', 'parent'];
                if (in_array($role, $studentRoles, true)) {
                    $aud->orWhere('audience', 'students');
                } else {
                    $aud->orWhere('audience', 'teachers')
                        ->orWhere('audience', 'admins');
                }

                $aud->orWhere(function ($courseQ) use ($user, $tenantId) {
                    $courseQ->where('audience', 'course')
                        ->whereExists(function ($exists) use ($user, $tenantId) {
                            $exists->selectRaw('1')
                                ->from('enrollments')
                                ->whereColumn('enrollments.course_id', 'announcements.course_id')
                                ->where('enrollments.user_id', $user->id)
                                ->where('enrollments.tenant_id', $tenantId);
                        });
                });

                $aud->orWhere('sender_id', $user->id);
            });
        });

        if ($isStaff) {
            $query->orWhere(fn ($d) => $d->where('sender_id', $user->id)->where('status', 'draft'));
        }

        $announcements = $query
            ->with('sender:id,name,avatar_url')
            ->orderByDesc('created_at')
            ->paginate(min(max((int) $request->input('per_page', 20), 1), 100));

        return response()->json([
            'announcements' => $announcements->items(),
            'meta' => [
                'current_page' => $announcements->currentPage(),
                'last_page' => $announcements->lastPage(),
                'per_page' => $announcements->perPage(),
                'total' => $announcements->total(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->current_tenant?->id ?? $request->header('X-Tenant-Id');

        $announcement = Announcement::where('tenant_id', $tenantId)
            ->with('sender:id,name,avatar_url', 'course:id,title')
            ->findOrFail($id);

        // Same visibility rules as index(): drafts and announcements aimed at
        // other audiences must not be readable by guessing a UUID.
        if ($announcement->status !== 'sent' || ! $announcement->published_at) {
            abort(404, 'Announcement not found.');
        }

        $role = $user->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->first()?->role;

        $visible = $announcement->audience === 'all'
            || $announcement->sender_id === $user->id
            || (in_array($role, ['student', 'parent'], true) && $announcement->audience === 'students')
            || (! in_array($role, ['student', 'parent'], true) && in_array($announcement->audience, ['teachers', 'admins'], true))
            || ($announcement->audience === 'course' && $user->enrollments()
                ->where('course_id', $announcement->course_id)
                ->whereIn('status', ['active', 'completed'])
                ->exists());

        abort_unless($visible, 404, 'Announcement not found.');

        return response()->json(['announcement' => $announcement]);
    }

    /**
     * Create an announcement (teacher / owner / admin).
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->current_tenant->id;
        $this->authorizeSender($request, $tenantId);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:10000',
            'audience' => 'required|in:all,students,teachers,admins,course',
            'course_id' => 'nullable|uuid|exists:courses,id',
            'channels' => 'nullable|array|in:database,mail,push,sms,whatsapp,telegram,discord,slack',
            'scheduled_at' => 'nullable|date',
        ]);

        if ($validated['audience'] === 'course' && empty($validated['course_id'])) {
            return response()->json(['message' => 'course_id is required when audience is course.'], 422);
        }

        if (! empty($validated['course_id'])) {
            Course::where('tenant_id', $tenantId)->findOrFail($validated['course_id']);
        }

        $announcement = Announcement::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'sender_id' => $request->user()->id,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'audience' => $validated['audience'],
            'course_id' => $validated['course_id'] ?? null,
            'channels' => $validated['channels'] ?? ['database'],
            'status' => 'draft',
            'scheduled_at' => $validated['scheduled_at'] ?? null,
        ]);

        return response()->json(['announcement' => $announcement], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->current_tenant->id;
        $this->authorizeSender($request, $tenantId);

        $announcement = Announcement::where('tenant_id', $tenantId)->findOrFail($id);

        abort_if($announcement->status === 'sent', 422, 'Sent announcements cannot be edited.');

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'body' => 'sometimes|string',
            'audience' => 'sometimes|in:all,students,teachers,admins,course',
            'course_id' => 'nullable|uuid|exists:courses,id',
            'channels' => 'nullable|array|in:database,mail,push,sms,whatsapp,telegram,discord,slack',
            'scheduled_at' => 'nullable|date',
        ]);

        $announcement->update($validated);

        return response()->json(['announcement' => $announcement]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->current_tenant->id;
        $this->authorizeSender($request, $tenantId);

        Announcement::where('tenant_id', $tenantId)->findOrFail($id)->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }

    /**
     * Publish: resolve the audience and dispatch through the selected channels.
     */
    public function send(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->current_tenant->id;
        $this->authorizeSender($request, $tenantId);

        $announcement = Announcement::where('tenant_id', $tenantId)->findOrFail($id);

        abort_if($announcement->status === 'sent', 422, 'Announcement already sent.');

        $recipients = $this->notificationService->resolveAudience(
            $tenantId,
            $announcement->audience,
            $announcement->course_id,
        );

        $this->notificationService->dispatch(
            notifiable: $recipients,
            type: 'announcement.published',
            title: $announcement->title,
            body: $announcement->body,
            sender: $request->user(),
            tenantId: $tenantId,
            data: [
                'announcement_id' => $announcement->id,
                'audience' => $announcement->audience,
                'action_url' => url('/announcements'),
            ],
            channels: $announcement->channels,
        );

        $announcement->update([
            'status' => 'sent',
            'published_at' => now(),
            'scheduled_at' => null,
        ]);

        return response()->json([
            'message' => 'Announcement sent.',
            'recipient_count' => $recipients->count(),
        ]);
    }

    private function authorizeSender(Request $request, string $tenantId): void
    {
        $role = $request->user()->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->first()?->role;

        abort_if(! in_array($role, ['teacher', 'owner', 'admin'], true), 403, 'Teacher access required.');
    }
}
