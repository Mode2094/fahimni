<?php

namespace App\Services;

use App\Exceptions\CloudflareApiException;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare for SaaS (Custom Hostnames) — the ONLY way a teacher's own domain
 * reaches the platform.
 *
 * Why this exists: the origin VPS sits behind a strict Cloudflare-only firewall
 * (`deploy/scripts/cloudflare-origin-firewall.sh`). A teacher domain that points
 * straight at the origin IP is therefore unreachable on :443. Instead, every
 * teacher domain is registered as a Cloudflare *custom hostname* on the
 * platform's own zone: Cloudflare terminates TLS at the edge (no teacher needs a
 * Cloudflare account) and forwards the request to the zone's fallback origin
 * over the Cloudflare network — which the origin firewall already trusts. The
 * origin IP is never exposed.
 *
 * The API token is a SECRET and lives in the environment ONLY
 * (`CLOUDFLARE_API_TOKEN`). It is never stored in the database, never logged,
 * and never returned to any client. Non-secret topology (zone id, fallback
 * origin, CNAME target) may be overridden from the admin panel via
 * platform_settings so the target address stays centrally changeable.
 *
 * Docs: https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/
 */
class CloudflareSaasService
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    private const TIMEOUT = 20;

    private const CONNECT_TIMEOUT = 6;

    /**
     * @return array{api_token: string, account_id: string, zone_id: string, zone_name: string, origin_ip: string, fallback_origin: string, cname_target: string}
     */
    public static function config(): array
    {
        return [
            // Secret — environment only. Never written to the database.
            'api_token' => (string) config('services.cloudflare.api_token', ''),
            'account_id' => (string) config('services.cloudflare.account_id', ''),
            'zone_id' => (string) config('services.cloudflare.zone_id', ''),
            'zone_name' => (string) config('services.cloudflare.zone_name', ''),
            'origin_ip' => (string) config('services.cloudflare.origin_ip', ''),
            // Non-secret overrides — admin-editable, so the target is changeable
            // without a code change or a redeploy.
            'fallback_origin' => (string) (PlatformSetting::get('custom_domain_fallback_origin') ?: config('services.cloudflare.fallback_origin', '')),
            'cname_target' => (string) (PlatformSetting::get('custom_domain_cname_target') ?: config('services.cloudflare.cname_target', '')),
        ];
    }

    /** Credentials + zone present, so the API can actually be talked to. */
    public static function configured(): bool
    {
        $c = self::config();

        return $c['api_token'] !== '' && $c['zone_id'] !== '';
    }

    /**
     * Master switch. The platform setting wins over the env default so an admin
     * can enable/disable the whole thing without a redeploy.
     */
    public static function enabled(): bool
    {
        return PlatformSetting::bool('cloudflare_saas_enabled', (bool) config('services.cloudflare.enabled', false))
            && self::configured();
    }

    /** The hostname teachers point their CNAME (or apex ALIAS) at. */
    public static function cnameTarget(): string
    {
        return trim(self::config()['cname_target']);
    }

    /**
     * Register a new custom hostname (idempotent callers should store the id).
     *
     * @return array<string, mixed> the created custom hostname
     */
    public static function createHostname(string $hostname): array
    {
        $zone = self::config()['zone_id'];

        $json = self::send('POST', "/zones/{$zone}/custom_hostnames", [
            'hostname' => $hostname,
            // HTTP DCV is fully automatic once the customer CNAME points at us;
            // a TXT DCV record is returned in `validation_records` regardless.
            'ssl' => ['method' => 'http', 'type' => 'dv'],
        ]);

        if (($json['success'] ?? false) !== true || ! isset($json['result'])) {
            throw new CloudflareApiException(self::firstError($json) ?? 'Cloudflare rejected the custom hostname.');
        }

        return $json['result'];
    }

    /** @return array<string, mixed>|null null when the hostname no longer exists */
    public static function getHostname(string $id): ?array
    {
        $zone = self::config()['zone_id'];
        $json = self::send('GET', "/zones/{$zone}/custom_hostnames/{$id}");

        if (($json['success'] ?? false) !== true || ! isset($json['result'])) {
            return null;
        }

        return $json['result'];
    }

    /** @return list<array<string, mixed>> */
    public static function listHostnames(): array
    {
        $zone = self::config()['zone_id'];
        $json = self::send('GET', "/zones/{$zone}/custom_hostnames");

        return (($json['success'] ?? false) === true && is_array($json['result'] ?? null))
            ? array_values($json['result'])
            : [];
    }

    /**
     * Find a custom hostname by its exact name. Used to reconcile a hostname
     * that exists in Cloudflare but whose id we lost locally, so we never try to
     * create a duplicate (Cloudflare rejects that) and never strand a live
     * certificate.
     *
     * @return array<string, mixed>|null
     */
    public static function findHostnameByName(string $hostname): ?array
    {
        $zone = self::config()['zone_id'];
        $json = self::send('GET', "/zones/{$zone}/custom_hostnames?hostname={$hostname}");

        foreach (($json['result'] ?? []) as $h) {
            if (is_array($h) && strcasecmp((string) ($h['hostname'] ?? ''), $hostname) === 0) {
                return $h;
            }
        }

        return null;
    }

    /** Idempotent: a hostname that is already gone counts as deleted. */
    public static function deleteHostname(string $id): bool
    {
        if ($id === '') {
            return true;
        }

        $zone = self::config()['zone_id'];
        $json = self::send('DELETE', "/zones/{$zone}/custom_hostnames/{$id}");

        return ($json['success'] ?? false) === true;
    }

    /**
     * One-time topology: a proxied fallback-origin record, the fallback-origin
     * setting itself, and a proxied CNAME target teachers can point at.
     * Repeatable — each step is only performed when missing.
     *
     * @return array{fallback_origin: string, cname_target: string, fallback_status: ?string, steps: list<string>}
     */
    public static function ensureSetup(): array
    {
        $c = self::config();

        if ($c['zone_id'] === '' || $c['fallback_origin'] === '' || $c['cname_target'] === '') {
            throw new CloudflareApiException('Zone id, fallback origin and CNAME target must all be configured.');
        }

        $steps = [];

        if (! self::dnsRecordExists($c['fallback_origin'])) {
            if ($c['origin_ip'] === '') {
                throw new CloudflareApiException('CLOUDFLARE_SAAS_ORIGIN_IP is required to create the fallback origin record.');
            }

            self::createDnsRecord([
                'type' => 'A',
                'name' => $c['fallback_origin'],
                'content' => $c['origin_ip'],
                'proxied' => true,
                'ttl' => 1,
            ]);
            $steps[] = "created proxied A {$c['fallback_origin']} -> {$c['origin_ip']}";
        }

        $zone = $c['zone_id'];
        $fo = self::send('PUT', "/zones/{$zone}/custom_hostnames/fallback_origin", ['origin' => $c['fallback_origin']]);

        if (($fo['success'] ?? false) !== true) {
            throw new CloudflareApiException(self::firstError($fo) ?? 'Cloudflare rejected the fallback origin.');
        }

        $fallbackStatus = $fo['result']['status'] ?? null;
        $steps[] = "fallback_origin = {$c['fallback_origin']}".($fallbackStatus ? " ({$fallbackStatus})" : '');

        if (! self::dnsRecordExists($c['cname_target'])) {
            self::createDnsRecord([
                'type' => 'CNAME',
                'name' => $c['cname_target'],
                'content' => $c['fallback_origin'],
                'proxied' => true,
                'ttl' => 1,
            ]);
            $steps[] = "created proxied CNAME {$c['cname_target']} -> {$c['fallback_origin']}";
        }

        return [
            'fallback_origin' => $c['fallback_origin'],
            'cname_target' => $c['cname_target'],
            'fallback_status' => $fallbackStatus,
            'steps' => $steps,
        ];
    }

    public static function dnsRecordExists(string $name): bool
    {
        $zone = self::config()['zone_id'];
        $json = self::send('GET', "/zones/{$zone}/dns_records?name={$name}");

        return ($json['success'] ?? false) === true && ! empty($json['result']);
    }

    /** @param array<string, mixed> $record */
    public static function createDnsRecord(array $record): array
    {
        $zone = self::config()['zone_id'];
        $json = self::send('POST', "/zones/{$zone}/dns_records", $record);

        if (($json['success'] ?? false) !== true) {
            throw new CloudflareApiException(self::firstError($json) ?? 'Cloudflare rejected the DNS record.');
        }

        return $json['result'] ?? [];
    }

    /**
     * Low-level API call. Never logs the token or the request body.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function send(string $method, string $path, array $body = []): array
    {
        $token = self::config()['api_token'];

        if ($token === '') {
            throw new CloudflareApiException('Cloudflare API token is not configured.');
        }

        try {
            $client = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout(self::TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT);

            $url = self::API_BASE.$path;

            $response = match (strtoupper($method)) {
                'GET' => $client->get($url),
                'POST' => $client->post($url, $body),
                'PUT' => $client->put($url, $body),
                'PATCH' => $client->patch($url, $body),
                'DELETE' => $client->delete($url, $body),
                default => throw new CloudflareApiException('Unsupported Cloudflare API method.'),
            };
        } catch (CloudflareApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Cloudflare API transport error', ['path' => $path, 'error' => $e->getMessage()]);

            throw new CloudflareApiException('Cloudflare API is unreachable.', 0, $e);
        }

        if ($response->serverError()) {
            Log::warning('Cloudflare API server error', ['path' => $path, 'status' => $response->status()]);

            throw new CloudflareApiException("Cloudflare API server error ({$response->status()}).");
        }

        $json = $response->json();

        return is_array($json) ? $json : ['success' => false, 'errors' => [['message' => 'Malformed Cloudflare response.']]];
    }

    /** @param array<string, mixed> $json */
    private static function firstError(array $json): ?string
    {
        $errors = $json['errors'] ?? null;

        if (is_array($errors) && isset($errors[0]['message']) && is_string($errors[0]['message'])) {
            return $errors[0]['message'];
        }

        return null;
    }
}
