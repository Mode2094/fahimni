<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Announcements end-to-end: teacher drafts → publishes → the right students
 * receive a real in-app notification they can open and mark read. Also covers
 * permissions, tenant isolation, expired enrolments, duplicate sends and the
 * guarantee that in-app delivery never depends on mail/push.
 */
class AnnouncementFlowTest extends TenantTestCase
{
    use RefreshDatabase;

    private function teacher(Tenant $tenant): User
    {
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'teacher');

        return $user;
    }

    private function student(Tenant $tenant, array $attrs = []): User
    {
        $user = $this->makeUser($attrs);
        $this->attachToTenant($user, $tenant, 'student');

        return $user;
    }

    public function test_teacher_draft_publish_notifies_only_intended_students(): void
    {
        config(['queue.default' => 'sync']);

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);

        $otherTenant = $this->makeTenant();
        $this->attachSubscription($otherTenant);

        $teacher = $this->teacher($tenant);
        $studentA = $this->student($tenant);
        $studentB = $this->student($tenant);
        $outsider = $this->student($otherTenant);

        // ── create (draft) ──
        $create = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson('/api/v1/announcements', [
                'title' => 'إعلان تجريبي',
                'body' => 'نص الإعلان',
                'audience' => 'students',
                'channels' => ['database'],
            ]);
        $create->assertCreated();
        $id = $create->json('announcement.id');
        $this->assertSame('draft', $create->json('announcement.status'));

        // Teacher sees the draft + the staff capability flag.
        $teacherList = $this->withHeaders($this->authHeaders($teacher, $tenant))->getJson('/api/v1/announcements');
        $teacherList->assertOk()->assertJsonPath('meta.can_manage', true);
        $this->assertContains($id, collect($teacherList->json('announcements'))->pluck('id')->all());

        // A student does not see the draft and is not flagged as staff.
        $studentList = $this->withHeaders($this->authHeaders($studentA, $tenant))->getJson('/api/v1/announcements');
        $studentList->assertOk()->assertJsonPath('meta.can_manage', false);
        $this->assertNotContains($id, collect($studentList->json('announcements'))->pluck('id')->all());

        // ── publish ──
        $send = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/announcements/{$id}/send");
        $send->assertOk()->assertJsonPath('recipient_count', 2);

        $this->assertSame('sent', Announcement::find($id)->status);

        $notifA = DB::table('notifications')->where('notifiable_id', $studentA->id)->where('type', 'announcement.published')->first();
        $notifB = DB::table('notifications')->where('notifiable_id', $studentB->id)->where('type', 'announcement.published')->first();
        $this->assertNotNull($notifA, 'student A should be notified');
        $this->assertNotNull($notifB, 'student B should be notified');

        $data = json_decode($notifA->data, true);
        $this->assertSame($id, $data['announcement_id']);
        $this->assertSame('/announcements?open='.$id, $data['action_url']);

        // The other tenant's student must never receive it.
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $outsider->id)->count());

        // Student sees it in /notifications with an unread badge …
        $list = $this->withHeaders($this->authHeaders($studentA, $tenant))->getJson('/api/v1/notifications');
        $list->assertOk();
        $this->assertSame(1, $list->json('meta.unread_count'));
        $this->assertSame('إعلان تجريبي', $list->json('notifications.0.title'));
        $this->assertIsArray($list->json('notifications.0.data'));
        $this->assertSame('/announcements?open='.$id, $list->json('notifications.0.data.action_url'));

        // … opens the full announcement …
        $this->withHeaders($this->authHeaders($studentA, $tenant))
            ->getJson("/api/v1/announcements/{$id}")
            ->assertOk()
            ->assertJsonPath('announcement.body', 'نص الإعلان');

        // … and marking it read clears the badge.
        $this->withHeaders($this->authHeaders($studentA, $tenant))
            ->postJson("/api/v1/notifications/{$notifA->id}/read")
            ->assertOk();
        $this->withHeaders($this->authHeaders($studentA, $tenant))
            ->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('unread_count', 0);
    }

    public function test_students_cannot_create_or_send_announcements(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $student = $this->student($tenant);

        $id = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson('/api/v1/announcements', [
                'title' => 'x', 'body' => 'y', 'audience' => 'all', 'channels' => ['database'],
            ])->json('announcement.id');

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->postJson('/api/v1/announcements', ['title' => 'z', 'body' => 'w', 'audience' => 'all'])
            ->assertForbidden();

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->postJson("/api/v1/announcements/{$id}/send")
            ->assertForbidden();
    }

    public function test_announcement_is_isolated_per_tenant(): void
    {
        config(['queue.default' => 'sync']);

        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $this->attachSubscription($tenantB);

        $teacherA = $this->teacher($tenantA);
        $teacherB = $this->teacher($tenantB);

        $id = $this->withHeaders($this->authHeaders($teacherA, $tenantA))
            ->postJson('/api/v1/announcements', [
                'title' => 'A only', 'body' => 'secret', 'audience' => 'students', 'channels' => ['database'],
            ])->json('announcement.id');

        // B's teacher cannot see or send A's announcement.
        $listB = $this->withHeaders($this->authHeaders($teacherB, $tenantB))->getJson('/api/v1/announcements');
        $this->assertNotContains($id, collect($listB->json('announcements'))->pluck('id')->all());

        $this->withHeaders($this->authHeaders($teacherB, $tenantB))
            ->postJson("/api/v1/announcements/{$id}/send")
            ->assertNotFound();
    }

    public function test_course_audience_excludes_expired_enrolments(): void
    {
        config(['queue.default' => 'sync']);

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);

        $course = Course::create([
            'tenant_id' => $tenant->id, 'teacher_id' => $teacher->id,
            'title' => 'Course', 'slug' => 'course-'.uniqid(), 'is_published' => true,
        ]);

        $active = $this->student($tenant);
        $expired = $this->student($tenant);

        Enrollment::create([
            'tenant_id' => $tenant->id, 'course_id' => $course->id, 'user_id' => $active->id,
            'status' => 'active', 'enrolled_at' => now(), 'expires_at' => now()->addMonth(),
        ]);
        Enrollment::create([
            'tenant_id' => $tenant->id, 'course_id' => $course->id, 'user_id' => $expired->id,
            'status' => 'active', 'enrolled_at' => now()->subMonths(2), 'expires_at' => now()->subMonth(),
        ]);

        $id = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson('/api/v1/announcements', [
                'title' => 'Course msg', 'body' => 'body', 'audience' => 'course',
                'course_id' => $course->id, 'channels' => ['database'],
            ])->json('announcement.id');

        $send = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/announcements/{$id}/send");
        $send->assertOk();

        $this->assertNotNull(DB::table('notifications')->where('notifiable_id', $active->id)->where('type', 'announcement.published')->first());
        $this->assertNull(DB::table('notifications')->where('notifiable_id', $expired->id)->where('type', 'announcement.published')->first());
    }

    public function test_double_send_does_not_duplicate_notifications(): void
    {
        config(['queue.default' => 'sync']);

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $student = $this->student($tenant);

        $id = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson('/api/v1/announcements', [
                'title' => 'once', 'body' => 'body', 'audience' => 'students', 'channels' => ['database'],
            ])->json('announcement.id');

        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/announcements/{$id}/send")
            ->assertOk();

        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/announcements/{$id}/send")
            ->assertStatus(422);

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $student->id)->where('type', 'announcement.published')->count());
    }

    public function test_in_app_delivery_works_without_mail_or_push_channels(): void
    {
        config(['queue.default' => 'sync']);

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $student = $this->student($tenant);

        // Teacher only asked for email; the platform must still guarantee the
        // in-app notification (mail may be unconfigured/disabled).
        $id = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson('/api/v1/announcements', [
                'title' => 'email only', 'body' => 'body', 'audience' => 'students', 'channels' => ['mail'],
            ])->json('announcement.id');

        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/announcements/{$id}/send")
            ->assertOk();

        $this->assertNotNull(
            DB::table('notifications')->where('notifiable_id', $student->id)->where('type', 'announcement.published')->first()
        );
    }
}
