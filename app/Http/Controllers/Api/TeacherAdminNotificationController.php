<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The teacher's read-only view of platform notifications sent by the Super
 * Admin ("إدارة منصة فهمني").
 *
 * A teacher can only ever see notifications they hold a receipt for — so they
 * cannot reach another teacher's notification. There is deliberately NO delete
 * route: a teacher may read and mark-as-read, nothing more.
 */
class TeacherAdminNotificationController extends Controller
{
    public function __construct(
        private readonly AdminNotificationService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $paginator = $this->service->teacherFeed($user, $request->integer('per_page', 20));

        return response()->json([
            'notifications' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'unread_count' => $this->service->teacherUnreadCount($user),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->service->teacherUnreadCount($request->user()),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $notification = $this->service->teacherDetail($request->user(), $id);

        if (! $notification) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(['notification' => $notification]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $ok = $this->service->teacherMarkRead($request->user(), $id);

        if (! $ok) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'message' => 'Notification marked as read.',
            'unread_count' => $this->service->teacherUnreadCount($request->user()),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->service->teacherMarkAllRead($request->user());

        return response()->json([
            'message' => 'All notifications marked as read.',
            'unread_count' => 0,
        ]);
    }

    public function contact(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:10000']);

        $notification = $this->service->teacherDetail($request->user(), $id);

        if (! $notification) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        // Support tickets carry the teacher's tenant context; make sure it is
        // resolved from the tenant middleware even though this feed itself is
        // platform-level.
        $ticket = $this->service->contactTicket($request->user(), $notification, $data['body']);

        return response()->json(['message' => 'Sent to the platform team.', 'ticket' => $ticket], 201);
    }
}
