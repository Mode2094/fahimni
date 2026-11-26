<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\TeacherDomainController;
use App\Models\TenantSetting;
use App\Services\CloudflareSaasService;
use Illuminate\Console\Command;

/**
 * Operate the Cloudflare for SaaS custom-domain integration.
 *
 *   php artisan fahimni:cloudflare-saas status   # config + live hostnames
 *   php artisan fahimni:cloudflare-saas setup    # create fallback origin + CNAME target
 *   php artisan fahimni:cloudflare-saas sync      # provision/refresh every connected domain
 *
 * Safe to run repeatedly; each step is idempotent.
 */
class CloudflareSaasCommand extends Command
{
    protected $signature = 'fahimni:cloudflare-saas {action=status : status|setup|sync} {--domain= : limit sync to one domain}';

    protected $description = 'Manage Cloudflare for SaaS custom hostnames (status, one-time setup, full sync).';

    public function handle(): int
    {
        if (! CloudflareSaasService::configured()) {
            $this->error('Cloudflare for SaaS is not configured (CLOUDFLARE_API_TOKEN + CLOUDFLARE_ZONE_ID).');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'setup' => $this->setup(),
            'sync' => $this->sync(),
            default => $this->status(),
        };
    }

    private function status(): int
    {
        $c = CloudflareSaasService::config();

        $this->table(['Setting', 'Value'], [
            ['enabled', CloudflareSaasService::enabled() ? 'yes' : 'no'],
            ['zone_id', $c['zone_id'] ?: '(unset)'],
            ['zone_name', $c['zone_name'] ?: '(unset)'],
            ['fallback_origin', $c['fallback_origin'] ?: '(unset)'],
            ['cname_target', $c['cname_target'] ?: '(unset)'],
            ['origin_ip', $c['origin_ip'] ?: '(unset)'],
        ]);

        $hostnames = CloudflareSaasService::listHostnames();

        if ($hostnames === []) {
            $this->warn('No custom hostnames on the zone yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['hostname', 'status', 'ssl_status'],
            array_map(fn ($h) => [
                $h['hostname'] ?? '?',
                $h['status'] ?? '?',
                $h['ssl']['status'] ?? '?',
            ], $hostnames)
        );

        return self::SUCCESS;
    }

    private function setup(): int
    {
        $result = CloudflareSaasService::ensureSetup();

        foreach ($result['steps'] as $step) {
            $this->info("• {$step}");
        }

        $this->info("Fallback origin: {$result['fallback_origin']} ({$result['fallback_status']})");
        $this->info("CNAME target:   {$result['cname_target']}");

        return self::SUCCESS;
    }

    private function sync(): int
    {
        $query = TenantSetting::whereNotNull('custom_domain');

        $only = (string) ($this->option('domain') ?? '');
        if ($only !== '') {
            $query->where('custom_domain', strtolower($only));
        }

        $settings = $query->get();

        if ($settings->isEmpty()) {
            $this->warn('No connected custom domains.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($settings as $setting) {
            $ready = TeacherDomainController::syncCustomHostname($setting);
            $setting->refresh();

            $metadata = $setting->metadata ?? [];
            $wasVerified = ($metadata['domain_verified'] ?? false) === true;

            if ($ready && ! $wasVerified) {
                $metadata['domain_verified'] = true;
                $metadata['domain_verified_at'] = $metadata['domain_verified_at'] ?? now()->toIso8601String();
                $setting->update(['metadata' => $metadata]);
            } elseif (! $ready && $wasVerified) {
                $metadata['domain_verified'] = false;
                $metadata['domain_unverified_at'] = now()->toIso8601String();
                $setting->update(['metadata' => $metadata]);
            }

            $rows[] = [
                (string) $setting->custom_domain,
                $metadata['cf_hostname_id'] ?? '—',
                $metadata['cf_status'] ?? '—',
                $metadata['cf_ssl_status'] ?? '—',
                $ready ? 'LIVE' : 'pending',
            ];
        }

        $this->table(['domain', 'hostname_id', 'status', 'ssl', 'result'], $rows);

        return self::SUCCESS;
    }
}
