<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudentDeviceLimit;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserDevice;
use Symfony\Component\Uid\UuidV7;

/**
 * إعادة تعيين أجهزة كل الطلاب دفعة واحدة — إجراء من المعلم.
 *
 * A term change or a device audit needs everyone re-approved at once. The bulk
 * reset must remove only THIS tenant's students' devices, must NOT force a
 * sign-out, and must never touch another tenant's data.
 */
class DeviceBulkResetTest extends TenantTestCase
{
    private function makeStudentWithDevice(Tenant $tenant, string $deviceId = 'dev-a'): User
    {
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $course = Course::factory()->create(['tenant_id' => $tenant->id]);
        Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'user_id' => $student->id,
            'status' => 'active', 'enrolled_at' => now(), 'metadata' => [],
        ]);

        UserDevice::create([
            'tenant_id' => $tenant->id, 'user_id' => $student->id,
            'device_id' => $deviceId, 'device_name' => 'Browser',
            'last_seen_at' => now(),
        ]);

        return $student;
    }

    public function test_a_teacher_resets_every_students_devices_without_ending_their_sessions(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $a = $this->makeStudentWithDevice($tenant, 'dev-a');
        $b = $this->makeStudentWithDevice($tenant, 'dev-b');

        $beforeA = (int) $a->fresh()->auth_version;
        $beforeB = (int) $b->fresh()->auth_version;

        $res = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson('/api/v1/teacher/students/reset-all-devices');

        $res->assertOk()
            ->assertJsonPath('deleted', 2)
            ->assertJsonPath('students', 2);

        $this->assertSame(0, UserDevice::withoutTenant()->where('tenant_id', $tenant->id)->count());

        // Devices are unbound, but existing sessions are NOT revoked: a device
        // reset is not a sign-out.
        $this->assertSame($beforeA, $a->fresh()->auth_version);
        $this->assertSame($beforeB, $b->fresh()->auth_version);
    }

    public function test_a_teacher_can_clear_every_individual_override_in_one_action(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $a = $this->makeStudentWithDevice($tenant, 'dev-a');
        $b = $this->makeStudentWithDevice($tenant, 'dev-b');

        foreach ([$a, $b] as $s) {
            StudentDeviceLimit::create([
                'tenant_id' => $tenant->id, 'student_id' => $s->id, 'max_devices' => 5,
            ]);
        }

        $res = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson('/api/v1/teacher/students/reset-all-limits');

        $res->assertOk()->assertJsonPath('removed', 2);

        $this->assertSame(0, StudentDeviceLimit::where('tenant_id', $tenant->id)->count());
        // Policy-only reset: devices stay registered and sessions stay live.
        $this->assertSame(2, UserDevice::withoutTenant()->where('tenant_id', $tenant->id)->count());
    }

    public function test_a_bulk_reset_never_touches_another_tenants_devices(): void
    {
        $tenantA = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantB);

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenantA, 'teacher');

        $this->makeStudentWithDevice($tenantA, 'a-dev');
        $this->makeStudentWithDevice($tenantB, 'b-dev');

        $this->withHeaders($this->authHeaders($teacher, $tenantA))
            ->postJson('/api/v1/teacher/students/reset-all-devices')
            ->assertOk();

        $this->assertSame(0, UserDevice::withoutTenant()->where('tenant_id', $tenantA->id)->count());
        $this->assertSame(1, UserDevice::withoutTenant()->where('tenant_id', $tenantB->id)->count());
    }

    public function test_a_student_cannot_reset_devices(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $student = $this->makeStudentWithDevice($tenant, 'dev-a');

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->postJson('/api/v1/teacher/students/reset-all-devices')
            ->assertStatus(403);

        $this->assertSame(1, UserDevice::withoutTenant()->where('tenant_id', $tenant->id)->count());
    }
}
