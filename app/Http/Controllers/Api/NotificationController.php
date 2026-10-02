<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->header('X-Tenant-Id');

        $page = $request->integer('per_page', 20);

        $notifications = $this->notificationService->list($user->id, $tenantId, $page);

        return response()->json([
            'notifications' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'unread_count' => $this->notificationService->unreadCount($user->id, $tenantId),
            ],
        ]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $this->notificationService->markAsRead($id, $user->id);

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->header('X-Tenant-Id');

        $this->notificationService->markAllAsRead($user->id, $tenantId);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->header('X-Tenant-Id');

        return response()->json([
            'unread_count' => $this->notificationService->unreadCount($user->id, $tenantId),
        ]);
    }
}
