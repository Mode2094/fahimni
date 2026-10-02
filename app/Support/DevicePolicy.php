<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\StudentDeviceLimit;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DevicePolicy
{
    public static function policyEnabled(string $tenantId): bool
    {
        $setting = TenantSetting::where('tenant_id', $tenantId)->first();
        $metadata = $setting?->metadata ?? [];

        return (bool) ($metadata['device_policy_enabled'] ?? true);
    }

    public static function maxDevices(string $tenantId): int
    {
        $setting = TenantSetting::where('tenant_id', $tenantId)->first();
        $metadata = $setting?->metadata ?? [];

        return max(1, (int) ($metadata['device_policy_max_devices'] ?? 1));
    }

    public static function allowedDevices(string $tenantId, User $student): int
    {
        $override = StudentDeviceLimit::where('tenant_id', $tenantId)
            ->where('student_id', $student->id)
            ->first();

        return $override ? max(1, $override->max_devices) : self::maxDevices($tenantId);
    }

    public static function registeredDeviceCount(string $tenantId, User $student): int
    {
        return UserDevice::where('tenant_id', $tenantId)
            ->where('user_id', $student->id)
            ->count();
    }

    /**
     * Register the device for the student and enforce the limit.
     * Returns an error response array, or null when allowed.
     */
    public static function register(Request $request, string $tenantId, User $student): ?array
    {
        $deviceId = $request->header('X-Device-Id')
            ?? $request->input('device_id')
            ?? $request->input('device');

        if (! $deviceId) {
            return null;
        }

        $deviceId = substr((string) $deviceId, 0, 100);

        $device = UserDevice::where('tenant_id', $tenantId)
            ->where('user_id', $student->id)
            ->where('device_id', $deviceId)
            ->first();

        if ($device) {
            $device->update([
                'device_name' => substr((string) ($request->header('X-Device-Name') ?? $request->input('device_name') ?? ''), 0, 190),
                'ip_address' => $request->ip(),
                'last_seen_at' => now(),
            ]);

            return null;
        }

        $allowed = self::allowedDevices($tenantId, $student);
        $current = self::registeredDeviceCount($tenantId, $student);

        if ($current >= $allowed) {
            return [
                'status' => Response::HTTP_FORBIDDEN,
                'message' => "Device limit reached ({$allowed} device(s)). Contact your teacher to reset your devices.",
            ];
        }

        UserDevice::create([
            'tenant_id' => $tenantId,
            'user_id' => $student->id,
            'device_id' => $deviceId,
            'device_name' => substr((string) ($request->header('X-Device-Name') ?? $request->input('device_name') ?? ''), 0, 190),
            'ip_address' => $request->ip(),
            'last_seen_at' => now(),
        ]);

        return null;
    }

    public static function globalRegistrationEnabled(): bool
    {
        return (bool) PlatformSetting::get('registration_enabled', true);
    }
}
