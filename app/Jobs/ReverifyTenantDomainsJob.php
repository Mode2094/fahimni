<?php

namespace App\Jobs;

use App\Http\Controllers\Api\TeacherDomainController;
use App\Models\TenantSetting;
use App\Services\CloudflareSaasService;
use App\Services\TenantDomainResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Daily reconciliation of connected custom domains.
 *
 * Under Cloudflare for SaaS this is the status monitor: it ensures every
 * connected domain has a Cloudflare custom hostname, refreshes the hostname +
 * certificate status, marks a domain verified (and only then servable) once
 * Cloudflare reports both hostname AND certificate active, and un-verifies a
 * domain that Cloudflare can no longer serve (moved away / hostname deleted).
 *
 * When Cloudflare for SaaS is disabled it falls back to the original direct
 * mode: a live DNS check that clears domain_verified on mismatch.
 */
class ReverifyTenantDomainsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $platform = app(TenantDomainResolver::class)->platformDomain();

        $settings = TenantSetting::whereNotNull('custom_domain')->get();

        foreach ($settings as $setting) {
            try {
                if (CloudflareSaasService::enabled()) {
                    $this->reconcileCloudflare($setting);

                    continue;
                }

                $this->reconcileDirect($setting, $platform);
            } catch (\Throwable $e) {
                Log::warning('Domain re-verification error', [
                    'tenant_id' => $setting->tenant_id,
                    'domain' => $setting->custom_domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function reconcileCloudflare(TenantSetting $setting): void
    {
        $ready = TeacherDomainController::syncCustomHostname($setting);
        $setting->refresh();

        $wasVerified = ($setting->metadata['domain_verified'] ?? false) === true;

        if ($ready && ! $wasVerified) {
            $metadata = $setting->metadata ?? [];
            $metadata['domain_verified'] = true;
            $metadata['domain_verified_at'] = $metadata['domain_verified_at'] ?? now()->toIso8601String();
            unset($metadata['domain_unverified_at']);
            $setting->update(['metadata' => $metadata]);
        } elseif (! $ready && $wasVerified) {
            $metadata = $setting->metadata ?? [];
            $metadata['domain_verified'] = false;
            $metadata['domain_unverified_at'] = now()->toIso8601String();
            $setting->update(['metadata' => $metadata]);

            Log::warning('Custom domain no longer active on Cloudflare', [
                'tenant_id' => $setting->tenant_id,
                'domain' => $setting->custom_domain,
                'cf_status' => $metadata['cf_status'] ?? null,
                'cf_ssl_status' => $metadata['cf_ssl_status'] ?? null,
            ]);
        }
    }

    private function reconcileDirect(TenantSetting $setting, string $platform): void
    {
        if (($setting->metadata['domain_verified'] ?? false) !== true) {
            return;
        }

        $ok = TeacherDomainController::dnsPointsAtPlatform((string) $setting->custom_domain, $platform);

        if (! $ok) {
            $metadata = $setting->metadata ?? [];
            $metadata['domain_verified'] = false;
            $metadata['domain_unverified_at'] = now()->toIso8601String();
            $setting->update(['metadata' => $metadata]);

            Log::warning('Custom domain re-verification failed', [
                'tenant_id' => $setting->tenant_id,
                'domain' => $setting->custom_domain,
            ]);
        }
    }
}
