<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantSetting;

/**
 * Single source of truth for «does this hostname belong to a live tenant on
 * this platform?» — shared by the Caddy on-demand-TLS `ask` gate, the
 * custom-domain site lookup, and the Sanctum stateful-domain trust middleware.
 *
 * A host is accepted when it is one of:
 *   • the platform's own hosts (apex, `www.`, `api.`), or
 *   • an ACTIVE teacher subdomain `{slug}.{platform}`, or
 *   • a VERIFIED custom domain (apex or its `www.` variant) of an ACTIVE tenant.
 *
 * Matching is exact label-wise — never a loose suffix/LIKE check — so a
 * look-alike (`fahimni.me.evil.com`) is rejected, and a host that merely ends
 * with the platform string is not treated as one of its subdomains.
 */
class TenantDomainResolver
{
    /** Platform domain used for subdomain matching (e.g. `fahimni.me`). */
    public function platformDomain(): string
    {
        return (string) PlatformSetting::get('platform_domain', 'lms.example.com');
    }

    /** True for the platform's own hosts: apex, `www.` and `api.`. */
    public function isPlatformHost(string $host, ?string $platform = null): bool
    {
        $platform = strtolower(rtrim($platform ?? $this->platformDomain(), '.'));

        return $host === $platform
            || $host === 'www.'.$platform
            || $host === 'api.'.$platform;
    }

    /**
     * True when the host is a live tenant address: an active `{slug}.{platform}`
     * subdomain or a VERIFIED custom domain (apex / its `www.` variant) of an
     * active tenant. A pure database lookup — no DNS, no network.
     *
     * Thin wrapper over {@see tenantForHost()} so the yes/no trust gate and the
     * callers that need the tenant itself can never diverge.
     */
    public function belongsToActiveTenant(string $host, ?string $platform = null): bool
    {
        return $this->tenantForHost($host, $platform) !== null;
    }

    /**
     * The ACTIVE tenant that owns this host, or `null` when the host is not a
     * live tenant address. A live address is a VERIFIED custom domain (apex or
     * its `www.` variant) of an ACTIVE tenant, or an active `{slug}.{platform}`
     * subdomain. Single source of truth for every host→tenant trust decision.
     */
    public function tenantForHost(string $host, ?string $platform = null): ?Tenant
    {
        $host = strtolower(rtrim(trim($host), ". \t"));
        $platform = strtolower(rtrim($platform ?? $this->platformDomain(), '.'));

        if ($host === '' || $platform === '' || strlen($host) > 190) {
            return null;
        }

        // Active teacher subdomain: {slug}.{platform} — exactly one extra label.
        if (str_ends_with($host, '.'.$platform)) {
            $sub = substr($host, 0, -(strlen($platform) + 1));

            if ($sub === '' || $sub === 'www' || str_contains($sub, '.')
                || ! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $sub)) {
                return null;
            }

            return Tenant::where('slug', $sub)->where('status', 'active')->first();
        }

        // Verified custom domain (apex or its `www.` variant).
        $apex = preg_replace('/^www\./', '', $host) ?? $host;

        if ($apex !== $host && $host !== 'www.'.$apex) {
            return null;
        }

        $setting = TenantSetting::withoutTenant()->where('custom_domain', $apex)->first();

        if (! $setting) {
            return null;
        }

        $meta = $setting->metadata ?? [];

        $verified = ($meta['domain_verified'] ?? false) === true;
        $hostActive = $host === 'www.'.$apex
            ? ($meta['cf_status_www'] ?? null) === 'active' && ($meta['cf_ssl_status_www'] ?? null) === 'active'
            : ($meta['cf_status'] ?? null) === 'active' && ($meta['cf_ssl_status'] ?? null) === 'active';

        if (! $verified && ! $hostActive) {
            return null;
        }

        return Tenant::whereKey($setting->tenant_id)->where('status', 'active')->first();
    }

    /**
     * The Caddy on-demand-TLS allow-list predicate: the platform's own hosts or
     * any live tenant address.
     */
    public function isAllowedEdgeHost(string $host, ?string $platform = null): bool
    {
        $platform = strtolower(rtrim($platform ?? $this->platformDomain(), '.'));

        return $this->isPlatformHost($host, $platform)
            || $this->belongsToActiveTenant($host, $platform);
    }
}
