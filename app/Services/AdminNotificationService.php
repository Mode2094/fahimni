<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\AdminNotificationReceipt;
use App\Models\AdminNotificationRevision;
use App\Models\AdminNotificationTarget;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Platform-level Super-Admin → teacher communication center.
 *
 * Kept completely separate from AnnouncementController / NotificationService
 * (which are tenant-scoped teacher → own-students messaging). The single
 * source of truth here means an edit or retract made after sending is
 * immediately reflected for every recipient — there is no per-teacher copy to
 * drift out of sync.
 */
class AdminNotificationService
{
    /**
     * Roles that represent a "teacher account" on the platform (the same staff
     * set the platform treats as running an institution). Students and parents
     * are never eligible.
     */
    public const TEACHER_ROLES = [
        'teacher', 'owner', 'admin',
        'principal', 'vice_principal', 'supervisor',
        'accountant', 'student_affairs', 'manager', 'assistant',
    ];

    /** Every eligible teacher account across the whole platform, deduplicated. */
    public function eligibleTeachers(): Collection
    {
        $ids = DB::table('tenant_user')
            ->where('status', 'active')
            ->whereIn('role', self::TEACHER_ROLES)
            ->distinct()
            ->pluck('user_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->where('is_super_admin', false)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /**
     * Resolve who a notification would reach, without creating receipts.
     *
     * @param  array<int,string>  $targetUserIds
     * @return Collection<int,User>
     */
    public function previewRecipients(string $audience, array $targetUserIds = []): Collection
    {
        if ($audience !== 'selected') {
            return $this->eligibleTeachers();
        }

        $ids = collect($targetUserIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->eligibleTeachers()->whereIn('id', $ids)->values();
    }

    /** Recipients for an already-saved notification. */
    public function resolveRecipients(AdminNotification $notification): Collection
    {
        if ($notification->audience === 'selected') {
            return $this->previewRecipients(
                'selected',
                $notification->targets()->pluck('user_id')->all(),
            );
        }

        return $this->eligibleTeachers();
    }

    /** Replace the explicit recipient list of a draft notification. */
    public function syncTargets(AdminNotification $notification, array $userIds): void
    {
        $userIds = array_values(array_unique(array_filter($userIds)));

        if ($userIds === []) {
            $notification->targets()->delete();

            return;
        }

        $notification->targets()->whereNotIn('user_id', $userIds)->delete();

        $existing = $notification->targets()->pluck('user_id')->all();
        $now = now();

        $rows = collect(array_diff($userIds, $existing))->map(fn ($uid) => [
            'id' => (string) UuidV7::generate(),
            'admin_notification_id' => $notification->id,
            'user_id' => $uid,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows) {
            AdminNotificationTarget::insert($rows);
        }
    }

    /**
     * Send a draft once. Returns the recipient count, or null when the
     * notification was already sent/retracted (atomic guard — a concurrent or
     * repeated click can never fan out twice).
     */
    public function send(AdminNotification $notification, User $actor): ?int
    {
        return DB::transaction(function () use ($notification, $actor) {
            $locked = AdminNotification::whereKey($notification->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'draft') {
                return null;
            }

            $recipients = $this->resolveRecipients($locked);
            $now = now();

            $rows = $recipients->map(fn (User $u) => [
                'id' => (string) UuidV7::generate(),
                'admin_notification_id' => $locked->id,
                'user_id' => $u->id,
                'delivered_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if ($rows) {
                AdminNotificationReceipt::upsert(
                    $rows,
                    ['admin_notification_id', 'user_id'],
                    ['delivered_at', 'updated_at'],
                );
            }

            $locked->forceFill(['status' => 'sent', 'published_at' => $now])->save();

            $this->recordRevision($locked, 'sent', [
                'recipient_count' => $recipients->count(),
                'audience' => $locked->audience,
            ], $actor);

            return $recipients->count();
        });
    }

    /**
     * Edit a notification. Text edits after sending are picked up by every
     * recipient automatically (they read this same row). The read flag is
     * never reset by an ordinary edit; only an explicit "notify recipients"
     * re-flags the receipts as unread.
     */
    public function update(AdminNotification $notification, array $data, bool $notifyRecipients, User $actor): AdminNotification
    {
        $before = $notification->only(['title', 'body', 'category', 'audience']);
        $isDraft = $notification->status === 'draft';

        $fields = [
            'title' => $data['title'],
            'body' => $data['body'],
            'category' => $data['category'],
        ];

        if ($isDraft && isset($data['audience'])) {
            $fields['audience'] = $data['audience'];
        }

        $notification->update($fields);

        if ($isDraft && array_key_exists('target_user_ids', $data)) {
            $this->syncTargets($notification, $data['target_user_ids'] ?? []);
        }

        $notified = false;
        if (! $isDraft && $notifyRecipients && $notification->status === 'sent') {
            $notification->receipts()->update(['read_at' => null, 'notified_at' => now()]);
            $notified = true;
        }

        $this->recordRevision($notification, $notified ? 'updated_notified' : 'updated', [
            'before' => $before,
            'after' => $notification->only(['title', 'body', 'category', 'audience']),
        ], $actor);

        return $notification;
    }

    /** Pull a sent notification back: it disappears for every recipient. */
    public function retract(AdminNotification $notification, User $actor): void
    {
        if ($notification->status === 'retracted') {
            return;
        }

        $notification->forceFill([
            'status' => 'retracted',
            'retracted_at' => now(),
        ])->save();

        $this->recordRevision($notification, 'retracted', [], $actor);
    }

    /**
     * Hard-delete a notification (recipients + receipts are removed; the admin
     * audit trail survives because it is not foreign-keyed).
     */
    public function destroy(AdminNotification $notification, User $actor): void
    {
        DB::transaction(function () use ($notification, $actor) {
            $this->recordRevision($notification, 'deleted', [
                'title' => $notification->title,
                'category' => $notification->category,
                'status' => $notification->status,
                'recipient_count' => $notification->receipts()->count(),
            ], $actor);

            $notification->delete();
        });
    }

    public function recordRevision(AdminNotification $notification, string $action, array $changes, ?User $actor): void
    {
        AdminNotificationRevision::create([
            'admin_notification_id' => $notification->id,
            'action' => $action,
            'changes' => $changes,
            'performed_by' => $actor?->id,
        ]);
    }

    /* ────────────────────────── Admin reads ────────────────────────── */

    public function adminStats(): array
    {
        return [
            'total' => AdminNotification::count(),
            'draft' => AdminNotification::where('status', 'draft')->count(),
            'sent' => AdminNotification::where('status', 'sent')->count(),
            'retracted' => AdminNotification::where('status', 'retracted')->count(),
            'teachers' => $this->eligibleTeachers()->count(),
        ];
    }

    public function adminList(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = AdminNotification::query()->withCount([
            'receipts as recipient_count',
            'receipts as read_count' => fn ($q) => $q->whereNotNull('read_at'),
        ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        if (! empty($filters['search'])) {
            $query->where(fn ($w) => $w
                ->where('title', 'like', '%'.$filters['search'].'%')
                ->orWhere('body', 'like', '%'.$filters['search'].'%'));
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function adminDetail(string $id): ?AdminNotification
    {
        return AdminNotification::query()
            ->withCount([
                'receipts as recipient_count',
                'receipts as read_count' => fn ($q) => $q->whereNotNull('read_at'),
            ])
            ->with([
                'targets.user:id,name,email',
                'receipts.user:id,name,email',
                'revisions.actor:id,name,email',
                'sender:id,name,email',
            ])
            ->find($id);
    }

    /* ───────────────────────── Teacher feed ────────────────────────── */

    public function teacherFeed(User $user, int $perPage = 20): LengthAwarePaginator
    {
        $paginator = AdminNotification::query()
            ->where('status', 'sent')
            ->whereIn('id', function ($q) use ($user) {
                $q->select('admin_notification_id')
                    ->from('admin_notification_receipts')
                    ->where('user_id', $user->id);
            })
            ->with(['receipts' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $paginator->getCollection()->transform(function (AdminNotification $n) {
            $receipt = $n->receipts->first();
            $n->setAttribute('read_at', $receipt?->read_at);
            $n->unsetRelation('receipts');

            return $n;
        });

        return $paginator;
    }

    /** A single notification, only when it belongs to this teacher. */
    public function teacherDetail(User $user, string $id): ?AdminNotification
    {
        $notification = AdminNotification::where('status', 'sent')->find($id);

        if (! $notification) {
            return null;
        }

        $receipt = $notification->receipts()->where('user_id', $user->id)->first();

        if (! $receipt) {
            return null;
        }

        $notification->setAttribute('read_at', $receipt->read_at);

        return $notification;
    }

    public function teacherUnreadCount(User $user): int
    {
        return (int) DB::table('admin_notification_receipts')
            ->join('admin_notifications', 'admin_notifications.id', '=', 'admin_notification_receipts.admin_notification_id')
            ->where('admin_notification_receipts.user_id', $user->id)
            ->whereNull('admin_notification_receipts.read_at')
            ->where('admin_notifications.status', 'sent')
            ->count();
    }

    public function teacherMarkRead(User $user, string $id): bool
    {
        $receipt = AdminNotificationReceipt::where('user_id', $user->id)
            ->where('admin_notification_id', $id)
            ->first();

        if (! $receipt) {
            return false;
        }

        if (! AdminNotification::whereKey($id)->where('status', 'sent')->exists()) {
            return false;
        }

        $receipt->forceFill(['read_at' => now()])->save();

        return true;
    }

    public function teacherMarkAllRead(User $user): int
    {
        $ids = DB::table('admin_notification_receipts')
            ->join('admin_notifications', 'admin_notifications.id', '=', 'admin_notification_receipts.admin_notification_id')
            ->where('admin_notification_receipts.user_id', $user->id)
            ->whereNull('admin_notification_receipts.read_at')
            ->where('admin_notifications.status', 'sent')
            ->pluck('admin_notification_receipts.id');

        if ($ids->isEmpty()) {
            return 0;
        }

        DB::table('admin_notification_receipts')
            ->whereIn('id', $ids)
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return $ids->count();
    }

    /* ───────────────────────── Communication ──────────────────────── */

    /**
     * Teacher "تواصل مع الإدارة": reuse the existing support-ticket system
     * (never a second chat). Reuses an open ticket already linked to this
     * notification so a teacher can't spawn a conversation per click.
     */
    public function contactTicket(User $teacher, AdminNotification $notification, string $body): SupportTicket
    {
        $tenantId = $teacher->tenantPivots()->where('status', 'active')->value('tenant_id');

        $existing = SupportTicket::withoutTenant()
            ->where('user_id', $teacher->id)
            ->whereIn('status', ['open', 'pending'])
            ->where('metadata->reference', $notification->id)
            ->first();

        if ($existing) {
            TicketMessage::create([
                'id' => UuidV7::generate(),
                'ticket_id' => $existing->id,
                'user_id' => $teacher->id,
                'body' => $body,
                'metadata' => ['via' => 'notification'],
            ]);

            return $existing->load(['user', 'messages.user']);
        }

        $ticket = SupportTicket::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'user_id' => $teacher->id,
            'subject' => mb_substr('استفسار بخصوص إشعار: '.$notification->title, 0, 200),
            'category' => 'admin_notification',
            'priority' => 'medium',
            'channel' => 'chat',
            'status' => 'open',
            'metadata' => ['via' => 'notification', 'reference' => $notification->id],
        ]);

        TicketMessage::create([
            'id' => UuidV7::generate(),
            'ticket_id' => $ticket->id,
            'user_id' => $teacher->id,
            'body' => $body,
            'metadata' => ['via' => 'notification'],
        ]);

        return $ticket->load(['user', 'messages.user']);
    }

    /** Admin one-to-one contact with a teacher, via the same ticket system. */
    public function adminContactTeacher(User $teacher, string $subject, string $body, User $actor, ?AdminNotification $notification = null): SupportTicket
    {
        $tenantId = $teacher->tenantPivots()->where('status', 'active')->value('tenant_id');

        $ticket = SupportTicket::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'user_id' => $teacher->id,
            'subject' => mb_substr($subject, 0, 200),
            'category' => 'admin_notification',
            'priority' => 'medium',
            'channel' => 'chat',
            'status' => 'open',
            'metadata' => ['via' => 'admin', 'reference' => $notification?->id, 'from_admin' => $actor->id],
        ]);

        TicketMessage::create([
            'id' => UuidV7::generate(),
            'ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'body' => $body,
            'metadata' => ['via' => 'admin'],
        ]);

        return $ticket->load(['user', 'messages.user']);
    }
}
