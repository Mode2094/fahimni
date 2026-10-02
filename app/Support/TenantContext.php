<?php

namespace App\Support;

/**
 * Server-side tenant context for the current request/queue job.
 *
 * Set by TenantMiddleware after membership validation, never from raw client
 * input. Eloquent global scopes read from here so that tenant scoping can
 * never be spoofed by a client-supplied header on unprotected routes, and
 * so that queue jobs (where request() is null) behave deterministically.
 */
class TenantContext
{
    private ?string $tenantId = null;

    public function set(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function current(): ?string
    {
        return $this->tenantId;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }
}
