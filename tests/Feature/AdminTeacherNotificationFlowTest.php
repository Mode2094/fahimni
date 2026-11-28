<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\AdminNotificationReceipt;
use App\Models\AdminNotificationRevision;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Platform-level Super Admin → teacher notification centre, end-to-end.
 *
 * Covers the whole journey the brief demands: broadcast to all teachers,
 * targeted sends, the teacher bell/feed + mark-all-read, permission
 * boundaries (teacher can't edit/delete/administer; student can't reach teacher
 * notifications), edit-after-send reflecting everywhere, the explicit
 * "notify recipients" option, retract + hard-delete disappearing for every
 * recipient while the audit trail survives, duplicate-send protection, and the
 * teacher→admin contact path reusing the support-ticket system.
 */
class AdminTeacherNotificationFlowTest extends TenantTestCase
{
    private function superAdmin(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['is_super_admin' => true, 'is_active' => true])->save();

        return $user;
    }

    private function teacher(Tenant $tenant): User
    {
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'teacher');

        return $user;
    }

    private function student(Tenant $tenant): User
    {
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'student');

        return $user;
    }

    private function adminHeaders(User $admin, Tenant $tenant): array
    {
        return $this->authHeaders($admin, $tenant);
    }

    public function test_super_admin_broadcasts_to_every_teacher_once_and_students_never_receive_it(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $this->attachSubscription($tenantB);

        $admin = $this->superAdmin();

        $teacherA = $this->teacher($tenantA);
        $teacherB = $this->teacher($tenantB);

        // A teacher who belongs to two institutions must still be notified once.
        $shared = $this->teacher($tenantA);
        $this->attachToTenant($shared, $tenantB, 'teacher');

        $student = $this->student($tenantA);

        $create = $this->withHeaders($this->adminHeaders($admin, $tenantA))
            ->postJson('/api/v1/admin/teacher-notifications', [
                'title' => 'صيانة مجدولة',
                'body' => 'سيتوقف النظام قليلاً الليلة.',
                'category' => 'maintenance',
                'audience' => 'all',
            ]);
        $create->assertCreated();
        $id = $create->json('notification.id');
        $this->assertSame('draft', $create->json('notification.status'));

        $send = $this->withHeaders($this->adminHeaders($admin, $tenantA))
            ->postJson("/api/v1/admin/teacher-notifications/{$id}/send");
        $send->assertOk();
        $this->assertSame(3, $send->json('recipient_count'));

        // Exactly the three distinct teacher accounts; nobody twice.
        $this->assertSame(3, AdminNotificationReceipt::where('admin_notification_id', $id)->count());
        $this->assertSame(1, AdminNotificationReceipt::where('admin_notification_id', $id)->where('user_id', $shared->id)->count());
        $this->assertSame(0, AdminNotificationReceipt::where('admin_notification_id', $id)->where('user_id', $student->id)->count());

        // Each teacher sees it in their own feed with an unread badge.
        $feed = $this->withHeaders($this->authHeaders($teacherA, $tenantA))
            ->getJson('/api/v1/admin-notifications');
        $feed->assertOk()->assertJsonPath('meta.unread_count', 1);
        $this->assertSame('صيانة مجدولة', $feed->json('notifications.0.title'));
        $this->assertSame('maintenance', $feed->json('notifications.0.category'));

        // The student cannot reach the teacher notification centre.
        $this->withHeaders($this->authHeaders($student, $tenantA))
            ->getJson('/api/v1/admin-notifications')
            ->assertForbidden();
    }

    public function test_targeted_notification_reaches_only_the_selected_teacher(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->superAdmin();

        $chosen = $this->teacher($tenant);
        $other = $this->teacher($tenant);

        $id = $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson('/api/v1/admin/teacher-notifications', [
                'title' => 'خاص بك',
                'body' => 'رسالة موجهة.',
                'category' => 'alert',
                'audience' => 'selected',
                'target_user_ids' => [$chosen->id],
            ])->json('notification.id');

        $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson("/api/v1/admin/teacher-notifications/{$id}/send")
            ->assertOk()
            ->assertJsonPath('recipient_count', 1);

        $this->assertSame(1, AdminNotificationReceipt::where('user_id', $chosen->id)->where('admin_notification_id', $id)->count());
        $this->assertSame(0, AdminNotificationReceipt::where('user_id', $other->id)->where('admin_notification_id', $id)->count());

        // The other teacher must not be able to open the targeted notification.
        $this->withHeaders($this->authHeaders($other, $tenant))
            ->getJson("/api/v1/admin-notifications/{$id}")
            ->assertNotFound();
    }

    public function test_teacher_can_read_and_mark_all_read_and_cannot_delete_via_api(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->superAdmin();
        $teacher = $this->teacher($tenant);

        $make = fn (string $title) => $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson('/api/v1/admin/teacher-notifications', [
                'title' => $title, 'body' => 'b', 'category' => 'news', 'audience' => 'all',
            ])->json('notification.id');

        $id1 = $make('أول');
        $id2 = $make('ثاني');
        foreach ([$id1, $id2] as $id) {
            $this->withHeaders($this->adminHeaders($admin, $tenant))
                ->postJson("/api/v1/admin/teacher-notifications/{$id}/send")->assertOk();
        }

        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->getJson('/api/v1/admin-notifications/unread-count')
            ->assertOk()->assertJsonPath('unread_count', 2);

        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/admin-notifications/{$id1}/read")
            ->assertOk()->assertJsonPath('unread_count', 1);

        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson('/api/v1/admin-notifications/read-all')
            ->assertOk()->assertJsonPath('unread_count', 0);

        // A teacher holds no delete/update route for platform notifications.
        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->deleteJson("/api/v1/admin-notifications/{$id1}")
            ->assertStatus(405);
        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->putJson("/api/v1/admin-notifications/{$id1}", ['title' => 'x'])
            ->assertStatus(405);

        // And cannot reach the admin management endpoints at all.
        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->getJson('/api/v1/admin/teacher-notifications')
            ->assertForbidden();
    }

    public function test_edit_after_send_reflects_for_every_recipient_without_resetting_read(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->superAdmin();
        $teacher = $this->teacher($tenant);

        $id = $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson('/api/v1/admin/teacher-notifications', [
                'title' => 'النسخة الأولى', 'body' => 'قديم', 'category' => 'update', 'audience' => 'all',
            ])->json('notification.id');

        $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson("/api/v1/admin/teacher-notifications/{$id}/send")->assertOk();

        // Teacher reads it.
        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/admin-notifications/{$id}/read")->assertOk();

        // Admin edits the text (no explicit notify).
        $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->putJson("/api/v1/admin/teacher-notifications/{$id}", [
                'title' => 'النسخة الثانية', 'body' => 'جديد', 'category' => 'update',
            ])->assertOk();

        $feed = $this->withHeaders($this->authHeaders($teacher, $tenant))->getJson('/api/v1/admin-notifications');
        $feed->assertOk();
        $this->assertSame('النسخة الثانية', $feed->json('notifications.0.title'));
        $this->assertSame('جديد', $feed->json('notifications.0.body'));
        // A plain text edit must not silently re-flag it as unread.
        $this->assertSame(0, $feed->json('meta.unread_count'));

        // The explicit notify option re-flags it.
        $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->putJson("/api/v1/admin/teacher-notifications/{$id}", [
                'title' => 'النسخة الثانية', 'body' => 'جديد', 'category' => 'update',
                'notify_recipients' => true,
            ])->assertOk();

        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->getJson('/api/v1/admin-notifications/unread-count')
            ->assertOk()->assertJsonPath('unread_count', 1);
    }

    public function test_retract_hides_it_from_every_recipient_but_admin_keeps_it(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->superAdmin();
        $teacher = $this->teacher($tenant);

        $id = $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson('/api/v1/admin/teacher-notifications', [
                'title' => 'سيتم سحبه', 'body' => 'b', 'category' => 'news', 'audience' => 'all',
            ])->json('notification.id');

        $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson("/api/v1/admin/teacher-notifications/{$id}/send")->assertOk();

        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->getJson('/api/v1/admin-notifications')
            ->assertOk()->assertJsonPath('meta.unread_count', 1);

        $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson("/api/v1/admin/teacher-notifications/{$id}/retract")
            ->assertOk()
            ->assertJsonPath('notification.status', 'retracted');

        // Gone from the teacher list, detail and counter.
        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->getJson('/api/v1/admin-notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0)
            ->assertJsonPath('meta.total', 0);
        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->getJson("/api/v1/admin-notifications/{$id}")
            ->assertNotFound();

        // Admin archive still shows it, with its audit trail.
        $show = $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->getJson("/api/v1/admin/teacher-notifications/{$id}");
        $show->assertOk()->assertJsonPath('notification.status', 'retracted');
        $this->assertNotEmpty($show->json('notification.revisions'));
    }

    public function test_hard_delete_removes_it_for_recipients_and_keeps_the_admin_audit(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->superAdmin();
        $teacher = $this->teacher($tenant);

        $id = $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson('/api/v1/admin/teacher-notifications', [
                'title' => 'سيتم حذفه', 'body' => 'b', 'category' => 'news', 'audience' => 'all',
            ])->json('notification.id');

        $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson("/api/v1/admin/teacher-notifications/{$id}/send")->assertOk();

        $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->deleteJson("/api/v1/admin/teacher-notifications/{$id}")
            ->assertOk();

        $this->assertNull(AdminNotification::find($id));
        $this->assertSame(0, AdminNotificationReceipt::where('admin_notification_id', $id)->count());

        // Recipients no longer see anything.
        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->getJson('/api/v1/admin-notifications')
            ->assertOk()->assertJsonPath('meta.total', 0);

        // The protected admin audit trail survives the delete.
        $this->assertDatabaseHas('admin_notification_revisions', [
            'admin_notification_id' => $id,
            'action' => 'deleted',
        ]);
        $this->assertSame(1, AdminNotificationRevision::where('admin_notification_id', $id)->where('action', 'deleted')->count());
    }

    public function test_duplicate_send_is_blocked_and_never_duplicates_receipts(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->superAdmin();
        $teacher = $this->teacher($tenant);

        $id = $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson('/api/v1/admin/teacher-notifications', [
                'title' => 'مرة واحدة', 'body' => 'b', 'category' => 'news', 'audience' => 'all',
            ])->json('notification.id');

        $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson("/api/v1/admin/teacher-notifications/{$id}/send")->assertOk();

        $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson("/api/v1/admin/teacher-notifications/{$id}/send")
            ->assertStatus(422);

        $this->assertSame(1, AdminNotificationReceipt::where('admin_notification_id', $id)->where('user_id', $teacher->id)->count());
    }

    public function test_recipient_preview_and_read_counts_track_reality(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->superAdmin();
        $t1 = $this->teacher($tenant);
        $t2 = $this->teacher($tenant);

        $preview = $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson('/api/v1/admin/teacher-notifications/recipients', ['audience' => 'all']);
        $preview->assertOk();
        $this->assertSame(2, $preview->json('count'));

        $id = $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson('/api/v1/admin/teacher-notifications', [
                'title' => 'قراءات', 'body' => 'b', 'category' => 'news', 'audience' => 'all',
            ])->json('notification.id');

        $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson("/api/v1/admin/teacher-notifications/{$id}/send")->assertOk();

        $this->withHeaders($this->authHeaders($t1, $tenant))
            ->postJson("/api/v1/admin-notifications/{$id}/read")->assertOk();

        $list = $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->getJson('/api/v1/admin/teacher-notifications');
        $list->assertOk();
        $row = collect($list->json('notifications'))->firstWhere('id', $id);
        $this->assertSame(2, $row['recipient_count']);
        $this->assertSame(1, $row['read_count']);
    }

    public function test_teacher_can_contact_the_admin_via_the_existing_ticket_system(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->superAdmin();
        $teacher = $this->teacher($tenant);

        $id = $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson('/api/v1/admin/teacher-notifications', [
                'title' => 'تواصل', 'body' => 'b', 'category' => 'alert', 'audience' => 'all',
            ])->json('notification.id');

        $this->withHeaders($this->adminHeaders($admin, $tenant))
            ->postJson("/api/v1/admin/teacher-notifications/{$id}/send")->assertOk();

        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/admin-notifications/{$id}/contact", ['body' => 'لدي استفسار عن هذا الإشعار.'])
            ->assertCreated();

        $this->assertSame(1, SupportTicket::withoutTenant()->where('user_id', $teacher->id)->count());
        $ticket = SupportTicket::withoutTenant()->where('user_id', $teacher->id)->first();
        $this->assertSame('admin_notification', $ticket->category);
        $this->assertSame($id, $ticket->metadata['reference']);

        // A second contact reuses the same open ticket instead of spawning one.
        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/admin-notifications/{$id}/contact", ['body' => 'متابعة.'])
            ->assertCreated();
        $this->assertSame(1, SupportTicket::withoutTenant()->where('user_id', $teacher->id)->count());
        $this->assertSame(2, DB::table('ticket_messages')->where('ticket_id', $ticket->id)->count());
    }

    public function test_teacher_announcements_and_student_notifications_are_untouched(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $student = $this->student($tenant);

        // The pre-existing student notification pipeline still works.
        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson('/api/v1/announcements', [
                'title' => 'إعلان الطلاب', 'body' => 'نص', 'audience' => 'students', 'channels' => ['database'],
            ])->assertCreated();

        $id = DB::table('announcements')->value('id');
        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/announcements/{$id}/send")->assertOk();

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson('/api/v1/notifications')
            ->assertOk()->assertJsonPath('meta.unread_count', 1);

        // And the separate admin centre is empty for this tenant's teacher.
        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->getJson('/api/v1/admin-notifications')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }
}
