<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

/**
 * Super-Admin communication center for teachers (دائرة إشعارات المنصة).
 * Every route here sits behind the `admin` middleware (super admin only).
 *
 * This is intentionally NOT the teacher-announcement system: recipients are
 * eligible teacher accounts across the whole platform, and the notification
 * row is the single source of truth for edits / retracts.
 */
class AdminTeacherNotificationController extends Controller
{
    public function __construct(
        private readonly AdminNotificationService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'category', 'from', 'to', 'search']);

        $paginator = $this->service->adminList($filters, $request->integer('per_page', 20));

        return response()->json([
            'notifications' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'stats' => $this->service->adminStats(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $notification = AdminNotification::create([
            'id' => UuidV7::generate(),
            'category' => $data['category'],
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => $data['audience'],
            'status' => 'draft',
            'channels' => ['database'],
            'created_by' => $request->user()->id,
        ]);

        if ($data['audience'] === 'selected') {
            $this->service->syncTargets($notification, $data['target_user_ids'] ?? []);
        }

        $this->service->recordRevision($notification, 'created', [
            'title' => $notification->title,
            'category' => $notification->category,
            'audience' => $notification->audience,
        ], $request->user());

        AuditLogger::log($request, 'admin_notification.created', 'admin_notification', $notification->id, [
            'category' => $notification->category,
            'audience' => $notification->audience,
        ]);

        return response()->json(['notification' => $this->service->adminDetail($notification->id)], 201);
    }

    public function show(string $id): JsonResponse
    {
        $notification = $this->service->adminDetail($id);

        if (! $notification) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(['notification' => $notification]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $notification = AdminNotification::find($id);

        if (! $notification) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:20000',
            'category' => 'required|in:news,update,alert,maintenance',
            'audience' => 'nullable|in:all,selected',
            'target_user_ids' => 'nullable|array',
            'target_user_ids.*' => 'uuid',
        ]);

        // A sent notification may only be edited in text; the audience and
        // recipient set are frozen so it can never silently change hands.
        if ($notification->status !== 'draft') {
            unset($data['audience'], $data['target_user_ids']);
        }

        $this->service->update(
            $notification,
            $data,
            $request->boolean('notify_recipients'),
            $request->user(),
        );

        AuditLogger::log($request, 'admin_notification.updated', 'admin_notification', $notification->id, [
            'notify_recipients' => $request->boolean('notify_recipients'),
            'status' => $notification->status,
        ]);

        return response()->json(['notification' => $this->service->adminDetail($notification->id)]);
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $notification = AdminNotification::find($id);

        if (! $notification) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($notification->status !== 'draft') {
            return response()->json(['message' => 'Announcement already sent.'], 422);
        }

        if ($this->service->resolveRecipients($notification)->isEmpty()) {
            return response()->json(['message' => 'لا يوجد مستلمون مطابقون لهذا الإشعار.'], 422);
        }

        $count = $this->service->send($notification, $request->user());

        if ($count === null) {
            return response()->json(['message' => 'Announcement already sent.'], 422);
        }

        AuditLogger::log($request, 'admin_notification.sent', 'admin_notification', $notification->id, [
            'recipient_count' => $count,
        ]);

        return response()->json([
            'message' => 'Notification sent.',
            'recipient_count' => $count,
            'notification' => $this->service->adminDetail($notification->id),
        ]);
    }

    public function retract(Request $request, string $id): JsonResponse
    {
        $notification = AdminNotification::find($id);

        if (! $notification) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $this->service->retract($notification, $request->user());

        AuditLogger::log($request, 'admin_notification.retracted', 'admin_notification', $notification->id);

        return response()->json([
            'message' => 'Notification retracted.',
            'notification' => $this->service->adminDetail($notification->id),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $notification = AdminNotification::find($id);

        if (! $notification) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $this->service->destroy($notification, $request->user());

        AuditLogger::log($request, 'admin_notification.deleted', 'admin_notification', $id);

        return response()->json(['message' => 'Notification deleted.']);
    }

    public function teachers(): JsonResponse
    {
        return response()->json(['teachers' => $this->service->eligibleTeachers()]);
    }

    public function recipients(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => 'required|in:all,selected',
            'target_user_ids' => 'nullable|array',
            'target_user_ids.*' => 'uuid',
        ]);

        $recipients = $this->service->previewRecipients(
            $data['audience'],
            $data['target_user_ids'] ?? [],
        );

        return response()->json([
            'count' => $recipients->count(),
            'recipients' => $recipients->take(300)->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])->values(),
        ]);
    }

    public function contact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:10000',
            'notification_id' => 'nullable|uuid|exists:admin_notifications,id',
        ]);

        $teacher = User::findOrFail($data['user_id']);
        $notification = isset($data['notification_id'])
            ? AdminNotification::find($data['notification_id'])
            : null;

        $ticket = $this->service->adminContactTeacher(
            $teacher,
            $data['subject'],
            $data['body'],
            $request->user(),
            $notification,
        );

        AuditLogger::log($request, 'admin_notification.contacted', 'support_ticket', $ticket->id, [
            'teacher_id' => $teacher->id,
        ]);

        return response()->json(['message' => 'Message sent via support ticket.', 'ticket' => $ticket], 201);
    }

    /**
     * @return array<string,mixed>
     */
    private function validatePayload(Request $request): array
    {
        $audience = $request->input('audience', 'all');

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:20000',
            'category' => 'required|in:news,update,alert,maintenance',
            'audience' => 'required|in:all,selected',
            'target_user_ids' => $audience === 'selected' ? 'required|array|min:1' : 'nullable|array',
            'target_user_ids.*' => 'uuid',
        ]);

        $data['audience'] = $audience;

        return $data;
    }
}
