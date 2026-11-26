<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Regression: audit logging must never write an empty/invalid `tenant_id`.
 *
 * The admin panels send `X-Tenant-Id: ''` on purpose (a Super Admin acts
 * platform-wide). The old code used `?? `, so an empty header fell through as
 * `''`; Postgres rejected it ("invalid input syntax for type uuid") and, because
 * audit logging runs inside the caller's transaction, it aborted the
 * transaction and turned a successful action into an HTTP 500.
 */
class AuditLoggerTest extends TestCase
{
    private function requestWithHeader(?string $header): Request
    {
        $server = $header === null ? [] : ['HTTP_X_TENANT_ID' => $header];

        return Request::create('/api/v1/admin/teachers', 'POST', [], [], [], $server);
    }

    public function test_empty_tenant_header_is_stored_as_null_not_an_empty_uuid(): void
    {
        AuditLogger::log($this->requestWithHeader(''), 'test.event');

        $log = AuditLog::latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->tenant_id);
    }

    public function test_absent_tenant_header_is_stored_as_null(): void
    {
        AuditLogger::log($this->requestWithHeader(null), 'test.event');

        $this->assertNull(AuditLog::latest('created_at')->first()->tenant_id);
    }

    public function test_garbage_tenant_header_is_rejected_to_null(): void
    {
        AuditLogger::log($this->requestWithHeader('not-a-uuid'), 'test.event');

        $this->assertNull(AuditLog::latest('created_at')->first()->tenant_id);
    }

    public function test_valid_tenant_id_is_preserved(): void
    {
        $tenantId = (string) \Illuminate\Support\Str::uuid();

        AuditLogger::log($this->requestWithHeader($tenantId), 'test.event');

        $this->assertSame($tenantId, AuditLog::latest('created_at')->first()->tenant_id);
    }

    public function test_explicit_tenant_argument_wins_over_an_empty_header(): void
    {
        $tenantId = (string) \Illuminate\Support\Str::uuid();

        AuditLogger::log($this->requestWithHeader(''), 'test.event', null, null, [], $tenantId);

        $this->assertSame($tenantId, AuditLog::latest('created_at')->first()->tenant_id);
    }
}
