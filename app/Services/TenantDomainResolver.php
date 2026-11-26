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
     */
    public function belongsToActiveTenant(string $host, ?string $platform = null): bool
    {
        $host = strtolower(rtrim(trim($host), ". \t"));
        $platform = strtolower(rtrim($platform ?? $this->platformDomain(), '.'));

        if ($host === '' || $platform === '' || strlen($host) > 190) {
            return false;
        }

        // Teacher subdomain: {slug}.{platform} — exactly one extra label.
        if (str_ends_with($host, '.'.$platform)) {
            $sub = substr($host, 0, -(strlen($platform) + 1));

            if ($sub !== '' && $sub !== 'www' && ! str_contains($sub, '.')
                && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $sub)) {
                return Tenant::where('slug', $sub)->where('status', 'active')->exists();
            }

            return false;
        }

        // Verified custom domain, apex or its `www.` variant. The setting must
        // be the one that declares this exact host — never a different tenant's
        // name reached by a loose match.
        $apex = preg_replace('/^www\./', '', $host) ?? $host;

        if ($apex !== $host && $host !== 'www.'.$apex) {
            return false;
        }

        $setting = TenantSetting::withoutTenant()->where('custom_domain', $apex)->first();

        if (! $setting) {
            return false;
        }

        $meta = $setting->metadata ?? [];

        // Servable when the tenant's domain is verified (at least one of its
        // hostnames is active on Cloudflare) OR when THIS exact host is already
        // active on Cloudflare. The second clause is defence-in-depth: a
        // still-pending apex must never block an already-active `www` (and vice
        // versa) even before the next sync refreshes `domain_verified`.
        $verified = ($meta['domain_verified'] ?? false) === true;

        $hostActive = $host === 'www.'.$apex
            ? ($meta['cf_status_www'] ?? null) === 'active' && ($meta['cf_ssl_status_www'] ?? null) === 'active'
            : ($meta['cf_status'] ?? null) === 'active' && ($meta['cf_ssl_status'] ?? null) === 'active';

        if (! $verified && ! $hostActive) {
            return false;
        }

        return Tenant::whereKey($setting->tenant_id)->where('status', 'active')->exists();
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
