<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class AuditLogger
{
    /**
     * @param  string|null  $actorId  Record the event under this REAL actor
     *                                instead of the request's (possibly
     *                                impersonated) user — used by admin support
     *                                sessions so the trail names the admin.
     */
    public static function log(Request $request, string $action, ?string $entityType = null, ?string $entityId = null, array $details = [], ?string $tenantId = null, ?string $actorId = null): void
    {
        try {
            $user = $request->user() ?? auth('sanctum')->user();

            AuditLog::create([
                'id' => UuidV7::generate(),
                'user_id' => $actorId ?? $user?->id,
                'tenant_id' => $tenantId ?? $request->header('X-Tenant-Id') ?? $request->current_tenant->id ?? null,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'details' => $details,
            ]);
        } catch (\Throwable) {
            // Never break the main flow because of audit logging.
        }
    }
}
