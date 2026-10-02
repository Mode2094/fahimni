<?php

namespace App\Jobs;

use App\Http\Controllers\Api\TeacherDomainController;
use App\Models\TenantSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Daily re-verification of connected custom domains.
 *
 * DNS verification is one-shot today: if a tenant's DNS is moved away (or
 * the tenant is compromised) the platform keeps serving their site under
 * the domain. This job re-checks the CNAME and clears domain_verified on
 * mismatch so lookup() stops routing the domain.
 */
class ReverifyTenantDomainsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $platform = strtolower((string) config('app.url', ''));

        if ($platform === '' || ! str_contains($platform, '.')) {
            return;
        }

        // platform hostname without scheme/port
        $host = strtolower((string) parse_url($platform, PHP_URL_HOST));

        if ($host === '') {
            return;
        }

        $verifiedSettings = TenantSetting::whereNotNull('custom_domain')
            ->get()
            ->filter(fn (TenantSetting $s) => ($s->metadata['domain_verified'] ?? false) === true);

        foreach ($verifiedSettings as $setting) {
            try {
                $ok = TeacherDomainController::dnsPointsAtPlatform((string) $setting->custom_domain, $host);

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
            } catch (\Throwable $e) {
                Log::warning('Domain re-verification error', [
                    'tenant_id' => $setting->tenant_id,
                    'domain' => $setting->custom_domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
