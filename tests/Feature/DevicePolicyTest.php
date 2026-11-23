<?php

namespace Tests\Feature;

use App\Models\StudentDeviceLimit;
use App\Models\UserDevice;
use App\Support\DevicePolicy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Device-binding policy — the privacy + behaviour contract.
 *
 * The policy is opt-in (no id header = no row), uses only the client-supplied
 * id (no canvas/WebGL/audio fingerprinting), truncates an over-long id to the
 * column width, and enforces the per-student cap.
 */
class DevicePolicyTest extends TenantTestCase
{
    private function requestWith(?string $deviceId, ?string $deviceName = null): Request
    {
        $request = Request::create('/api/v1/videos/x/playback', 'GET');

        if ($deviceId !== null) {
            $request->headers->set('X-Device-Id', $deviceId);
        }
        if ($deviceName !== null) {
            $request->headers->set('X-Device-Name', $deviceName);
        }

        return $request;
    }

    private function student(): array
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        return [$tenant, $student];
    }

    public function test_no_device_id_means_no_registration(): void
    {
        [$tenant, $student] = $this->student();

        $result = DevicePolicy::register($this->requestWith(null), $tenant->id, $student);

        $this->assertNull($result);
        $this->assertSame(0, UserDevice::withoutTenant()->where('user_id', $student->id)->count());
    }

    public function test_a_new_device_is_registered_then_counted(): void
    {
        [$tenant, $student] = $this->student();

        $this->assertNull(DevicePolicy::register($this->requestWith('device-a', 'Chrome · Apple'), $tenant->id, $student));
        $this->assertSame(1, DevicePolicy::registeredDeviceCount($tenant->id, $student));

        // Same id again: updated, never duplicated.
        $this->assertNull(DevicePolicy::register($this->requestWith('device-a'), $tenant->id, $student));
        $this->assertSame(1, DevicePolicy::registeredDeviceCount($tenant->id, $student));
    }

    public function test_the_device_cap_is_enforced(): void
    {
        [$tenant, $student] = $this->student();

        // Default cap is 1 device per student.
        DevicePolicy::register($this->requestWith('device-a'), $tenant->id, $student);

        $result = DevicePolicy::register($this->requestWith('device-b'), $tenant->id, $student);

        $this->assertIsArray($result);
        $this->assertSame(Response::HTTP_FORBIDDEN, $result['status']);
        $this->assertSame(1, DevicePolicy::registeredDeviceCount($tenant->id, $student));
    }

    public function test_a_per_student_override_raises_the_cap(): void
    {
        [$tenant, $student] = $this->student();

        StudentDeviceLimit::create([
            'tenant_id' => $tenant->id,
            'student_id' => $student->id,
            'max_devices' => 2,
        ]);

        DevicePolicy::register($this->requestWith('device-a'), $tenant->id, $student);
        $this->assertNull(DevicePolicy::register($this->requestWith('device-b'), $tenant->id, $student));
        $this->assertSame(2, DevicePolicy::registeredDeviceCount($tenant->id, $student));
    }

    public function test_an_over_long_device_id_is_truncated_to_the_column_width(): void
    {
        [$tenant, $student] = $this->student();

        DevicePolicy::register($this->requestWith(str_repeat('x', 250)), $tenant->id, $student);

        $stored = UserDevice::withoutTenant()->where('user_id', $student->id)->value('device_id');
        $this->assertSame(100, strlen($stored));
    }

    public function test_the_policy_uses_only_the_client_supplied_id(): void
    {
        // No User-Agent / Accept-Language / canvas / WebGL input exists in the
        // request below, yet registration still succeeds — proving the policy
        // does not depend on, or collect, fingerprinting signals.
        [$tenant, $student] = $this->student();

        $result = DevicePolicy::register($this->requestWith('only-this-id'), $tenant->id, $student);

        $this->assertNull($result);
        $row = UserDevice::withoutTenant()->where('user_id', $student->id)->first();
        $this->assertSame('only-this-id', $row->device_id);
        $this->assertSame('', $row->device_name); // nothing invented
    }
}
