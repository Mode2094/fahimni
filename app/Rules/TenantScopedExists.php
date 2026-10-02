<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Existence check scoped to the current tenant. The built-in `exists:`
 * rule ignores the Eloquent global tenant scope, allowing a client to
 * reference rows from another tenant (cross-tenant FK injection).
 */
class TenantScopedExists implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly string $column = 'id',
        private readonly string $tenantColumn = 'tenant_id',
        private readonly ?string $tenantId = null,
        private readonly bool $ignoreSoftDeletes = true,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $tenantId = $this->tenantId
            ?? request()?->attributes->get('current_tenant')?->getKey()
            ?? request()?->header('X-Tenant-Id');

        if (! $tenantId) {
            $fail('Tenant context is required.');

            return;
        }

        $query = DB::table($this->table)
            ->where($this->column, $value)
            ->where($this->tenantColumn, $tenantId);

        // Rows with a deleted_at column that are soft-deleted must never be
        // referenced again (orphaned attachments otherwise break exams).
        if ($this->ignoreSoftDeletes && Schema::hasColumn($this->table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if (! $query->exists()) {
            $fail('The selected :attribute is invalid for this tenant.');
        }
    }
}
