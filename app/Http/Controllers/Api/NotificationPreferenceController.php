<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    private const ALL_TYPES = [
        'enrollment.created', 'lesson.completed', 'exam.graded',
        'subscription.activated', 'payment.completed',
        'announcement.published', 'chat.message',
    ];

    private const ALL_CHANNELS = ['database', 'mail', 'push', 'sms', 'whatsapp'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->header('X-Tenant-Id');

        $preferences = NotificationPreference::where('user_id', $user->id)
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('notification_type');

        $result = [];
        foreach (self::ALL_TYPES as $type) {
            $pref = $preferences->get($type);
            $channels = $pref?->channels ?? ['database'];
            $channels = is_array($channels) ? $channels : ['database'];
            $result[] = [
                'notification_type' => $type,
                'is_enabled' => $pref ? $pref->is_enabled : true,
                'channels' => array_values(array_intersect(self::ALL_CHANNELS, $channels)) ?: ['database'],
            ];
        }

        return response()->json(['preferences' => $result]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->header('X-Tenant-Id');

        $request->validate([
            'notification_type' => 'required|string|in:'.implode(',', self::ALL_TYPES),
            'is_enabled' => 'required|boolean',
            'channels' => 'nullable|array',
            'channels.*' => 'in:'.implode(',', self::ALL_CHANNELS),
        ]);

        NotificationPreference::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'notification_type' => $request->notification_type,
            ],
            [
                'is_enabled' => $request->boolean('is_enabled'),
                'channels' => array_values(array_intersect(self::ALL_CHANNELS, $request->input('channels', ['database']))),
            ]
        );

        return response()->json(['message' => 'Preference updated.']);
    }
}
