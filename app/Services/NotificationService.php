<?php

namespace App\Services;

use App\Integrations\Contracts\MessagingDriver;
use App\Jobs\SendNotificationJob;
use App\Mail\PlatformNotification;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Uid\UuidV7;

class NotificationService
{
    public const CHANNELS = ['database', 'mail', 'push', 'sms', 'whatsapp', 'telegram', 'discord', 'slack'];

    public function __construct(
        private readonly PushService $pushService,
        private readonly SmsService $smsService,
        private readonly WhatsAppService $whatsappService,
        private readonly IntegrationService $integrationService,
    ) {}

    /**
     * In-app notification (backward compatible with existing callers).
     */
    public function notify(
        User $notifiable,
        string $type,
        string $title,
        ?string $body = null,
        ?User $sender = null,
        ?string $tenantId = null,
        array $data = [],
    ): void {
        $this->dispatch($notifiable, $type, $title, $body, $sender, $tenantId, $data, ['database']);
    }

    /**
     * Full multi-channel dispatch. Respects the user's notification
     * preferences for this type when they exist.
     *
     * All per-recipient work runs in queued jobs so fan-outs (announcements,
     * observers) never block the request.
     */
    public function dispatch(
        User|Collection $notifiable,
        string $type,
        string $title,
        ?string $body = null,
        ?User $sender = null,
        ?string $tenantId = null,
        array $data = [],
        array $channels = ['database'],
    ): void {
        $recipients = $notifiable instanceof User
            ? collect([$notifiable])
            : $notifiable->whereNotNull('id');

        if ($recipients->isEmpty()) {
            return;
        }

        $preferences = NotificationPreference::whereIn('user_id', $recipients->pluck('id'))
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId), fn ($q) => $q->whereNull('tenant_id'))
            ->where('notification_type', $type)
            ->get()
            ->keyBy('user_id');

        $recipients->each(function (User $user) use ($type, $title, $body, $sender, $tenantId, $data, $channels, $preferences) {
            $resolved = $this->resolveChannels($user, $tenantId, $type, $channels, $preferences->get($user->id));

            if (empty($resolved)) {
                return;
            }

            SendNotificationJob::dispatch($user, $type, $title, $body, $sender, $tenantId, $data, $resolved);
        });
    }

    /**
     * Per-recipient delivery, executed inside SendNotificationJob.
     */
    public function deliver(
        User $user,
        string $type,
        string $title,
        ?string $body,
        ?User $sender,
        ?string $tenantId,
        array $data,
        array $channels,
    ): void {
        if (in_array('database', $channels, true)) {
            $this->storeInApp($user, $type, $title, $body, $sender, $tenantId, $data);
        }

        if (in_array('mail', $channels, true)) {
            $this->sendMail($user, $title, $body, $data);
        }

        if (in_array('push', $channels, true)) {
            $this->pushService->send($user, $title, $body, $data, $tenantId);
        }

        if (in_array('sms', $channels, true)) {
            $this->smsService->send($user->phone, $body ?? $title, $tenantId);
        }

        if (in_array('whatsapp', $channels, true)) {
            $this->sendWhatsApp($user->phone, $body ?? $title, $tenantId);
        }

        if (in_array('telegram', $channels, true) && $tenantId) {
            $this->sendIntegrationMessage('telegram', $body ?? $title, null, $tenantId);
        }

        if (in_array('discord', $channels, true) && $tenantId) {
            $this->sendIntegrationMessage('discord', $body ?? $title, null, $tenantId);
        }

        if (in_array('slack', $channels, true) && $tenantId) {
            $this->sendIntegrationMessage('slack', $body ?? $title, null, $tenantId);
        }
    }

    /**
     * Send via the tenant's connected integration driver (best-effort).
     */
    private function sendIntegrationMessage(string $provider, string $message, ?string $target, ?string $tenantId): void
    {
        try {
            $driver = $this->integrationService->driver($provider, $tenantId);

            if ($driver instanceof MessagingDriver
                && $driver->configured()
                && $driver->enabled()) {
                $driver->sendMessage($message, $target);
            }
        } catch (\Throwable $e) {
            Log::warning("Notification {$provider} failed", ['error' => $e->getMessage(), 'tenant' => $tenantId]);
        }
    }

    private function sendWhatsApp(?string $phone, string $message, ?string $tenantId): void
    {
        if (! $phone) {
            return;
        }

        try {
            $driver = $this->integrationService->driver('whatsapp', $tenantId);

            if ($driver instanceof MessagingDriver
                && $driver->configured()
                && $driver->enabled()) {
                $driver->sendMessage($message, $phone);

                return;
            }
        } catch (\Throwable) {
            // Fall back to the legacy env-configured WhatsApp service.
        }

        $this->whatsappService->send($phone, $message, $tenantId);
    }

    /**
     * Build the recipient list for an announcement without sending anything.
     */
    public function resolveAudience(
        string $tenantId,
        string $audience,
        ?string $courseId = null,
    ): Collection {
        $users = DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->whereNull('users.deleted_at')
            ->select('users.*');

        $users = match ($audience) {
            'students' => (clone $users)->whereIn('tenant_user.role', ['student', 'parent']),
            'teachers' => (clone $users)->whereIn('tenant_user.role', [
                'teacher', 'owner', 'admin',
                'principal', 'vice_principal', 'supervisor',
                'accountant', 'student_affairs', 'manager', 'assistant',
            ]),
            'admins' => (clone $users)->where('tenant_user.role', 'admin'),
            'course' => $this->resolveCourseAudience($tenantId, $courseId),
            default => $users,
        };

        return $users
            ->where('users.is_active', true)
            ->get()
            ->map(fn ($row) => (new User)->newFromBuilder((array) $row));
    }

    private function resolveCourseAudience(string $tenantId, ?string $courseId): Collection
    {
        if (! $courseId) {
            return collect();
        }

        $studentIds = DB::table('enrollments')
            ->where('tenant_id', $tenantId)
            ->where('course_id', $courseId)
            ->pluck('user_id');

        $teacherIds = DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->whereIn('role', ['teacher', 'owner', 'admin'])
            ->pluck('user_id');

        $userIds = $studentIds->merge($teacherIds)->unique()->values();

        return DB::table('users')
            ->whereIn('id', $userIds)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get()
            ->mapInto(User::class);
    }

    /**
     * Merge requested channels with the user's saved preference for this type.
     */
    private function resolveChannels(User $user, ?string $tenantId, string $type, array $channels, ?NotificationPreference $preference = null): array
    {
        $preference ??= NotificationPreference::where('user_id', $user->id)
            ->where('tenant_id', $tenantId)
            ->where('notification_type', $type)
            ->first();

        if (! $preference) {
            return $channels;
        }

        if (! $preference->is_enabled) {
            return [];
        }

        $saved = is_array($preference->channels) ? $preference->channels : [];

        if (empty($saved)) {
            return $channels;
        }

        return array_values(array_intersect($channels, $saved));
    }

    private function storeInApp(
        User $notifiable,
        string $type,
        string $title,
        ?string $body,
        ?User $sender,
        ?string $tenantId,
        array $data,
    ): void {
        DB::table('notifications')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'type' => $type,
            'notifiable_type' => User::class,
            'notifiable_id' => $notifiable->id,
            'sender_id' => $sender?->id,
            'title' => $title,
            'body' => $body,
            'data' => json_encode($data),
            'read_at' => null,
            'read_in_app' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sendMail(User $user, string $title, ?string $body, array $data): void
    {
        try {
            Mail::to($user->email)->queue(new PlatformNotification($title, $body, $data));
        } catch (\Throwable $e) {
            Log::warning('Notification email failed', ['error' => $e->getMessage(), 'user' => $user->id]);
        }
    }

    public function markAsRead(string $notificationId, string $userId): void
    {
        DB::table('notifications')
            ->where('id', $notificationId)
            ->where('notifiable_id', $userId)
            ->update(['read_at' => now(), 'read_in_app' => true]);
    }

    public function markAllAsRead(string $userId, ?string $tenantId = null): void
    {
        $query = DB::table('notifications')
            ->where('notifiable_id', $userId)
            ->whereNull('read_at');

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $query->update(['read_at' => now(), 'read_in_app' => true]);
    }

    public function list(string $userId, ?string $tenantId = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = DB::table('notifications')
            ->where('notifiable_id', $userId);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function unreadCount(string $userId, ?string $tenantId = null): int
    {
        $query = DB::table('notifications')
            ->where('notifiable_id', $userId)
            ->whereNull('read_at');

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->count();
    }
}
